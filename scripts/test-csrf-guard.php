#!/usr/bin/env php
<?php
/**
 * Regression tests: the CSRF guard must cover every POST, not a hand-written list.
 * Run: php scripts/test-csrf-guard.php
 *
 * Before this fix the guard only inspected 25 actions out of 134, and accepted a
 * JSON content type as proof of good faith. Anything a cross-site form could not
 * do (send a custom header) was the only thing checked, and only for the actions
 * somebody remembered to list.
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

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

$exempt = evershelfCsrfExemptPostActions();

// ── The webapp's proof: the header is accepted everywhere ──────────────────
assert_true(evershelfCsrfGuardAllows('inventory_add', '1', ''), 'header is enough for a write action');
assert_true(evershelfCsrfGuardAllows('tts_proxy', '1', 'text/plain'), 'header is enough whatever the content type');
foreach ($exempt as $a) {
    assert_true(evershelfCsrfGuardAllows($a, '1', ''), "header accepted for exempt action {$a}");
}

// ── Actions that used to have no check at all are now guarded ──────────────
foreach (['chat_save', 'health_ingest', 'tts_proxy', 'generate_recipe_stream', 'inventory_list'] as $a) {
    if (in_array($a, $exempt, true)) {
        continue;
    }
    assert_true(!evershelfCsrfGuardAllows($a, '', 'application/json'), "{$a}: JSON content type alone is not proof");
    assert_true(!evershelfCsrfGuardAllows($a, '', 'text/plain'), "{$a}: <form enctype=text/plain> is rejected");
    assert_true(!evershelfCsrfGuardAllows($a, '', 'application/x-www-form-urlencoded'), "{$a}: urlencoded form is rejected");
    assert_true(!evershelfCsrfGuardAllows($a, '0', ''), "{$a}: a wrong header value is rejected");
}

// ── No action at all is not a loophole ─────────────────────────────────────
assert_true(!evershelfCsrfGuardAllows('', '', 'application/json'), 'a POST without an action still needs the header');

// ── Native clients keep the historical fallback, and only they ─────────────
foreach ($exempt as $a) {
    assert_true(evershelfCsrfGuardAllows($a, '', 'application/json; charset=utf-8'), "{$a}: JSON fallback kept for native clients");
    assert_true(!evershelfCsrfGuardAllows($a, '', 'text/plain'), "{$a}: JSON fallback is not a form fallback");
    assert_true(!evershelfCsrfGuardAllows($a, '', ''), "{$a}: no content type and no header is rejected");
}

// ── Wiring: the guard runs for every POST, before any dispatcher branch ────
$index = (string)file_get_contents(__DIR__ . '/../api/index.php');
assert_true(!str_contains($index, '$_writeActions'), 'the partial $_writeActions allowlist is gone');
assert_true(str_contains($index, 'evershelfCsrfGuardAllows('), 'index.php calls the shared CSRF decision');

$guardPos    = strpos($index, "evershelfCsrfGuardAllows(");
$pingPos     = strpos($index, "=== 'ping'");
$dispatchPos = strpos($index, '$db = getDB();');
assert_true($guardPos !== false && $pingPos !== false && $dispatchPos !== false, 'guard, ping branch and dispatcher all found');
assert_true($guardPos < $pingPos && $pingPos < $dispatchPos, 'guard runs before the early-exit branches and the dispatcher');

// ── Every exemption must name an action that exists (a typo would break a client silently) ──
foreach ($exempt as $a) {
    assert_true(str_contains($index, "'{$a}'"), "exempt action {$a} exists in index.php");
}

// ── The clients that POST without the header are exactly the exempt ones ───
$appJs = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
assert_true(str_contains($appJs, "'X-EverShelf-Request': '1'"), 'the webapp sends the CSRF header');
// chat_clear() is a POST with no body; the header must not depend on a body.
assert_true(str_contains($appJs, "} else if (method !== 'GET') {"), 'bodyless non-GET calls also send the CSRF header');
$mcp = (string)file_get_contents(__DIR__ . '/../mcp-server/src/evershelf-api.js');
assert_true(str_contains($mcp, "'X-EverShelf-Request': '1'"), 'the MCP server sends the CSRF header');

if ($fail > 0) {
    echo "\n{$fail} test(s) failed\n";
    exit(1);
}
echo "\nAll CSRF guard tests passed\n";
