#!/usr/bin/env php
<?php
/**
 * Regression tests: the dashboard insight rotation must actually draw its charts.
 *
 * Regression history: the rotation reveals one panel per minute and fills its bars
 * (they are rendered at 0% with the target in `data-target`, so the CSS transition
 * animates them in). A refactor that added three more panels deleted the local
 * `showNutr` / `showMonthly` / `showMacros` / `showSpend` constants but left the
 * `if (showNutr) { … }` branches behind. Nothing declared them, so the
 * requestAnimationFrame() callback threw `ReferenceError: showNutr is not defined`
 * on every rotation tick: no bar was ever filled and every chart on the home page
 * (macros, nutrition score, monthly categories, the spend comparison) stayed empty,
 * while the numbers around them looked fine.
 *
 * Run: php scripts/test-dashboard-panels.php
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

$root  = __DIR__ . '/..';
$lines = explode("\n", (string)file_get_contents($root . '/assets/js/app.js'));
// Same line numbers, comments removed: the guard looks at code, not at prose.
$code = [];
foreach ($lines as $i => $line) {
    $code[$i + 1] = js_strip_comments($line);
}
$jsCode = implode("\n", $code);

/** Drop comments so prose never triggers the guards (same rule as the other JS tests). */
function js_strip_comments(string $line): string
{
    if (preg_match('~^\s*//~', $line)) {
        return '';
    }
    $stripped = preg_replace('~\s//[^"\']*$~', '', $line);
    return $stripped ?? $line;
}

/**
 * Body of a top-level `function name(…)` in app.js (declaration line included),
 * found by matching braces. Strings, templates and comments are skipped so the
 * braces they contain cannot unbalance the count.
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
            if ($close === false) { return null; }
            $i = $close + 1;
            continue;
        }
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return trim(substr($source, $start, $i - $start + 1));
            }
        }
    }
    return null;
}


/**
 * Identifiers declared anywhere in the source: `const|let|var name`, destructuring
 * patterns, function/arrow parameters and catch bindings.
 */
function js_declared_names(string $source): array
{
    $names = [];
    $add = function (string $chunk) use (&$names): void {
        // Defaults and rest elements hold no binding of their own; taking every
        // identifier is a harmless superset.
        $chunk = preg_replace('/=[^,]*/', '', $chunk) ?? $chunk;
        if (preg_match_all('/[A-Za-z_$][\w$]*/', $chunk, $m)) {
            foreach ($m[0] as $n) {
                $names[$n] = true;
            }
        }
    };
    // Declarations, including the continuations of a list: `let a = 0, b = null;`
    // (a name that is never assigned anywhere is exactly the crash class we guard).
    if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)/', $source, $m)) {
        foreach ($m[1] as $n) {
            $names[$n] = true;
        }
    }
    if (preg_match_all('/(?<![\w$.])([A-Za-z_$][\w$]*)\s*=(?!=|>)/', $source, $m)) {
        foreach ($m[1] as $n) {
            $names[$n] = true;
        }
    }
    if (preg_match_all('/\b(?:const|let|var)\s*[\[{]([^\]}]*)[\]}]\s*=/', $source, $m)) {
        foreach ($m[1] as $chunk) {
            $add($chunk);
        }
    }
    return $names;
}

/** Function and arrow parameters, plus catch bindings: more names to trust. */
function js_extra_declared(string $source): array
{
    $names = [];
    $add = function (string $chunk) use (&$names): void {
        $chunk = preg_replace('/=[^,]*/', '', $chunk) ?? $chunk;
        if (preg_match_all('/[A-Za-z_$][\w$]*/', $chunk, $m)) {
            foreach ($m[0] as $n) {
                $names[$n] = true;
            }
        }
    };
    if (preg_match_all('/\bfunction\b[^(]*\(([^)]*)\)/', $source, $m)) {
        foreach ($m[1] as $params) {
            $add($params);
        }
    }
    if (preg_match_all('/\(([^()]*)\)\s*=>/', $source, $m)) {
        foreach ($m[1] as $params) {
            $add($params);
        }
    }
    if (preg_match_all('/(?<![\w$.])([A-Za-z_$][\w$]*)\s*=>/', $source, $m)) {
        foreach ($m[1] as $n) {
            $names[$n] = true;
        }
    }
    if (preg_match_all('/\bcatch\s*\(\s*([A-Za-z_$][\w$]*)/', $source, $m)) {
        foreach ($m[1] as $n) {
            $names[$n] = true;
        }
    }
    return $names;
}



