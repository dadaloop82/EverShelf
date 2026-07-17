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
    foreach ($cache['items'] ?? [] as $it) {
        if (stripos($it['shopping_name'] ?? $it['name'] ?? '', 'spinaci') !== false) {
            assert_true(($it['daily_rate'] ?? 0) < 150, 'live Spinaci daily_rate < 150 g/day');
            assert_true(($it['suggested_qty'] ?? 0) <= 1500, 'live Spinaci suggested_qty <= 1500 g');
        }
    }
} else {
    echo "SKIP: no Spinaci in foglia product in DB\n";
}

echo $fail === 0 ? "\nAll shopping guard tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
