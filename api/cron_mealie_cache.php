<?php
/**
 * Sync Mealie recipe catalog to local offline cache.
 * Cron: 0 4 * * 0 php /var/www/html/dispensa/api/cron_mealie_cache.php
 */
define('CRON_MODE', true);
require __DIR__ . '/bootstrap.php';

if (!mealieConfigured()) {
    // Not configured = nothing to watch: no ping at all, so the user never has to
    // create a watchdog check for an integration they do not use.
    echo json_encode(['success' => false, 'error' => 'mealie_not_configured']);
    exit(0);
}

// Dead man's switch: mark the run as started (no-op without a ping URL).
evershelfHealthcheckPing('mealie_cache', 'start');

$result = mealieSyncCache(false);
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

// A "skipped" sync (cache still fresh) is a successful run for the watchdog; a
// real failure is reported as such so the alert arrives immediately.
$ok     = !empty($result['success']);
$detail = $ok
    ? (!empty($result['skipped']) ? 'skipped: cache still fresh' : 'recipes ' . ($result['count'] ?? 0))
    : (string)($result['error'] ?? 'sync_failed');
evershelfHealthcheckPing('mealie_cache', $ok ? 'ok' : 'fail', $detail);
