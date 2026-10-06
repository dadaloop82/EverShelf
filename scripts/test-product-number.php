#!/usr/bin/env php
<?php
/**
 * Regression tests: a product title is stored in the SINGULAR, and the pantry list
 * shows the PLURAL only when there really is more than one piece.
 *
 * The user asked for "Uovo medio" in the catalog and "3 Uova medie" in the pantry:
 * the number is a display rule, never a second stored title, or the catalog, the
 * search and the maintenance pass would come to disagree about the same article.
 *
 *   * the stored title is the singular of its genre (productKindSingularizeName()),
 *     applied at the same choke point as the genre prefix and the capital letter,
 *   * the pantry name is derived at read time (productNameForPieces()) and only for
 *     the units that count pieces: "500 g Pasta" is a mass and stays singular,
 *   * a genre the dictionary does not know is INVARIANT: "3 Latte", never "3 Latti" —
 *     the app never invents a plural it was not given, and the curated adjectives are
 *     the only words that inflect ("Uova medie" → "Uovo medio"),
 *   * a title that merely CONTAINS the genre ("Bucce cotte di pomodoro") is not
 *     rewritten: only a genre that LEADS the name moves,
 *   * "Tarallini" is not "Taralli": whole-word matching, so a similar-looking variety
 *     is never dragged along,
 *   * both directions are idempotent: singular→plural→singular round-trips.
 *
 * Run: php scripts/test-product-number.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$fail = 0;

function assert_same($expected, $actual, string $msg): void
{
    global $fail;
    if ($expected !== $actual) {
        echo "FAIL: {$msg} (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}
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


// ── The stored title is the singular of the genre it carries ────────────────
assert_same('Uovo medio', productKindSingularizeName('Uova medie', 'Uova'), 'singular: the head moves and the adjective follows (Uova medie → Uovo medio)');
assert_same('Mela rossa Gala', productKindSingularizeName('Mele rosse Gala', 'Mele'), 'singular: a variety after the genre is left exactly as written');
assert_same('Cipolla dorata', productKindSingularizeName('Cipolle dorate', 'Cipolla'), 'singular: the genre is accepted in its singular form too');
assert_same('Pomodoro pelato', productKindSingularizeName('Pomodori pelati', 'Pomodori'), 'singular: -i/-i pairs inflect (Pomodori pelati → Pomodoro pelato)');
assert_same('Zucchina bio Bimby', productKindSingularizeName('Zucchine bio Bimby', 'Zucchine'), 'singular: an untouched tail word is not an error (bio, Bimby)');
assert_same('Fetta biscottata integrale', productKindSingularizeName('Fette biscottate integrali', 'Fette biscottate'),
    'singular: a two-word genre moves as a whole');

// A genre the dictionary does not know has no singular to move to: the title is kept.
assert_same('Cracker integrali', productKindSingularizeName('Cracker integrali', 'Cracker'), 'invariant: an unknown genre never changes the title');
assert_same('Latte di Montagna', productKindSingularizeName('Latte di Montagna', 'Latte'), 'invariant: a mass noun has no plural (and no singular to move to)');
assert_same('Tarallini salati', productKindSingularizeName('Tarallini salati', 'Taralli'),
    'whole words: "Tarallini" is not "Taralli" (the pass cannot drag a variety)');
assert_same('Bucce cotte di pomodoro', productKindSingularizeName('Bucce cotte di pomodoro', 'Pomodori'),
    'only the head: a genre that merely appears in the title is not rewritten');
assert_same('', productKindSingularizeName('', 'Mele'), 'empty: an empty name stays empty');
assert_same('Mele', productKindSingularizeName('Mele', ''), 'empty: without a genre nothing moves');

// ── The pantry name is the plural of the pieces really there ────────────────
assert_same('Mele', productNameForPieces('Mela', 3, 'pz', 'Mela'), 'plural: 3 pieces of a piece-unit genre read plural');
assert_same('Mela', productNameForPieces('Mela', 1, 'pz', 'Mela'), 'plural: one piece stays singular');
assert_same('Mela', productNameForPieces('Mela', 0.5, 'pz', 'Mela'), 'plural: a fraction is not "more than one"');
assert_same('Uova medie', productNameForPieces('Uovo medio', 12, 'pz', 'Uovo'), 'plural: the adjective follows the head (Uovo medio → Uova medie)');
assert_same('Cipolle dorate', productNameForPieces('Cipolla dorata', 2.95, 'pz', 'Cipolla'), 'plural: a weighted piece count still pluralises');
assert_same('Fette biscottate integrali', productNameForPieces('Fetta biscottata integrale', 10, 'conf', 'Fetta biscottata'),
    'plural: "conf" counts pieces; the two-word genre moves as a whole');
assert_same('Zucchine bio Bimby', productNameForPieces('Zucchina bio Bimby', 2, 'pz', 'Zucchina'), 'plural: the variety is untouched');

// A mass has no plural: g/ml (and anything unknown) always stay singular.
assert_same('Pasta', productNameForPieces('Pasta', 500, 'g', 'Pasta'), 'units: 500 g of pasta is a mass, not 500 pastas');
assert_same('Farina 00', productNameForPieces('Farina 00', 1000, 'g', 'Farina'), 'units: 1000 g stays singular (unit g)');
assert_same('Latte', productNameForPieces('Latte', 1500, 'ml', 'Latte'), 'units: millilitres stay singular');
assert_same('Pasta', productNameForPieces('Pasta', 5, 'xyz', 'Pasta'), 'units: an unknown unit is read as a mass (never guess a plural)');

// An unknown genre is invariant here too — the app never invents "3 Latti".
assert_same('Latte', productNameForPieces('Latte', 5, 'pz', 'Latte'), 'invariant: an unknown genre has no plural to show');
assert_same('Cracker integrali', productNameForPieces('Cracker integrali', 4, 'pz', 'Cracker'), 'invariant: an unknown genre keeps its own form');
assert_same('Zzzxyz', productNameForPieces('Zzzxyz', 3, 'pz', ''), 'invariant: no genre at all leaves the title alone');
// …but when the caller did not store a genre, the dictionary answers for it.
assert_same('Mele', productNameForPieces('Mela', 4, 'pz', ''), 'dictionary: a missing genre is looked up (Mela → Mele)');

// ── Round trip: the pantry name can always go back to the stored one ───────
$stored = 'Cipolla dorata';
$pantry = productNameForPieces($stored, 3, 'pz', 'Cipolla');
assert_same($stored, productKindSingularizeName($pantry, 'Cipolla'), 'round trip: plural → singular returns the stored title');
assert_same($pantry, productNameForPieces(productKindSingularizeName($pantry, 'Cipolla'), 3, 'pz', 'Cipolla'),
    'round trip: singular → plural returns the pantry title (the pass can replay it)');



// ── The unit vocabulary is the app's own ───────────────────────────────────
assert_true(productKindUnitCountsPieces('pz'), 'units: "pz" counts pieces');
assert_true(productKindUnitCountsPieces('conf'), 'units: "conf" counts pieces');
assert_true(productKindUnitCountsPieces(''), 'units: a missing unit is the app default (pieces)');
assert_true(!productKindUnitCountsPieces('g'), 'units: "g" measures a mass');
assert_true(!productKindUnitCountsPieces('ml'), 'units: "ml" measures a mass');
assert_true(!productKindUnitCountsPieces('kg'), 'units: "kg" measures a mass');

// ── The save path stores the singular, the list read derives the plural ────
$written = mergeIncomingProductFields(null, ['name' => 'uova medie', 'lang' => 'it'], null);
assert_same('Uovo medio', $written['name'], 'save path: the stored title is the singular, capitalized');
assert_same('Uovo', $written['kind'], 'save path: the genre is stored in its singular form too');
$writtenAgain = mergeIncomingProductFields(['name' => $written['name'], 'kind' => $written['kind']],
    ['name' => $written['name'], 'kind' => $written['kind']], null);
assert_same('Uovo medio', $writtenAgain['name'], 'save path: re-saving does not drift the title');

// The pantry list must be able to show the plural: the API hands out display_name.
$apiPhp = (string)file_get_contents(__DIR__ . '/../api/index.php');
assert_true(str_contains($apiPhp, "\$row['display_name'] = productNameForPieces("),
    'api: listInventory derives the display name for the pantry');
assert_true(str_contains($apiPhp, "COALESCE(p.kind, '') as kind"), 'api: listInventory reads the stored genre');
$appJs = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
assert_true(str_contains($appJs, 'item.display_name || item.name'), 'ui: the pantry renders the display name when the API sends it');

// ── The vocabulary lives in ONE place ──────────────────────────────────────
$libPhp = (string)file_get_contents(__DIR__ . '/../api/lib/product_kind.php');
assert_true(str_contains($libPhp, 'function evershelfProductKindNumberForms('), 'lib: the number forms live in product_kind.php');
assert_true(str_contains($libPhp, 'function evershelfProductKindAgreementForms('), 'lib: the agreeing adjectives live in product_kind.php');
assert_true(substr_count($libPhp, 'evershelfProductKindNumberForms') >= 2,
    'lib: the number forms are read through one lookup (no second vocabulary)');
assert_true(!str_contains($libPhp, 'evershelfProductNumberForms'), 'lib: no second, competing number vocabulary was born');

// The directory of the two forms must be unambiguous: a plural shared by two genres
// would break the round trip (and the display could not pick one).
$dupes = [];
$seen  = [];
foreach (evershelfProductKindNumberForms() as $canonical => $forms) {
    foreach ([productKindFoldWord((string)$canonical), productKindFoldWord($forms[0]), productKindFoldWord($forms[1])] as $fold) {
        if ($fold === '') {
            continue;
        }
        if (isset($seen[$fold]) && $seen[$fold] !== $canonical) {
            $dupes[] = $fold;
        }
        $seen[$fold] = $canonical;
    }
}
assert_same([], $dupes, 'directory: no form belongs to two genres');

echo "\n" . ($fail === 0 ? 'All product-number tests passed.' : $fail . ' test(s) failed.') . "\n";
exit($fail === 0 ? 0 : 1);

