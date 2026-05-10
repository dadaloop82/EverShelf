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

    $itemCount = count($decoded['items'] ?? []);
    echo '[' . date('Y-m-d H:i:s') . '] OK — ' . $itemCount . " items cached\n";

    // ── Bring! server-side cleanup ────────────────────────────────────────
    // After computing smart shopping, automatically remove stale Bring! items
    // and add/update critical ones. This runs fully server-side every cron cycle.
    try {
        $cleanupResult = bringCleanupObsolete($db);
        if (isset($cleanupResult['skipped'])) {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! cleanup skipped: ' . $cleanupResult['skipped'] . "\n";
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! cleanup — removed: ' . ($cleanupResult['removed'] ?? 0)
                . '/' . ($cleanupResult['candidates'] ?? 0) . ' candidates'
                . ($cleanupResult['errors'] ? ', errors: ' . $cleanupResult['errors'] : '') . "\n";
        }

        $addResult = bringAutoAddCritical($db);
        if (isset($addResult['skipped'])) {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! auto-add skipped: ' . $addResult['skipped'] . "\n";
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! auto-add — added: ' . ($addResult['added'] ?? 0)
                . ', updated specs: ' . ($addResult['updated'] ?? 0) . "\n";
        }
    } catch (Throwable $be) {
        echo '[' . date('Y-m-d H:i:s') . '] Bring! sync warning: ' . $be->getMessage() . "\n";
    }

    // ── Shelf life pre-warming ────────────────────────────────────────────
    // Pre-warm the opened shelf life cache for opened items not yet cached.
    // Capped at 5 items per cron cycle to avoid Gemini rate limits.
    try {
        $prewarmResult = prewarmShelfLifeCache($db, 5);
        if ($prewarmResult['warmed'] > 0) {
            echo '[' . date('Y-m-d H:i:s') . '] Shelf life pre-warm — warmed: ' . $prewarmResult['warmed']
                . ', skipped: ' . $prewarmResult['skipped'] . "\n";
        }
    } catch (Throwable $pe) {
        echo '[' . date('Y-m-d H:i:s') . '] Shelf life pre-warm warning: ' . $pe->getMessage() . "\n";
    }

} catch (Throwable $e) {
    $msg = $e->getMessage();
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $msg . "\n";
    // Report to GitHub Issues (uses the same _phpErrorReport from index.php)
    _phpErrorReport($msg, $e->getFile(), $e->getLine(), $e->getTraceAsString(), get_class($e));
    exit(1);
}
