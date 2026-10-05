#!/usr/bin/env php
<?php
/**
 * Regression tests: the cron watchdog (healthchecks.io / Uptime Kuma) must build
 * the right request for both URL styles, must never fall back to a URL that was
 * set but is invalid, and must stay wired to the three CLI jobs.
 * Run: php scripts/test-healthcheck.php
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

// ── URL precedence: per-job override, then shared, never a bad value ─────────
assert_same('https://hc-ping.com/perjob', evershelfHealthcheckResolveUrl('https://hc-ping.com/perjob', 'https://hc-ping.com/shared'), 'resolve: per-job URL wins over the shared one');
assert_same('https://hc-ping.com/shared', evershelfHealthcheckResolveUrl('', 'https://hc-ping.com/shared'), 'resolve: shared URL is the fallback');
assert_same('https://hc-ping.com/shared', evershelfHealthcheckResolveUrl('   ', 'https://hc-ping.com/shared'), 'resolve: whitespace means "not set"');
assert_same('', evershelfHealthcheckResolveUrl('', ''), 'resolve: nothing configured = no ping (no-op)');
assert_same('', evershelfHealthcheckResolveUrl('ftp://hc-ping.com/uuid', 'https://hc-ping.com/shared'), 'resolve: an invalid per-job URL does not silently fall back');
assert_same('', evershelfHealthcheckResolveUrl('', 'file:///etc/passwd'), 'resolve: a non-http(s) shared URL is ignored');
assert_same('https://hc-ping.com/x?a=1', evershelfHealthcheckResolveUrl('  https://hc-ping.com/x?a=1  ', ''), 'resolve: a valid URL is trimmed and kept (query preserved)');

// The per-job env key name is part of the documented interface (.env.example).
assert_same('NOTIFY_HEALTHCHECK_URL_SMART_SHOPPING', evershelfHealthcheckEnvKey('smart_shopping'), 'env key: job name upper-cased');
assert_same('NOTIFY_HEALTHCHECK_URL_BARCODE_CATALOG', evershelfHealthcheckEnvKey('barcode-catalog'), 'env key: dash normalised to underscore');

// ── State normalisation: an unknown state must never break a run ─────────────
assert_same('ok', evershelfHealthcheckState('OK'), 'state: case-insensitive');
assert_same('fail', evershelfHealthcheckState('fail'), 'state: fail kept');
assert_same('start', evershelfHealthcheckState(' start '), 'state: start trimmed');
assert_same('ok', evershelfHealthcheckState('wat'), 'state: unknown falls back to ok');

// ── Healthchecks.io style (no query): state is a path suffix, log is the body ─
$hc  = 'https://hc-ping.com/1f0e2d3c-aaaa-bbbb-cccc-ddddeeeeffff';
$req = evershelfHealthcheckRequest($hc, 'ok', 'completed');
assert_same($hc, $req['url'], 'hc: a success ping keeps the URL (no suffix)');
assert_same('POST', $req['method'], 'hc: success is a POST');
assert_same('completed', $req['body'], 'hc: the detail becomes the log body');

$req = evershelfHealthcheckRequest($hc, 'fail', 'boom');
assert_same($hc . '/fail', $req['url'], 'hc: failure appends /fail');
assert_same('boom', $req['body'], 'hc: the failure body carries the reason');

$req = evershelfHealthcheckRequest($hc, 'start');
assert_same($hc . '/start', $req['url'], 'hc: start appends /start');
assert_same('', $req['body'], 'hc: start sends no body');

$req = evershelfHealthcheckRequest($hc . '/', 'fail', '');
assert_same($hc . '/fail', $req['url'], 'hc: a trailing slash is not doubled');
assert_same('', $req['body'], 'hc: an empty detail means an empty body (not "0")');

// ── Uptime Kuma push style (query present): status travels as parameters ─────
$kuma = 'https://kuma.example/api/push/AbCdEf123?foo=1';
$req  = evershelfHealthcheckRequest($kuma, 'ok', '');
assert_same($kuma . '&status=up&msg=ok', $req['url'], 'kuma: success appends status=up&msg=ok');
assert_same('GET', $req['method'], 'kuma: push URLs are called with GET');
assert_same('', $req['body'], 'kuma: GET carries no body (the message is a parameter)');

$req = evershelfHealthcheckRequest($kuma, 'fail', 'sync_failed');
assert_same($kuma . '&status=down&msg=fail%3A%20sync_failed', $req['url'], 'kuma: failure = status=down with a url-encoded message');
assert_same('GET', $req['method'], 'kuma: a failure is still a GET');

$req = evershelfHealthcheckRequest($kuma, 'start', '');
assert_same($kuma . '&status=up&msg=start', $req['url'], 'kuma: start is reported as up (it is not a failure)');

// A detail coming from an exception must not be able to inject a parameter.
$req = evershelfHealthcheckRequest($kuma, 'fail', "boom &status=up\ninjected");
assert_true(!str_contains($req['url'], '&status=up&status=up'), 'kuma: an ampersand in the detail is url-encoded');
assert_true(!str_contains($req['url'], "\n"), 'kuma: CR/LF stripped from the detail');

$clamped = evershelfHealthcheckRequest($hc, 'fail', str_repeat('x', 500));
assert_true(strlen($clamped['body']) <= 300, 'detail: clamped to stay a useful log line');

// ── Transport: an invalid URL never reaches curl, and never leaks the URL ────
$bad = evershelfHealthcheckSend('file:///etc/passwd', 'ok');
assert_true($bad['ok'] === false, 'send: a non-http(s) URL is refused');
assert_same('invalid_url', $bad['error'], 'send: refused with a machine key');

if (function_exists('curl_init')) {
    // Loopback port 1 is closed: fast refusal, and the URL (the credential) must
    // not come back inside the error string.
    $secret = 'http://127.0.0.1:1/super-secret-uuid';
    $res    = evershelfHealthcheckSend($secret, 'ok');
    assert_true($res['ok'] === false, 'send: an unreachable URL reports a failure (never throws)');
    assert_true(!str_contains($res['error'], 'super-secret-uuid'), 'send: the URL is never echoed back in the error');
}

// ── The status file: recorded outcomes, hand-edited files, unknown jobs ──────
$path    = CRON_HEALTH_PATH;
$hadFile = file_exists($path);
$backup  = $hadFile ? file_get_contents($path) : null;

evershelfHealthcheckRecord('smart_shopping', ['state' => 'ok', 'ok' => true, 'sent' => true, 'http' => 200]);
evershelfHealthcheckRecord('mealie_cache', ['state' => 'fail', 'ok' => true, 'sent' => true, 'http' => 200]);
$status = evershelfHealthcheckStatus();
assert_true(isset($status['smart_shopping'], $status['mealie_cache']), 'status: every recorded job is reported');
assert_true($status['smart_shopping']['ok'] === true, 'status: a job that ended well is reported as ok');
// A job failure whose alert was delivered fine must still read as a failure: the
// panel reports the job, not the delivery.
assert_true($status['mealie_cache']['ok'] === false, 'status: a failed job stays failed even when the ping was delivered');
assert_same('fail', $status['mealie_cache']['state'], 'status: the failure is not masked by a successful delivery');
assert_true($status['mealie_cache']['delivered'] === true, 'status: the delivery is reported separately from the outcome');
assert_true($status['smart_shopping']['ts'] > 0, 'status: a timestamp is recorded');
assert_true(!isset($status['barcode_catalog']), 'status: a job that never ran is absent (the panel says "never")');

// The reverse case: the job is fine but the watchdog could not be reached.
evershelfHealthcheckRecord('barcode_catalog', ['state' => 'ok', 'ok' => false, 'sent' => true, 'http' => 0]);
$status = evershelfHealthcheckStatus();
assert_true($status['barcode_catalog']['ok'] === true, 'status: an undelivered ping does not fail a healthy job');
assert_true($status['barcode_catalog']['delivered'] === false, 'status: an undelivered ping is flagged as not delivered');

evershelfHealthcheckRecord('not_a_job', ['state' => 'ok', 'ok' => true]);
assert_true(!str_contains((string)file_get_contents($path), 'not_a_job'), 'status: an unknown job is never written');

file_put_contents($path, '{"smart_shopping": "not-an-object", "barcode_catalog": {"ts": 123, "ok": true}}');
$status = evershelfHealthcheckStatus();
assert_true(!isset($status['smart_shopping']), 'status: a hand-edited scalar entry is skipped, not fatal');
assert_same(123, $status['barcode_catalog']['ts'] ?? 0, 'status: a valid hand-edited entry is still read');

file_put_contents($path, 'not json at all');
assert_same([], evershelfHealthcheckStatus(), 'status: a corrupt file degrades to "no data"');

// Restore whatever the instance had before the test.
if ($hadFile && $backup !== null) {
    file_put_contents($path, $backup);
} else {
    @unlink($path);
}

// ── Wiring contract: the jobs the watchdog knows are the jobs that ping ──────
$cronFiles = [
    'smart_shopping'  => __DIR__ . '/../api/cron_smart_shopping.php',
    'barcode_catalog' => __DIR__ . '/../api/cron_barcode_catalog.php',
    'mealie_cache'    => __DIR__ . '/../api/cron_mealie_cache.php',
];
assert_same(array_keys($cronFiles), EVERSHELF_HEALTHCHECK_JOBS, 'contract: one watched job per CLI cron script');
foreach ($cronFiles as $job => $file) {
    assert_true(is_file($file), "contract: {$job} cron script exists");
    $src = (string)file_get_contents($file);
    assert_true(str_contains($src, "evershelfHealthcheckPing('{$job}', 'start')"), "contract: {$job} pings start at the beginning");
    if ($job === 'mealie_cache') {
        // It exits before the ping when Mealie is not configured, so success and
        // failure are the two branches of a ternary on the sync result instead of
        // an unconditional ping plus a catch block.
        assert_true(str_contains($src, "'mealie_cache', \$ok ? 'ok' : 'fail'"), 'contract: mealie_cache reports the sync result');
    } else {
        assert_true(str_contains($src, "evershelfHealthcheckPing('{$job}', 'ok'"), "contract: {$job} pings ok on success");
        assert_true(str_contains($src, "evershelfHealthcheckPing('{$job}', 'fail'"), "contract: {$job} pings fail on error");
    }
}

// A configured instance reports "configured" as a boolean, never the URL itself.
$settings = json_encode([
    'notify_healthcheck_set'    => evershelfHealthcheckConfigured(),
    'notify_healthcheck_jobs'   => EVERSHELF_HEALTHCHECK_JOBS,
    'notify_healthcheck_status' => evershelfHealthcheckStatus(),
]);
assert_true(!str_contains($settings, 'hc-ping.com'), 'api: the ping URL is never part of the settings payload');

// ── HTTP contract: the action must read the JSON body the panel posts ────────
// `api()` in assets/js/app.js always sends `Content-Type: application/json`, so an
// action that reads only `$_POST` silently ignores the field: testing a freshly
// typed URL pinged the *stored* URL instead and reported *its* success, and a
// mistyped paste looked like a working configuration. Lock both ends together.
$actionSrc = (string)file_get_contents(__DIR__ . '/../api/index.php');
$appJsSrc  = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$fnStart   = strpos($actionSrc, 'function notifyHealthcheckTestAction');
assert_true($fnStart !== false, 'contract: notifyHealthcheckTestAction exists');
$fnBody = $fnStart === false ? '' : substr($actionSrc, $fnStart, 1500);
assert_true(str_contains($fnBody, "\$_POST['notify_healthcheck_url']"), 'contract: the action accepts the classic form field');
assert_true(str_contains($fnBody, "file_get_contents('php://input')"), 'contract: the action reads the JSON body the panel posts');
assert_true(str_contains($fnBody, 'json_decode'), 'contract: the JSON body is parsed before use');
assert_true(str_contains($appJsSrc, 'api(\'notify_healthcheck_test\''), 'contract: the panel calls notify_healthcheck_test');
assert_true(str_contains($appJsSrc, '{ notify_healthcheck_url: typed }'), 'contract: the panel posts notify_healthcheck_url');
assert_true(str_contains($actionSrc, "'notify_healthcheck_url' => 'NOTIFY_HEALTHCHECK_URL'"), 'contract: save_settings persists that same key');
assert_true(str_contains($appJsSrc, 'payload.notify_healthcheck_url = hcUrl'), 'contract: the panel saves that same key');

echo $fail === 0 ? "\nAll healthcheck tests passed.\n" : "\n{$fail} test(s) FAILED.\n";
exit($fail === 0 ? 0 : 1);
