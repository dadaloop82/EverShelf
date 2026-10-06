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
 *   * every title opens with a capital letter (productTitleCapitalize()), whichever
 *     pass wrote the name — the genre prefix can be switched off, this rule cannot,
 *   * computeShoppingName() still reads the SAME dictionaries through the SAME lookup
 *     (productKindFromDictionary) — the shopping/Bring! names must not change, and on a
 *     dictionary-known product the shopping generic and the title genre are the same
 *     string by construction (asserted over the whole live pantry below).
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

// Trailing genre tokens (packaging/brand opener) — must not let ingredients steal the name.
assert_same('Piadina', computeShoppingName('La sfogliata tradizionale piadine', '', '', false), 'shopping: trailing piadine → Piadina');
assert_same('Avocado', computeShoppingName('Frutta fresca Avocados', '', '', false), 'shopping: trailing avocados → Avocado');
assert_same('Cereali', computeShoppingName('Muesli Frutta Secca', '', '', false), 'shopping: muesli family → Cereali');
assert_same('Cereali', computeShoppingName('Granola con cioccolato', '', '', false), 'shopping: granola family → Cereali');
// Ingredient further along must not become the dictionary genre (phrase map lead-only).
assert_same('', productKindFromDictionary('Campagnole con farina di riso')['kind'],
    'dictionary: ingredient "farina di riso" mid-title is not a genre');
assert_same('Farina di riso', productKindFromDictionary('Farina di riso Caputo')['kind'],
    'dictionary: a real flour title still resolves');

// One vocabulary, two consumers: the shopping generic IS productKindFromDictionary()'s
// answer, so the buyable name and the genre leading the title can never drift apart.
$oneVocabulary = [
    'Yogurt Greco Fage', 'Penne rigate', 'Panna da cucina 200 ml', 'Fette biscottate integrali',
    'Passata di pomodoro', 'Pomodori pelati', 'Tarallini', 'Bucce cotte di pomodoro',
    'Farina di mais', 'Acqua frizzante', 'Prosciutto cotto a fette',
];
$drifted = [];
foreach ($oneVocabulary as $oneName) {
    $dict = productKindFromDictionary($oneName)['kind'];
    if ($dict === '') {
        continue;
    }
    $shop = computeShoppingName($oneName, '', '', false);
    if ($shop !== $dict) {
        $drifted[] = "{$oneName}: shopping={$shop} dict={$dict}";
    }
}
assert_same([], $drifted, 'shopping + genre: one dictionary, no drift');

// …and the same invariant must hold for the live pantry (read-only, skipped without a DB).
$liveDb = __DIR__ . '/../data/evershelf.db';
if (is_file($liveDb)) {
    try {
        $pdo = new PDO('sqlite:' . $liveDb);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $bad = 0;
        $checked = 0;
        foreach ($pdo->query('SELECT name, category, brand FROM products') as $row) {
            $dict = productKindFromDictionary((string)($row['name'] ?? ''))['kind'];
            if ($dict === '') {
                continue; // the dictionary does not know it → shopping falls back to its own tail
            }
            $checked++;
            if (computeShoppingName((string)$row['name'], (string)($row['category'] ?? ''), (string)($row['brand'] ?? ''), false) !== $dict) {
                $bad++;
            }
        }
        assert_same(0, $bad, "shopping + genre: same generic over the live pantry ({$checked} dictionary-known products)");
    } catch (Throwable $e) {
        echo 'SKIP: live pantry check (' . $e->getMessage() . ")\n";
    }
}

// ── The pipeline prefixes, brings the title to the singular, and reports the source ──
$applied = productKindApply('Fette biscottate integrali', 'Mulino Bianco', 'pane', 'it', false, 'Fette biscottate');
assert_same('Fetta biscottata integrale', $applied['name'], 'pipeline: a title led by its genre is not prefixed again, only brought to the singular');
assert_same('existing', $applied['source'], 'pipeline: the short-circuit is reported');
assert_same('Fetta biscottata', $applied['kind'], 'pipeline: the genre already stored on the product is carried over (in its singular form)');
assert_same('Fetta biscottata', productKindApply('Fette biscottate integrali', 'Mulino Bianco', 'pane', 'it', false)['kind'],
    'pipeline: without a stored genre the dictionary still names the genre — a title that already leads with it is never left without one');

$fresh = productKindApply('Orecchiette', 'De Cecco', 'pasta', 'it', false);
assert_same('Pasta', $fresh['kind'], 'pipeline: dictionary still finds a genre with the AI switched off');
assert_same('Pasta orecchiette', $fresh['name'], 'pipeline: the resolved genre leads the title');
assert_same($fresh['name'], productKindApply($fresh['name'], 'De Cecco', 'pasta', 'it', false, $fresh['kind'])['name'],
    'pipeline: re-running it changes nothing');