/** Browser/runtime globals that may legitimately stand alone in a guard. */
function js_browser_globals(): array
{
    return [
        'window', 'document', 'navigator', 'location', 'history', 'screen', 'self', 'globalThis',
        'localStorage', 'sessionStorage', 'indexedDB', 'caches', 'performance', 'console', 'crypto',
        'fetch', 'Request', 'Response', 'Headers', 'AbortController', 'AbortSignal', 'URL',
        'URLSearchParams', 'Blob', 'File', 'FileReader', 'FormData', 'TextEncoder', 'TextDecoder',
        'DOMParser', 'Image', 'Audio', 'Notification', 'Worker', 'XMLHttpRequest', 'EventSource',
        'BroadcastChannel', 'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval',
        'requestAnimationFrame', 'cancelAnimationFrame', 'queueMicrotask', 'structuredClone',
        'matchMedia', 'getComputedStyle', 'alert', 'confirm', 'prompt', 'parseInt', 'parseFloat',
        'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent', 'Object', 'Array', 'JSON',
        'Math', 'Date', 'Number', 'String', 'Boolean', 'RegExp', 'Error', 'TypeError', 'RangeError',
        'Map', 'Set', 'WeakMap', 'WeakSet', 'Symbol', 'Promise', 'Proxy', 'Reflect', 'BigInt', 'Intl',
        'HTMLElement', 'Event', 'CustomEvent', 'MutationObserver', 'IntersectionObserver',
        'ResizeObserver', 'typeof', 'new', 'this', 'true', 'false', 'null', 'undefined', 'NaN',
        'Infinity', 'void', 'delete',
    ];
}



/**
 * Bare identifiers used as a guard (`if (name)`, `if (name && …)`, `while (name)`)
 * that the source never declares. $code is [1-based line => comment-stripped line].
 */
