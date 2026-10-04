#!/usr/bin/env php
<?php
/**
 * Regression tests: bug reports must never publish a credential, and must stay
 * bounded whatever a client sends.
 *
 * Run: php scripts/test-report-redaction.php
 *
 * Before this fix _createOrCommentGithubIssue() published the client-supplied
 * `location.href` and an unlimited `context` object verbatim to a public
 * repository, and the documented auth accepts `?api_token=…` in the URL — so one
 * PWA error raised while the app was loaded on such a URL would have opened a
 * public issue containing the token. `report_error` is a public action, so the
 * context was also the largest request body allowed by post_max_size.
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

/** Body of a top-level function, used for wiring assertions. */
function source_of(string $file, string $fn): string
{
    $src = (string)file_get_contents($file);
    if (!preg_match('/^function ' . preg_quote($fn, '/') . '\s*\(.*?\n\}/sm', $src, $m)) {
        return '';
    }
    return $m[0];
}

$indexFile = __DIR__ . '/../api/index.php';
$index     = (string)file_get_contents($indexFile);

// ── The leak the audit found: ?api_token=… in the reported URL ─────────────
$url = 'http://10.0.0.5:8080/index.html?api_token=REDACTME123&tab=inventory';
$out = evershelfRedactSecrets($url);
assert_true(!str_contains($out, 'REDACTME123'), 'api_token in a query string is redacted');
assert_true(str_contains($out, 'api_token=[REDACTED]'), 'the key name survives, the value does not');
assert_true(str_contains($out, 'tab=inventory'), 'unrelated query parameters are kept for debugging');

// ── The other shapes a secret arrives in ───────────────────────────────────
$cases = [
    '{"api_token": "REDACTME123", "step": "sync"}' => 'JSON body field',
    '"password": "REDACTME123"'                    => 'JSON password field',
    "api_key='REDACTME123'"                        => 'quoted form field',
    'health_token: REDACTME123'                    => 'colon-separated token',
    'Authorization: Bearer REDACTME123'            => 'Authorization header',
    'X-Health-Token: REDACTME123'                  => 'X-Health-Token header',
    'fetch("https://user:REDACTME123@host/api")'   => 'credentials inside a URL',
    'GH_ISSUE_TOKEN=REDACTME123'                   => 'env-style assignment',
];
foreach ($cases as $input => $label) {
    $out = evershelfRedactSecrets($input);
    assert_true(!str_contains($out, 'REDACTME123'), "{$label}: secret value is gone");
}

// ── Tokens with a recognisable shape, wherever they appear ─────────────────
$shapes = [
    'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ01'          => 'GitHub personal token',
    'github_pat_ABCDEFGHIJKLMNOPQRSTUVWX'       => 'GitHub fine-grained token',
    'AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ012345678' => 'Google API key',
    'GOCSPX-ABCDEFGHIJKLM'                      => 'Google OAuth secret',
    'sk-ABCDEFGHIJKLMNOPQRSTUVWX'               => 'OpenAI key',
    'xoxb-123456789012-abcdefghijkl'            => 'Slack token',
];
foreach ($shapes as $token => $label) {
    $out = evershelfRedactSecrets("failed while calling {$token} from cron");
    assert_true(!str_contains($out, $token), "{$label} is redacted by shape");
}

// ── No false positives: readable debug output stays readable ───────────────
$keep = [
    'TypeError: monkey=1 is not a function',
    'SQLITE_ERROR: no such column: quantity (file=/var/www/html/dispensa/data/evershelf.db)',
    'GET api/index.php?action=get_client_log&limit=50 returned 0 rows',
    'recipe "Spaghetti alla carbonara" not found',
];
foreach ($keep as $text) {
    assert_true(evershelfRedactSecrets($text) === $text, 'left untouched: ' . substr($text, 0, 40));
}

// ── Idempotent: the gate runs more than once on the same payload ───────────
$once  = evershelfRedactSecrets($url);
$twice = evershelfRedactSecrets($once);
assert_true($once === $twice, 'redaction is idempotent');

// ── Context: secrets nested anywhere are redacted ──────────────────────────
$json = evershelfReportContextJson([
    'endpoint' => 'api/index.php?api_token=REDACTME123',
    'nested'   => ['headers' => ['Authorization: Bearer REDACTME123']],
]);
assert_true(!str_contains($json, 'REDACTME123'), 'nested context values are redacted');
assert_true(is_array(json_decode($json, true)), 'context JSON stays valid JSON');

