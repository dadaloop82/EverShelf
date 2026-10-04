#!/usr/bin/env php
<?php
/**
 * Regression test: internal shopping-list cleanup.
 *
 * Bug it locks down: auto-added rows stamped with a PLANNING marker
 * (🟡 "A breve" / 🔵 "Previsione") were never cleaned up, because
 * internalShoppingCleanupObsolete() only looked for the urgent markers
 * ⚡/🟠. After the user restocked a product it vanished from the smart
 * cache, but its 🟡/🔵 row stayed on the shopping list forever — the
 * list kept suggesting items that were back in abundance.
 *
 * The test also locks the family-stock guard: the row must disappear as
 * soon as any variant of the same generic family is back in stock.
 *
 * Run: php scripts/test-internal-shopping-cleanup.php
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

// ── Guard: the test only makes sense for the internal (non-Bring!) list ──────
if (isShoppingBringMode()) {
    echo "SKIP: SHOPPING_MODE=bring — internal cleanup is disabled.\n";
    exit(0);
}

$cacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
$cacheBackup = file_exists($cacheFile) ? file_get_contents($cacheFile) : null;
$lockFile = sys_get_temp_dir() . '/evershelf_test_internal_cleanup.sqlite';

$cleanup = static function () use ($cacheFile, $cacheBackup, $lockFile): void {
    if ($cacheBackup === null) {
        @unlink($cacheFile);
    } else {
        @file_put_contents($cacheFile, $cacheBackup);
    }
    @unlink($lockFile);
};
register_shutdown_function($cleanup);

// ── Minimal DB fixture (tmp file, never the production DB) ──────────────────
@unlink($lockFile);
$db = new PDO('sqlite:' . $lockFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, brand TEXT DEFAULT '', shopping_name TEXT DEFAULT '')");
$db->exec("CREATE TABLE inventory (product_id INTEGER, quantity REAL, expiry_date TEXT)");
$db->exec("CREATE TABLE shopping_list (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, raw_name TEXT DEFAULT '', specification TEXT DEFAULT '')");
// Real schema also touched by the shared predicate (blocklist / purchase history).
$db->exec("CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '', updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, type TEXT NOT NULL, quantity REAL NOT NULL, location TEXT NOT NULL DEFAULT 'dispensa', notes TEXT DEFAULT '', undone INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

$db->exec("INSERT INTO products (id, name, shopping_name) VALUES
    (1, 'Latte fresco', 'Latte'),
    (2, 'Yogurt bianco', 'Yogurt'),
    (3, 'Burro', 'Burro'),
    (4, 'Pasta', 'Pasta'),
    (5, 'Uova', 'Uova'),
    (6, 'Panna', 'Panna')");
// Only Burro has live stock (qty 3) → its family is back in abundance.
$db->exec("INSERT INTO inventory (product_id, quantity, expiry_date) VALUES (3, 3, NULL)");

// Smart cache: Latte (depleted → planning row) and Burro (depleted, but family stocked).
// Yogurt / Panna / Uova are absent → they were restocked or manually handled.
file_put_contents($cacheFile, json_encode([
    'success' => true,
    'cached_ts' => time(),
    'items' => [
        ['name' => 'Latte', 'shopping_name' => 'Latte', 'urgency' => 'medium', 'current_qty' => 0],
        ['name' => 'Burro', 'shopping_name' => 'Burro', 'urgency' => 'high', 'current_qty' => 0],
    ],
], JSON_UNESCAPED_UNICODE));

$db->exec("INSERT INTO shopping_list (id, name, raw_name, specification) VALUES
    (1, 'Latte',  'Latte',  '🟡 A breve · 🛒 2 l'),
    (2, 'Yogurt', 'Yogurt', '🔵 Previsione'),
    (3, 'Burro',  'Burro',  '🟠 Presto'),
    (4, 'Pasta',  'Pasta',  'Il mio appunto'),
    (5, 'Uova',   'Uova',   '⚡ Urgente · 🛒 Esaurito'),
    (6, 'Panna',  'Panna',  '🟡 A breve')");

$result = internalShoppingCleanupObsolete($db);

$remaining = $db->query("SELECT name FROM shopping_list ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

assert_true(in_array('Latte', $remaining, true), 'kept: still-depleted item present in smart cache');
assert_true(in_array('Pasta', $remaining, true), 'kept: user row without EverShelf marker');
assert_true(in_array('Uova', $remaining, true), 'kept: row explicitly marked "🛒 Esaurito"');
assert_true(!in_array('Yogurt', $remaining, true), 'removed: stale 🔵 Previsione row after restock (the reported bug)');
assert_true(!in_array('Panna', $remaining, true), 'removed: stale 🟡 A breve row after restock (the reported bug)');
assert_true(!in_array('Burro', $remaining, true), 'removed: family back in stock overrides sibling urgency');
assert_true((int)($result['removed'] ?? 0) === 3, 'removed counter matches (' . (int)($result['removed'] ?? 0) . ')');

// Emulate the pre-fix behaviour (markers ⚡/🟠 only) to prove the regression window.
$onlyUrgent = static function (string $spec): bool {
    foreach (['⚡', '🟠'] as $m) {
        if (mb_strpos($spec, $m) !== false) {
            return true;
        }
    }
    return false;
};
assert_true(!$onlyUrgent('🔵 Previsione') && !$onlyUrgent('🟡 A breve'), 'pre-fix logic would have left 🟡/🔵 rows untouched');

// ── Round-trip lock: every spec the auto-add can build must be recognised by the
// cleanup, otherwise a row can be added and never removed (the B1 class of bug).
$sampleRow = static fn(string $urgency, float $qty): array => [
    'name'          => 'Latte fresco',
    'shopping_name' => 'Latte',
    'brand'         => 'Granarolo',
    'urgency'       => $urgency,
    'current_qty'   => $qty,
    'suggested_qty' => 2,
    'unit'          => 'l',
];
foreach (['critical', 'high', 'medium', 'low'] as $u) {
    $spec = evershelfBuildShoppingSpec($sampleRow($u, 0.0));
    assert_true(evershelfSpecIsAppManaged($spec), "round-trip: {$u} spec is recognised as EverShelf-owned ({$spec})");
}
assert_true(
    array_values(array_unique(EVERSHELF_SYNC_MARKERS)) === ['⚡', '🟠', '🟡', '🔵', '🛒'],
    'single shared marker list used by both transports'
);
assert_true(evershelfSpecIsDeliberate('⚡ Urgente · 🛒 Esaurito'), 'deliberate "finished" rows are protected');
assert_true(!evershelfSpecIsAppManaged('Il mio appunto'), 'user rows are never treated as app-managed');

// The shared predicate must drop a stale row even when the smart cache still lists a
// sibling variant of the same family (family-stock guard on both transports).
$index2 = evershelfSmartItemsIndex([
    ['name' => 'Burro', 'shopping_name' => 'Burro', 'urgency' => 'high', 'current_qty' => 0],
]);
$needed = evershelfShoppingRowStillNeeded($db, $index2, 'Burro', 'Burro', static fn(string $g): float => 3.0);
assert_true(!$needed, 'shared predicate: family in stock ⇒ row no longer needed');

echo $fail === 0 ? "\nAll internal-shopping cleanup tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
