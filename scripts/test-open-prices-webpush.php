<?php
/**
 * Guard: Open Prices helpers + Web Push VAPID mint stay usable without composer.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/lib/env.php';
require_once $root . '/api/logger.php';
require_once $root . '/api/lib/open_prices.php';
require_once $root . '/api/lib/webpush.php';

$failed = 0;
function assert_true(bool $cond, string $msg): void {
    global $failed;
    if ($cond) {
        echo "OK: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failed++;
    }
}

assert_true(evershelfPriceSource() === 'auto' || in_array(evershelfPriceSource(), ['ai', 'open_prices', 'auto'], true), 'price source is a known value');
assert_true(evershelfPriceEnabledDecided() === (env('PRICE_ENABLED', '') !== ''), 'price decided flag matches env presence');

$pair = evershelfWebPushGenerateVapidKeys();
assert_true(isset($pair['public'], $pair['private_pem']), 'VAPID pair has public + PEM');
assert_true(strlen($pair['public']) > 40, 'VAPID public key looks non-empty');
assert_true(str_contains($pair['private_pem'], 'BEGIN'), 'VAPID private is PEM');

// Sign a dummy JWT audience with the generated material (no network).
$auth = evershelfWebPushVapidAuthorization(
    'https://fcm.googleapis.com/fcm/send/test',
    $pair['public'],
    $pair['private_pem']
);
assert_true(is_string($auth) && str_starts_with($auth, 'vapid t='), 'VAPID Authorization header can be built');

$inbox = sys_get_temp_dir() . '/evershelf_webpush_inbox_test.json';
// Inbox helpers use a fixed path under data/; just ensure functions exist.
assert_true(function_exists('evershelfWebPushInboxPush'), 'inbox push helper exists');
assert_true(function_exists('evershelfOpenPricesLookup'), 'Open Prices lookup helper exists');

if ($failed > 0) {
    fwrite(STDERR, "$failed assertion(s) failed\n");
    exit(1);
}
echo "All open-prices / webpush guards passed.\n";
