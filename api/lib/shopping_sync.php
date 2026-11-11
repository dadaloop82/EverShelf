<?php
/**
 * EverShelf — shopping-list sync, shared by every transport.
 *
 * Two transports keep a shopping list in sync with the smart-shopping engine:
 * the Bring! integration and the built-in (internal) list. Both auto-add the
 * rows that need restocking and later remove them once the product is back in
 * abundance. Keeping two copies of that logic caused a real regression: the
 * internal cleanup only recognised the urgent markers (⚡/🟠) while the auto-add
 * also stamps planning markers (🟡/🔵), so those rows survived forever.
 *
 * Every decision about "is this row ours?" and "is this row still needed?" now
 * lives here, so the transports cannot drift apart again.
 */

/** Urgency / quantity markers EverShelf stamps onto an auto-added row. */
const EVERSHELF_SYNC_MARKERS = ['⚡', '🟠', '🟡', '🔵', '🛒'];

/**
 * A quantity at or below this is a trace, not stock (same numbers as
 * `productQtyThreshold()` in api/index.php, which now reads this table).
 * Two grams left in a jar must never hide the whole family from the shopping list.
 */
const EVERSHELF_TRACE_QTY = ['g' => 2.0, 'ml' => 2.0, 'kg' => 0.002, 'l' => 0.002, 'conf' => 0.05, 'pz' => 0.25];

/** Markers meaning "the user deliberately declared this variant finished". */
const EVERSHELF_FINISHED_MARKERS = ['🛒 Esaurito', '🛒 Finished'];

/** Urgency → label prefix used on the specification line (single source of truth). */
function evershelfUrgencyLabel(string $urgency): string
{
    return match ($urgency) {
        'critical' => '⚡ Urgente',
        'high'     => '🟠 Presto',
        'medium'   => '🟡 A breve',
        'low'      => '🔵 Previsione',
        default    => '',
    };
}

/**
 * Scale of a pantry unit. Stock of another scale never covers a purchase: 360 g of
 * jam is not "one jar", and packs of milk are not litres — the family checks used to
 * add every unit together, so any leftover of any size hid the whole family.
 *
 * Mass/volume/count come from `evershelfUnitFamily()` (api/lib/recipe_shopping.php);
 * containers get their own scale because "1 conf" is not a weight.
 */
function evershelfUnitScale(string $unit): string
{
    $u = mb_strtolower(trim($unit));
    if ($u === 'conf' || in_array($u, ['confezione', 'confezioni', 'busta', 'buste', 'pacco', 'pacchi', 'barattolo', 'barattoli'], true)) {
        return 'pack';
    }
    return match (evershelfUnitFamily($u)) {
        'g' => 'mass',
        'ml' => 'volume',
        default => 'piece',
    };
}

/** A quantity expressed in the base unit of its scale, so a family can be summed. */
function evershelfUnitBaseQty(float $qty, string $unit): float
{
    return $qty * evershelfUnitFactor($unit);
}

/** The trace threshold of a unit, in the base unit of its scale. */
function evershelfUnitTraceQty(string $unit): float
{
    $key = mb_strtolower(trim($unit));
    return (EVERSHELF_TRACE_QTY[$key] ?? 0.5) * evershelfUnitFactor($unit);
}

/**
 * Can this stock row cover a purchase of `$unit`?
 *
 * Only when it is (a) the same scale, (b) above the trace threshold and (c) not
 * expired: a 0.5 g smear left in the jar, an expired pack or a family that only has
 * grams while the finished article is sold by the pack must not hide a restock.
 */
function evershelfStockRowCoversUnit(float $qty, string $rowUnit, ?string $expiry, string $unit): bool
{
    if ($qty <= evershelfUnitTraceQty($rowUnit)) {
        return false;
    }
    if (evershelfUnitScale($rowUnit) !== evershelfUnitScale($unit)) {
        return false;
    }
    if ($expiry !== null && $expiry !== '' && strtotime($expiry) < strtotime(date('Y-m-d'))) {
        return false;
    }
    return true;
}

