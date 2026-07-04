#!/usr/bin/env php
<?php
/**
 * Cron: refresh offline barcode catalog from free databases (OFF, OPF, UPCitemdb, …).
 * Schedule weekly (or daily for small catalogs):
 *   0 3 * * 0 php /var/www/html/dispensa/api/cron_barcode_catalog.php >> /var/www/html/dispensa/data/cron.log 2>&1
 *
 * Also invoked from cron_smart_shopping.php when BARCODE_OFFLINE_SYNC_DAYS elapsed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('CRON_MODE', true);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/index.php';

evershelfRotateCronLog();

try {
    $db = getDB();
    $days = barcodeOfflineSyncDays();
    $last = $db->query("SELECT value FROM app_settings WHERE key = 'barcode_catalog_last_sync'")->fetchColumn();
    $force = in_array('--force', $argv, true);

    if (!$force && $last) {
        $ageDays = (time() - strtotime((string)$last)) / 86400;
        if ($ageDays < $days) {
            echo '[' . date('Y-m-d H:i:s') . "] barcode catalog sync skipped (last sync {$ageDays}d ago, interval {$days}d)\n";
            exit(0);
        }
    }

    $limit = null;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $limit = (int)substr($arg, 8);
        }
    }

    $result = barcodeCatalogSync($db, $limit);
    echo '[' . date('Y-m-d H:i:s') . '] barcode catalog sync — refreshed: '
        . ($result['refreshed'] ?? 0) . '/' . ($result['total'] ?? 0)
        . ', failed: ' . ($result['failed'] ?? 0) . "\n";
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] barcode catalog sync ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
