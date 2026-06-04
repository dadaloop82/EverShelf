#!/usr/bin/env php
<?php
/**
 * One-time merge of duplicate product records (same normalized name + compatible brand).
 * Opened-package splits remain as separate inventory rows on the canonical product.
 *
 * Usage: php scripts/merge-duplicate-products.php [--dry-run]
 */
declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv, true);
$dbPath = __DIR__ . '/../data/evershelf.db';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "Database not found: $dbPath\n");
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function normName(string $name): string {
    return mb_strtolower(trim($name));
}

function normBrand(string $brand): string {
    return mb_strtolower(trim($brand));
}

function brandsCompatible(string $a, string $b): bool {
    $na = normBrand($a);
    $nb = normBrand($b);
    return $na === $nb || $na === '' || $nb === '';
}

function productScore(PDO $db, int $id): float {
    $tx = (float)$db->query("SELECT COUNT(*) FROM transactions WHERE product_id = $id")->fetchColumn();
    $inv = (float)$db->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE product_id = $id")->fetchColumn();
    return $tx * 10 + $inv;
}

function mergeProducts(PDO $db, int $keepId, int $dropId): void {
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE inventory SET product_id = ? WHERE product_id = ?')->execute([$keepId, $dropId]);
        $db->prepare('UPDATE transactions SET product_id = ? WHERE product_id = ?')->execute([$keepId, $dropId]);
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$dropId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

$products = $db->query('SELECT id, name, brand, barcode FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$byName = [];
foreach ($products as $p) {
    $key = normName($p['name']);
    if ($key === '') {
        continue;
    }
    $byName[$key][] = $p;
}

$merged = 0;
foreach ($byName as $nameKey => $group) {
    if (count($group) < 2) {
        continue;
    }

    // Split into compatible-brand clusters
    $clusters = [];
    foreach ($group as $p) {
        $placed = false;
        foreach ($clusters as &$cluster) {
            $ref = $cluster[0];
            if (brandsCompatible($p['brand'] ?? '', $ref['brand'] ?? '')) {
                $cluster[] = $p;
                $placed = true;
                break;
            }
        }
        unset($cluster);
        if (!$placed) {
            $clusters[] = [$p];
        }
    }

    foreach ($clusters as $cluster) {
        if (count($cluster) < 2) {
            continue;
        }

        usort($cluster, fn($a, $b) => productScore($db, (int)$b['id']) <=> productScore($db, (int)$a['id']));
        $keep = (int)$cluster[0]['id'];
        $keepName = $cluster[0]['name'];
        for ($i = 1; $i < count($cluster); $i++) {
            $drop = (int)$cluster[$i]['id'];
            echo ($dryRun ? '[dry-run] ' : '') . "Merge #{$drop} \"{$cluster[$i]['name']}\" → #{$keep} \"{$keepName}\"\n";
            if (!$dryRun) {
                mergeProducts($db, $keep, $drop);
            }
            $merged++;
        }
    }
}

echo $dryRun
    ? "Dry run: $merged merge(s) would be performed.\n"
    : "Done: $merged duplicate product(s) merged.\n";
