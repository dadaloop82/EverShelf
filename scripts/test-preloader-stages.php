#!/usr/bin/env php
<?php
/**
 * Regression tests: the splash boot rail must stay in sync with the boot.
 *
 * The preloader draws one icon per startup stage — grey while the stage is
 * pending, blinking while its health checks run, then coloured with an aura in
 * its own accent (amber / red when it did not pass) — and prints the version
 * loud enough to be read out on a bug report.
 *
 * Four lists have to agree or the splash lies to the user:
 *   index.html                 the drawn rail (`data-stage` + its i18n label)
 *   _PRELOADER_STAGE_KEYS      stage → label key, used at runtime
 *   _PRELOADER_STAGE_OF_CHECK  every health_check key owned by a stage
 *   translations/*.json        all six locales carry every label
 * A check nobody owns would leave its icon blinking forever; a stage without a
 * locale string would print its raw `startup.stage_*` key instead.
 *
 * Run: php scripts/test-preloader-stages.php
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

$root = __DIR__ . '/..';
$html = (string)file_get_contents($root . '/index.html');
$js   = (string)file_get_contents($root . '/assets/js/app.js');
$css  = (string)file_get_contents($root . '/assets/css/style.css');

/** Body of a top-level `const NAME = { … };` object literal. */
function js_object_body(string $source, string $name): ?string
{
    $re = '/const\s+' . preg_quote($name, '/') . '\s*=\s*\{(.*?)\n\};/s';
    return preg_match($re, $source, $m) ? $m[1] : null;
}

/**
 * Body of a top-level `function name(…) { … }` (declaration line included),
 * found by matching braces; strings and comments are skipped so the braces they
 * contain cannot unbalance the count (same matcher as the other JS guard tests).
 */
