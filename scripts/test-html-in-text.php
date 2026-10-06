#!/usr/bin/env php
<?php
/**
 * Regression tests: HTML must never reach a text-only surface.
 *
 * Regression history: formatQuantity() returns a little markup for "conf" units
 * (`10 conf <span class="conf-size-info">(da 36g)</span>`) because most callers inject
 * it into innerHTML, where the span only gets a smaller/greyer look. The screensaver
 * facts (facts.item_qty, facts.high_qty) instead print the whole sentence with
 * textContent, and the item chooser modal escapes the value with escapeHtml(), so the
 * tag itself showed up in the UI as literal text:
 *   "Lenticchie: ne hai 2 conf <span class="conf-size-info">(da 250g)</span>"
 *
 * The rules locked here:
 *   * a bare formatQuantity() result must never be handed to escapeHtml(), assigned to
 *     textContent/title, or interpolated into a sentence printed as text — text-only
 *     callers must go through stripHtml() or _formatQtyPlain();
 *   * generateScreensaverFact() may only build its {qty} values with a text-only helper;
 *   * the screensaver fact element is written with textContent, never with innerHTML.
 *
 * Run: php scripts/test-html-in-text.php
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
$lines = explode("\n", (string)file_get_contents($root . '/assets/js/app.js'));
// Same line numbers, comments removed: the guard looks at code, not at prose.
$code = array_map('js_strip_comments', $lines);

/** [lineIndex => functionName] for every `function name(` declaration in app.js. */
function js_functions(array $lines): array
{
    $map = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/', $line, $m)) {
            $map[$i] = $m[1];
        }
    }
    return $map;
}

/**
 * Drop comments from a line so that prose ("escapeHtml(x)", "formatQuantity()")
 * never triggers the guard. Conservative: full-line comments and trailing `// …`
 * preceded by whitespace (URLs in strings survive because `https://` has no space).
 */
function js_strip_comments(string $line): string
{
    if (preg_match('~^\s*//~', $line)) {
        return '';
    }
    $stripped = preg_replace('~\s//[^"\']*$~', '', $line);
    return $stripped ?? $line;
}

/** Name of the function that owns $line (the functions in app.js are top-level). */
function js_enclosing(array $map, int $line): string
{
    $name = '(top level)';
    foreach ($map as $start => $fn) {
        if ($start <= $line) {
            $name = $fn;
        } else {
            break;
        }
    }
    return $name;
}

/** [first, last) line range of the function that owns $line. */
function js_segment(array $map, int $line, int $total): array
{
    $first = 0;
    $last = $total;
    foreach ($map as $start => $fn) {
        if ($start <= $line) {
            $first = $start;
        } elseif ($last === $total) {
            $last = $start;
            break;
        }
    }
    return [$first, $last];
}

/** Body of a top-level function (declaration line included), or null. */
function js_function_body(array $lines, array $map, string $name): ?string
{
    $starts = array_keys($map);
    foreach ($map as $start => $fn) {
        if ($fn !== $name) {
            continue;
        }
        $end = count($lines);
        foreach ($starts as $s) {
            if ($s > $start) {
                $end = $s;
                break;
            }
        }
        return implode("\n", array_slice($lines, $start, $end - $start));
    }
    return null;
}

/**
 * Line number (1-based) where $var is assigned from a *bare* formatQuantity() call
 * inside [$from, $to), or null when every assignment is wrapped in a text-only helper.
 */
