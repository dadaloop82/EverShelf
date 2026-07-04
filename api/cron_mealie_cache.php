<?php
/**
 * Sync Mealie recipe catalog to local offline cache.
 * Cron: 0 4 * * 0 php /var/www/html/dispensa/api/cron_mealie_cache.php
 */
define('CRON_MODE', true);
require __DIR__ . '/bootstrap.php';

if (!mealieConfigured()) {
    echo json_encode(['success' => false, 'error' => 'mealie_not_configured']);
    exit(0);
}

$result = mealieSyncCache(false);
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
