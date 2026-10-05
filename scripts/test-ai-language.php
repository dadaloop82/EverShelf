#!/usr/bin/env php
<?php
/**
 * Regression tests: the photo-identification flow must answer in the UI language.
 *
 * Run: php scripts/test-ai-language.php
 *
 * Issue #260: gemini_identify built its prompt from a hardcoded Italian string,
 * so an English — or German, French, Spanish, Chinese — user who photographed,
 * say, toilet paper got "Carta igienica" and an Italian description back. The
 * frontend always knew the language, the request simply never carried it. The
 * fragments now live in api/lib/ai_prompts.php, one complete set per locale, and
 * the handler resolves them from the `lang` the client sends.
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

$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];

// ── Every shipped locale carries a complete fragment set ───────────────────
$fragments = [];
$keys      = null;
foreach ($locales as $lang) {
    $set              = evershelfIdentifyPromptFragments($lang);
    $fragments[$lang] = $set;
    assert_true(count($set) === 9, "{$lang}: nine prompt fragments");
    if ($keys === null) {
        $keys = array_keys($set);
    }
    assert_true(array_keys($set) === $keys, "{$lang}: same fragment keys as the other locales");
}
assert_true(
    $keys === ['intro', 'lang_rule', 'json_rule', 'name', 'brand', 'category', 'search_terms', 'confidence', 'description'],
    'the fragment key set is the documented one'
);
foreach ($keys as $key) {
    $empty = array_filter($locales, static fn(string $l): bool => trim($fragments[$l][$key]) === '');
    assert_true($empty === [], "every locale fills the '{$key}' fragment");
}

// Unknown values fall back to English: an empty prompt would silently break the scan.
assert_true(evershelfIdentifyPromptFragments('xx') === $fragments['en'], 'an unknown locale falls back to English');
assert_true(evershelfIdentifyPromptFragments('') === $fragments['en'], 'an empty locale falls back to English');

// ── Only the Italian set is Italian ────────────────────────────────────────
$italianMarkers = ['Analizza questa foto', 'Rispondi SOLO', 'in italiano', 'Marca se visibile', 'Breve descrizione'];
foreach ($locales as $lang) {
    if ($lang === 'it') {
        continue;
    }
    assert_true($fragments[$lang]['intro'] !== $fragments['it']['intro'], "{$lang}: the prompt is not the Italian one");
    assert_true($fragments[$lang]['lang_rule'] !== $fragments['it']['lang_rule'], "{$lang}: the answer-language rule is localized");

    $leaks = [];
    foreach ($fragments[$lang] as $key => $value) {
        foreach ($italianMarkers as $marker) {
            if (str_contains($value, $marker)) {
                $leaks[] = "{$key} ({$marker})";
            }
        }
    }
    assert_true($leaks === [], "{$lang}: no fragment reuses an Italian string" . ($leaks ? ' — ' . implode(', ', $leaks) : ''));
}

// ── The canonical category tokens are never translated ─────────────────────
// mapToLocalCategory() in app.js matches them against CATEGORY_ICONS keys.
$canonical = ['latticini', 'pasta', 'bevande', 'snack', 'carne', 'pesce', 'frutta', 'verdura', 'surgelati', 'condimenti', 'conserve', 'cereali', 'pane', 'igiene', 'pulizia', 'altro'];
foreach ($locales as $lang) {
    $missing = array_filter($canonical, static fn(string $t): bool => !str_contains($fragments[$lang]['category'], $t));
    assert_true($missing === [], "{$lang}: the category fragment keeps every canonical token");
}

// ── Wiring: the router takes the language and passes it on ─────────────────
/** Source of a top-level function: from its declaration to the next one. */
function source_of(string $file, string $fn): string
{
    $src   = (string)file_get_contents($file);
    $start = strpos($src, 'function ' . $fn . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\nfunction ", $start);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

$indexFile = __DIR__ . '/../api/index.php';
$index     = (string)file_get_contents($indexFile);

$handler = source_of($indexFile, 'geminiIdentifyProduct');
assert_true($handler !== '', 'the identify handler was found');
assert_true(
    str_contains($handler, "recipeNormalizeLang(\$input['lang'] ?? env('APP_LANG', 'en'))"),
    'the identify handler resolves the language the client sends'
);
assert_true(str_contains($handler, 'evershelfIdentifyPromptFragments($lang)'), 'the prompt is built from the localized fragment set');
assert_true(
    str_contains($handler, "searchOpenFoodFacts(\$searchTerms, \$identified['name'], \$identified['brand'] ?? '', \$lang)"),
    'the Open Food Facts lookup gets the same language'
);
assert_true(str_contains($handler, '{$p[\'intro\']}'), 'the prompt template interpolates the localized fragments');
assert_true(!str_contains($handler, 'Analizza questa foto'), 'the hardcoded Italian intro is gone');

$off = source_of($indexFile, 'searchOpenFoodFacts');
assert_true($off !== '', 'the Open Food Facts search was found');
assert_true((bool)preg_match('/\$lang\s*=\s*recipeNormalizeLang\(\$lang\);/', $off), 'the search normalizes its language argument');
assert_true(str_contains($off, 'lc={$lang}'), 'Open Food Facts is queried in the user locale');
assert_true(str_contains($off, "'product_name_' . \$lang"), 'the localized product-name field is preferred');
assert_true(!str_contains($off, 'lc=it'), 'the hardcoded Italian locale is gone from the identify search');

assert_true(!str_contains($index, 'Categoria in italiano'), 'the hardcoded Italian category prompt is gone from the router');
assert_true(!str_contains($index, 'function _identifyProductPromptStrings'), 'the fragments are not duplicated in the router');

// ── Wiring: the lib is loaded before the router runs ──────────────────────
$bootstrap = (string)file_get_contents(__DIR__ . '/../api/bootstrap.php');
assert_true(
    str_contains($bootstrap, "require_once __DIR__ . '/lib/ai_prompts.php';"),
    'bootstrap.php loads api/lib/ai_prompts.php'
);

// ── Wiring: both frontend callers send the language ───────────────────────
$appJs = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$call  = "api('gemini_identify', {}, 'POST', { image: base64, lang: _currentLang })";
assert_true(substr_count($appJs, $call) === 2, 'both gemini_identify callers send lang: _currentLang');
assert_true(
    !str_contains($appJs, "api('gemini_identify', {}, 'POST', { image: base64 })"),
    'no caller sends the image without the language'
);

if ($fail > 0) {
    echo "\n{$fail} test(s) failed\n";
    exit(1);
}
echo "\nAll AI language tests passed\n";
