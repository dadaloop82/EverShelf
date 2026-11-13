#!/usr/bin/env php
<?php
/**
 * Consolidate catalog rows that carry the SAME name.
 *
 * The identity of a food in EverShelf is its NAME: the brand printed on the pack is a variant,
 * not a new product. `findDuplicateProductId()` and the consolidator now reuse a row across
 * brands, but the rows created BEFORE that rule still sit beside each other — one per label
 * ("Banane [SCB]" + "Banane [Iper Poli]", "Panna da cucina" x3, "Tonno all'olio d'oliva" x4) —
 * and every one of them is its own family in the shopping engine, which is why the list asks
 * for food that is already on the shelf and the anomaly banner counts one purchase twice.
 *
 * This pass merges each exact-name group into the canonical row with the same move the app
 * itself does (`mergeProducts()`): inventory, transactions and tag links follow the kept row,
 * then the duplicate catalog row is deleted. Nothing else is touched — no title, no brand and
 * no unit is rewritten.
 *
 * Usage:
 *   php scripts/consolidate-duplicate-products.php [--dry-run] [--no-backup] [--limit=N]
 *
 *   --dry-run    print the groups and the row that would be kept, write nothing
 *   --no-backup  skip the VACUUM INTO copy in data/backups/
 *   --limit=N    stop after N groups
 *
 * Medical rows are never merged by name: two boxes with the same title are still two products
 * (another package, another AIC), and the cabinet keeps them apart by code.
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$args = array_slice($argv, 1);
$options = ['dry_run' => false, 'backup' => true, 'limit' => 0];
foreach ($args as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $name = substr($arg, 2);
    $value = '';
    if (str_contains($name, '=')) {
        [$name, $value] = explode('=', $name, 2);
    }
    if ($name === 'dry-run') {
        $options['dry_run'] = true;
    } elseif ($name === 'no-backup') {
        $options['backup'] = false;
    } elseif ($name === 'limit') {
        $options['limit'] = max(0, (int)$value);
    } elseif ($name === 'help' || $name === 'h') {
        fwrite(STDERR, "Usage: php scripts/consolidate-duplicate-products.php [--dry-run] [--no-backup] [--limit=N]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: --{$name}\n");
        exit(2);
    }
}

$db = getDB();
$dryRun = $options['dry_run'];


/** A category that carries information (not an Open Food Facts catch-all, not a URL). */
$categorySaysSomething = static function (string $cat): bool {
    $c = mb_strtolower(trim($cat));
    if ($c === '' || str_starts_with($c, 'en:') || str_starts_with($c, 'http')) {
        return false;
    }
    return true;
};

