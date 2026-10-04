#!/usr/bin/env php
<?php
/**
 * Regression tests: recipe → shopping list with pantry deduction (api/lib/recipe_shopping.php).
 *
 * Locks down the quantity arithmetic that decides what is actually missing:
 *   need (recipe, normalised to g/ml/pz) − have (in-stock rows of the same family)
 * and the rules that keep the result polite: container units are expanded with the
 * product package, stock held in another unit is never nagged about, free staples
 * (salt/pepper/oil/water) are never nagged about, and an ingredient already on the
 * shopping list is reported as such instead of being added twice.
 *
 * Runs on an isolated tmp-file SQLite fixture — the production DB is never touched.
 * The live-insert section needs the HA webhook off (it would post a fake
 * `shopping_add` event to Home Assistant); it is skipped when HA_ENABLED=true.
 *
 * Run: php scripts/test-recipe-shopping.php
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
    assert_true(
        $expected === $actual,
        $msg . ' (got ' . var_export($actual, true) . ', expected ' . var_export($expected, true) . ')'
    );
}

function assert_near(float $a, float $b, float $eps, string $msg): void
{
    assert_true(abs($a - $b) <= $eps, $msg . " (got {$a}, expected ~{$b})");
}

/** Compare a parsed recipe quantity without dumping the whole array on failure. */
function assert_qty(string $input, float $qty, string $unit, string $msg): void
{
    $got = evershelfParseRecipeQty($input);
    assert_true(
        abs($got['qty'] - $qty) < 0.001 && $got['unit'] === $unit,
        $msg . " (got {$got['qty']} {$got['unit']}, expected {$qty} {$unit})"
    );
}

// ── Unit grammar ────────────────────────────────────────────────────────────
assert_same('g', evershelfUnitFamily('kg'), 'kg belongs to the mass family');
assert_same('g', evershelfUnitFamily('Grammi'), 'case-insensitive unit names');
assert_same('ml', evershelfUnitFamily('l'), 'l belongs to the volume family');
assert_same('pz', evershelfUnitFamily('pz'), 'pz is the count family');
assert_same('', evershelfUnitFamily('conf'), 'container units are not a measured family');

assert_near(evershelfUnitFactor('kg'), 1000.0, 0.001, 'kg → g factor');
assert_near(evershelfUnitFactor('etto'), 100.0, 0.001, 'etto → g factor');

// Container amount expands with the product package: 2 conf × 700 g = 1400 g.
$boxed = evershelfBaseQty(2, 'conf', 700, 'g');
assert_same('g', $boxed['family'], 'container expanded through package_unit');
assert_near($boxed['qty'], 1400.0, 0.001, '2 conf × 700 g = 1400 g');
$unknown = evershelfBaseQty(3, 'pz', 0, '');
assert_same('pz', $unknown['family'], 'unknown package falls back to pieces');
assert_near($unknown['qty'], 3.0, 0.001, 'pieces stay pieces');

// ── Labels ──────────────────────────────────────────────────────────────────
assert_same('300 g', evershelfBaseLabel(300, 'g'), 'grams label');
assert_same('1.5 kg', evershelfBaseLabel(1500, 'g'), 'kilos above 1000 g');
assert_same('1 l', evershelfBaseLabel(1000, 'ml'), 'litres above 1000 ml');
assert_same('2 pz', evershelfBaseLabel(2, 'pz'), 'pieces label');
assert_same('', evershelfBaseLabel(0, 'g'), 'no amount, no label');

// ── Recipe quantity parsing (same grammar as the JS _parseRecipeQtyString) ───
assert_qty('500 g', 500.0, 'g', 'plain grams');
assert_qty('1,5 kg', 1500.0, 'g', 'comma decimal + kg');
assert_qty('0.5 l', 500.0, 'ml', 'litres → ml');
assert_qty('2 pz', 2.0, 'pz', 'pieces');
assert_qty('q.b.', 0.0, '', 'no amount means no amount');
assert_qty('1 conf', 1.0, 'conf', 'container keeps its own unit');


