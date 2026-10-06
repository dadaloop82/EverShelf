<?php
/**
 * Cron: weekly pantry digest → ntfy / webhook (NOTIFY_EVENTS must include weekly_digest).
 * Example: 0 9 * * 1 php /var/www/html/dispensa/api/cron_weekly_digest.php
 */
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
    $expiring = (int)$db->query(
        "SELECT COUNT(DISTINCT i.product_id) FROM inventory i
         WHERE i.quantity > 0 AND i.expiry_date IS NOT NULL
           AND i.expiry_date >= date('now')
           AND i.expiry_date <= date('now', '+7 days')"
    )->fetchColumn();
    $expired = (int)$db->query(
        "SELECT COUNT(DISTINCT i.product_id) FROM inventory i
         WHERE i.quantity > 0 AND i.expiry_date IS NOT NULL AND i.expiry_date < date('now')"
    )->fetchColumn();
    $shop = (int)$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn();
    $critical = 0;
    $cacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
    if (is_readable($cacheFile)) {
        $cache = json_decode((string)file_get_contents($cacheFile), true);
        foreach ($cache['items'] ?? [] as $row) {
            if (($row['urgency'] ?? '') === 'critical') {
                $critical++;
            }
        }
    }
    _fireHaWebhook('weekly_digest', [
        'expiring_7d' => $expiring,
        'expired' => $expired,
        'shopping_list' => $shop,
        'smart_critical' => $critical,
        'summary' => "expiring={$expiring} expired={$expired} list={$shop} critical={$critical}",
    ]);
    echo '[' . date('Y-m-d H:i:s') . "] weekly_digest ok\n";
} catch (Throwable $e) {
    EverLog::error('cron_weekly_digest', ['error' => $e->getMessage()]);
    echo '[' . date('Y-m-d H:i:s') . '] ERROR ' . $e->getMessage() . "\n";
    exit(1);
}
