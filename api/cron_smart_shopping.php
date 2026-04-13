<?php
/**
 * Cron: pre-compute smart shopping list and save to cache.
 * Install with:  crontab -e
 *   *\/5 * * * * php /var/www/html/evershelf/api/cron_smart_shopping.php >> /var/www/html/evershelf/data/cron.log 2>&1
 */

// Only allow CLI execution — block HTTP access
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// Define CRON_MODE before loading index.php so the router is skipped
define('CRON_MODE', true);

// Load all API functions without running the HTTP router
require_once __DIR__ . '/index.php';

const CACHE_FILE = __DIR__ . '/../data/smart_shopping_cache.json';

try {
    $db = getDB();

    // Capture the JSON output of smartShopping()
    ob_start();
    smartShopping($db);
    $json = ob_get_clean();

    $decoded = json_decode($json, true);
    if (!$decoded || !isset($decoded['success'])) {
        throw new RuntimeException('Invalid JSON from smartShopping(): ' . substr($json, 0, 200));
    }

    $decoded['cached_at'] = date('c');
    $decoded['cached_ts'] = time();

    if (file_put_contents(CACHE_FILE, json_encode($decoded, JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException('Cannot write cache file: ' . CACHE_FILE);
    }

    echo '[' . date('Y-m-d H:i:s') . '] OK — ' . count($decoded['items'] ?? []) . " items cached\n";
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