// ── Context: capped at 4 KB however big the client payload was ─────────────
$huge = ['blob' => str_repeat('A', 200000), 'tail' => 'end'];
$json = evershelfReportContextJson($huge);
assert_true(strlen($json) <= 4096, 'a 200 KB context is capped to 4 KB (got ' . strlen($json) . ' bytes)');
assert_true(is_array(json_decode($json, true)), 'the capped context is still valid JSON');

$many = [];
for ($i = 0; $i < 60; $i++) {
    $many['k' . $i] = str_repeat('x', 500);
}
$json = evershelfReportContextJson($many);
assert_true(strlen($json) <= 4096, '60 populated keys are capped to 4 KB');
assert_true(str_contains($json, '_truncated'), 'the cap is visible to whoever reads the issue');

$deep = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => 'secret-value']]]]]];
assert_true(str_contains(evershelfReportContextJson($deep), '[nested too deep]'), 'deep nesting is flattened');

// ── Context: hostile input must not break json_encode() ────────────────────
$binary = evershelfReportContextJson(['bytes' => "\xC3\x28 broken \xFF\xFE utf8"]);
assert_true(json_decode($binary, true) !== null || $binary === '', 'invalid UTF-8 does not break the JSON');
assert_true(evershelfReportContextJson([]) === '', 'an empty context produces no block at all');

// ── Truncation never leaves a dangling multi-byte sequence ─────────────────
$cut = evershelfTruncateUtf8('caffè latte per la colazione', 8);
assert_true(json_encode(['v' => $cut]) !== false, 'truncation keeps the string JSON-encodable');
assert_true(strlen($cut) <= 8, 'truncation respects the byte budget');

// ── Publishing is an explicit opt-in (REPORT_ENABLED), default off ─────────
assert_true(_ghReportsEnabled('true'), 'REPORT_ENABLED=true enables publishing');
assert_true(_ghReportsEnabled('1'), 'REPORT_ENABLED=1 enables publishing');
assert_true(_ghReportsEnabled('ON'), 'the flag is case-insensitive');
assert_true(!_ghReportsEnabled('false'), 'REPORT_ENABLED=false disables publishing');
assert_true(!_ghReportsEnabled(''), 'an unset flag disables publishing');
assert_true(!_ghReportsEnabled('0'), 'REPORT_ENABLED=0 disables publishing');
assert_true(
    str_contains((string)file_get_contents(__DIR__ . '/../api/lib/github.php'), "env('REPORT_ENABLED', 'false')"),
    'the code default is off, not on'
);
assert_true(
    str_contains((string)file_get_contents(__DIR__ . '/../.env.example'), 'REPORT_ENABLED='),
    '.env.example documents the switch'
);

// ── Wiring: every outbound sink redacts, and the gates are in place ────────
$gate = source_of($indexFile, '_createOrCommentGithubIssue');
assert_true(str_contains($gate, 'evershelfRedactSecrets($message)'), 'issue body: message is redacted');
assert_true(str_contains($gate, 'evershelfRedactSecrets($stack)'), 'issue body: stack trace is redacted');
assert_true(str_contains($gate, 'evershelfReportContextJson($context'), 'issue body: context is redacted and capped');
assert_true(!str_contains($gate, 'json_encode($context, JSON_PRETTY_PRINT'), 'the raw context dump is gone');

$log = source_of($indexFile, '_appendErrorLog');
assert_true(str_contains($log, 'evershelfRedactSecrets($message)'), 'local log: message is redacted');
assert_true(str_contains($log, 'evershelfReportContextJson($context'), 'local log: context is redacted and capped');

$client = source_of($indexFile, 'clientLog');
assert_true(str_contains($client, 'evershelfRedactSecrets'), 'the public client-log sink redacts its lines too');
assert_true(str_contains($client, 'evershelfTruncateUtf8'), 'and bounds each line instead of trusting the client');

assert_true(str_contains($index, "'skipped' => 'reporting_disabled'"), 'report_error stops before publishing when disabled');
assert_true(str_contains($index, '!$token || !_ghReportsEnabled()'), 'report_bug is gated the same way');
assert_true(str_contains($index, '_ghReportsEnabled() && _isLatestVersion('), 'the PHP crash reporter is gated too');
assert_true(
    str_contains($index, "is_array(\$input['context'] ?? null) ? \$input['context'] : []"),
    'a non-array context from a public action cannot reach the helpers'
);

echo $fail === 0 ? "\nAll report redaction tests passed\n" : "\n{$fail} test(s) failed\n";
exit($fail === 0 ? 0 : 1);
