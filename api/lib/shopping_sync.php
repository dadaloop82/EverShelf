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
 * @param callable(string):float|null $familyStockQty live stock for a generic family
 */
function evershelfShoppingRowStillNeeded(
    PDO $db,
    array $index,
    string $name,
    string $rawName = '',
    ?callable $familyStockQty = null
): bool {
    $si = evershelfSmartItemFor($index, $name, $rawName);
    if ($si === null || !evershelfSmartItemIsStillNeeded($db, $si)) {
        return false;
    }
    // A sibling variant back in stock means the whole generic family is covered.
    if ($familyStockQty !== null) {
        $generic = computeShoppingName($name !== '' ? $name : $rawName);
        if ($generic !== '' && $familyStockQty($generic) > 0.001) {
            return false;
        }
    }
    return true;
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
