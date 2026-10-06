#!/usr/bin/env php
<?php
/**
 * Regression tests: shopping consumption/price guards must never inflate totals again.
 * Run: php scripts/test-shopping-guards.php
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

function assert_near(float $a, float $b, float $eps, string $msg): void
{
    assert_true(abs($a - $b) <= $eps, $msg . " (got {$a}, expected ~{$b})");
}

// ── Spinaci regression: moves + short burst must not explode rate ─────────
$usageWithMoves = 1062.0;   // 450 real + 612 from two [Spostamento] outs
$usageReal = 450.0;
$daysSinceFirst = 13.0;

$badBurstRate = $usageWithMoves / 1.72; // old bug pattern
$fixedRate = shoppingFallbackDailyRate($usageReal, $daysSinceFirst);
$cappedRate = shoppingSanitizeDailyRate($badBurstRate, 'g', 0, 1, 450, 450, 3, 6);

assert_true($badBurstRate > 500, 'sanity: burst rate would have been >500 g/day');
assert_near($fixedRate, 450 / 13, 1.0, 'fallback spreads over calendar days');
assert_true($cappedRate <= SHOPPING_GUARD_MAX_G_PER_DAY, 'sanitize caps absurd g/day rate');

// Depleted family must not count as "covered" (would block list removal logic).
$db = getDB();
$pid = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
if ($pid > 0) {
    $evalZero = shoppingEvaluateFamilyRestock($db, $pid);
    $stmt = $db->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id = ?');
    $stmt->execute([$pid]);
    $stock = (float)$stmt->fetchColumn();
    if ($stock <= 0.001 && ($evalZero['need_base'] ?? 0) > 0.001) {
        assert_true(empty($evalZero['covered']), 'zero stock + positive need is not covered');
    }
}
assert_true($cappedRate < 100, 'spinaci-like burst capped below 100 g/day');

// ── Suggested qty cap ───────────────────────────────────────────────────────
$cap = shoppingCapSuggestedQty(9000, 'g', 500, 'g', 15);
assert_true($cap['quantity'] <= 1500, '9000g suggestion capped to max 3×500g packs');

$cap2 = shoppingCapSuggestedQty(13500, 'g', 500, 'g', 22);
assert_true($cap2['quantity'] <= 1500, 'price-run 13.5kg capped');

// ── Price qty cap ───────────────────────────────────────────────────────────
$pq = shoppingCapPriceQty(9000, 'g', 500, 'g');
assert_true($pq['quantity'] <= 1500, 'price payload qty capped');

// ── Line € cap ──────────────────────────────────────────────────────────────
$line = shoppingGuardLineTotal(51.03, 'Spinaci');
assert_true($line === SHOPPING_GUARD_MAX_LINE_EUR, '€51 line clamped to max');

$okLine = shoppingGuardLineTotal(7.49, 'Olio');
assert_near($okLine ?? 0, 7.49, 0.01, 'normal line unchanged');

// ── _calcEstimatedTotal with huge qty ───────────────────────────────────────
$est = _calcEstimatedTotal(1.89, 'busta 500g', 13500, 'g', 500, 'g');
assert_true($est !== null && $est <= SHOPPING_GUARD_MAX_LINE_EUR + 0.01, 'calc total capped by pack limit (~€5.67 not €51)');

// ── Live DB: Spinaci product if present ─────────────────────────────────────
$db = getDB();
$liveSmartItems = [];
$row = $db->query("SELECT id FROM products WHERE lower(name) LIKE '%spinaci in foglia%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    @unlink(__DIR__ . '/../data/smart_shopping_cache.json');
    ob_start();
    smartShopping($db);
    $out = ob_get_clean();
    $cache = json_decode($out, true);
    if (!$cache && is_file(__DIR__ . '/../data/smart_shopping_cache.json')) {
        $cache = json_decode(file_get_contents(__DIR__ . '/../data/smart_shopping_cache.json'), true);
    }
    $liveSmartItems = is_array($cache['items'] ?? null) ? $cache['items'] : [];
    foreach ($cache['items'] ?? [] as $it) {
        if (stripos($it['shopping_name'] ?? $it['name'] ?? '', 'spinaci') !== false) {
            assert_true(($it['daily_rate'] ?? 0) < 150, 'live Spinaci daily_rate < 150 g/day');
            assert_true(($it['suggested_qty'] ?? 0) <= 1500, 'live Spinaci suggested_qty <= 1500 g');
        }
    }
} else {
    echo "SKIP: no Spinaci in foglia product in DB\n";
}
// ── Stock at home covers the need ──────────────────────────────────────────
// Regression: "Fette biscottate Integrali" (10 packs, 353 days of stock) and
// "Origano foglie" (20 g) were predicted again — the low-stock ratio rule only
// compares against default_quantity and the family rule only looks at siblings.
assert_true(smartItemStockCoversNeed(10.0, 0.2, 353.0, 7), '10 packs cover a 0.2-pack window');
assert_true(!smartItemStockCoversNeed(0.0, 3.4, 0.0, 7), 'no stock is never coverage');
assert_true(!smartItemStockCoversNeed(3.0, 7.0, 10.0, 7), 'partial stock (3 of 7) still needs a trip');
assert_true(!smartItemStockCoversNeed(9.0, 3.4, 3.0, 7), 'stock that runs out inside the window still needs a trip');

$cacheCovered = [
    'name' => 'Fette biscottate Integrali', 'current_qty' => 10, 'period_usage' => 0.2,
    'days_left' => 353, 'days_to_expiry' => 2, 'edible_days' => 30,
];
assert_true(smartItemStockCoversCacheNeed($cacheCovered), 'cache: restocked item is covered');
assert_true(!smartItemShouldSyncToBring($cacheCovered + ['urgency' => 'high']), 'cache: covered stock is never synced, even at high urgency');
assert_true(!smartItemShouldSyncToBring($cacheCovered + ['urgency' => 'critical']), 'cache: covered stock is never synced, even at critical urgency');

$cacheExpired = [
    'name' => 'Origano foglie', 'current_qty' => 20, 'period_usage' => 3.4,
    'days_left' => 41, 'days_to_expiry' => -5, 'edible_days' => 7, 'urgency' => 'high',
];
assert_true(!smartItemStockCoversCacheNeed($cacheExpired), 'cache: expired stock is not coverage');
assert_true(smartItemShouldSyncToBring($cacheExpired), 'cache: expired leftovers still ask for a restock');

$cacheEmpty = [
    'name' => 'Avocado Hass', 'current_qty' => 0, 'period_usage' => 0.93,
    'days_left' => 0, 'days_to_expiry' => 999, 'edible_days' => 7, 'urgency' => 'critical',
];
assert_true(!smartItemStockCoversCacheNeed($cacheEmpty), 'cache: depleted item is not covered');
assert_true(smartItemShouldSyncToBring($cacheEmpty), 'cache: depleted critical item is still synced');

$cacheNoUsage = [
    'name' => 'Pelati', 'current_qty' => 3, 'period_usage' => 0,
    'days_left' => 365, 'days_to_expiry' => 999, 'edible_days' => 30, 'urgency' => 'high',
];
assert_true(smartItemShouldSyncToBring($cacheNoUsage), 'cache: no usage history means no suppression');

// Live cache: nothing that already covers the need may be predicted or synced.
// The list regenerated by the Spinaci check above is the freshest sample; fall
// back to the on-disk cache when that product is absent.
$liveItems = $liveSmartItems;
if ($liveItems === []) {
    $liveCache = json_decode((string)@file_get_contents(__DIR__ . '/../data/smart_shopping_cache.json'), true);
    $liveItems = is_array($liveCache['items'] ?? null) ? $liveCache['items'] : [];
}
if ($liveItems !== []) {
    $covered = 0;
    foreach ($liveItems as $it) {
        if (!is_array($it)) {
            continue;
        }
        if (smartItemStockCoversCacheNeed($it)) {
            $covered++;
            echo '  covered but still predicted: ' . ($it['name'] ?? '?') . "\n";
        }
    }
    assert_true($covered === 0, 'live cache: no predicted item is already covered by stock');
} else {
    echo "SKIP: no smart shopping cache to inspect\n";
}



echo $fail === 0 ? "\nAll shopping guard tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
