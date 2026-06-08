<?php
/**
 * Full Bring! sync: recompute smart shopping, migrate names, dedupe generics,
 * fix specs, remove obsolete items, add missing critical/high.
 *
 * Usage: php scripts/sync-shopping-bring.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$db = getDB();

echo '[' . date('Y-m-d H:i:s') . "] Starting full Bring! sync…\n";

ob_start();
bringSyncFull($db, true);
$json = ob_get_clean();
$result = json_decode($json, true);

if (!$result || empty($result['success'])) {
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . ($result['error'] ?? $json) . "\n";
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . '] Smart items: ' . ($result['smart_items'] ?? '?') . "\n";
echo '[' . date('Y-m-d H:i:s') . '] Migrate: ' . json_encode($result['migrate'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo '[' . date('Y-m-d H:i:s') . '] Dedupe: ' . json_encode($result['dedupe'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo '[' . date('Y-m-d H:i:s') . '] Specs: ' . json_encode($result['specs'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo '[' . date('Y-m-d H:i:s') . '] Cleanup: ' . json_encode($result['cleanup'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo '[' . date('Y-m-d H:i:s') . '] Auto-add: ' . json_encode($result['auto_add'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
if (!empty($result['dedupe_final'])) {
    echo '[' . date('Y-m-d H:i:s') . '] Dedupe (final): ' . json_encode($result['dedupe_final'], JSON_UNESCAPED_UNICODE) . "\n";
}
if (!empty($result['cache_restored'])) {
    echo '[' . date('Y-m-d H:i:s') . '] Cache restored: ' . $result['cache_restored'] . " items\n";
}
echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
