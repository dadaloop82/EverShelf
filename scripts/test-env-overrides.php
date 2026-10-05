#!/usr/bin/env php
<?php
/**
 * Regression tests: a setting stored in the DB fallback must be read back within
 * the same request.
 *
 * Run: php scripts/test-env-overrides.php
 *
 * Issue #261: on an install whose .env is not writable (Docker, a .env owned by
 * root), save_settings stores the value in app_settings.env_overrides and answers
 * "success: true, stored: database" — and then the app kept showing the old value,
 * because `env()` ignored it from then on. Reason: bootstrap.php calls
 * env('DISPLAY_ERRORS') *before* api/database.php is loaded, so on every HTTP
 * request the first `loadEnvOverrides()` ran while getDB() did not exist yet and
 * cached the empty answer for the rest of the request. Custom storage units,
 * dietary preferences and every other fallback setting were affected — they looked
 * "lost" and could not be re-added.
 *
 * The child process below reproduces the bootstrap order faithfully, because the
 * bug is a static cache and cannot be observed twice in one process.
 */
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

const TEST_ENV_KEY = 'EVERSHELF_TEST_OVERRIDE';

// Parent: a normal bootstrap, so the DB exists and the fallback can be exercised.
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$api = realpath(__DIR__ . '/../api');
$src = (string)file_get_contents($api . '/lib/env.php');

// ── Structural guard: the "database not loaded yet" answer must not be cached ──
assert_true(
    !preg_match('/function loadEnvOverrides.*?\$cache = \[\];\s*if \(!function_exists\(.getDB.\)\)/s', $src),
    'the DB-unavailable answer is not stored in the static cache'
);
assert_true(
    (bool)preg_match('/if \(!function_exists\(.getDB.\)\) \{.*?return \[\];/s', $src),
    'loadEnvOverrides() answers "no overrides" while the DB is unavailable'
);

// ── The fallback itself still round-trips in a normal request ─────────────────
assert_true(saveEnvOverrides([TEST_ENV_KEY => 'from-db']), 'the DB fallback accepts a value');
assert_true(env(TEST_ENV_KEY) === 'from-db', 'env() reads the value written to the DB fallback');

// ── A fresh request that starts like bootstrap.php must see it too ────────────
$key   = TEST_ENV_KEY;
$child = sys_get_temp_dir() . '/evershelf-env-override-child-' . getmypid() . '.php';
file_put_contents($child, <<<PHP
<?php
// Same order as api/bootstrap.php: env() is used before api/database.php loads.
require '{$api}/lib/env.php';
\$early = env('{$key}', 'missing');
require '{$api}/lib/constants.php';
require '{$api}/logger.php';
require '{$api}/database.php';
\$late = env('{$key}', 'missing');
echo json_encode(['early' => \$early, 'late' => \$late]);
PHP);
$out = shell_exec('php ' . escapeshellarg($child) . ' 2>&1');
@unlink($child);
$seen = json_decode((string)$out, true);
assert_true(is_array($seen), 'the child process ran and answered — got: ' . trim((string)$out));
assert_true(($seen['early'] ?? null) === 'missing', 'before the DB is loaded there is nothing to read');
assert_true(
    ($seen['late'] ?? null) === 'from-db',
    'after the DB is loaded the same request reads the saved override (issue #261)'
);

// ── Cleanup: the test key must not linger in the app's overrides ──────────────
clearEnvOverrides([TEST_ENV_KEY]);
assert_true(env(TEST_ENV_KEY) === '', 'the test key is removed again');
assert_true(!array_key_exists(TEST_ENV_KEY, loadEnvOverrides()), 'no test key is left in the DB fallback');

if ($fail > 0) {
    echo "\n{$fail} test(s) failed\n";
    exit(1);
}
echo "\nAll env override tests passed\n";