// ── Isolated fixture (tmp file, never the production DB) ────────────────────
$dbFile = sys_get_temp_dir() . '/evershelf_test_recipe_shopping.sqlite';
register_shutdown_function(static function () use ($dbFile): void { @unlink($dbFile); });
@unlink($dbFile);
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("
    CREATE TABLE products (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, brand TEXT DEFAULT '', category TEXT DEFAULT '',
        unit TEXT DEFAULT 'pz', default_quantity REAL DEFAULT 1, shopping_name TEXT DEFAULT '',
        package_unit TEXT DEFAULT ''
    );
    -- Mirror the real schema: inventory has NO `unit` column, the unit lives on the product.
    CREATE TABLE inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL,
        location TEXT NOT NULL DEFAULT 'dispensa', quantity REAL NOT NULL DEFAULT 1, expiry_date DATE
    );
    CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '', updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE shopping_list (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, raw_name TEXT NOT NULL DEFAULT '',
        specification TEXT NOT NULL DEFAULT '', added_at INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0
    );
");
$db->exec("INSERT INTO products (id, name, unit, default_quantity, shopping_name, package_unit) VALUES
    (1, 'Pasta di semola', 'g', 500, 'Pasta', 'g'),
    (2, 'Ricotta vaccina', 'pz', 250, 'Ricotta', 'g'),
    (3, 'Farina 00', 'g', 1000, 'Farina', 'g'),
    (4, 'Uova fresche', 'pz', 6, 'Uova', ''),
    (5, 'Ceci secchi', 'g', 500, 'Ceci', 'g'),
    (6, 'Mozzarella', 'pz', 0, 'Mozzarella', '')");
$db->exec("INSERT INTO inventory (product_id, location, quantity) VALUES
    (1, 'dispensa', 200),
    (2, 'frigo', 3),
    (4, 'frigo', 6),
    (5, 'dispensa', 100),
    (6, 'frigo', 2)");
$db->exec("INSERT INTO shopping_list (name, raw_name, specification) VALUES ('Farina', 'Farina 00', 'Il mio appunto')");

$recipe = [
    'title' => 'Test',
    'persons' => 2,
    'ingredients' => [
        ['name' => 'Pasta di semola', 'qty' => '500 g', 'qty_number' => 500, 'from_pantry' => true, 'product_id' => 1],
        ['name' => 'Ricotta vaccina', 'qty' => '250 g', 'from_pantry' => true, 'product_id' => 2],
        ['name' => 'Farina 00', 'qty' => '1 kg', 'qty_number' => 1000, 'from_pantry' => true, 'product_id' => 3],
        ['name' => 'Uova fresche', 'qty' => '2 pz', 'from_pantry' => true, 'product_id' => 4],
        ['name' => 'Mozzarella', 'qty' => '250 g', 'from_pantry' => true, 'product_id' => 6],
        ['name' => 'Sale fino', 'qty' => 'q.b.'],
    ],
    'shopping_suggestions' => [
        ['name' => 'Parmigiano Reggiano', 'qty' => '150 g', 'reason' => 'not_in_pantry'],
        ['name' => 'Ceci secchi', 'qty' => '300 g', 'reason' => 'not_in_pantry'],
    ],
];

$plan   = evershelfRecipeShoppingPlan($db, $recipe, ['dry_run' => true, 'lang' => 'it']);
$byName = [];
foreach ($plan['items'] as $item) {
    $byName[$item['name']] = $item;
}

// 500 g asked, 200 g in stock → buy only the difference.
assert_same('partial', $byName['Pasta di semola']['state'], 'partly stocked ingredient is partial');
assert_same('500 g', $byName['Pasta di semola']['need'], 'need is labelled from the recipe text');
assert_same('200 g', $byName['Pasta di semola']['have'], 'have is the matched in-stock amount');
assert_same('300 g', $byName['Pasta di semola']['missing'], 'missing is the gap only');
assert_same('Da ricetta · 300 g', $byName['Pasta di semola']['specification'], 'gap is what would be added');
assert_same(1, $byName['Pasta di semola']['product_id'], 'pantry row matched by product_id');

// 250 g asked, 3 pz × 250 g in the fridge → nothing to buy (container expansion).
assert_same('covered', $byName['Ricotta vaccina']['state'], 'container stock covers a gram need');
assert_same('750 g', $byName['Ricotta vaccina']['have'], '3 pz ricotta counted as 750 g');
assert_same('', $byName['Ricotta vaccina']['missing'], 'covered ingredient has no gap');

// 2 pz asked, 6 pz in stock → covered in the count family.
assert_same('covered', $byName['Uova fresche']['state'], 'piece need covered by piece stock');

// 250 g asked, stock held in pieces with no package size → covered, never nagged.
assert_same('covered', $byName['Mozzarella']['state'], 'uncomparable stock is never reported as missing');

// Already on the list → reported as such, never added twice.
assert_same('listed', $byName['Farina 00']['state'], 'ingredient already on the list is flagged');
assert_true($byName['Farina 00']['in_list'], 'in_list flag set for existing row');
assert_same('Da ricetta · 1 kg', $byName['Farina 00']['specification'], 'planned spec still describes the need');

// Free staples are never "missing" (strict pantry mode does not track salt).
assert_same('covered', $byName['Sale fino']['state'], 'free staple is never missing');
assert_true($byName['Sale fino']['staple'], 'staple flag set');

// Not in pantry at all → the whole need is missing.
assert_same('missing', $byName['Parmigiano Reggiano']['state'], 'unknown ingredient is fully missing');
assert_same('150 g', $byName['Parmigiano Reggiano']['missing'], 'nothing in stock → whole need');
assert_same('Da ricetta · 150 g', $byName['Parmigiano Reggiano']['specification'], 'missing ingredient spec');

// Suggestion with partial stock → only the gap, even though the AI said "not in pantry".
assert_same('partial', $byName['Ceci secchi']['state'], 'stale AI suggestion recomputed against real stock');
assert_same('200 g', $byName['Ceci secchi']['missing'], '300 g asked − 100 g in stock = 200 g');

// Dry run must not write anything.
assert_same(1, (int)$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn(), 'dry run adds no row');

// ── Real insert (only the gap, only selected rows) ──────────────────────────
if (env('HA_ENABLED', 'false') === 'true') {
    echo "SKIP: live insert assertions need HA_ENABLED=false in .env "
        . "(the HA webhook would post a fake shopping_add event).\n";
} else {
    evershelfRecipeShoppingPlan($db, $recipe, ['lang' => 'it']);
    $rows  = $db->query('SELECT name, raw_name, specification FROM shopping_list ORDER BY id')->fetchAll();
    $spec  = [];
    foreach ($rows as $row) {
        $spec[mb_strtolower($row['name'])] = $row['specification'];
    }

    // Only the three real gaps, each carrying just the deducted amount.
    assert_same('Da ricetta · 300 g', $spec['pasta'] ?? '', 'partly stocked ingredient added as the gap only');
    assert_same('Da ricetta · 150 g', $spec['parmigiano'] ?? '', 'missing ingredient added under its generic name');
    assert_same('Da ricetta · 200 g', $spec['ceci'] ?? '', 'partial suggestion added as the gap only');

    // Covered / on-list / staple rows are never written.
    assert_true(!isset($spec['ricotta']), 'covered container stock not added');
    assert_true(!isset($spec['uova']), 'covered piece need not added');
    assert_true(!isset($spec['sale']), 'staple not added');
    assert_same('Il mio appunto', $spec['farina'] ?? '', 'existing row left untouched');
    assert_same(
        1,
        count(array_filter($rows, static fn(array $r): bool => mb_strtolower($r['name']) === 'farina')),
        'on-list ingredient not duplicated'
    );
    assert_same(4, (int)$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn(), 'seed row + exactly three gaps');

    // Selecting a single ingredient writes only that one.
    $only = evershelfRecipeShoppingPlan($db, $recipe, ['lang' => 'it', 'selected' => ['Parmigiano Reggiano']]);
    $onlyItem = null;
    foreach ($only['items'] as $it) {
        if ($it['name'] === 'Parmigiano Reggiano') {
            $onlyItem = $it;
        }
    }
    assert_same(1, (int)($only['summary']['selected'] ?? 0), 'selection narrows the plan to one item');
    assert_same(0, (int)$only['summary']['added'], 'already-added item is not re-inserted');
    assert_same('listed', $onlyItem['state'] ?? '', 'previously added item now reports as listed');

    // Ignoring the pantry on purpose must buy the whole need.
    $full = evershelfRecipeShoppingPlan($db, $recipe, ['lang' => 'it', 'only_missing' => false, 'selected' => ['Uova fresche']]);
    $fullItem = null;
    foreach ($full['items'] as $it) {
        if ($it['name'] === 'Uova fresche') {
            $fullItem = $it;
        }
    }
    assert_same('Da ricetta · 2 pz', $fullItem['specification'] ?? '', 'only_missing=false adds the full recipe amount');
}

echo $fail === 0 ? "\nAll recipe-shopping tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);

