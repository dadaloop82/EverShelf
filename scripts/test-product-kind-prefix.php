#!/usr/bin/env php
<?php
/**
 * Regression tests: the genre (genere) must lead every article title.
 *
 * The user asked for "Yogurt Fiori di latte" / "Formaggio Fiori di latte" because
 * the generic product type is often missing from the name on the label, and the
 * title must carry it for good. The rules that must not silently regress:
 *
 *   * the curated dictionary answers first (no AI call) — and only when the genre
 *     word LEADS the name; "Fiori di latte" matches "latte" but the dictionary
 *     cannot tell a yoghurt from a cheese, so it is not authoritative,
 *   * the signature cache serves similar products, so the family pays one AI word,
 *   * the prefix is idempotent: a title that already carries a genre is untouched,
 *   * computeShoppingName() still reads the SAME dictionaries (they moved to
 *     api/lib/product_kind.php) — the shopping/Bring! names must not change.
 *
 * Run: php scripts/test-product-kind-prefix.php
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

// ── Dictionary: authoritative only when the genre leads the name ────────────
$yogurt = productKindFromDictionary('Yogurt Greco Fage');
assert_same('Yogurt', $yogurt['kind'], 'dictionary: "Yogurt Greco Fage" → Yogurt');
assert_true($yogurt['confident'] === true, 'dictionary: leading genre word is authoritative');

$fiori = productKindFromDictionary('Fiori di latte');
assert_same('Latte', $fiori['kind'], 'dictionary: "Fiori di latte" falls back to Latte');
assert_true($fiori['confident'] === false, 'dictionary: non-leading match is NOT authoritative (needs cache/AI)');

$panna = productKindFromDictionary('Panna da cucina 200 ml');
assert_same('Panna da cucina', $panna['kind'], 'dictionary: multi-word phrase map wins over the single token');
assert_true($panna['confident'] === true, 'dictionary: curated phrase is authoritative');

assert_same('', productKindFromDictionary('Zzzxyz')['kind'], 'dictionary: unknown product yields no genre');

// Brand words are not significant tokens (they must not fake a genre match)
assert_same(['fiori', 'latte'], evershelfSignificantTokens('Fiori di latte Santa Lucia 200 g', 'Santa Lucia'),
    'tokenizer: brand words/numbers/stop words are dropped');

// ── Signature cache: one paid AI word serves the whole family ───────────────
$cache = productKindCacheStore([], 'fiori latte', 'Formaggio', 'ai');
$hit = productKindCacheLookup($cache, 'fiori latte santa lucia');
assert_true($hit !== null && $hit['v'] === 'Formaggio', 'cache: similar product reuses the resolved genre');
assert_true($hit !== null && $hit['src'] === 'ai', 'cache: the source of the reused genre is kept');
assert_same(null, productKindCacheLookup($cache, 'bucce pomodoro zzz'), 'cache: unrelated signatures do not match');
assert_true(productKindCacheLookup([], 'fiori latte') === null, 'cache: empty cache misses instead of inventing a genre');

// ── The prefix itself ──────────────────────────────────────────────────────
assert_same('Yogurt Fiori di latte', applyProductKindPrefix('Fiori di latte', 'Yogurt'), 'prefix: genre becomes part of the title');
assert_same('Formaggio Fiori di latte', applyProductKindPrefix('Fiori di latte', 'Formaggio'), 'prefix: the genre the AI found is used');
assert_same('Yogurt Fiori di latte', applyProductKindPrefix('Yogurt Fiori di latte', 'Formaggio'), 'prefix: never doubled');
assert_same('Fiori di yogurt', applyProductKindPrefix('Fiori di yogurt', 'Yogurt'), 'prefix: genre already inside the title is respected');
assert_same('Latte', applyProductKindPrefix('Latte', 'Latte'), 'prefix: a title that IS the genre is left alone');
assert_same('Fiori di latte', applyProductKindPrefix('Fiori di latte', ''), 'prefix: no genre resolved → title untouched');
assert_true(productNameStartsWithKnownKind('Formaggio Fiori di latte'), 'guard: detects a title that already leads with a genre');
assert_true(!productNameStartsWithKnownKind('Fiori di latte'), 'guard: a title without genre is detected as such');

// A genre already present in ANOTHER FORM must not be prefixed again: the rule is
// "where it — or something similar — is not already there".
assert_same('Tarallini', applyProductKindPrefix('Tarallini', 'Taralli'), 'prefix: a plural/variant of the genre is respected');
assert_same('Pera Italiana Succo e polpa frutta', applyProductKindPrefix('Pera Italiana Succo e polpa frutta', 'Pere'),
    'prefix: singular name + plural genre is respected');
assert_same('Kaffee', applyProductKindPrefix('Kaffee', 'Caffè'), 'prefix: the same word with another spelling is respected');
assert_same('Piadelle integrali', applyProductKindPrefix('Piadelle integrali', 'Piadina'), 'prefix: the same root with another suffix is respected');
assert_same('Italia Zuccheri 100% Italiano', applyProductKindPrefix('Italia Zuccheri 100% Italiano', 'Zucchero'),
    'prefix: a similar genre further along the title is respected');
assert_same('Bucce cotte di pomodoro', applyProductKindPrefix('Bucce cotte di pomodoro', 'Pomodori'),
    'prefix: the plural genre is already there as the singular');
assert_same('Pasta Penne rigate', applyProductKindPrefix('Penne rigate', 'Pasta'), 'prefix: an absent genre is still added');
assert_true(!productKindNameAlreadyHasKind('Bucce salumi vari', 'Sale'), 'guard: a mere look-alike further along does not count as the genre');
assert_true(productKindNameAlreadyHasKind('Soia drink', 'Latte di soia'), 'guard: the genre phrase is recognised word by word');

// Every canonical genre must be recognised in front of a title (no double prefix)
$vocab = evershelfProductKindVocabulary();
assert_true(count($vocab) > 40, 'vocabulary: the curated dictionaries expose their genres (' . count($vocab) . ')');
$doublePrefixed = [];
foreach ($vocab as $kind) {
    if (applyProductKindPrefix($kind . ' Prodotto X', 'Yogurt') !== $kind . ' Prodotto X') {
        $doublePrefixed[] = $kind;
    }
}
assert_same([], $doublePrefixed, 'guard: no genre in the vocabulary can be prefixed twice');

// ── Same dictionaries as computeShoppingName() (the refactor must be silent) ─
assert_same('Yogurt', evershelfShoppingKeywordMap()['yogurt'] ?? '', 'shopping: keyword map still resolves yogurt');
assert_same('Yogurt', computeShoppingName('Yogurt Greco Fage', 'latticini', 'Fage', false), 'shopping: computeShoppingName() output unchanged');
assert_same('Fette biscottate', computeShoppingName('Fette biscottate integrali', 'pane', 'Mulino Bianco', false), 'shopping: phrase map still first');

// ── The pipeline prefixes, and reports where the genre came from ────────────
$applied = productKindApply('Fette biscottate integrali', 'Mulino Bianco', 'pane', 'it', false, 'Fette biscottate');
assert_same('Fette biscottate integrali', $applied['name'], 'pipeline: a title already led by its genre is not touched');
assert_same('existing', $applied['source'], 'pipeline: the short-circuit is reported');
assert_same('Fette biscottate', $applied['kind'], 'pipeline: the genre already stored on the product is carried over');
assert_same('', productKindApply('Fette biscottate integrali', 'Mulino Bianco', 'pane', 'it', false)['kind'],
    'pipeline: without a stored genre the short-circuit reports none');

$fresh = productKindApply('Orecchiette', 'De Cecco', 'pasta', 'it', false);
assert_same('Pasta', $fresh['kind'], 'pipeline: dictionary still finds a genre with the AI switched off');
assert_same('Pasta Orecchiette', $fresh['name'], 'pipeline: the resolved genre leads the title');
assert_same($fresh['name'], productKindApply($fresh['name'], 'De Cecco', 'pasta', 'it', false, $fresh['kind'])['name'],
    'pipeline: re-running it changes nothing');

// A title that already says "pomodoro" must not become "Pomodori Bucce cotte di pomodoro":
// the genre is there, only the number differs.
$redundant = productKindApply('Bucce cotte di pomodoro xyzq', 'Rossi', 'verdura', 'it', false);
assert_same('Pomodori', $redundant['kind'], 'pipeline: the weak dictionary still reports the genre');
assert_same('Bucce cotte di pomodoro xyzq', $redundant['name'], 'pipeline: …but a singular/plural genre is not prefixed again');

// The genre stored on the product freezes the title, even when the dictionary would now
// suggest a broader genre for the same word (its own "toast" → "Pane"): a real run of the
// maintenance pass must be the last word on the title, or every pass would drift it.
$frozen = productKindApply('Toast Sandwich American Style', 'Xyz', 'pane', 'it', false, 'Toast');
assert_same('Toast Sandwich American Style', $frozen['name'], 'pipeline: a stored genre leading the title is not overwritten');
assert_same('Toast', $frozen['kind'], 'pipeline: …and the stored genre is kept');
assert_same('existing', $frozen['source'], 'pipeline: …and reported as already present');

// ── Settings keys exist in every locale (and the UI is wired to them) ───────
$keys = [
    'card_title', 'card_hint', 'kind_prefix_label', 'auto_favorite_label',
    'auto_favorite_suffix', 'apply_existing', 'apply_confirm', 'apply_done', 'apply_failed',
];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $data = json_decode((string)file_get_contents(__DIR__ . '/../translations/' . $loc . '.json'), true);
    $missing = [];
    foreach ($keys as $k) {
        if (empty($data['settings']['product_rules'][$k])) {
            $missing[] = $k;
        }
    }
    assert_same([], $missing, "i18n: settings.product_rules.* complete in {$loc}");
}

$html = (string)file_get_contents(__DIR__ . '/../index.html');
$appJs = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$apiPhp = (string)file_get_contents(__DIR__ . '/../api/index.php');
assert_true(str_contains($html, 'id="setting-product-kind-prefix"'), 'ui: the genre-prefix toggle exists in index.html');
assert_true(str_contains($html, 'id="setting-auto-favorite-min-uses"'), 'ui: the auto-favourite threshold exists in index.html');
assert_true(str_contains($html, 'onclick="applyProductAutoRules()"'), 'ui: the maintenance button is wired');
assert_true(str_contains($appJs, 'function _applyProductRuleSettingsUI'), 'ui: settings are applied by app.js');
assert_true(str_contains($appJs, "'product_kind_prefix'") && str_contains($appJs, "'auto_favorite_min_uses'"),
    'ui: settings travel both ways (server keys + local storage)');
assert_true(str_contains($apiPhp, "case 'products_apply_auto_rules':"), 'api: the maintenance action is routed');
assert_true(str_contains($apiPhp, 'productKindApply('), 'api: the save path applies the genre prefix');
assert_true(str_contains($apiPhp, 'mergeIncomingProductFields'), 'api: the single title choke point still exists');

echo $fail === 0 ? "\nAll product-kind tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
