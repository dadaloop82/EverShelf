#!/usr/bin/env php
<?php
/**
 * Regression tests: the products the household uses most become favourites on their
 * own — and the automatic rule never fights the user.
 *
 * Rules under test (api/lib/auto_favorite.php):
 *   * a product consumed AUTO_FAVORITE_MIN_USES times in the window is promoted,
 *   * only real consumption counts ('out', not undone, inside AUTO_FAVORITE_WINDOW_DAYS),
 *   * a manual unstar vetoes the product for good (favorite_user_override),
 *   * the rule only ever ADDS favourites.
 *
 * Hermetic: runs on an in-memory SQLite database, never on data/evershelf.db.
 *
 * Run: php scripts/test-auto-favorite.php
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

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
initializeDB($db);
migrateDB($db);

function mkProduct(PDO $db, string $name, int $favorite = 0, int $override = 0): int
{
    $db->prepare('INSERT INTO products (name, is_favorite, favorite_user_override) VALUES (?, ?, ?)')
       ->execute([$name, $favorite, $override]);
    return (int)$db->lastInsertId();
}

function addTx(PDO $db, int $productId, string $type, int $daysAgo, int $undone = 0): void
{
    $db->prepare(
        "INSERT INTO transactions (product_id, type, quantity, location, undone, created_at)
         VALUES (?, ?, 1, 'dispensa', ?, datetime('now', ?))"
    )->execute([$productId, $type, $undone, '-' . $daysAgo . ' days']);
}

function isFavorite(PDO $db, int $productId): int
{
    return (int)$db->query("SELECT COALESCE(is_favorite, 0) FROM products WHERE id = {$productId}")->fetchColumn();
}

$min  = autoFavoriteMinUses();
$days = autoFavoriteWindowDays();

// ── Defaults (only when the install has not overridden them) ────────────────
$envKeys = loadEnv();
if (!array_key_exists('AUTO_FAVORITE_MIN_USES', $envKeys)) {
    assert_same(3, $min, 'default: AUTO_FAVORITE_MIN_USES is 3');
}
if (!array_key_exists('AUTO_FAVORITE_WINDOW_DAYS', $envKeys)) {
    assert_same(90, $days, 'default: AUTO_FAVORITE_WINDOW_DAYS is 90');
}
assert_true($days >= 1, 'window: at least one day');

if ($min === 0) {
    echo "SKIP: AUTO_FAVORITE_MIN_USES=0 on this install — behaviour tests need the rule enabled\n";
    echo $fail === 0 ? "\nAll auto-favourite tests passed.\n" : "\n{$fail} test(s) failed.\n";
    exit($fail === 0 ? 0 : 1);
}

// ── Threshold: one use short is not enough, the threshold is ────────────────
$almost = mkProduct($db, 'Yogurt Greco quasi');
for ($i = 0; $i < max(1, $min - 1); $i++) {
    addTx($db, $almost, 'out', 1);
}
assert_same(max(1, $min - 1), autoFavoriteUseCount($db, $almost), 'count: only type=out rows inside the window count');
assert_true(maybeAutoFavorite($db, $almost) === false, 'threshold: one use below the threshold does not promote');
assert_same(0, isFavorite($db, $almost), 'threshold: the product is still not a favourite');

$reached = mkProduct($db, 'Yogurt Greco usato');
for ($i = 0; $i < $min; $i++) {
    addTx($db, $reached, 'out', 2);
}
assert_true(maybeAutoFavorite($db, $reached) === true, 'threshold: reaching the threshold promotes to favourite');
assert_same(1, isFavorite($db, $reached), 'threshold: the star is stored');
assert_true(maybeAutoFavorite($db, $reached) === false, 'idempotent: an existing favourite is left alone');

// ── Only real consumption counts ───────────────────────────────────────────
$restocked = mkProduct($db, 'Latte solo acquistato');
for ($i = 0; $i < $min; $i++) {
    addTx($db, $restocked, 'in', 1);
}
assert_same(0, autoFavoriteUseCount($db, $restocked), 'count: purchases (type=in) are not usage');
assert_true(maybeAutoFavorite($db, $restocked) === false, 'count: buying a product does not make it a favourite');

$wasted = mkProduct($db, 'Insalata buttata');
for ($i = 0; $i < $min; $i++) {
    addTx($db, $wasted, 'waste', 1);
}
assert_true(maybeAutoFavorite($db, $wasted) === false, 'count: waste is not usage');

$undone = mkProduct($db, 'Pasta annullata');
for ($i = 0; $i < $min + 2; $i++) {
    addTx($db, $undone, 'out', 1, 1);
}
assert_true(maybeAutoFavorite($db, $undone) === false, 'count: undone transactions are ignored');

$stale = mkProduct($db, 'Farina vecchia');
for ($i = 0; $i < $min + 2; $i++) {
    addTx($db, $stale, 'out', $days + 10);
}
assert_same(0, autoFavoriteUseCount($db, $stale), 'count: uses older than the window are ignored');
assert_true(maybeAutoFavorite($db, $stale) === false, 'window: an old habit does not promote today');

// ── The user always wins ───────────────────────────────────────────────────
$vetoed = mkProduct($db, 'Burro tolto dai preferiti');
for ($i = 0; $i < $min + 5; $i++) {
    addTx($db, $vetoed, 'out', 1);
}
rememberFavoriteOverride($db, $vetoed, false);
assert_true(maybeAutoFavorite($db, $vetoed) === false, 'veto: a manual unstar stops the automatic rule');
assert_same(0, isFavorite($db, $vetoed), 'veto: the product stays out of the favourites');

$manual = mkProduct($db, 'Olio messo a mano', 1, 0);
rememberFavoriteOverride($db, $manual, true);
assert_same(0, (int)$db->query("SELECT COALESCE(favorite_user_override, 0) FROM products WHERE id = {$manual}")->fetchColumn(),
    'override: starring by hand clears the veto');
rememberFavoriteOverride($db, $manual, false);
assert_same(1, (int)$db->query("SELECT COALESCE(favorite_user_override, 0) FROM products WHERE id = {$manual}")->fetchColumn(),
    'override: unstarring by hand records the veto');
assert_true(autoFavoriteUseCount($db, $manual) === 0, 'override: a star without uses stays a manual decision');

// ── Sweep (the maintenance action) ─────────────────────────────────────────
$swept = mkProduct($db, 'Passata usata spesso');
for ($i = 0; $i < $min; $i++) {
    addTx($db, $swept, 'out', 3);
}
$preview = autoFavoriteSweepPreview($db);
assert_same(1, $preview, 'sweep: the dry run counts exactly the qualifying product left');
assert_same(1, autoFavoriteSweep($db), 'sweep: one product promoted in one pass');
assert_same(0, autoFavoriteSweep($db), 'sweep: a second pass has nothing left to promote');
assert_same(1, isFavorite($db, $swept), 'sweep: the frequently used product is now a favourite');

$nothingLeft = 1;
foreach ([$almost, $restocked, $wasted, $undone, $stale, $vetoed] as $id) {
    if (isFavorite($db, $id) === 1) {
        $nothingLeft = 0;
    }
}
assert_same(1, $nothingLeft, 'sweep: no product was promoted behind the user back');
assert_same(1, isFavorite($db, $reached), 'sweep: favourites are only ever added');

// ── Wiring: the hook and the settings exist ────────────────────────────────
$apiPhp = (string)file_get_contents(__DIR__ . '/../api/index.php');
$html   = (string)file_get_contents(__DIR__ . '/../index.html');
assert_true(str_contains($apiPhp, 'maybeAutoFavorite($db, (int)$productId);'), 'wiring: consuming a product runs the rule');
assert_true(str_contains($apiPhp, 'rememberFavoriteOverride($db, $id, (bool)$fav);'), 'wiring: the manual toggle records the veto');
assert_true(str_contains($apiPhp, "'auto_favorite_min_uses'"), 'wiring: the threshold is exposed to the settings page');
assert_true(str_contains($html, 'id="setting-auto-favorite-min-uses"'), 'wiring: the threshold input exists');

echo $fail === 0 ? "\nAll auto-favourite tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
