#!/usr/bin/env php
<?php
/**
 * Regression tests: the produce seasonality matcher must anchor on the head noun.
 *
 * Regression history: the first implementation used substring matching, so
 * "Cosce di pollo" matched "cipollotto", "Italia Zuccheri" matched "zucca" and
 * "Budino gusto vaniglia da zuccherare" matched "zucca" — the review card told
 * users to remove products that had nothing to do with produce. Frozen/canned
 * goods were flagged too, although seasonality is a property of fresh produce.
 *
 * Run: php scripts/test-seasonal-match.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/lib/seasonal.php';

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
    $ok = $expected === $actual;
    assert_true($ok, $ok
        ? $msg
        : $msg . ' (got ' . var_export($actual, true) . ', expected ' . var_export($expected, true) . ')');
}

function assert_null($actual, string $msg): void
{
    assert_true($actual === null, $msg . ($actual === null ? '' : ' (got ' . var_export($actual['item']['name'] ?? $actual, true) . ')'));
}

// ── Word-boundary helper ────────────────────────────────────────────────────
assert_true(seasonalStartsWithWord('melone retato', 'melone'), 'prefix match on a word boundary');
assert_true(seasonalStartsWithWord('zucca', 'zucca'), 'exact word is a prefix match');
assert_true(!seasonalStartsWithWord('melograno', 'melo'), 'partial word is not a prefix match');
assert_true(!seasonalStartsWithWord('cipollotto', 'pollo'), 'substring is not a prefix match');

// ── Head noun extraction ────────────────────────────────────────────────────
assert_same('cosce', seasonalHeadToken(seasonalNormalize('Cosce di pollo · Fileni')), 'head noun of "Cosce di pollo"');
assert_same('budino', seasonalHeadToken(seasonalNormalize('Budino gusto vaniglia da zuccherare')), 'head noun skips "gusto"');
assert_same('italia', seasonalHeadToken(seasonalNormalize('Italia Zuccheri 100% Italiano')), 'head noun skips numbers');
assert_same('miele', seasonalHeadToken(seasonalNormalize('Miele di arancia')), 'head noun of "Miele di arancia"');

// ── Preserved produce is never seasonal ─────────────────────────────────────
assert_true(seasonalIsPreserved('Basilico tritato surgelato'), 'frozen basil is preserved');
assert_true(seasonalIsPreserved('Spinaci in cubetti surgelati'), 'frozen spinach cubes are preserved');
assert_true(seasonalIsPreserved('Pomodori pelati'), 'peeled tomatoes are preserved');
assert_true(!seasonalIsPreserved('Basilico fresco'), 'fresh basil is not preserved');
assert_null(seasonalMatchProduce('Basilico tritato surgelato', 1), 'frozen basil is not matched in January');
assert_null(seasonalMatchProduce('Spinaci in cubetti surgelati', 8), 'frozen spinach is not matched in August');
assert_null(seasonalMatchProduce('Pomodori pelati', 1), 'canned tomatoes are not matched in January');

// ── False positives that must stay dead (October) ───────────────────────────
$falsePositives = [
    'Cosce di pollo · Fileni',
    'Budino gusto vaniglia da zuccherare',
    'Italia Zuccheri 100% Italiano',
    'Miele di arancia',
    'Formaggio pepe e cipolla',
    'Olive verdi denocciolate',
    'Tarallini · Eurospin',
    'Fior Di Panna',
];
foreach ($falsePositives as $name) {
    assert_null(seasonalMatchProduce($name, 10), 'no produce match for "' . $name . '"');
}

// ── Real produce still matches, and the month decides the status ─────────────
$anguriaOct = seasonalMatchProduce('Anguria', 10);
assert_true($anguriaOct !== null && $anguriaOct['status'] === 'off', 'Anguria is out of season in October');
$anguriaJul = seasonalMatchProduce('Anguria', 7);
assert_true($anguriaJul !== null && $anguriaJul['status'] === 'peak', 'Anguria is in season in July');

$melone = seasonalMatchProduce('Melone', 10);
assert_true($melone !== null && $melone['status'] === 'off', 'Melone is out of season in October');
$meloneJul = seasonalMatchProduce('Melone', 7);
assert_true($meloneJul !== null && $meloneJul['status'] === 'peak', 'Melone is in season in July');

$zucca = seasonalMatchProduce('Zucca a pezzi', 10);
assert_true($zucca !== null && $zucca['status'] === 'peak', 'Zucca is in season in October');
$zuccaJul = seasonalMatchProduce('Zucca a pezzi', 7);
assert_true($zuccaJul !== null && $zuccaJul['status'] === 'off', 'Zucca is out of season in July');

$pesca = seasonalMatchProduce('Pesca e Nettarina di Romagna IGP', 10);
assert_true($pesca !== null && $pesca['status'] === 'off', 'Peaches are out of season in October');

$carote = seasonalMatchProduce('Carote Cat. I', 10);
assert_true($carote !== null && $carote['status'] === 'peak', 'Carrots are in season in October');

assert_same('off', seasonalStatusForName('Anguria', 10), 'seasonalStatusForName follows the month');
assert_same('unknown', seasonalStatusForName('Sambuca extra', 10), 'a spirit is not produce');
assert_same('unknown', seasonalStatusForName('', 10), 'an empty name is not produce');

// ── Canned pulp vs a fresh variety described by its pulp ────────────────────
assert_true(seasonalIsPreserved('Polpa di pomodoro'), 'tomato pulp is a preserve');
assert_true(seasonalIsPreserved('Purea di mele'), 'apple puree is a preserve');
assert_true(!seasonalIsPreserved('Pesca noce piatta a polpa gialla'), 'a yellow-fleshed peach is fresh');
$pescaNoce = seasonalMatchProduce('Pesca noce piatta a polpa gialla', 10);
assert_true($pescaNoce !== null && $pescaNoce['status'] === 'off', 'flat peaches are out of season in October');

// ── Fresh-produce category gate (shopping-list suppression) ─────────────────
assert_true(seasonalIsFreshCategory('frutta'), 'frutta is a fresh category');
assert_true(seasonalIsFreshCategory('Verdura'), 'verdura is a fresh category');
assert_true(seasonalIsFreshCategory('en:vegetables-and-their-products'), 'an Open Food Facts vegetable slug is fresh');
assert_true(!seasonalIsFreshCategory(''), 'a missing category is not fresh');
assert_true(!seasonalIsFreshCategory('latticini'), 'dairy is not fresh produce');
assert_true(!seasonalIsFreshCategory('en:plant-based-foods-and-beverages'), 'a plant-based pantry shelf is not fresh produce');
assert_true(!seasonalIsFreshCategory('conserve'), 'preserves are not fresh produce');

assert_true(seasonalProduceOutOfSeason('Anguria', 'frutta', 10), 'watermelon is hidden in October');
assert_true(!seasonalProduceOutOfSeason('Anguria', 'frutta', 7), 'watermelon is suggested in July');
assert_true(!seasonalProduceOutOfSeason('Anguria', 'altro', 10), 'a non-produce category is never hidden');
assert_true(!seasonalProduceOutOfSeason('Carote Cat. I', 'verdura', 10), 'in-season carrots stay');
assert_true(!seasonalProduceOutOfSeason('Origano foglie', 'en:plant-based-foods-and-beverages', 10), 'dried oregano stays all year');
assert_true(!seasonalProduceOutOfSeason('Pomodori pelati', 'conserve', 10), 'canned tomatoes stay all year');
assert_true(!seasonalProduceOutOfSeason('Cosce di pollo · Fileni', 'carne', 10), 'meat never matches the catalogue');
assert_true(seasonalProduceOutOfSeason('Pesca noce piatta a polpa gialla', 'frutta', 10), 'fresh flat peaches are hidden in October');

// ── Harvest calendar vs what the shelf actually has (cured/stored crops) ────
assert_true(seasonalIsAllYearCrop('Cipolla Dorata degli Ausoni'), 'onions are sold from storage all year');
assert_true(seasonalIsAllYearCrop('Patate Biologiche'), 'potatoes are sold from storage all year');
assert_true(seasonalIsAllYearCrop('limoni'), 'citrus keeps in cold storage all year');
assert_true(seasonalIsAllYearCrop('Pomodori a grappolo'), 'tomatoes are on the shelf all year (greenhouse)');
assert_true(!seasonalIsAllYearCrop('Melone Retato'), '"melone" is not "mela"');
assert_true(!seasonalIsAllYearCrop('Anguria'), 'watermelon is not a stored crop');
assert_true(!seasonalIsAllYearCrop(''), 'an empty name is not a stored crop');
assert_true(!seasonalProduceOutOfSeason('Cipolla Dorata degli Ausoni', 'verdura', 10), 'onions stay in October');
assert_true(!seasonalProduceOutOfSeason('Arance I Succosi', 'frutta', 10), 'oranges stay in October');

// ── All-year crops must not swallow the summer vegetables ───────────────────
// Head-noun prefixes ate the neighbours: "mel[ae]" made "Melanzane" a stored
// crop, "zucc[ah]" made "Zucchine" one and "rap[ae]" made "Rapanelli" one — the
// very produce the winter list exists to hide was exempted from hiding.
assert_true(!seasonalIsAllYearCrop('Melanzane'), 'eggplant is not a stored all-year crop');
assert_true(!seasonalIsAllYearCrop('Melanzane Lunghe'), 'long eggplants are not a stored all-year crop');
assert_true(!seasonalIsAllYearCrop('Zucchine'), 'zucchini are not a stored all-year crop');
assert_true(!seasonalIsAllYearCrop('Zucchine Bio'), 'organic zucchini are not a stored all-year crop');
assert_true(!seasonalIsAllYearCrop('Rapanelli'), 'radishes are not a stored all-year crop');
assert_true(seasonalIsAllYearCrop('Zucche'), 'the plural "zucche" is still a stored crop');
assert_true(seasonalIsAllYearCrop('Zucca Delica'), 'pumpkin is a stored crop');
assert_true(seasonalIsAllYearCrop('Mele Fuji'), 'stored apples are an all-year crop');
assert_true(seasonalIsAllYearCrop('Pere Abate'), 'stored pears are an all-year crop');
assert_true(seasonalIsAllYearCrop('Rape Rosse'), 'stored turnips are an all-year crop');

// ── Sugar is not a pumpkin: a tail of one letter may inflect, two may not ────
assert_null(seasonalMatchProduce('Zucchero', 10), 'sugar is not a pumpkin');
assert_null(seasonalMatchProduce('Zuccheri', 10), 'sugars are not pumpkins');
assert_null(seasonalMatchProduce('Italia Zuccheri 100% Italiano', 10), 'a sugar brand is not a pumpkin');
assert_true(seasonalMatchProduce('Zucca Delica', 10) !== null, 'pumpkin still matches its own name');

// ── The review card must not contradict the smart list it reviews ────────────
$cardMonth = (int)date('n');
$cardDb = new PDO('sqlite::memory:');
$cardNames = ['Anguria', 'Cipolla Dorata degli Ausoni', 'Zucchero', 'Melanzane Lunghe', 'Origano foglie'];
$card = seasonalReviewShopping($cardDb, array_map(static fn(string $n): array => ['name' => $n], $cardNames));
$cardFlagged = array_column($card['out_of_season'], 'name');
foreach ($cardNames as $cardName) {
    $listHides = seasonalProduceOutOfSeason($cardName, 'verdura', $cardMonth);
    assert_true(!in_array($cardName, $cardFlagged, true) || $listHides,
        'the card agrees with the list about "' . $cardName . '"');
}
assert_true(!in_array('Cipolla Dorata degli Ausoni', $cardFlagged, true), 'the card does not ask to remove stored onions');
assert_true(!in_array('Zucchero', $cardFlagged, true), 'the card never asks to remove sugar');
$hideable = array_values(array_filter($cardNames, static fn(string $n): bool => seasonalProduceOutOfSeason($n, 'verdura', $cardMonth)));
assert_same($hideable, array_values(array_intersect($cardFlagged, $hideable)),
    'the card flags every catalogue entry the list would hide in month ' . $cardMonth);
assert_same('shopping.seasonal_tip_' . $cardMonth, $card['tip_key'], 'the card returns a stable i18n tip key');

// ── Every tip key the API can emit exists in all six locales ────────────────
// The i18n audit only sees keys used in JS; keys returned by PHP are covered here.
$localeKeys = ['shopping.seasonal_out_note'];
for ($m = 1; $m <= 12; $m++) {
    $localeKeys[] = 'shopping.seasonal_tip_' . $m;
}
$missingKeys = [];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $locale) {
    $raw = @file_get_contents(__DIR__ . '/../translations/' . $locale . '.json');
    $data = $raw === false ? [] : (json_decode($raw, true) ?: []);
    foreach ($localeKeys as $key) {
        $node = $data;
        foreach (explode('.', $key) as $part) {
            $node = is_array($node) ? ($node[$part] ?? null) : null;
        }
        if (!is_string($node) || $node === '') {
            $missingKeys[] = $locale . ':' . $key;
        }
    }
}
assert_true($missingKeys === [],
    'every monthly tip key and the note exist in all six locales'
    . ($missingKeys ? ' (missing ' . implode(', ', array_slice($missingKeys, 0, 5)) . ')' : ''));

echo $fail === 0 ? "\nAll seasonal match tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
