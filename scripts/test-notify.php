#!/usr/bin/env php
<?php
/**
 * Regression tests: the outbound notifier must never leak a topic into a log or
 * send to an unconfigured channel, and its event list must stay aligned with the
 * events _fireHaWebhook() actually emits (ntfy / generic webhook, no paid service).
 * Run: php scripts/test-notify.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

function assert_same($expected, $actual, string $msg): void
{
    global $fail;
    if ($expected !== $actual) {
        echo 'FAIL: ' . $msg . ' (got ' . var_export($actual, true) . ', expected ' . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

// ── Channel parsing ─────────────────────────────────────────────────────────
assert_same(['ntfy', 'webhook'], evershelfNotifyParseChannels('ntfy, webhook ,NTFY,bogus,, '), 'channels: trims, dedupes, drops unknown');
assert_same([], evershelfNotifyParseChannels('ha'), 'channels: "ha" is not a NOTIFY_CHANNELS value (legacy gate)');
assert_same(['webhook'], evershelfNotifyParseChannels('  WEBHOOK  '), 'channels: case-insensitive');

// ── Event parsing / filtering ───────────────────────────────────────────────
assert_same(['expiry_alert', 'shopping_add', 'stock_update'], evershelfNotifyParseEvents('all'), 'events: "all" = every known event');
assert_same(['expiry_alert'], evershelfNotifyParseEvents('EXPIRY_ALERT, nope'), 'events: unknown dropped, case-insensitive');
assert_same([], evershelfNotifyParseEvents(''), 'events: empty list subscribes to nothing');
assert_true(evershelfNotifyEventEnabled('expiry_alert', ['expiry_alert']), 'event gate: listed event passes');
assert_true(!evershelfNotifyEventEnabled('stock_update', ['expiry_alert']), 'event gate: unlisted event is filtered out');

// The event names must be the ones _fireHaWebhook() emits, or automations and
// notifications silently drift apart (the A1 contract).
assert_same(['expiry_alert', 'shopping_add', 'stock_update'], EVERSHELF_NOTIFY_EVENTS_KNOWN, 'event list matches the _fireHaWebhook() events');
assert_same(['ntfy', 'webhook'], EVERSHELF_NOTIFY_CHANNELS_KNOWN, 'channel list is exactly ntfy + webhook');

// ── ntfy topic: the only credential, and a URL path segment ─────────────────
assert_true(evershelfNtfyTopicValid('evershelf-casa_2026'), 'topic: letters/digits/_- accepted');
assert_true(!evershelfNtfyTopicValid('evershelf casa'), 'topic: space rejected');
assert_true(!evershelfNtfyTopicValid('a/b'), 'topic: slash rejected (cannot escape the path)');
assert_true(!evershelfNtfyTopicValid(''), 'topic: empty rejected');
assert_true(!evershelfNtfyTopicValid(str_repeat('a', 65)), 'topic: longer than 64 chars rejected');
assert_true(!evershelfNtfyTopicValid('topic?x=1'), 'topic: query characters rejected');

// ── Priority: a typo must never break delivery ──────────────────────────────
assert_same('4', evershelfNotifyPriority('HIGH'), 'priority: name → numeric');
assert_same('5', evershelfNotifyPriority('urgent'), 'priority: urgent → 5');
assert_same('3', evershelfNotifyPriority('wat'), 'priority: unknown → default 3');
assert_same('2', evershelfNotifyPriority('2'), 'priority: numeric passthrough');

// ── Body clamp (ntfy caps a message at 4096 bytes) ──────────────────────────
$long    = str_repeat('è', 5000); // 2 bytes per char
$clamped = evershelfNotifyClampBody($long);
assert_true(strlen($clamped) <= EVERSHELF_NOTIFY_BODY_LIMIT, 'body: clamped under the ntfy limit');
assert_true(mb_check_encoding($clamped, 'UTF-8'), 'body: clamp cuts on a UTF-8 boundary');
assert_true(str_ends_with($clamped, '…'), 'body: truncation is visible');
assert_same('ciao', evershelfNotifyClampBody('ciao'), 'body: short message untouched');

// ── Header safety: a title must not be able to inject a header ──────────────
$safe = evershelfNotifyHeaderSafe("Scadenze\r\nX-Evil: 1");
assert_true(!str_contains($safe, "\r") && !str_contains($safe, "\n"), 'header: CR/LF stripped from a title');
assert_true(!str_contains($safe, "\x07"), 'header: control characters stripped');

// ── URL validation: only plain web targets ─────────────────────────────────
assert_true(evershelfNotifyUrlValid('https://ntfy.sh'), 'url: https accepted');
assert_true(evershelfNotifyUrlValid('http://192.168.1.50:8080'), 'url: self-hosted ntfy on the LAN accepted');
assert_true(!evershelfNotifyUrlValid('file:///etc/passwd'), 'url: file:// rejected');
assert_true(!evershelfNotifyUrlValid('gopher://evil'), 'url: gopher:// rejected');
assert_true(!evershelfNotifyUrlValid('ntfy.sh'), 'url: scheme-less rejected');
assert_true(!evershelfNotifyUrlValid(''), 'url: empty rejected');

// ── Message formatting ─────────────────────────────────────────────────────
[$title, $msg] = evershelfNotifyFormatEvent('expiry_alert', [
    'count'   => 2,
    'days'    => 3,
    'type'    => 'expiring_soon',
    'summary' => 'Latte, Yogurt',
], 'it');
assert_true($title !== '' && !str_starts_with($title, 'notify.'), 'expiry title resolves in the request language');
assert_true(str_contains($msg, '2') && str_contains($msg, 'Latte'), 'expiring message carries count + names');

[$t2, $m2] = evershelfNotifyFormatEvent('expiry_alert', [
    'count'   => 1,
    'type'    => 'expired',
    'summary' => 'Ricotta',
], 'it');
assert_true($t2 !== $title, 'expired and expiring_soon have different titles');
assert_true(str_contains($m2, 'Ricotta'), 'expired message carries the names');

[$t3, $m3] = evershelfNotifyFormatEvent('shopping_add', ['item' => 'Latte', 'specification' => '1 L'], 'en');
assert_same('Latte — 1 L', $m3, 'shopping_add message joins item and specification');
assert_true($t3 !== '' && !str_starts_with($t3, 'notify.'), 'shopping_add title resolves');

[, $m4] = evershelfNotifyFormatEvent('stock_update', ['item' => 'Farina', 'quantity' => 3.0], 'en');
assert_same('Farina: 3', $m4, 'stock_update message trims a trailing zero in the quantity');

// Unknown events still deliver something rather than a stub.
[$t5, $m5] = evershelfNotifyFormatEvent('something_new', ['a' => 1], 'en');
assert_true($t5 !== '' && str_contains($m5, 'something_new'), 'unknown event falls back to the raw payload');

// ── Configuration probe ────────────────────────────────────────────────────
$configured = evershelfNotifyConfigured();
assert_same(['ntfy', 'webhook', 'ha'], array_keys($configured), 'configured(): reports the three channel families');
foreach ($configured as $name => $flag) {
    assert_true(is_bool($flag), "configured(): {$name} is a boolean");
}

echo "\n" . ($fail === 0 ? "All notify tests passed.\n" : "{$fail} notify test(s) FAILED.\n");
exit($fail === 0 ? 0 : 1);