// A title that already says "pomodoro" must not become "Pomodori Bucce cotte di pomodoro":
// the genre is there, only the number differs. And because this title does NOT lead with the
// genre, the stored genre keeps the dictionary's own form — narrowing it would store a word
// the title never had ("Bucce cotte di Pelato").
$redundant = productKindApply('Bucce cotte di pomodoro xyzq', 'Rossi', 'verdura', 'it', false);
assert_same('Pomodori', $redundant['kind'], 'pipeline: the weak dictionary still reports the genre');
assert_same('Bucce cotte di pomodoro xyzq', $redundant['name'], 'pipeline: …but a genre already worded in the title is not prefixed again');
// A genre that DOES lead the title is the genre of that title, and is stored singular.
assert_same('Carota', productKindApply('Carote baby xyzq', 'Bonduelle', 'verdura', 'it', false)['kind'],
    'pipeline: a genre leading the title is stored in its singular form');
assert_same('Carota baby xyzq', productKindApply('Carote baby xyzq', 'Bonduelle', 'verdura', 'it', false)['name'],
    'pipeline: …and the title itself is brought to the singular');

// The genre stored on the product freezes the title, even when the dictionary would now
// suggest a broader genre for the same word (its own "toast" → "Pane"): a real run of the
// maintenance pass must be the last word on the title, or every pass would drift it.
$frozen = productKindApply('Toast Sandwich American Style', 'Xyz', 'pane', 'it', false, 'Toast');
assert_same('Toast sandwich american style', $frozen['name'], 'pipeline: a stored genre leading the title is not overwritten (the sentence case still applies)');
assert_same('Toast', $frozen['kind'], 'pipeline: …and the stored genre is kept');
assert_same('existing', $frozen['source'], 'pipeline: …and reported as already present');

// ── Every title opens with a capital letter and keeps no other (sentence case) ──
assert_same('Latte fresco', productTitleCapitalize('latte fresco'), 'capital: a lowercase title is raised');
assert_same('Latte fresco', productTitleCapitalize('LATTE FRESCO'), 'case: the rest of the title is lowered');
assert_same('Yogurt fiori di latte', productTitleCapitalize('yogurt Fiori di latte'), 'case: only the first letter keeps a capital');
assert_same('Latte fresco', productTitleCapitalize('Latte fresco'), 'capital: an already correct title is left alone');
assert_same('Latte fresco', productTitleCapitalize('  latte fresco  '), 'capital: surrounding spaces are trimmed');
assert_same('È pronto', productTitleCapitalize('è pronto'), 'capital: accents are raised in UTF-8 (è → È)');
assert_same('Iphone 15', productTitleCapitalize('iPhone 15'), 'case: the rule is mechanical — a brand spelling loses its lowercase first letter too');
assert_same('Nutella', productTitleCapitalize('NUTELLA'), 'case: a shouting brand is tamed like any other word');
assert_same('3 mele', productTitleCapitalize('3 mele'), 'capital: a title opening with a digit is left alone (no letter to raise)');
assert_same('6 UOVA PASTA GIALLA', productTitleCapitalize('6 UOVA PASTA GIALLA'), 'case: a title with no first letter is not rewritten at all');
assert_same('🍎 mela', productTitleCapitalize('🍎 mela'), 'capital: a title opening with an emoji is left alone');
assert_same('', productTitleCapitalize('   '), 'capital: an empty title stays empty');
assert_same('Latte fresco', productTitleCapitalize(productTitleCapitalize('latte fresco')), 'capital: idempotent (the pass can replay it)');
// …but a sigla keeps its capitals, or a whole pantry of IGP/DOP files would be wrecked.
assert_same('Aceto balsamico di modena IGP', productTitleCapitalize('Aceto Balsamico di Modena IGP'),
    'case: a sigla keeps its capitals (IGP); a place name is a word like any other and is lowered');
assert_same('Mozzarella di bufala DOP', productTitleCapitalize('MOZZARELLA DI BUFALA DOP'),
    'case: DOP survives the lowercasing');
assert_same('Olio EVO XYZ', productTitleCapitalize('OLIO EVO XYZ'),
    'case: an all-caps token the vocabulary does not know is read as a sigla and kept (EVO, XYZ)');
