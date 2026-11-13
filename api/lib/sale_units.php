<?php
/**
 * Retail sale units — the shelf quantity a household can actually pick up.
 *
 * The smart list computed a *need* (1022 g of grana, 13 eggs, 9 boxes of crackers)
 * and printed it as a shopping quantity. A need is not a purchase: nobody fills a
 * bag with 1022 g of Trentingrana. This module snaps a need to the smallest real
 * shelf unit of its category and returns the step the client's stepper must move by
 * (`sale_step` in the payload), so the UI and the server agree on what "+1" means.
 *
 * The table lives in `data/sale_units.json` — DATA, because product words and
 * retail sizes are vocabulary, not logic (see AGENTS.md §4b).
 */

/** @return array<string,mixed> */
function saleUnitsTable(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }
    $path = __DIR__ . '/../../data/sale_units.json';
    $table = [];
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $table = $decoded;
        }
    }
    return $table;
}

/** Unit group used by the rules: pieces / measure / whole packs. */
function saleUnitGroup(string $unit): string
{
    $u = strtolower(trim($unit));
    if ($u === 'pz') {
        return 'pz';
    }
    if ($u === 'conf') {
        return 'conf';
    }
    if (in_array($u, ['g', 'ml', 'kg', 'l', 'lt'], true)) {
        return 'g';
    }
    return $u;
}

/**
 * The rule that governs this product, or null when the table has nothing to say.
 *
 * @return array<string,mixed>|null
 */
function saleUnitRuleFor(string $category, string $name, string $unit): ?array
{
    $table = saleUnitsTable();
    $rules = $table['rules'] ?? [];
    if (!is_array($rules) || $rules === []) {
        return null;
    }
    $group = saleUnitGroup($unit);
    $cat = strtolower(trim($category));
    $needle = mb_strtolower(trim($name));
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        if ((string)($rule['unit'] ?? '') !== $group) {
            continue;
        }
        $cats = is_array($rule['categories'] ?? null) ? $rule['categories'] : [];
        $byCat = $cat !== '' && in_array($cat, $cats, true);
        $byName = false;
        foreach ((array)($rule['match'] ?? []) as $pat) {
            $pat = mb_strtolower((string)$pat);
            if ($pat !== '' && str_contains($needle, $pat)) {
                $byName = true;
                break;
            }
        }
        if ($byName || $byCat) {
            return $rule;
        }
    }
    return null;
}


/**
 * Snap a needed quantity up to the smallest real shelf unit.
 *
 * Returns `['quantity','unit','step','rule']` — `step` is what the UI stepper moves
 * by (6 eggs, 250 g of cheese, 1 pack) and it is the *same* number the server used,
 * so a household tapping + never lands between two packs.
 *
 * @param float  $qty      the computed need (never printed as it is)
 * @param string $unit     the item's unit
 * @param string $category product category slug
 * @param string $name     product name (for the `match` patterns)
 */
function saleUnitSnap(float $qty, string $unit, string $category, string $name): array
{
    $group = saleUnitGroup($unit);
    $rule = saleUnitRuleFor($category, $name, $unit);
    if ($qty <= 0) {
        $qty = 0.0;
    }
    // A measure written in kg/l is compared in its own base before snapping.
    $u = strtolower(trim($unit));
    $scale = in_array($u, ['kg', 'l', 'lt'], true) ? 1000.0 : 1.0;

    if ($rule === null) {
        $out = $qty;
        if ($group === 'conf' || $group === 'pz') {
            $out = max(1.0, ceil($out));
        }
        return [
            'quantity' => $out,
            'unit' => $unit,
            'step' => ($group === 'conf' || $group === 'pz') ? 1.0 : 0.0,
            'rule' => null,
        ];
    }

    $min = (float)($rule['min'] ?? 0);
    $max = (float)($rule['max'] ?? 0);
    $base = $qty * $scale;                     // the need in the rule's own unit
    if ($base < $min) {
        $base = $min;
    }

    $ladder = is_array($rule['steps'] ?? null) ? array_map('floatval', $rule['steps']) : [];
    $stepGrid = (float)($rule['step'] ?? 0);

    if ($ladder !== []) {
        sort($ladder);
        $picked = 0.0;
        foreach ($ladder as $rung) {
            if ($rung >= $base - 0.001) {
                $picked = $rung;
                break;
            }
        }
        if ($picked <= 0) {
            // Beyond the ladder: keep climbing by its largest rung.
            $top = (float)end($ladder);
            $picked = $top > 0 ? $top * ceil($base / $top) : $base;
        }
        // Pieces move by one (2 onions, then 3); a ladder of trays moves by its
        // first rung, which is the smallest pack on the shelf (eggs: 6).
        $step = ($group === 'pz' && count($ladder) > 2) ? $ladder[0] : ($ladder[1] ?? $ladder[0] ?? 1.0);
        $snapped = $picked;
    } elseif ($stepGrid > 0) {
        $snapped = ceil($base / $stepGrid) * $stepGrid;
        $step = $stepGrid;
    } else {
        $snapped = $base;
        $step = 1.0;
    }

    if ($max > 0 && $snapped > $max) {
        $snapped = $max;
    }
    if ($min > 0 && $snapped < $min) {
        $snapped = $min;
    }

    return [
        'quantity' => $snapped / $scale,
        'unit' => $unit,
        'step' => $step / $scale,
        'rule' => $rule,
    ];
}

/** Categories that must not be planned on a monthly horizon (they would spoil). */
function saleUnitsFreshCategories(): array
{
    $table = saleUnitsTable();
    $cats = $table['fresh']['categories'] ?? [];
    return is_array($cats) ? array_map('strtolower', $cats) : [];
}

/** Horizon (days) for a fresh product: a weekly shop, never the monthly window. */
function saleUnitsFreshHorizonDays(): int
{
    $table = saleUnitsTable();
    $days = (int)($table['fresh']['max_horizon_days'] ?? 7);
    return max(1, min(30, $days));
}

function saleUnitsIsFresh(string $category): bool
{
    return in_array(strtolower(trim($category)), saleUnitsFreshCategories(), true);
}
