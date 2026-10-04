#!/usr/bin/env php
<?php
/**
 * Regression tests: ICS expiry feed (api/lib/calendar_ics.php).
 * Covers RFC 5545 escaping, line folding (incl. multi-byte safety), all-day date
 * maths and the shape of the generated calendar.
 * Run: php scripts/test-calendar-ics.php
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
    assert_true(
        $expected === $actual,
        $msg . " (got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . ')'
    );
}

// ── Escaping (RFC 5545 §3.3.11) ─────────────────────────────────────────────
assert_same(evershelfIcsEscape("Latte, 1L; bio\n2"), 'Latte\\, 1L\\; bio\\n2', 'escapes comma, semicolon and newline');
assert_same(evershelfIcsEscape('back\\slash'), 'back\\\\slash', 'escapes backslash first (no double-escaping)');
assert_same(evershelfIcsEscape('Fraîche, Käse; 中文'), 'Fraîche\\, Käse\\; 中文', 'leaves UTF-8 untouched');

// ── Folding (RFC 5545 §3.1) ────────────────────────────────────────────────
$folded = evershelfIcsFold('SUMMARY:' . str_repeat('a', 400));
$foldLines = explode("\r\n", $folded);
assert_true(count($foldLines) > 1, 'long line is folded');
assert_same('', implode('', array_filter($foldLines, static function ($l) { return strlen($l) > 75; })), 'no folded line exceeds 75 octets');
assert_true($foldLines[1][0] === ' ', 'continuation lines start with a single space');
assert_same('SUMMARY:' . str_repeat('a', 400), str_replace("\r\n ", '', $folded), 'unfolding restores the original value');
assert_same('SHORT:value', evershelfIcsFold('SHORT:value'), 'short lines are untouched');

$cjk = evershelfIcsFold('SUMMARY:' . str_repeat('è', 200));
assert_true(mb_check_encoding($cjk, 'UTF-8'), 'folding never splits a multi-byte character');
assert_true(strpos($cjk, "\xc3\xa8") !== false, 'multi-byte payload preserved');

// ── Dates ──────────────────────────────────────────────────────────────────
assert_same('20261010', evershelfIcsYmd('2026-10-10'), 'YYYY-MM-DD → YYYYMMDD');
assert_same('20261010', evershelfIcsYmd('2026-10-10 13:45:00'), 'datetime accepted, time dropped');
assert_true(evershelfIcsYmd('2026-13-40') === null, 'impossible date rejected');
assert_true(evershelfIcsYmd(null) === null, 'null expiry rejected');
assert_true(evershelfIcsYmd('') === null, 'empty expiry rejected');
assert_same('20261101', evershelfIcsNextDay('20261031'), 'DTEND rolls over month end');
assert_same('20260301', evershelfIcsNextDay('20260228'), 'DTEND rolls over in a non-leap year');
assert_same('20240229', evershelfIcsNextDay('20240228'), 'DTEND handles a leap year');

// ── Quantities ─────────────────────────────────────────────────────────────
assert_same('2', evershelfIcsFormatQty(2.0), 'whole quantity has no decimals');
assert_same('1.5', evershelfIcsFormatQty(1.5), 'fraction kept');
assert_same('0.05', evershelfIcsFormatQty(0.05), 'small quantity kept');
assert_same('0', evershelfIcsFormatQty(0.0), 'zero renders as 0');

// ── Whole feed, against the live database ──────────────────────────────────
// A port in HTTP_HOST must not land in the UID domain: RFC 5545 wants a
// domain-part, and '127.0.0.18088' is what a naive sanitizer produces.
$_SERVER['HTTP_HOST'] = 'pantry.example:8080';
$db = getDB();
$feed = evershelfIcsBuild($db, 'it');
$ics = $feed['ics'];
assert_same("BEGIN:VCALENDAR\r\n", substr($ics, 0, 17), 'feed starts with BEGIN:VCALENDAR');
assert_same("END:VCALENDAR\r\n", substr($ics, -15), 'feed ends with END:VCALENDAR');
assert_true(strpos($ics, "VERSION:2.0") !== false, 'declares VERSION:2.0');
assert_true(strpos($ics, 'X-WR-CALNAME:') !== false, 'declares a calendar name');
assert_true(strpos($ics, 'REFRESH-INTERVAL;VALUE=DURATION:PT6H') !== false, 'asks clients to re-poll every 6h');
assert_same(
    substr_count($ics, 'BEGIN:VEVENT'),
    substr_count($ics, 'END:VEVENT'),
    'VEVENT blocks are balanced'
);
assert_same(
    substr_count($ics, 'BEGIN:VEVENT'),
    substr_count($ics, 'BEGIN:VALARM'),
    'every event carries its reminder alarm'
);
assert_same($feed['count'], substr_count($ics, 'BEGIN:VEVENT'), 'reported count matches the events emitted');
assert_true(strpos($ics, "BEGIN:VCALENDAR\r\nVERSION") !== false, 'no stray output before the calendar');

$tooLong = 0;
foreach (explode("\r\n", $ics) as $line) {
    if (strlen($line) > 75) {
        $tooLong++;
    }
}
assert_same(0, $tooLong, 'no physical line in the generated feed exceeds 75 octets');

// Every emitted VEVENT must carry the mandatory all-day fields.
if ($feed['count'] > 0) {
    assert_true(strpos($ics, 'DTSTART;VALUE=DATE:') !== false, 'events use all-day DTSTART');
    assert_true(strpos($ics, 'DTEND;VALUE=DATE:') !== false, 'events use all-day DTEND');
    assert_true(strpos($ics, 'UID:evershelf-inv-') !== false, 'events have a stable UID');
    assert_true(strpos($ics, '@pantry.example') !== false, 'UID domain is the bare host');
    assert_true(strpos($ics, 'pantry.example:8080') === false, 'no port leaks into the payload');
}

// ── t() lookup used by the feed (PHP side of translations/*.json) ──────────
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $name = evershelfTr('ics.calendar.name', $loc);
    assert_true($name !== 'ics.calendar.name' && $name !== '', "ics.calendar.name resolved for {$loc}");
}
assert_true(evershelfTr('ics.event.quantity', 'it') === 'Quantità', 'italian label resolved');
assert_same('es.event.missing', evershelfTr('es.event.missing', 'it'), 'missing key falls back to the key itself');

echo "\n" . ($fail === 0 ? "ALL PASSED\n" : "{$fail} FAILED\n");
exit($fail === 0 ? 0 : 1);
