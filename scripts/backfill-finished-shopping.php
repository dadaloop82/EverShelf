#!/usr/bin/env php
<?php
/**
 * Backfill shopping list for products depleted in the last N days.
 * Works for both Bring! and internal shopping modes.
 * Usage: php scripts/backfill-finished-shopping.php [days]
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$days = max(1, (int)($argv[1] ?? RECENTLY_EXHAUSTED_DAYS));
$db = getDB();

$rows = $db->query("
    SELECT p.id, p.name, p.shopping_name
    FROM products p
    WHERE COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) <= 0.001
      AND (
        SELECT MAX(t.created_at) FROM transactions t
        WHERE t.product_id = p.id AND t.undone = 0 AND t.type IN ('out','waste')
      ) >= datetime('now', '-{$days} days')
    ORDER BY (
        SELECT MAX(t.created_at) FROM transactions t
        WHERE t.product_id = p.id AND t.undone = 0 AND t.type IN ('out','waste')
    ) DESC
")->fetchAll(PDO::FETCH_ASSOC);

$mode = isShoppingBringMode() ? 'bring' : 'internal';
echo '[' . date('Y-m-d H:i:s') . "] Backfill {$days}d ({$mode}) — " . count($rows) . " prodotti esauriti\n";

$added = 0;
$updated = 0;
$skipped = 0;
$byReason = [];
foreach ($rows as $r) {
    $res = shoppingAddDepletedProduct($db, (int)$r['id']);
    $reason = (string)($res['reason'] ?? '');
    if (!empty($res['added'])) {
        $added++;
        echo "  + {$r['name']} → {$res['generic_name']}\n";
    } elseif (!empty($res['updated'])) {
        $updated++;
        echo "  ~ {$r['name']} → {$res['generic_name']}\n";
    } else {
        $skipped++;
        $byReason[$reason ?: 'other'] = ($byReason[$reason ?: 'other'] ?? 0) + 1;
    }
}

echo "Done: added={$added} updated={$updated} skipped={$skipped}\n";
if ($byReason) {
    echo 'Skip reasons: ' . json_encode($byReason, JSON_UNESCAPED_UNICODE) . "\n";
}
