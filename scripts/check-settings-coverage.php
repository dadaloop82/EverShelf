<?php
/**
 * Settings coverage guard — "is every setting the program has reachable from the UI?"
 *
 * A setting can be forgotten in two ways: the server honours it and no screen can
 * change it (the case this guard was written for: `RECIPE_RETENTION_DAYS`,
 * `TRANSACTION_RETENTION_DAYS`, `VACUUM_EXPIRY_EXTENSION_DAYS` were writable by
 * `save_settings()` and had no control at all), or it is deliberately shell-only and
 * nobody wrote down why.
 *
 * It reads the four maps of `saveSettings()` (keyMap / boolMap / intMap / floatMap),
 * the generated registry (`assets/js/settings.schema.generated.js`, one entry per
 * control with its `.env` key) and `api/lib/settings_env_allowlist.php` (the keys that
 * are env-only on purpose, with their bucket).
 *
 * A key is FINE when
 *   - a control carries it (`env` in the registry), or
 *   - the allowlist documents why it has none.
 * Anything else exits 1 with the key and the input name that writes it.
 *
 * Usage: php scripts/check-settings-coverage.php [--json]
 */

$root = dirname(__DIR__);
$json = in_array('--json', $argv ?? [], true);

$src  = (string)file_get_contents($root . '/api/index.php');
$from = strpos($src, 'function saveSettings(): void {');
if ($from === false) {
    fwrite(STDERR, "saveSettings() not found in api/index.php\n");
    exit(1);
}
$body = substr($src, $from, 20000);

// input key => ENV_KEY, from the four maps the function applies.
$writable = [];
foreach (['keyMap', 'boolMap', 'intMap', 'floatMap'] as $map) {
    if (!preg_match('/\$' . $map . ' = \[(.*?)\n    \];/s', $body, $m)) {
        continue;
    }
    preg_match_all("/'([a-z_0-9]+)'\s*=>\s*'([A-Z0-9_]+)'/", $m[1], $rows, PREG_SET_ORDER);
    foreach ($rows as $row) {
        $writable[$row[1]] = $row[2];
    }
}

$js = (string)file_get_contents($root . '/assets/js/settings.schema.generated.js');
$jsonStart = strpos($js, '{');
$schema = json_decode(substr($js, $jsonStart, strrpos($js, ';') - $jsonStart), true);
if (!is_array($schema)) {
    fwrite(STDERR, "settings.schema.generated.js is not readable — run php scripts/gen-settings-schema.php\n");
    exit(1);
}

$covered = [];
foreach ($schema['entries'] as $entry) {
    if (!empty($entry['env'])) {
        $covered[$entry['env']] = $entry['id'];
    }
}

require_once $root . '/api/lib/settings_env_allowlist.php';
$allow = evershelfSettingsEnvAllowlist();

$withControl = [];
$documented  = [];
$missing     = [];
foreach ($writable as $input => $envKey) {
    if (isset($covered[$envKey])) {
        $withControl[$envKey] = $covered[$envKey];
    } elseif (isset($allow[$envKey])) {
        $documented[$envKey] = $allow[$envKey];
    } else {
        $missing[$envKey] = $input;
    }
}

$report = [
    'writable'     => count($writable),
    'with_control' => count($withControl),
    'documented'   => count($documented),
    'missing'      => $missing,
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    printf(
        "settings coverage: %d writable keys | %d with a control | %d env-only (documented) | %d MISSING\n",
        $report['writable'], $report['with_control'], $report['documented'], count($missing)
    );
    foreach ($missing as $envKey => $input) {
        printf("  MISSING  %-30s (written as `%s` by save_settings)\n", $envKey, $input);
    }
    if (!$missing) {
        echo "every setting the server can write has a control or a documented reason\n";
    }
}

exit($missing ? 1 : 0);
