#!/usr/bin/env php
<?php
/**
 * Inspect internal shopping_list vs catalog stock and removal matching.
 * Run: php scripts/audit-shopping-list.php [--fix]
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$fix = in_array('--fix', $argv ?? [], true);
$db = getDB();

$list = $db->query('SELECT id, name, raw_name, specification FROM shopping_list ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo 'Shopping list rows: ' . count($list) . "\n\n";

$prods = $db->query(
    'SELECT p.id, p.name, p.brand, p.shopping_name,
        (SELECT COALESCE(SUM(i.quantity),0) FROM inventory i WHERE i.product_id=p.id) AS stock
     FROM products p'
)->fetchAll(PDO::FETCH_ASSOC);

$toRemove = [];
$toNormalize = [];

foreach ($list as $sl) {
    $listName = (string)$sl['name'];
    $rawName = (string)($sl['raw_name'] ?? '');
    $genKey = internalShoppingListGenericKey($db, $listName, $rawName);
    $canonical = computeShoppingName($genKey);
    if ($canonical !== '' && mb_strtolower(trim($listName)) !== mb_strtolower(trim($canonical))
        && mb_strtolower(trim($listName)) !== mb_strtolower(trim($genKey))) {
        $toNormalize[] = ['id' => $sl['id'], 'from' => $listName, 'to' => $canonical !== '' ? $canonical : $genKey];
    }

    $matched = [];
    foreach ($prods as $p) {
        $display = trim((string)($p['shopping_name'] ?? '')) ?: computeShoppingName((string)$p['name'], '', (string)($p['brand'] ?? ''));
        $bringKey = italianToBring($display);
        if (!shoppingListRowMatchesProduct($db, $listName, $rawName, (string)$p['name'], (string)($p['shopping_name'] ?? ''))) {
            continue;
        }
        $eval = shoppingEvaluateFamilyRestock($db, (int)$p['id']);
        $matched[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'shop' => $display,
            'stock' => (float)$p['stock'],
            'covered' => !empty($eval['covered']),
            'need_base' => (float)($eval['need_base'] ?? 0),
        ];
    }

    echo "#{$sl['id']} \"{$listName}\" generic_key={$genKey}\n";
    if (!$matched) {
        echo "  ⚠ no catalog match (removal on buy may fail)\n";
    } else {
        $anyCovered = false;
        foreach ($matched as $m) {
            $flag = $m['covered'] ? 'COVERED' : 'need=' . $m['need_base'];
            echo "  · prod#{$m['id']} stock={$m['stock']} {$flag} | {$m['shop']} ← {$m['name']}\n";
            if ($m['covered'] && $m['stock'] > 0) {
                $anyCovered = true;
            }
        }
        if ($anyCovered) {
            $toRemove[] = (int)$sl['id'];
        }
    }
    echo "\n";
}

echo "=== Normalize to generic title: " . count($toNormalize) . " ===\n";
foreach ($toNormalize as $n) {
    echo "  #{$n['id']} \"{$n['from']}\" → \"{$n['to']}\"\n";
}

echo "=== Rows removable (stock covers plan need): " . count($toRemove) . " ===\n";
echo ' ids: ' . implode(', ', $toRemove) . "\n";

if ($fix) {
    internalShoppingDedupeGenerics($db);
    foreach ($toNormalize as $n) {
        $row = $db->prepare('SELECT raw_name FROM shopping_list WHERE id = ?');
        $row->execute([$n['id']]);
        $cur = $row->fetch(PDO::FETCH_ASSOC);
        $raw = trim((string)($cur['raw_name'] ?? ''));
        if ($raw === '' || strcasecmp($raw, $n['to']) === 0) {
            $raw = $n['from'];
        }
        $db->prepare('UPDATE shopping_list SET name = ?, raw_name = ? WHERE id = ?')
            ->execute([$n['to'], $raw, $n['id']]);
    }
    foreach ($toRemove as $id) {
        $db->prepare('DELETE FROM shopping_list WHERE id = ?')->execute([$id]);
    }
    @unlink(__DIR__ . '/../data/smart_shopping_cache.json');
    echo "\nApplied fix: dedupe + normalize + removed covered rows.\n";
}
