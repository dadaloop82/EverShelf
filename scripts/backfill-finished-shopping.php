#!/usr/bin/env php
<?php
/**
 * Backfill Bring!/shopping list for products depleted in the last N days.
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

echo '[' . date('Y-m-d H:i:s') . "] Backfill {$days}d — " . count($rows) . " prodotti esauriti\n";

$added = 0;
$updated = 0;
$skipped = 0;
foreach ($rows as $r) {
    $res = bringAddDepletedProduct($db, (int)$r['id']);
    if (!empty($res['added'])) {
        $added++;
        echo "  + {$r['name']} → {$res['generic_name']}\n";
    } elseif (!empty($res['updated'])) {
        $updated++;
        echo "  ~ {$r['name']} → {$res['generic_name']}\n";
    } else {
        $skipped++;
    }
}

ob_start();
smartShopping($db);
$json = ob_get_clean();
$decoded = json_decode($json, true);
if ($decoded && !empty($decoded['success'])) {
    $decoded['cached_at'] = date('c');
    $decoded['cached_ts'] = time();
    file_put_contents(
        __DIR__ . '/../data/smart_shopping_cache.json',
        json_encode($decoded, JSON_UNESCAPED_UNICODE)
    );
}

ob_start();
bringSyncFull($db, false);
$sync = json_decode(ob_get_clean(), true);
$auto = $sync['auto_add'] ?? [];

echo '[' . date('Y-m-d H:i:s') . "] bringAddDepleted: added={$added} updated={$updated} skipped={$skipped}\n";
echo '[' . date('Y-m-d H:i:s') . '] bringSync auto_add: ' . json_encode($auto, JSON_UNESCAPED_UNICODE) . "\n";
