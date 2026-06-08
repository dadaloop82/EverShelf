#!/usr/bin/env php
<?php
/**
 * Audit: products depleted in last N days vs shopping list / Bring / smart shopping.
 * Usage: php scripts/audit-finished-shopping.php [days]
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$days = max(1, (int)($argv[1] ?? 30));
$db = getDB();

// Recompute smart shopping fresh
ob_start();
smartShopping($db);
$smartJson = ob_get_clean();
$smartData = json_decode($smartJson, true);
$smartItems = $smartData['items'] ?? [];
$smartByPid = [];
$smartByName = [];
foreach ($smartItems as $si) {
    foreach ($si['variants'] ?? [] as $v) {
        $smartByPid[(int)$v['product_id']] = $si;
    }
    $smartByPid[(int)$si['product_id']] = $si;
    $sn = strtolower(trim($si['shopping_name'] ?? $si['name'] ?? ''));
    if ($sn !== '') $smartByName[$sn] = $si;
}

// Bring list
$bringNames = [];
$bringSpecs = [];
$auth = bringAuth();
if ($auth && !empty($auth['bringListUUID'])) {
    $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$auth['bringListUUID']}");
    if ($listData && isset($listData['purchase'])) {
        foreach ($listData['purchase'] as $bi) {
            $k = mb_strtolower($bi['name'] ?? '');
            $bringNames[$k] = $bi['name'] ?? '';
            $bringSpecs[$k] = $bi['specification'] ?? '';
        }
    }
}

// Internal shopping list
$shopNames = [];
$shopRows = $db->query("SELECT name, specification FROM shopping_list")->fetchAll(PDO::FETCH_ASSOC);
foreach ($shopRows as $r) {
    $shopNames[mb_strtolower($r['name'])] = $r;
}

// Products with zero stock, last activity in window
$rows = $db->query("
    SELECT p.id, p.name, p.brand, p.shopping_name, p.unit,
           COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) AS stock_qty,
           (SELECT MAX(t.created_at) FROM transactions t
            WHERE t.product_id = p.id AND t.undone = 0
              AND t.type IN ('out','waste','in')
              AND t.created_at >= datetime('now', '-{$days} days')) AS last_activity,
           (SELECT MAX(t.created_at) FROM transactions t
            WHERE t.product_id = p.id AND t.undone = 0
              AND t.type IN ('out','waste')
              AND t.created_at >= datetime('now', '-{$days} days')) AS last_out,
           (SELECT COUNT(*) FROM transactions t
            WHERE t.product_id = p.id AND t.undone = 0 AND t.type IN ('out','waste')) AS use_count,
           (SELECT COUNT(*) FROM transactions t
            WHERE t.product_id = p.id AND t.undone = 0 AND t.type = 'in') AS buy_count
    FROM products p
    WHERE COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) <= 0.001
      AND (SELECT MAX(t.created_at) FROM transactions t
           WHERE t.product_id = p.id AND t.undone = 0
             AND t.type IN ('out','waste','in')
             AND t.created_at >= datetime('now', '-{$days} days')) IS NOT NULL
    ORDER BY last_activity DESC
")->fetchAll(PDO::FETCH_ASSOC);

$missing = [];
$onList = [];
$suppressed = [];

foreach ($rows as $r) {
    $pid = (int)$r['id'];
    $generic = trim($r['shopping_name'] ?? '') ?: computeShoppingName($r['name'], '', $r['brand'] ?? '');
    $bringKey = mb_strtolower(italianToBring($generic));
    $shopKey = mb_strtolower($generic);

    $smart = $smartByPid[$pid] ?? $smartByName[mb_strtolower($generic)] ?? null;
    $onBring = isset($bringNames[$bringKey]);
    $onShop = isset($shopNames[$shopKey]);
    $inSmart = $smart !== null && ($smart['urgency'] ?? 'none') !== 'none';

    $entry = [
        'id' => $pid,
        'name' => $r['name'],
        'brand' => $r['brand'],
        'generic' => $generic,
        'last_activity' => $r['last_activity'],
        'last_out' => $r['last_out'],
        'use_count' => (int)$r['use_count'],
        'buy_count' => (int)$r['buy_count'],
        'on_bring' => $onBring,
        'on_shop' => $onShop,
        'in_smart' => $inSmart,
        'smart_urgency' => $smart['urgency'] ?? null,
        'smart_reasons' => $smart['reasons'] ?? [],
        'bring_spec' => $bringSpecs[$bringKey] ?? '',
    ];

    if (!$onBring && !$onShop && !$inSmart) {
        $missing[] = $entry;
    } elseif ($onBring || $onShop) {
        $onList[] = $entry;
    } elseif ($inSmart) {
        $suppressed[] = $entry; // in smart but not synced yet
    } else {
        $missing[] = $entry;
    }
}

echo "=== Audit prodotti esauriti (ultimi {$days} giorni) ===\n";
echo 'Totale esauriti con attività recente: ' . count($rows) . "\n";
echo 'Già in lista/Bring: ' . count($onList) . "\n";
echo 'In smart shopping ma non in lista: ' . count($suppressed) . "\n";
echo 'MANCANTI (né lista né Bring né smart): ' . count($missing) . "\n\n";

if ($missing) {
    echo "--- MANCANTI ---\n";
    foreach ($missing as $m) {
        echo sprintf(
            "- [%d] %s%s → generico: %s | usi:%d acquisti:%d | ultimo:%s\n",
            $m['id'],
            $m['name'],
            $m['brand'] ? " ({$m['brand']})" : '',
            $m['generic'],
            $m['use_count'],
            $m['buy_count'],
            $m['last_activity']
        );
    }
    echo "\n";
}

if ($suppressed) {
    echo "--- IN SMART MA NON IN LISTA/BRING ---\n";
    foreach ($suppressed as $m) {
        echo sprintf(
            "- [%d] %s → %s | urgenza:%s | %s\n",
            $m['id'],
            $m['name'],
            $m['generic'],
            $m['smart_urgency'] ?? '?',
            implode(', ', $m['smart_reasons'] ?? [])
        );
    }
}

// Export JSON for fix script
file_put_contents(
    __DIR__ . '/../data/audit_finished_missing.json',
    json_encode(['days' => $days, 'missing' => $missing, 'suppressed' => $suppressed], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
echo "\nReport salvato in data/audit_finished_missing.json\n";