/** Stock and ledger weight of one row — the figures `productCanonicalScore()` reads. */
$weigh = static function (PDO $db, int $id) use ($categorySaysSomething): array {
    $stock = (float)$db->query('SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE product_id = ' . $id)->fetchColumn();
    $in = (float)$db->query("SELECT COALESCE(SUM(quantity), 0) FROM transactions WHERE product_id = " . $id . " AND type = 'in' AND undone = 0")->fetchColumn();
    $row = $db->query('SELECT barcode, image_url, category, name, unit FROM products WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    $score = 0.0;
    if (trim((string)($row['barcode'] ?? '')) !== '') {
        $score += 10000;
    }
    $score += mb_strlen(trim((string)($row['name'] ?? '')));
    if (trim((string)($row['image_url'] ?? '')) !== '') {
        $score += 100;
    }
    if ($categorySaysSomething((string)($row['category'] ?? ''))) {
        $score += 50;
    }
    $score += $stock * 20 + $in * 5;
    return ['score' => $score, 'stock' => $stock, 'in' => $in, 'unit' => (string)($row['unit'] ?? 'pz')];
};

$groups = $db->query(
    "SELECT lower(trim(name)) AS n, COUNT(*) AS c, GROUP_CONCAT(id) AS ids
       FROM products
      WHERE parent_id IS NULL AND COALESCE(is_medical, 0) = 0
      GROUP BY lower(trim(name))
     HAVING c > 1
      ORDER BY c DESC, n ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($options['limit'] > 0) {
    $groups = array_slice($groups, 0, $options['limit']);
}
if ($groups === []) {
    echo "No same-name groups left: the catalog is consolidated.\n";
    exit(0);
}

// A snapshot before touching anything: VACUUM INTO writes a consistent copy while the app keeps
// serving (the same primitive createLocalBackup() uses).
if (!$dryRun && $options['backup']) {
    $dir = __DIR__ . '/../data/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/pre-consolidate-' . date('Ymd-His') . '.db';
    $db->exec('VACUUM INTO ' . $db->quote($file));
    echo "Backup: {$file}\n";
}

$mergedGroups = 0;
$mergedRows = 0;
$moveTags = $db->prepare(
    'INSERT OR IGNORE INTO product_tag_links (product_id, tag_id, created_at)
     SELECT ?, tag_id, created_at FROM product_tag_links WHERE product_id = ?'
);

foreach ($groups as $g) {
    $rows = [];
    foreach (array_map('intval', explode(',', (string)$g['ids'])) as $id) {
        $w = $weigh($db, $id);
        $rows[] = ['id' => $id, 'score' => $w['score'], 'stock' => $w['stock'], 'unit' => $w['unit']];
    }
    $name = (string)$g['n'];

    // Stock speaks in the row's own unit: moving 3 "pz" of cream into a "ml" row would silently
    // turn 3 packs into 3 millilitres. When two STOCKED rows disagree on the unit there is a
    // real conversion to make, and this pass is not the place to guess it — the household can
    // still merge those two from the product sheet, where the app converts.
    $stockedUnits = [];
    foreach ($rows as $r) {
        if ($r['stock'] > 0) {
            $stockedUnits[$r['unit']] = true;
        }
    }
    if (count($stockedUnits) > 1) {
        printf(
            "[skip]   \"%s\": %s — different units with stock inside: merge them by hand from the product sheet\n",
            $name,
            implode(', ', array_map(
                static fn(array $r): string => '#' . $r['id'] . ' ' . $r['stock'] . ' ' . $r['unit'],
                array_values(array_filter($rows, static fn(array $r): bool => $r['stock'] > 0))
            ))
        );
        continue;
    }

    // The row the household already uses wins: barcode, a picture, a real category, the stock.
    usort($rows, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
    $keep = array_shift($rows);
    // …unless the kept row is empty and the stock sits in a row of ANOTHER unit: the stocked row
    // is the one whose unit the quantities already speak.
    if ($keep['stock'] <= 0 && $stockedUnits !== [] && !isset($stockedUnits[$keep['unit']])) {
        $ranked = $rows;
        usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
        foreach ($ranked as $i => $r) {
            if ($r['stock'] > 0) {
                unset($ranked[$i]);
                $rows = array_values($ranked);
                $rows[] = $keep;
                $keep = $r;
                break;
            }
        }
    }
    $dropped = array_map(
        static fn(array $r): string => '#' . $r['id'] . ' (' . rtrim(rtrim(number_format((float)$r['stock'], 2, '.', ''), '0'), '.') . ' ' . $r['unit'] . ')',
        $rows
    );
    printf(
        "%s \"%s\": keep #%d, merge %s\n",
        $dryRun ? '[dry-run]' : '[merge]  ',
        $name,
        $keep['id'],
        implode(', ', $dropped)
    );
    if ($dryRun) {
        continue;
    }

    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            $db->prepare('UPDATE inventory SET product_id = ? WHERE product_id = ?')->execute([$keep['id'], $r['id']]);
            $db->prepare('UPDATE transactions SET product_id = ? WHERE product_id = ?')->execute([$keep['id'], $r['id']]);
            $moveTags->execute([$keep['id'], $r['id']]);
            // An explicit variant follows its parent rather than disappearing with the row.
            $db->prepare('UPDATE products SET parent_id = ? WHERE parent_id = ?')->execute([$keep['id'], $r['id']]);
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$r['id']]);
            $mergedRows++;
        }
        $db->commit();
        $mergedGroups++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, "  ! group \"{$name}\" not merged: " . $e->getMessage() . "\n");
    }
}

if ($dryRun) {
    echo "\nDry run: nothing written. Run again without --dry-run to merge the groups above.\n";
} else {
    echo "\nMerged {$mergedGroups} groups / {$mergedRows} rows.\n";
    if ($mergedGroups > 0) {
        EverLog::info('duplicate products consolidated', [
            'event'  => 'products_consolidated_script',
            'groups' => $mergedGroups,
            'rows'   => $mergedRows,
        ]);
    }
}
