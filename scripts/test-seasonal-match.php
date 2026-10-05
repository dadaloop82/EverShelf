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

echo $fail === 0 ? "\nAll seasonal match tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