/**
 * Family stock that can actually cover a purchase of the reference product, **expressed
 * in that product's own unit** (a jar, a litre, a pack), so callers can compare it with
 * a suggested quantity or a period need without converting anything.
 *
 * A family member counts only when it is fresh (not expired), above the trace threshold,
 * and — crucially — measured in the same base family once packages are expanded: 3 packs
 * of milk × 1 l = 3 l cover a litre of milk, while 360 g of jam do not cover a 620 g jar.
 * `evershelfBaseQty()` is the app's own converter, so the two cannot drift.
 *
 * Two things are deliberately generous, because they only ever make a family look
 * STOCKED (never invent a suggestion), and both were reported as «la lista si riempie
 * di cose che ho»:
 *
 *  - **A piece is a piece.** `evershelfBaseQty()` expands a `pz` row with a `package_unit`
 *    into grams (4.5 avocados × 160 g = 720 g), which is right for a weight question but
 *    made the 4.5 avocados in the fridge cover NOTHING for the same family sold by the
 *    piece: «Avocado Hass» read 0 of every unit and went back on the list as «Esaurito».
 *  - **A count is a count.** A family mixes scales — «Mozzarella» is sold as a 1 kg pack
 *    and as pieces of 100 g with no declared weight — so the answer is the LARGER of the
 *    two readings (measured, and counted in packs/pieces). `shoppingUnitIsPack()` is the
 *    same vocabulary the rate guard and the reference cap already use.
 *
 * The family is still the stored `shopping_name`, and nothing wider: a title word is not
 * proof (a «Muesli … e miele» box would answer for the honey family), so a product filed
 * under another generic stays another family — that is data to correct, not a rule to bend.
 */
function evershelfFamilyUsableStockQty(
    PDO $db,
    string $shoppingName,
    string $unit,
    float $packageSize = 0.0,
    string $packageUnit = ''
): float {
    $key = mb_strtolower(trim($shoppingName));
    if ($key === '') {
        return 0.0;
    }
    $ref = evershelfBaseQty(1.0, $unit, $packageSize, $packageUnit);
    $refBase = $ref['qty'] > 0 ? $ref['qty'] : 1.0;
    $refFamily = $ref['family'];
    $refIsCount = shoppingUnitIsPack($unit);

    $stmt = $db->prepare("
        SELECT p.name, p.shopping_name, p.unit, p.default_quantity, p.package_unit,
               COALESCE(SUM(CASE WHEN i.expiry_date IS NULL OR i.expiry_date >= date('now')
                                 THEN i.quantity ELSE 0 END), 0) AS fresh_qty
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id AND i.quantity > 0
        WHERE LOWER(TRIM(COALESCE(NULLIF(p.shopping_name, ''), p.name))) = ?
        GROUP BY p.id
    ");
    $stmt->execute([$key]);
    $measured = 0.0;   // in the reference's base family (expanded with package sizes)
    $counted  = 0.0;   // in the reference's own counted unit (packs / pieces)
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sn = trim((string)($row['shopping_name'] ?? '')) ?: (string)$row['name'];
        if (!productMatchesShoppingFamily((string)$row['name'], $sn)) {
            continue;
        }
        $qty = (float)($row['fresh_qty'] ?? 0);
        $rowUnit = (string)($row['unit'] ?? '');
        if ($qty <= evershelfUnitTraceQty($rowUnit)) {
            continue;
        }
        $rowBase = evershelfBaseQty($qty, $rowUnit, (float)($row['default_quantity'] ?? 0), (string)($row['package_unit'] ?? ''));
        if ($rowBase['qty'] > 0 && $rowBase['family'] === $refFamily) {
            $measured += $rowBase['qty'];
        }
        if ($refIsCount && shoppingUnitIsPack($rowUnit)) {
            $counted += $qty * evershelfUnitFactor($rowUnit) / max(0.0001, evershelfUnitFactor($unit));
        }
    }
    return max($measured / $refBase, $counted);
}

/**
 * The household refilled the shelf after declaring an article finished.
 *
 * A row stamped `🛒 Esaurito` is a DECISION («ho finito questo») and no prune may drop it
 * behind the user's back — that is what `evershelfSpecIsDeliberate()` protects. But when
 * the same article comes back into the pantry (a purchase, a corrected stocktake) the
 * decision is no longer true, and the row sat on the list forever: «Banane · 🛒 Esaurito»
 * next to three bananas in the fridge, reported as «la lista si riempie di cose che ho».
 *
 * The question is asked of the FAMILY, with the same usable-stock rule every other
 * coverage decision uses (fresh, above the trace threshold, same base family), so a trace
 * or an expired pack never resurrects the row. A pack family needs one whole pack; a
 * measured family at least half a typical package.
 */