assert_same('Bio uova', productTitleCapitalize('BIO uova'), 'case: "BIO" is a word, not a sigla — it goes to lowercase like everything else');
assert_same('Uova pasta', productTitleCapitalize('UOVA PASTA'), 'case: "UOVA"/"PASTA" are words of the vocabulary, not sigle');
assert_same('Aceto balsamico di modena IGP', productTitleCapitalize('aceto balsamico di modena I.G.P'),
    'case: a sigla is written back in its canonical capitals (I.G.P → IGP)');
assert_same('Aceto balsamico di modena IGP', productTitleCapitalize('aceto balsamico di modena Igp'),
    'case: …even when the user typed it in mixed case');
assert_same('Mozzarella (DOP)', productTitleCapitalize('Mozzarella (DOP)'),
    'case: the brackets the user typed around a sigla stay where they are');
assert_same('Marmellata di &quot;limone di siracusa IGP&quot;',
    productTitleCapitalize('Marmellata di &quot;Limone di Siracusa I.G.P.&quot;'),
    'case: HTML entities in a title survive, and the sigla inside them is still canonical');

// ── The stored title is the singular, and only the head brings its adjectives along ──
assert_same('Uovo', productKindApply('Uova', '', '', 'it', false)['name'], 'singular: a bare plural title is stored singular');
assert_same('Uovo fresco grande', productKindApply('Uova Fresche Grandi', '', '', 'it', false)['name'],
    'singular: the adjectives right after the genre follow it');
assert_same('Biscotto macine con panna fresca', productKindApply('Biscotti Macine con Panna Fresca', '', '', 'it', false)['name'],
    'singular: …but an adjective further along belongs to another noun and stays put ("panna fresco" would be wrong)');
assert_same('Carota baby', productKindApply('carote baby', '', '', 'it', false)['name'],
    'singular: …and a variety name is never inflected');
assert_same('«Carota» baby', productKindApply('«Carote» baby', '', '', 'it', false)['name'],
    'singular: the punctuation around the genre travels with it');
assert_same('6 UOVA PASTA GIALLA', productKindApply('6 UOVA PASTA GIALLA', '', '', 'it', false)['name'],
    'singular: a title opening with a quantity is left alone (the number must not move behind the genre)');
assert_same('Uova medie', productNameForPieces('Uovo medio', 6, 'pz', 'Uovo'),
    'plural: the list derives the plural of the singular title back');
assert_same('Uova fresche grandi', productNameForPieces('Uovo fresco grande', 6, 'pz', 'Uovo'),
    'plural: …with the agreeing adjectives');
assert_same('6 UOVA PASTA GIALLA', productNameForPieces('6 UOVA PASTA GIALLA', 6, 'pz', 'Uovo'),
    'plural: …and a quantity-led title is never rewritten either');

// The pipeline capitalizes as well, so a save and the maintenance pass agree…
assert_same('Pasta orecchiette', productKindApply('orecchiette', 'De Cecco', 'pasta', 'it', false)['name'],
    'pipeline: the genre prefixes the title, which then opens with a capital letter');
// …even on a title the dictionary already answers for (no prefix added, letter raised).
assert_same('Latte fresco', productKindApply('latte fresco', '', '', 'it', false)['name'],
    'pipeline: a genre-led lowercase title is raised, not prefixed again');

// The save path is the choke point: whatever wrote the name, the stored title opens
// with a capital letter.
$typed = mergeIncomingProductFields(null, ['name' => 'latte fresco parzialmente scremato', 'lang' => 'it'], null);
assert_same('Latte fresco parzialmente scremato', $typed['name'], 'save path: the stored title opens with a capital letter');
$forced = mergeIncomingProductFields(null, ['name' => 'passata di pomodoro xyz', 'name_user_set' => 1], null);
assert_same('Passata di pomodoro xyz', $forced['name'], 'save path: a hand-typed name is capitalized too (and not prefixed twice)');

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
// The capital letter must survive PRODUCT_KIND_PREFIX=false: in the save path the call
// sits OUTSIDE the genre guard (and the guard is what the comment above it marks).
$capCall  = strpos($apiPhp, '$name = productTitleCapitalize($name);');
$kindGuard = strpos($apiPhp, '// Genre (genere) as an integral part of the article title');
assert_true($capCall !== false, 'api: the save path capitalizes the stored title');
assert_true($capCall !== false && $kindGuard !== false && $capCall < $kindGuard,
    'api: …outside the genre guard, so switching the genre prefix off cannot switch the capital letter off');
assert_true(str_contains((string)file_get_contents(__DIR__ . '/../api/lib/product_kind.php'), 'function productTitleCapitalize'),
    'api: the title capitalizer lives with the other title rules (lib/product_kind.php)');

echo $fail === 0 ? "\nAll product-kind tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