function js_function_body(string $source, string $name): ?string
{
    $re = '/^\s*(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\(/m';
    if (!preg_match($re, $source, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1];
    $len   = strlen($source);
    $i     = strpos($source, '{', $start);
    if ($i === false) {
        return null;
    }
    $depth = 0;
    for (; $i < $len; $i++) {
        $c = $source[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            for ($i++; $i < $len; $i++) {
                if ($source[$i] === '\\') { $i++; continue; }
                if ($source[$i] === $c) { break; }
            }
            continue;
        }
        if ($c === '/' && ($source[$i + 1] ?? '') === '/') {
            while ($i < $len && $source[$i] !== "\n") { $i++; }
            continue;
        }
        if ($c === '/' && ($source[$i + 1] ?? '') === '*') {
            $close = strpos($source, '*/', $i);
            if ($close === false) {
                return null;
            }
            $i = $close + 1;
            continue;
        }
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

// ── [1] the rail drawn in index.html ────────────────────────────────────────
$rail  = [];
$rowRe = '/<li class="preloader-stage ([a-z-]+)" data-stage="([a-z_]+)" style="([^"]*)">\s*'
    . '<span class="preloader-stage-icon">([^<]+)<\/span>\s*'
    . '<span class="preloader-stage-label" data-i18n="([a-z0-9_.]+)">/';
preg_match_all($rowRe, $html, $rows, PREG_SET_ORDER);
foreach ($rows as $r) {
    $rail[] = ['state' => $r[1], 'stage' => $r[2], 'style' => $r[3], 'icon' => $r[4], 'key' => $r[5]];
}

assert_true(count($rail) === 7, 'the splash rail draws all 7 startup stages (found ' . count($rail) . ')');
assert_true(count(array_column($rail, 'stage')) === count(array_unique(array_column($rail, 'stage'))),
    'no stage is drawn twice on the rail');

$active  = array_values(array_filter($rail, fn($r) => $r['state'] === 'is-active'));
$pending = array_values(array_filter($rail, fn($r) => $r['state'] === 'is-pending'));
assert_true(count($active) === 1 && $active[0]['stage'] === 'connect',
    'the rail opens on the connection stage, blinking');
assert_true(count($pending) === count($rail) - 1, 'every other stage starts grey (is-pending)');
foreach ($rail as $r) {
    assert_true(str_contains($r['style'], '--stage-accent:') && str_contains($r['style'], '--stage-glow:'),
        "stage {$r['stage']} defines its accent colour and its glow");
}

// ── [2] drawn rail ↔ _PRELOADER_STAGE_KEYS (same order, same labels) ────────
$keysBody = js_object_body($js, '_PRELOADER_STAGE_KEYS');
assert_true($keysBody !== null, 'app.js declares _PRELOADER_STAGE_KEYS');
$mapped = [];
if ($keysBody !== null && preg_match_all('/([a-z_]+):\s*\'([a-z0-9_.]+)\'/', $keysBody, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) {
        $mapped[$m[1]] = $m[2];
    }
}
assert_true(array_column($rail, 'stage') === array_keys($mapped),
    'the rail follows the order of _PRELOADER_STAGE_KEYS (' . implode(' → ', array_keys($mapped)) . ')');
foreach ($rail as $r) {
    assert_true(($mapped[$r['stage']] ?? null) === $r['key'],
        "stage {$r['stage']} is labelled by {$r['key']} in markup and in app.js");
}
$badNs = array_values(array_filter(array_values($mapped), fn($k) => !str_starts_with($k, 'startup.stage_')));
assert_true($badNs === [], 'every stage label key lives in startup.stage_*: ' . implode(', ', $badNs));

// ── [3] health_check keys ↔ stage ownership (nothing left blinking) ─────────
$checkBody = js_function_body($js, '_runStartupCheck') ?? '';
assert_true($checkBody !== '', 'app.js still has _runStartupCheck()');
preg_match_all('/\{\s*key:\s*\'([a-z0-9_]+)\'/', $checkBody, $cm);
$checks = $cm[1];
assert_true(count($checks) > 25, 'the startup check list is read from _runStartupCheck() (' . count($checks) . ' keys)');

$owners    = [];
$stageBody = js_object_body($js, '_PRELOADER_STAGE_OF_CHECK');
assert_true($stageBody !== null, 'app.js declares _PRELOADER_STAGE_OF_CHECK');
if ($stageBody !== null) {
    preg_match_all('/([a-z_]+):\s*\[(.*?)\]/s', $stageBody, $om, PREG_SET_ORDER);
    foreach ($om as $o) {
        preg_match_all('/\'([a-z0-9_]+)\'/', $o[2], $ks);
        foreach ($ks[1] as $k) {
            assert_true(!isset($owners[$k]), "check {$k} belongs to exactly one stage");
            $owners[$k] = $o[1];
        }
    }
}
$unowned  = array_values(array_diff($checks, array_keys($owners)));
$orphaned = array_values(array_diff(array_keys($owners), $checks));
assert_true($unowned === [], 'every health check is owned by a rail stage: ' . implode(', ', $unowned));
assert_true($orphaned === [], 'no stage owns a check the splash never runs: ' . implode(', ', $orphaned));
assert_true($owners !== [] && array_values(array_unique($owners)) === ['php', 'files', 'db', 'config', 'network'],
    'the checks are grouped php → files → db → config → network, in rail order');


// ── [4] runtime wiring: grey → blink → colour, and a settle at the end ─────
assert_true(str_contains($checkBody, 'settleStage()'), '_runStartupCheck() settles a stage as soon as the next one starts');
assert_true(str_contains($checkBody, "_preloaderStage(stage, 'active')"), 'each stage blinks while its checks run');
assert_true(str_contains($checkBody, "_preloaderStage('connect', 'active')"), 'the connection stage is lit before the fetch');
assert_true(str_contains($checkBody, "_preloaderStage('connect', 'done')"), 'the connection stage settles once the payload is in');
assert_true(substr_count($checkBody, "_preloaderStage('connect', 'warn')") === 2
        && str_contains($checkBody, "_preloaderStage('connect', 'error')"),
    'a token/pairing block or an unreachable server turns the connection icon amber or red');

$initBody = js_function_body($js, '_initApp') ?? '';
assert_true(str_contains($initBody, "_preloaderStage('ui', 'active')"), 'the last stage blinks while the dashboard is prepared');
assert_true(str_contains($initBody, "_preloaderStage('ui', 'done')"), 'the last stage lights up just before the splash fades out');

$stageFn = js_function_body($js, '_preloaderStage') ?? '';
assert_true(str_contains($stageFn, 'is-pending') && str_contains($stageFn, "'is-' + state"),
    '_preloaderStage() replaces the state class instead of stacking them');
assert_true(str_contains($stageFn, 'textContent'), 'the stage caption is written as plain text (never innerHTML)');

// ── [5] CSS: grey → blink → coloured + aura, warnings never look green ─────
assert_true(preg_match('/\.preloader-stage-icon \{.*?filter: grayscale\(1\)/s', $css) === 1,
    'a pending icon is desaturated (grey)');
assert_true(preg_match('/\.preloader-stage\.is-active \.preloader-stage-icon \{.*?animation: stageBlink/s', $css) === 1,
    'an active icon blinks');
assert_true(str_contains($css, '@keyframes stageBlink {'), 'the blink keyframes exist');
assert_true(preg_match('/\.preloader-stage\.is-done \.preloader-stage-icon,\s*\.preloader-stage\.is-warn \.preloader-stage-icon,\s*\.preloader-stage\.is-error \.preloader-stage-icon \{.*?grayscale\(0\).*?var\(--stage-accent\)/s', $css) === 1,
    'a settled icon regains its colour and gains an aura in its accent');
assert_true(str_contains($css, '@keyframes stagePop {'), 'a settled icon pops in');
assert_true(str_contains($css, '.preloader-stage.is-warn  { --stage-accent: #fbbf24;')
        && str_contains($css, '.preloader-stage.is-error { --stage-accent: #f87171;'),
    'warn/error override the accent so a failed stage cannot look green');
assert_true(preg_match('/\.preloader-stage\.is-done:not\(:last-child\)::after \{.*?var\(--stage-glow\)/s', $css) === 1,
    'the connector behind a settled stage lights up too');
assert_true(preg_match('/@media \(prefers-reduced-motion: reduce\) \{(?:(?!\n\}).)*\.preloader-stage\.is-active/s', $css) === 1,
    'the rail respects prefers-reduced-motion');
assert_true(str_contains($css, '.preloader-stage-caption') && str_contains($html, 'id="preloader-stage-caption"'),
    'the running stage is named under the rail (the only label on a phone)');

// ── [6] the version is loud, and bump-version.sh still finds it ────────────
assert_true(preg_match('/id="preloader-version">v\d+\.\d+\.\d+</', $html) === 1,
    'the splash chip holds a semver, in the shape bump-version.sh rewrites');
assert_true(str_contains($html, 'data-i18n="startup.version"'), 'the version chip label is translated');
assert_true(preg_match('/app-preloader-verchip-value \{.*?font-size: 1\.05rem/s', $css) === 1,
    'the version chip is bigger than the 0.68rem footnote it replaced');
$bump = (string)file_get_contents($root . '/scripts/bump-version.sh');
// The script escapes the quote (`preloader-version\">v<semver>`) inside its sed
// expression: both sides must keep targeting the same marker.
assert_true(preg_match('/s\/\(preloader-version\\\\">v\)\[0-9\]\+/', $bump) === 1,
    'bump-version.sh and the markup still agree on the splash badge');

// ── [7] every label exists in all six locales ───────────────────────────────
$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];
$needed  = array_values($mapped);
$needed[] = 'startup.version';
foreach ($locales as $loc) {
    $data = json_decode((string)file_get_contents($root . '/translations/' . $loc . '.json'), true);
    assert_true(is_array($data), $loc . '.json is valid JSON');
    $missing = [];
    $values  = [];
    foreach ($needed as $key) {
        $value = is_array($data) ? ($data['startup'][substr($key, strlen('startup.'))] ?? '') : '';
        if (!is_string($value) || trim($value) === '') {
            $missing[] = $key;
        } else {
            $values[] = $value;
        }
    }
    assert_true($missing === [], $loc . ' carries every splash label: ' . implode(', ', $missing));
    assert_true(!preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', implode(' ', $values)),
        $loc . ' splash labels carry no emoji (the icon is drawn beside them)');
}

if ($fail === 0) {
    echo "\nAll preloader-stage tests passed.\n";
}
exit($fail === 0 ? 0 : 1);