function evershelfFamilyBackInStock(PDO $db, string $shoppingName): bool
{
    $key = mb_strtolower(trim($shoppingName));
    if ($key === '') {
        return false;
    }
    // The family's own scale: the unit of the product that holds the most fresh stock of it
    // (so a `conf` row of 250 g answers in packs, not in grams with no package size).
    $stmt = $db->prepare("
        SELECT p.unit AS unit, p.default_quantity AS def_qty, p.package_unit AS pkg_unit,
               SUM(i.quantity) AS qty
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id AND i.quantity > 0
        WHERE LOWER(TRIM(COALESCE(NULLIF(p.shopping_name, ''), p.name))) = ?
          AND (i.expiry_date IS NULL OR i.expiry_date >= date('now'))
        GROUP BY p.id
        ORDER BY qty DESC
        LIMIT 1
    ");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $unit = trim((string)($row['unit'] ?? ''));
    if ($unit === '') {
        return false;
    }
    $defaultQty = (float)($row['def_qty'] ?? 0);
    $packageUnit = trim((string)($row['pkg_unit'] ?? ''));
    $required   = shoppingUnitIsPack($unit) ? 1.0 : max(1.0, 0.5 * $defaultQty);
    return evershelfFamilyUsableStockQty($db, $key, $unit, $defaultQty, $packageUnit) >= $required;
}

/** True when the specification carries a marker EverShelf itself wrote. */
function evershelfSpecIsAppManaged(string $spec): bool
{
    foreach (EVERSHELF_SYNC_MARKERS as $marker) {
        if (mb_strpos($spec, $marker) !== false) {
            return true;
        }
    }
    return false;
}

/** True when the row was deliberately marked as finished — never auto-removed. */
function evershelfSpecIsDeliberate(string $spec): bool
{
    foreach (EVERSHELF_FINISHED_MARKERS as $marker) {
        if (mb_strpos($spec, $marker) !== false) {
            return true;
        }
    }
    return false;
}

/** Significant tokens of a product/shopping name (stopwords and short words dropped). */
function evershelfNameTokens(string $name): array
{
    static $stop = [
        'di', 'del', 'della', 'dei', 'degli', 'delle', 'da', 'in', 'con', 'per', 'a', 'e',
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'al', 'alle', 'agli', 'allo',
        'su', 'se', 'che', 'non', 'ma', 'o', 'nel', 'nei', 'tra', 'sui', 'sulle', 'sugli',
    ];
    $clean  = mb_strtolower(trim(preg_replace('/[^\p{L}\s]/u', ' ', $name) ?? $name));
    $tokens = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter(
        $tokens,
        static fn(string $t): bool => mb_strlen($t) > 2 && !in_array($t, $stop, true)
    )));
}

/**
 * Index the smart-shopping cache for row matching.
 *
 * @param array<int,array<string,mixed>> $smartItems
 * @return array{exact: array<string,array>, token: array<string,array>}
 */
function evershelfSmartItemsIndex(array $smartItems): array
{
    $index = ['exact' => [], 'token' => []];
    foreach ($smartItems as $si) {
        foreach (array_unique(array_filter([
            (string)($si['shopping_name'] ?? ''),
            (string)($si['name'] ?? ''),
        ])) as $name) {
            $key = mb_strtolower(trim($name));
            if ($key !== '') {
                $index['exact'][$key] = $si;
            }
            foreach (evershelfNameTokens($name) as $token) {
                if (!isset($index['token'][$token])) {
                    $index['token'][$token] = $si;
                }
            }
        }
    }
    return $index;
}


/**
 * Resolve which smart item a list row refers to.
 *
 * Exact shopping-name match wins over the first-token fallback so "Panna"
 * (in stock) never matches "Panna da cucina" (depleted). The generic shopping
 * name computed from the row is tried too, so "Uovo medio" finds "Uova".
 *
 * @param array{exact: array<string,array>, token: array<string,array>} $index
 * @return array<string,mixed>|null
 */
function evershelfSmartItemFor(array $index, string $name, string $rawName = ''): ?array
{
    $candidates = [];
    foreach (array_unique(array_filter([$name, $rawName])) as $candidate) {
        $candidates[] = (string)$candidate;
        $computed = computeShoppingName((string)$candidate);
        if ($computed !== '') {
            $candidates[] = $computed;
        }
    }
    foreach (array_unique($candidates) as $candidate) {
        $key = mb_strtolower(trim($candidate));
        if ($key !== '' && isset($index['exact'][$key])) {
            return $index['exact'][$key];
        }
    }
    $tokens = evershelfNameTokens($name !== '' ? $name : $rawName);
    $first  = $tokens[0] ?? '';
    if ($first !== '' && isset($index['token'][$first])) {
        return $index['token'][$first];
    }
    return null;
}

/** True when the smart cache still asks for this product (and the user did not block it). */
function evershelfSmartItemIsStillNeeded(PDO $db, array $si): bool
{
    return smartItemShouldSyncToBring($si) && !bringSmartItemSkipBringSync($db, $si);
}

/**
 * Single "is this auto-added row still needed?" predicate used by both transports.
 *
 * @param array{exact: array<string,array>, token: array<string,array>} $index
 * @param callable(string,string,float,string):float|null $familyUsableQty stock of a
 *        generic family that can actually cover a purchase, expressed in the reference
 *        product's unit (args: generic name, unit, package size, package unit). Without
 *        it, sibling stock is not consulted.
 */