function bare_format_quantity_assignment(array $lines, string $var, int $from, int $to): ?int
{
    $name = preg_quote($var, '/');
    for ($j = $from; $j < $to; $j++) {
        if (!preg_match('/\b' . $name . '\b\s*=[^=]/', $lines[$j])) {
            continue;
        }
        // The assignment (and with it the call) may continue on the next two lines.
        $chunk = implode(' ', array_slice($lines, $j, 3));
        if (!preg_match('/\b' . $name . '\b\s*=[^;]*?formatQuantity\(/', $chunk, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        if (str_contains($m[0][0], 'stripHtml(') || str_contains($m[0][0], '_formatQtyPlain(')) {
            continue; // already converted to plain text
        }
        return $j + 1;
    }
    return null;
}

$functions = js_functions($code);
$totalLines = count($code);

// ── [1] no bare formatQuantity() result flows into a text-only API ─────────
$leaks = [];
foreach ($code as $i => $line) {
    $vars = [];
    if (preg_match_all('/escapeHtml\(\s*([A-Za-z_$][\w$]*)\s*\)/', $line, $m)) {
        foreach ($m[1] as $var) {
            $vars[$var] = 'escapeHtml()';
        }
    }
    if (preg_match_all('/\.(?:textContent|title)\s*=\s*([A-Za-z_$][\w$]*)\s*[;`]/', $line, $m2)) {
        foreach ($m2[1] as $var) {
            $vars[$var] = 'textContent';
        }
    }
    if (str_contains($line, 'escapeHtml(formatQuantity(')) {
        $leaks[] = sprintf('app.js:%d (%s) passes formatQuantity() markup straight into escapeHtml()', $i + 1, js_enclosing($functions, $i));
    }
    foreach (array_keys($vars) as $var) {
        [$from, $to] = js_segment($functions, $i, $totalLines);
        $assigned = bare_format_quantity_assignment($code, $var, $from, $to);
        if ($assigned !== null) {
            $leaks[] = sprintf(
                'app.js:%d (%s) prints the bare formatQuantity() result "%s" (assigned at line %d) through %s',
                $i + 1,
                js_enclosing($functions, $i),
                $var,
                $assigned,
                $vars[$var]
            );
        }
    }
}
assert_true($leaks === [], 'no formatQuantity() markup reaches escapeHtml()/textContent/title'
    . ($leaks ? ' — ' . implode(' | ', $leaks) : ''));

// ── [2] every sentence printed as text only produces plain text ────────────
$factFn = js_function_body($code, $functions, 'generateScreensaverFact');
assert_true($factFn !== null, 'generateScreensaverFact() exists in app.js');
if ($factFn !== null) {
    $rawCalls   = preg_match_all('/\bformatQuantity\(/', $factFn);
    $plainCalls = preg_match_all('/(?:\bstripHtml\(\s*formatQuantity\(|\b_formatQtyPlain\()/', $factFn);
    $qtySlots   = preg_match_all('/replace\([\'"]\{qty\}[\'"]/', $factFn);
    assert_true($rawCalls === 0, 'generateScreensaverFact() never calls formatQuantity() directly (found ' . $rawCalls . ')');
    assert_true($qtySlots >= 1, 'generateScreensaverFact() interpolates {qty} at least once (found ' . $qtySlots . ')');
    assert_true($plainCalls >= $qtySlots, 'every {qty} in generateScreensaverFact() is built with a text-only helper ('
        . $plainCalls . ' plain call(s) for ' . $qtySlots . ' placeholder(s))');
}

// The dashboard alert banner is written with textContent too (titleEl/detailEl), and it
// diverts the conf-with-package-size case to a sub-unit total before this call.
$bannerFn = js_function_body($code, $functions, 'renderBannerItem');
assert_true($bannerFn !== null, 'renderBannerItem() exists in app.js');
if ($bannerFn !== null) {
    $bannerRaw = preg_match_all('/\bformatQuantity\(/', $bannerFn);
    assert_true($bannerRaw === 0, 'renderBannerItem() never calls formatQuantity() directly (found ' . $bannerRaw . ')');
    assert_true(str_contains($bannerFn, 'textContent') && str_contains($bannerFn, '_formatQtyPlain('),
        'renderBannerItem() keeps its textContent rendering and the plain-text formatter');
}

// ── [3] the screensaver fact element is text, never markup ─────────────────
foreach (['_showScreensaverSlot', 'showNextScreensaverFact'] as $name) {
    $body = js_function_body($code, $functions, $name);
    assert_true($body !== null && str_contains($body, 'textContent'), $name . '() renders the fact with textContent');
    assert_true($body !== null && !str_contains($body, 'innerHTML'), $name . '() never renders the fact as HTML');
}
$htmlWrites = [];
foreach ($code as $i => $line) {
    if (str_contains($line, 'screensaver-fact') && str_contains($line, 'innerHTML')) {
        $htmlWrites[] = 'app.js:' . ($i + 1);
    }
}
assert_true($htmlWrites === [], 'no innerHTML is written into the screensaver fact element'
    . ($htmlWrites ? ' (' . implode(', ', $htmlWrites) . ')' : ''));

// ── [4] the guard is not vacuous: the markup and the helpers still exist ───
$formatQuantityFn = js_function_body($code, $functions, 'formatQuantity');
assert_true($formatQuantityFn !== null && str_contains($formatQuantityFn, '<span class="conf-size-info">'),
    'formatQuantity() still emits the conf-size markup this guard protects against');
$formatQtyPlainFn = js_function_body($code, $functions, '_formatQtyPlain');
assert_true($formatQtyPlainFn !== null && preg_match('/replace\(\/<\[\^>\]/', (string)$formatQtyPlainFn) === 1,
    '_formatQtyPlain() strips tags');
assert_true(js_function_body($code, $functions, 'stripHtml') !== null, 'stripHtml() exists');

// ── [5] the sentences that interpolate the quantity keep {name} and {qty} ──
$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];
$strings = [];
foreach ($locales as $loc) {
    $decoded = json_decode((string)file_get_contents($root . '/translations/' . $loc . '.json'), true);
    $strings[$loc] = is_array($decoded) ? $decoded : [];
}
foreach (['item_qty', 'high_qty'] as $key) {
    $problems = [];
    foreach ($locales as $loc) {
        $value = $strings[$loc]['facts'][$key] ?? '';
        foreach (['{name}', '{qty}'] as $token) {
            if (!str_contains((string)$value, $token)) {
                $problems[] = $loc . ' lacks ' . $token;
            }
        }
    }
    assert_true($problems === [], 'facts.' . $key . ' keeps its placeholders in all locales'
        . ($problems ? ' (' . implode(', ', $problems) . ')' : ''));
}

if ($fail === 0) {
    echo "\nAll HTML-in-text tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