function js_undeclared_guards(array $code, array $declared): array
{
    $globals = js_browser_globals();
    $problems = [];
    foreach ($code as $line => $text) {
        if ($text === '') {
            continue;
        }
        $re = '/\b(?:if|while)\s*\(\s*!?\s*([A-Za-z_$][\w$]*)\s*(?:&&|\|\||\)|,)/';
        if (!preg_match_all($re, $text, $m, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($m as $hit) {
            $name = $hit[1];
            if (isset($declared[$name]) || in_array($name, $globals, true)) {
                continue;
            }
            $problems[] = $line . ': ' . $name;
        }
    }
    return $problems;
}

/** Numbered lines of a snippet, so js_undeclared_guards() can report positions. */
function js_number_lines(string $source): array
{
    $out = [];
    foreach (explode("\n", $source) as $i => $line) {
        $out[$i + 1] = $line;
    }
    return $out;
}

$declared = js_declared_names($jsCode) + js_extra_declared($jsCode);

// ── [1] no guard in app.js may reference an undeclared identifier ──
// This is the crash class behind the empty charts: a ReferenceError thrown inside
// requestAnimationFrame() is invisible in the UI (only window.onerror sees it), so
// the panel keeps its "0%" bars and reads as an empty chart or a zero total.
$free = js_undeclared_guards($code, $declared);
assert_true($free === [],
    'every identifier used as a guard in app.js is declared'
    . ($free ? ' (undeclared: ' . implode(' | ', array_slice($free, 0, 8)) . ')' : ''));

// ── [2] the function that was broken stays broken-proof ──
$apply = js_function_body($jsCode, '_applyInsightPhase');
$apply = $apply === null ? '' : js_strip_comments($apply);
assert_true($apply !== '', '_applyInsightPhase() exists');
// Scope-precise: a flag declared in *another* function (the screensaver has its own
// `showNutr`) must not make an orphan reference inside this one look legitimate.
$applyLocal  = js_declared_names($apply) + js_extra_declared($apply);
$applyFree   = $apply === '' ? [] : js_undeclared_guards(js_number_lines($apply), $applyLocal);
assert_true($applyFree === [],
    '_applyInsightPhase() only guards identifiers it declares itself'
    . ($applyFree ? ' (' . implode(' | ', $applyFree) . ')' : ''));
foreach (['showNutr', 'showMonthly', 'showMacros', 'showSpend'] as $gone) {
    assert_true($apply !== '' && !preg_match('/\b' . $gone . '\b/', $apply),
        '_applyInsightPhase() no longer references the deleted ' . $gone . ' flag');
}


// ── [3] every rotation phase is wired: section, reveal branch, renderer ──
$html   = (string)file_get_contents($root . '/index.html');
$phases = [
    'overview'  => ['overview-section',      '_renderOverviewSection'],
    'waste'     => ['waste-chart-section',   '_renderAntiWasteSection'],
    'trend'     => ['trend-section',         '_renderTrendSection'],
    'nutrition' => ['nutrition-section',     '_renderNutritionSection'],
    'freshness' => ['freshness-section',     '_renderFreshnessSection'],
    'monthly'   => ['monthly-stats-section', '_renderMonthlyStatsSection'],
    'spend'     => ['spend-section',         '_renderSpendSection'],
    'macros'    => ['macros-section',        '_renderMacrosSection'],
];
$listed = [];
if (preg_match('/const _INSIGHT_PHASES = \[([^\]]*)\]/', $jsCode, $pm)) {
    preg_match_all("/'([a-z]+)'/", $pm[1], $lm);
    $listed = $lm[1];
}
assert_true($listed === array_keys($phases),
    '_INSIGHT_PHASES matches the documented rotation (' . implode(', ', $listed) . ')');

foreach ($phases as $phase => [$id, $renderer]) {
    assert_true(str_contains($html, 'id="' . $id . '"'),
        'phase ' . $phase . ' has its #' . $id . ' in index.html');
    assert_true($apply !== '' && str_contains($apply, "phase === '" . $phase . "'"),
        'phase ' . $phase . ' has a reveal branch in _applyInsightPhase()');
    assert_true(js_function_body($jsCode, $renderer) !== null,
        'phase ' . $phase . ' has its renderer ' . $renderer . '()');
}


// ── [4] bars keep both halves of the contract: data-target out, fill on reveal ──
// [class, animation axis, function that emits it, panel that owns it]
$bars = [
    'nutr-score-fill' => ['width',  '_nutrScoreBar',             '_renderNutritionSection'],
    'ms-cat-bar'      => ['width',  '_renderMonthlyStatsSection','_renderMonthlyStatsSection'],
    'macro-bar-fill'  => ['width',  '_renderMacrosSection',      '_renderMacrosSection'],
    'spend-bar-fill'  => ['height', '_renderSpendSection',       '_renderSpendSection'],
];
foreach ($bars as $cls => [$axis, $emitter, $panel]) {
    $body  = (string)js_function_body($jsCode, $emitter);
    $owner = (string)js_function_body($jsCode, $panel);
    $emits = '/class="' . preg_quote($cls, '/') . '"[^>]*data-target=/';
    assert_true(preg_match($emits, $body) === 1,
        $emitter . '() emits .' . $cls . ' with a data-target');
    assert_true($emitter === $panel || str_contains($owner, $emitter . '('),
        $panel . '() reaches ' . $emitter . '()');
    $fills = '/\.' . preg_quote($cls, '/') . "'\)[\s\S]*?style\." . $axis
        . ' = \(bar\.dataset\.target/';
    assert_true(preg_match($fills, $apply) === 1,
        '_applyInsightPhase() fills .' . $cls . ' from data-target');
}

// ── [5] every bar is animated, from CSS or from the inline transition ──
$css = (string)file_get_contents($root . '/assets/css/style.css');
foreach (array_keys($bars) as $cls) {
    $rule   = '/' . preg_quote($cls, '/') . '\s*\{[^}]*transition:[^;]*(?:width|height|all)/';
    $inline = '/\.' . preg_quote($cls, '/') . "'\)[\s\S]*?style\.transition = 'width/";
    assert_true(preg_match($rule, $css) === 1 || preg_match($inline, $apply) === 1,
        '.' . $cls . ' animates its fill (CSS transition or inline)');
}



// ── [6] the spend panel keeps being fed by the API payload, not by a stub ──
$spend = (string)js_function_body($jsCode, '_renderSpendSection');
assert_true(str_contains($spend, 'data.totals') && str_contains($spend, '_renderSpendEmpty'),
    '_renderSpendSection() renders the API totals and explains an empty payload');
assert_true(str_contains($jsCode, "api('spend_stats')"),
    'the dashboard asks spend_stats for the spend rollup');
$api = (string)file_get_contents($root . '/api/index.php');
assert_true(str_contains($api, 'function getSpendStats'), 'api/index.php exposes getSpendStats()');
assert_true(str_contains($api, 'shopping_spend.json'),
    'the spend history is read from data/shopping_spend.json');
assert_true(preg_match('/\$dt->modify\("-\\{\$i\} months"\)/', $api) === 1,
    'getSpendStats() rolls the last 6 months up');

if ($fail === 0) {
    echo "\nAll dashboard-panel tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
