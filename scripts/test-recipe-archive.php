#!/usr/bin/env php
<?php
/**
 * Regression: recipe archive dedup by content hash; scheduled slots still replace per day.
 *
 * Run: php scripts/test-recipe-archive.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/database.php';
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

function assert_same($a, $b, string $msg): void
{
    assert_true($a === $b, $msg . ' (got ' . var_export($a, true) . ', expected ' . var_export($b, true) . ')');
}

$tmp = tempnam(sys_get_temp_dir(), 'evs_rec_');
if ($tmp === false) {
    fwrite(STDERR, "Could not create temp DB path\n");
    exit(1);
}
unlink($tmp);
$dbPath = $tmp . '.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
initializeDB($db);
migrateDB($db);

$today = '2099-01-15';

$r1 = ['title' => 'Pasta al pomodoro', 'persons' => 2, 'ingredients' => [['name' => 'Pasta', 'qty' => '100 g']], 'steps' => ['Cuoci']];
$id1 = recipesArchiveUpsert($db, $r1, '', $today);
$r2 = ['title' => 'Risotto ai funghi', 'persons' => 2, 'ingredients' => [['name' => 'Riso', 'qty' => '80 g']], 'steps' => ['Cuoci']];
$id2 = recipesArchiveUpsert($db, $r2, '', $today);

assert_true($id1 > 0 && $id2 > 0, 'two distinct recipes return ids');
assert_true($id1 !== $id2, 'different bodies get distinct rows');

$count = (int)$db->query("SELECT COUNT(*) FROM recipes WHERE date = '{$today}' AND meal = 'libero'")->fetchColumn();
assert_same(2, $count, 'two different libero recipes stored');

// Same body again (e.g. chat reopen / ingredient used flag) must not clone
$r1used = $r1;
$r1used['ingredients'][0]['used'] = true;
$r1used['ingredients'][0]['qty_number'] = 180; // persons/scale drift must not create a clone
$id1b = recipesArchiveUpsert($db, $r1used, '', $today);
assert_same($id1, $id1b, 'same content hash updates existing row');
$count2 = (int)$db->query("SELECT COUNT(*) FROM recipes WHERE date = '{$today}' AND meal = 'libero'")->fetchColumn();
assert_same(2, $count2, 'no clone after re-save with used flag');

$row = json_decode((string)$db->query("SELECT recipe_json FROM recipes WHERE id = {$id1}")->fetchColumn(), true);
assert_true(!empty($row['ingredients'][0]['used']), 'used flag persisted on update');

$hash = (string)$db->query("SELECT content_hash FROM recipes WHERE id = {$id1}")->fetchColumn();
assert_true(strlen($hash) === 64, 'content_hash is sha256 hex');

// Same title with rewritten steps still collapses for libero
$r1clone = $r1;
$r1clone['steps'] = ['Versione diversa del procedimento'];
$id1c = recipesArchiveUpsert($db, $r1clone, '', $today);
assert_same($id1, $id1c, 'same title libero updates existing row');
assert_same(2, (int)$db->query("SELECT COUNT(*) FROM recipes WHERE date = '{$today}' AND meal = 'libero'")->fetchColumn(), 'no clone after title match');

$pranzoA = ['title' => 'Primo pranzo', 'persons' => 2, 'ingredients' => [], 'steps' => ['A']];
$idP1 = recipesArchiveUpsert($db, $pranzoA, 'pranzo', $today);
$pranzoB = ['title' => 'Pranzo aggiornato', 'persons' => 2, 'ingredients' => [], 'steps' => ['B']];
$idP2 = recipesArchiveUpsert($db, $pranzoB, 'pranzo', $today);
assert_same($idP1, $idP2, 'same pranzo slot updates same row');

$decoded = json_decode((string)$db->query("SELECT recipe_json FROM recipes WHERE id = {$idP2}")->fetchColumn(), true);
assert_same('Pranzo aggiornato', $decoded['title'] ?? '', 'pranzo slot was overwritten not duplicated');

$bad = ['title' => 'libero', 'persons' => 2, 'ingredients' => [['name' => 'Zucchine', 'qty' => '2 pz']], 'steps' => []];
$source = "Crema di zucchine\n\nIngredienti\n- zucchine";
recipeNormalizeArchiveMetadata($bad, $source);
assert_same('Crema di zucchine', $bad['title'] ?? '', 'placeholder title replaced from chat text');

@unlink($dbPath);
exit($fail > 0 ? 1 : 0);