function evershelfShoppingRowStillNeeded(
    PDO $db,
    array $index,
    string $name,
    string $rawName = '',
    ?callable $familyUsableQty = null
): bool {
    $si = evershelfSmartItemFor($index, $name, $rawName);
    if ($si === null || !evershelfSmartItemIsStillNeeded($db, $si)) {
        return false;
    }
    // Sibling stock only drops the row when it actually covers the planning need.
    // A few leftover eggs must NOT yank "Uova" off the list while days_left is short.
    if ($familyUsableQty !== null) {
        $generic = computeShoppingName($name !== '' ? $name : $rawName);
        if ($generic !== '') {
            $unit = (string)($si['unit'] ?? $si['suggested_unit'] ?? '');
            $famQty = (float)$familyUsableQty(
                $generic,
                $unit,
                (float)($si['default_qty'] ?? 0),
                (string)($si['package_unit'] ?? '')
            );
            if ($famQty > 0.001) {
                $probe = $si;
                $probe['current_qty'] = $famQty;
                $probe['quantity'] = $famQty;
                if (smartItemStockCoversCacheNeed($probe)) {
                    return false;
                }
                $need = (float)($si['period_usage'] ?? 0);
                $daysLeft = $si['days_left'] ?? null;
                // Depleted-path safety net: cache said empty, no consumption window,
                // but the family is back on the shelf → row is obsolete.
                if ($need <= 0.001 && ($daysLeft === null || !is_numeric($daysLeft) || (float)$daysLeft > 14.0)) {
                    return false;
                }
            }
        }
    }
    return true;
}

/**
 * Memoised family stock that can cover a purchase of `$unit` (see
 * evershelfFamilyUsableStockQty()): the cleanup loops over every row, so the same
 * family must not run the same query repeatedly.
 */
function evershelfFamilyUsableStockQtyMemo(
    PDO $db,
    string $shoppingName,
    string $unit,
    float $packageSize = 0.0,
    string $packageUnit = ''
): float {
    static $memo = [];
    $key = mb_strtolower(trim($shoppingName)) . '|' . mb_strtolower(trim($unit))
        . '|' . $packageSize . '|' . mb_strtolower(trim($packageUnit));
    if (!array_key_exists($key, $memo)) {
        $memo[$key] = evershelfFamilyUsableStockQty($db, $shoppingName, $unit, $packageSize, $packageUnit);
    }
    return $memo[$key];
}

/**
 * Memoised live stock lookup for a generic shopping family.
 * `bringShoppingFamilyStockQty()` runs one query per call; the cleanup loops over
 * every row, so cache repeated families within the request (same result, fewer queries).
 */
function evershelfFamilyStockQty(PDO $db, string $shoppingName): float
{
    static $memo = [];
    $key = mb_strtolower(trim($shoppingName));
    if ($key === '') {
        return 0.0;
    }
    if (!array_key_exists($key, $memo)) {
        $memo[$key] = bringShoppingFamilyStockQty($db, $shoppingName);
    }
    return $memo[$key];
}

/**
 * Build the specification line for a smart-shopping row.
 * Shared by both transports so the markers always match what the cleanup expects.
 */
function evershelfBuildShoppingSpec(array $si): string
{
    $generic = (string)($si['shopping_name'] ?? '') ?: (string)($si['name'] ?? '');
    $parts   = [];
    if (!empty($si['name']) && $si['name'] !== $generic) {
        $parts[] = $si['name'] . (!empty($si['brand']) ? ' · ' . $si['brand'] : '');
    }
    $urg = evershelfUrgencyLabel((string)($si['urgency'] ?? ''));
    if ($urg !== '') {
        $parts[] = $urg;
    }
    $qtyLabel = formatSmartSuggestQty($si);
    if ($qtyLabel !== null) {
        $parts[] = '🛒 ' . $qtyLabel;
    }
    return implode(' · ', $parts);
}

/**
 * Read the smart-shopping cache for the sync loops.
 *
 * Applies the same "just purchased" filter the UI sees (B9), so the cron never
 * mutates the list from a snapshot the user has already dismissed. A decode
 * failure is logged instead of silently degrading to an empty list (P5).
 */
function evershelfLoadSmartItemsForSync(PDO $db): array
{
    $cacheFile = EVERSHELF_ROOT . '/data/smart_shopping_cache.json';
    if (!file_exists($cacheFile)) {
        return [];
    }
    $raw = @file_get_contents($cacheFile);
    if ($raw === false) {
        EverLog::warn('smart_cache_read_failed', ['path' => $cacheFile]);
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        EverLog::warn('smart_cache_decode_failed', ['path' => $cacheFile, 'bytes' => strlen($raw)]);
        return [];
    }
    $items = $data['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }
    try {
        return smartShoppingFilterPurchased($db, $items);
    } catch (Throwable $e) {
        // Missing/legacy schema — fall back to the raw snapshot rather than dropping rows.
        EverLog::warn('smart_cache_filter_failed', ['error' => $e->getMessage()]);
        return $items;
    }
}
