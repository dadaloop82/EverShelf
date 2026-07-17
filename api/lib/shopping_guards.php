<?php
/**
 * Shopping list guards — prevent inflated consumption rates and € totals.
 *
 * Regression: location moves ([Spostamento]) must never count as consumption;
 * short-history bursts must not explode daily_rate; price totals cap per line/trip.
 */

/** Max € for a single shopping-list line in the estimated total. */
const SHOPPING_GUARD_MAX_LINE_EUR = 25.0;

/** Max retail packs priced per line (one supermarket trip). */
const SHOPPING_GUARD_MAX_PRICE_PACKS = 3;

/** Absolute daily consumption ceiling (household). */
const SHOPPING_GUARD_MAX_G_PER_DAY = 250.0;
const SHOPPING_GUARD_MAX_ML_PER_DAY = 2000.0;

/** Min calendar days when spreading short-history usage (anti-burst). */
const SHOPPING_GUARD_MIN_HISTORY_DAYS = 7.0;

/**
 * SQL fragment: transaction is real consumption/purchase, not a location move.
 */
function shoppingTxNotMoveNotesSql(string $notesExpr = 'notes'): string
{
    return "({$notesExpr} IS NULL OR {$notesExpr} NOT LIKE '[Spostamento]%')";
}

/**
 * Fallback daily rate for products without 90d EWMA — spread over calendar days, not activity burst.
 */
function shoppingFallbackDailyRate(float $usage, float $daysSinceFirst): float
{
    if ($usage <= 0 || $daysSinceFirst >= 999) {
        return 0.0;
    }
    $effectiveDays = max(SHOPPING_GUARD_MIN_HISTORY_DAYS, $daysSinceFirst);
    return $usage / $effectiveDays;
}

/**
 * Hard cap on daily consumption rate (g/ml/pz already sanitized elsewhere).
 */
function shoppingSanitizeDailyRate(
    float $dailyRate,
    string $unit,
    float $defQty,
    int $buyCount,
    float $totalBought,
    float $used30d,
    int $useCount,
    float $usesPerMonth
): float {
    if ($dailyRate <= 0) {
        return 0.0;
    }

    $u = strtolower(trim($unit));

    if ($u === 'pz') {
        if ($useCount > 0 && $usesPerMonth > 0) {
            $cap = ($usesPerMonth / 30.0) * 1.2;
            $dailyRate = min($dailyRate, max(0.2, $cap));
        }
        return min($dailyRate, 1.5);
    }

    if (!in_array($u, ['g', 'ml', 'kg', 'l', 'lt'], true)) {
        return $dailyRate;
    }

    $isWeight = in_array($u, ['g', 'kg'], true);
    $absMax = $isWeight ? SHOPPING_GUARD_MAX_G_PER_DAY : SHOPPING_GUARD_MAX_ML_PER_DAY;
    if ($u === 'kg') {
        $dailyRate *= 1000.0;
    } elseif (in_array($u, ['l', 'lt'], true)) {
        $dailyRate *= 1000.0;
    }

    if ($used30d > 0) {
        $rate30 = $used30d / 30.0;
        $dailyRate = min($dailyRate, $rate30 * 2.0);
    }

    if ($buyCount > 0 && $totalBought > 0) {
        $avgPurchase = $totalBought / $buyCount;
        if ($avgPurchase >= 20) {
            $dailyRate = min($dailyRate, $avgPurchase / 2.0);
        }
    }

    if ($defQty >= 20) {
        $dailyRate = min($dailyRate, $defQty * 1.5);
    }

    return min($dailyRate, $absMax);
}

/**
 * Cap smart-shopping suggested qty so list badges stay realistic.
 *
 * @return array{quantity: ?float, unit: string}
 */
function shoppingCapSuggestedQty(
    ?float $qty,
    string $unit,
    float $defQty,
    string $pkgUnit,
    int $planDays
): array {
    if ($qty === null || $qty <= 0) {
        return ['quantity' => $qty, 'unit' => $unit];
    }

    $u = strtolower(trim($unit));
    $pu = strtolower(trim($pkgUnit));
    $planDays = max(1, min($planDays, 31));

    if ($u === 'g' || $u === 'ml') {
        $pack = ($defQty >= 20 && ($pu === '' || $pu === $u)) ? $defQty : ($u === 'ml' ? 1000.0 : 500.0);
        $maxByPack = $pack * SHOPPING_GUARD_MAX_PRICE_PACKS;
        $perDay = ($u === 'ml' ? 500.0 : 200.0);
        $maxByPlan = $perDay * min($planDays, 7);
        return ['quantity' => min($qty, $maxByPack, $maxByPlan), 'unit' => $u];
    }

    if ($u === 'conf' || $u === 'pz') {
        return ['quantity' => min($qty, (float) SHOPPING_GUARD_MAX_PRICE_PACKS), 'unit' => $u];
    }

    return ['quantity' => $qty, 'unit' => $unit];
}

/**
 * Cap qty used for € price estimation (one trip, not full restock).
 *
 * @return array{quantity: float, unit: string}
 */
function shoppingCapPriceQty(float $qty, string $unit, float $defQty, string $pkgUnit): array
{
    $capped = shoppingCapSuggestedQty($qty, $unit, $defQty, $pkgUnit, 7);
    return [
        'quantity' => (float) ($capped['quantity'] ?? max(1, $qty)),
        'unit'     => $capped['unit'],
    ];
}

/** Cap a single line € total; log when clamped. */
function shoppingGuardLineTotal(?float $lineTotal, string $name): ?float
{
    if ($lineTotal === null || $lineTotal <= 0) {
        return $lineTotal;
    }
    if ($lineTotal > SHOPPING_GUARD_MAX_LINE_EUR) {
        EverLog::warn('shopping_guard_line_clamped', [
            'name'  => $name,
            'was'   => round($lineTotal, 2),
            'cap'   => SHOPPING_GUARD_MAX_LINE_EUR,
        ]);
        return SHOPPING_GUARD_MAX_LINE_EUR;
    }
    return $lineTotal;
}

/** Re-sum list total after per-line caps (mutates $prices entries). */
function shoppingGuardApplyLineTotals(array &$prices): float
{
    $total = 0.0;
    foreach ($prices as $name => &$entry) {
        if (!is_array($entry)) {
            continue;
        }
        $est = $entry['estimated_total'] ?? null;
        if ($est === null) {
            continue;
        }
        $est = shoppingGuardLineTotal((float) $est, (string) $name);
        $entry['estimated_total'] = $est;
        if ($est !== null && !empty($entry['currency'])) {
            $entry['estimated_total_label'] = _formatPrice($est, $entry['currency']);
        }
        $total += $est ?? 0.0;
    }
    unset($entry);
    return round($total, 2);
}
