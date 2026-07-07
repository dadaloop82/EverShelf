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

// Define CRON_MODE before loading bootstrap so the HTTP router is skipped
define('CRON_MODE', true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/index.php';

const CACHE_FILE = __DIR__ . '/../data/smart_shopping_cache.json';

evershelfRotateCronLog();

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

    // ── Bring! server-side sync ───────────────────────────────────────────
    // Only when SHOPPING_MODE=bring. Internal list uses internalShoppingAutoAddCritical.
    try {
        if (isShoppingBringMode()) {
        $cleanupResult = bringCleanupObsolete($db);
        if (isset($cleanupResult['skipped'])) {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! cleanup skipped: ' . $cleanupResult['skipped'] . "\n";
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! cleanup — removed: ' . ($cleanupResult['removed'] ?? 0)
                . '/' . ($cleanupResult['candidates'] ?? 0) . ' candidates'
                . ($cleanupResult['errors'] ? ', errors: ' . $cleanupResult['errors'] : '') . "\n";
        }

        $dedupeResult = bringDedupeGenerics($db);
        if (isset($dedupeResult['skipped'])) {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! dedupe skipped: ' . $dedupeResult['skipped'] . "\n";
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! dedupe — removed: ' . ($dedupeResult['removed'] ?? 0)
                . ', merged specs: ' . ($dedupeResult['merged'] ?? 0) . "\n";
        }

        $specsResult = bringSyncSpecs($db);
        if (isset($specsResult['skipped'])) {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! specs skipped: ' . $specsResult['skipped'] . "\n";
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! specs — updated: ' . ($specsResult['updated'] ?? 0) . "\n";
        }

        $addResult = bringAutoAddCritical($db);
        if (isset($addResult['skipped'])) {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! auto-add skipped: ' . $addResult['skipped'] . "\n";
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! auto-add — added: ' . ($addResult['added'] ?? 0)
                . ', updated specs: ' . ($addResult['updated'] ?? 0) . "\n";
        }

        $dedupeFinal = bringDedupeGenerics($db);
        if (!isset($dedupeFinal['skipped']) && (($dedupeFinal['removed'] ?? 0) > 0)) {
            echo '[' . date('Y-m-d H:i:s') . '] Bring! dedupe (final) — removed: ' . ($dedupeFinal['removed'] ?? 0) . "\n";
        }
        } else {
            $internalAdd = internalShoppingAutoAddCritical($db);
            if (isset($internalAdd['skipped'])) {
                echo '[' . date('Y-m-d H:i:s') . '] Internal list auto-add skipped: ' . $internalAdd['skipped'] . "\n";
            } else {
                echo '[' . date('Y-m-d H:i:s') . '] Internal list auto-add — added: ' . ($internalAdd['added'] ?? 0)
                    . ', deduped: ' . ($internalAdd['deduped'] ?? 0) . "\n";
            }
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

    // ── Offline barcode catalog (weekly by default) ─────────────────────
    if (env('BARCODE_OFFLINE_ENABLED', 'true') === 'true') {
        try {
            $syncDays = max(1, min(90, (int)env('BARCODE_OFFLINE_SYNC_DAYS', '7')));
            $lastSync = $db->query("SELECT value FROM app_settings WHERE key = 'barcode_catalog_last_sync'")->fetchColumn();
            $ageDays = $lastSync ? (time() - strtotime((string)$lastSync)) / 86400 : 999;
            if ($ageDays >= $syncDays) {
                $bcResult = barcodeCatalogSync($db, 50);
                echo '[' . date('Y-m-d H:i:s') . '] Barcode catalog sync — refreshed: '
                    . ($bcResult['refreshed'] ?? 0) . '/' . ($bcResult['total'] ?? 0)
                    . ', failed: ' . ($bcResult['failed'] ?? 0) . "\n";
            }
        } catch (Throwable $be) {
            echo '[' . date('Y-m-d H:i:s') . '] Barcode catalog sync warning: ' . $be->getMessage() . "\n";
        }
    }

    // ── DB cleanup (retention policy) ────────────────────────────────────
    // Delete old recipes and transactions based on .env retention settings.
    try {
        ob_start();
        dbCleanup($db);
        ob_end_clean();
        echo '[' . date('Y-m-d H:i:s') . '] DB cleanup done'
            . ' (recipes >' . env('RECIPE_RETENTION_DAYS','7') . 'd'
            . ', tx >' . env('TRANSACTION_RETENTION_DAYS','90') . 'd' . ")\n";
    } catch (Throwable $ce) {
        echo '[' . date('Y-m-d H:i:s') . '] DB cleanup warning: ' . $ce->getMessage() . "\n";
    }

    // ── Daily incremental backup ──────────────────────────────────────────
    // Create a local backup at most once every 23 h; also push to Google Drive
    // if GDRIVE_ENABLED=true.  The guard prevents multiple backups per day even
    // though the cron runs every 5 minutes.
    if (env('BACKUP_ENABLED', 'true') === 'true') {
        try {
            $lastBackupTs = 0;
            if (file_exists(BACKUP_LAST_TS_PATH)) {
                $lastData     = json_decode(file_get_contents(BACKUP_LAST_TS_PATH), true) ?: [];
                $lastBackupTs = (int)($lastData['ts'] ?? 0);
            }
            if (time() - $lastBackupTs >= 82800) { // 23 h
                $backupResult = createLocalBackup($db);
                if ($backupResult['success']) {
                    echo '[' . date('Y-m-d H:i:s') . '] Backup local: ' . $backupResult['filename']
                        . ' (' . $backupResult['size_kb'] . 'KB, purged ' . $backupResult['purged'] . " old)\n";
                    if (env('GDRIVE_ENABLED', 'false') === 'true') {
                        $gResult = backupToGDrive($db);
                        if ($gResult['success']) {
                            echo '[' . date('Y-m-d H:i:s') . '] Backup GDrive: OK'
                                . ' (purged remote: ' . ($gResult['purged_remote'] ?? 0) . ")\n";
                        } else {
                            echo '[' . date('Y-m-d H:i:s') . '] Backup GDrive warning: ' . ($gResult['error'] ?? 'unknown') . "\n";
                        }
                    }
                } else {
                    echo '[' . date('Y-m-d H:i:s') . '] Backup warning: ' . ($backupResult['error'] ?? 'unknown') . "\n";
                }
            }
        } catch (Throwable $be) {
            echo '[' . date('Y-m-d H:i:s') . '] Backup error: ' . $be->getMessage() . "\n";
        }
    }

} catch (Throwable $e) {
    $msg = $e->getMessage();
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $msg . "\n";
    // Report to GitHub Issues (uses the same _phpErrorReport from index.php)
    _phpErrorReport($msg, $e->getFile(), $e->getLine(), $e->getTraceAsString(), get_class($e));
    exit(1);
}

// ── Home Assistant: expiry alerts ─────────────────────────────────────────────
// Fire one HA webhook per expiring item (once per day guard via a simple flag file).
if (env('HA_ENABLED', 'false') === 'true' && env('HA_WEBHOOK_ID', '') !== '') {
    try {
        $haFlagFile = __DIR__ . '/../data/ha_expiry_notified_' . date('Y-m-d') . '.json';
        if (!file_exists($haFlagFile)) {
            $expiryDays = max(1, (int)env('HA_EXPIRY_DAYS', '3'));
            $expiringItems = $db->query(
                "SELECT p.id AS product_id, i.id AS inventory_id,
                        p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit,
                        i.quantity, i.location, i.expiry_date, i.opened_at, i.vacuum_sealed
                 FROM inventory i JOIN products p ON i.product_id = p.id
                 WHERE i.quantity > 0 AND i.expiry_date IS NOT NULL
                   AND i.expiry_date BETWEEN date('now') AND date('now', '+{$expiryDays} days')
                 ORDER BY i.expiry_date ASC LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);

            $expiredItems = $db->query(
                "SELECT p.id AS product_id, i.id AS inventory_id,
                        p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit,
                        i.quantity, i.location, i.expiry_date, i.opened_at, i.vacuum_sealed
                 FROM inventory i JOIN products p ON i.product_id = p.id
                 WHERE i.quantity > 0 AND i.expiry_date IS NOT NULL
                   AND i.expiry_date < date('now')
                 ORDER BY i.expiry_date ASC LIMIT 10"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Normalise rows to full product format
            if (!function_exists('_haFormatProduct')) {
                function _haFormatProduct(array $row): array {
                    $daysRemaining = null;
                    if (!empty($row['expiry_date'])) {
                        $diff = (new DateTime(date('Y-m-d')))->diff(new DateTime($row['expiry_date']));
                        $daysRemaining = (int)$diff->format('%r%a');
                    }
                    return [
                        'product_id'       => (int)($row['product_id'] ?? 0),
                        'inventory_id'     => (int)($row['inventory_id'] ?? 0),
                        'name'             => $row['name'],
                        'brand'            => $row['brand'] ?? null,
                        'category'         => $row['category'] ?? null,
                        'quantity'         => (float)($row['quantity'] ?? 0),
                        'unit'             => $row['unit'] ?? '',
                        'default_quantity' => (float)($row['default_quantity'] ?? 0),
                        'package_unit'     => $row['package_unit'] ?? null,
                        'location'         => $row['location'] ?? null,
                        'expiry_date'      => $row['expiry_date'] ?? null,
                        'days_remaining'   => $daysRemaining,
                        'opened_at'        => $row['opened_at'] ?? null,
                        'vacuum_sealed'    => !empty($row['vacuum_sealed']),
                    ];
                }
            }
            $expiringItems = array_map('_haFormatProduct', $expiringItems);
            $expiredItems  = array_map('_haFormatProduct', $expiredItems);

            if (!empty($expiringItems)) {
                $names = implode(', ', array_column($expiringItems, 'name'));
                _fireHaWebhook('expiry_alert', [
                    'count'    => count($expiringItems),
                    'items'    => $expiringItems,
                    'type'     => 'expiring_soon',
                    'days'     => $expiryDays,
                    'summary'  => $names,
                ]);
                // Also send HA notification if service configured
                if (env('HA_NOTIFY_SERVICE', '') !== '') {
                    $msg = count($expiringItems) . ' product(s) expiring within ' . $expiryDays . ' days: ' . $names;
                    _sendHaNotify($msg, ['expiring_items' => $expiringItems]);
                }
                echo '[' . date('Y-m-d H:i:s') . '] HA expiry_alert fired: ' . count($expiringItems) . " items\n";
            }

            if (!empty($expiredItems)) {
                $expNames = implode(', ', array_column($expiredItems, 'name'));
                _fireHaWebhook('expiry_alert', [
                    'count'   => count($expiredItems),
                    'items'   => $expiredItems,
                    'type'    => 'expired',
                    'summary' => $expNames,
                ]);
                echo '[' . date('Y-m-d H:i:s') . '] HA expired fired: ' . count($expiredItems) . " items\n";
            }

            // Mark as done for today
            file_put_contents($haFlagFile, json_encode(['ts' => time(), 'expiring' => count($expiringItems ?? []), 'expired' => count($expiredItems ?? [])]));
            // Clean up old flag files (keep last 7 days)
            foreach (glob(__DIR__ . '/../data/ha_expiry_notified_*.json') as $oldFlag) {
                $flagDate = str_replace([__DIR__ . '/../data/ha_expiry_notified_', '.json'], '', $oldFlag);
                if ($flagDate < date('Y-m-d', strtotime('-7 days'))) @unlink($oldFlag);
            }
        }
    } catch (Throwable $haE) {
        echo '[' . date('Y-m-d H:i:s') . '] HA expiry hook warning: ' . $haE->getMessage() . "\n";
    }
}

// ── Avahi/mDNS discovery registration ─────────────────────────────────────────
// If avahi-daemon is running on this host, register the _evershelf._tcp service
// so that Home Assistant can auto-discover this instance via Zeroconf.
if (function_exists('shell_exec')) {
    try {
        $avahiService = '/etc/avahi/services/evershelf.xml';
        // Only create/update if avahi-daemon is installed and the file doesn't exist yet
        if (!file_exists($avahiService) && (shell_exec('which avahi-daemon 2>/dev/null') || shell_exec('which avahi-publish 2>/dev/null'))) {
            $template = __DIR__ . '/../docker/avahi-evershelf.xml';
            if (file_exists($template)) {
                $xml = file_get_contents($template);
                @file_put_contents($avahiService, $xml);
                echo '[' . date('Y-m-d H:i:s') . '] Avahi mDNS service registered at ' . $avahiService . "\n";
            }
        }
    } catch (Throwable $avahiE) {
        // Non-fatal: avahi not available
    }
}
