<?php
/**
 * Italian produce seasonality (Northern/IT climate).
 * Data adapted from SeasonalItaly (MIT) — https://github.com/archetipico/SeasonalItaly
 */

function seasonalProducePath(): string {
    return dirname(__DIR__) . '/../data/seasonal_produce_it.json';
}

function seasonalLoadCatalog(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = seasonalProducePath();
    if (!is_readable($path)) {
        $cache = ['items' => [], 'source' => ''];
        return $cache;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    $cache = is_array($raw) ? $raw : ['items' => [], 'source' => ''];
    return $cache;
}

function seasonalNormalize(string $name): string {
    $n = mb_strtolower(trim($name), 'UTF-8');
    $n = preg_replace('/\([^)]*\)/u', ' ', $n) ?? $n;
    $n = str_replace(["'", "'", '`'], '', $n);
    return preg_replace('/\s+/u', ' ', $n) ?? $n;
}

/**
 * @return array{item:array,status:string,score:int}|null
 */
function seasonalMatchProduce(string $name, ?int $month = null): ?array {
    $month = $month ?? (int)date('n');
    $q = seasonalNormalize($name);
    if ($q === '' || mb_strlen($q) < 3) {
        return null;
    }
    $catalog = seasonalLoadCatalog();
    $best = null;
    $bestScore = 0;
    foreach ($catalog['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $candidates = [(string)($item['name'] ?? '')];
        foreach ($item['aliases'] ?? [] as $a) {
            $candidates[] = (string)$a;
        }
        foreach ($candidates as $cand) {
            $c = seasonalNormalize($cand);
            if ($c === '' || mb_strlen($c) < 3) {
                continue;
            }
            $score = 0;
            if ($q === $c) {
                $score = 100;
            } elseif (str_starts_with($q, $c) || str_starts_with($c, $q)) {
                $score = 90;
            } elseif (preg_match('/\b' . preg_quote($c, '/') . '\b/u', $q)) {
                $score = 85;
            } elseif (mb_strlen($c) >= 5 && (str_contains($q, $c) || str_contains($c, $q))) {
                $score = 70;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $peak = array_map('intval', $item['peak'] ?? []);
                $shoulder = array_map('intval', $item['shoulder'] ?? []);
                if (in_array($month, $peak, true)) {
                    $status = 'peak';
                } elseif (in_array($month, $shoulder, true)) {
                    $status = 'shoulder';
                } else {
                    $status = 'off';
                }
                // Year-round imports are never "off"
                if (!empty($item['imported']) && $status === 'off') {
                    $status = 'shoulder';
                }
                $best = ['item' => $item, 'status' => $status, 'score' => $score];
            }
        }
    }
    return ($bestScore >= 70) ? $best : null;
}

function seasonalStatusForName(string $name, ?int $month = null): string {
    $m = seasonalMatchProduce($name, $month);
    return $m['status'] ?? 'unknown';
}

/**
 * Review shopping list vs seasonality + suggest peak produce not already stocked/listed.
 *
 * @param list<array{name?:string,raw_name?:string}> $shoppingItems
 * @return array{month:int,tip:string,out_of_season:list,suggest_add:list,source:string}
 */
function seasonalReviewShopping(PDO $db, array $shoppingItems, string $lang = 'it'): array {
    $month = (int)date('n');
    $catalog = seasonalLoadCatalog();

    $outOfSeason = [];
    $onListNorm = [];
    foreach ($shoppingItems as $row) {
        $label = trim((string)($row['name'] ?? $row['raw_name'] ?? ''));
        if ($label === '') {
            continue;
        }
        $onListNorm[seasonalNormalize($label)] = true;
        $match = seasonalMatchProduce($label, $month);
        if (!$match || $match['status'] !== 'off') {
            continue;
        }
        // Only flag when it's clearly produce (matched catalog)
        $outOfSeason[] = [
            'name' => $label,
            'raw_name' => (string)($row['raw_name'] ?? $label),
            'seasonal_name' => (string)($match['item']['name'] ?? ''),
            'kind' => (string)($match['item']['kind'] ?? ''),
            'status' => 'off',
        ];
    }

    // Stock names (cookable)
    $stockNorm = [];
    try {
        $rows = $db->query("
            SELECT p.name, p.category
            FROM inventory i
            JOIN products p ON p.id = i.product_id
            WHERE i.quantity > 0.001
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $stockNorm[seasonalNormalize((string)$r['name'])] = true;
            // also mark category produce generically
        }
    } catch (Throwable $e) {
        $rows = [];
    }

    // Past purchases boost suggestions
    $boughtBoost = [];
    try {
        $bought = $db->query("
            SELECT p.name, COUNT(*) AS c
            FROM transactions t
            JOIN products p ON p.id = t.product_id
            WHERE t.type = 'in' AND t.undone = 0
              AND t.created_at >= date('now', '-365 days')
            GROUP BY p.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bought as $b) {
            $boughtBoost[seasonalNormalize((string)$b['name'])] = (int)$b['c'];
        }
    } catch (Throwable $e) {
        // ignore
    }

    $suggest = [];
    foreach ($catalog['items'] ?? [] as $item) {
        if (!is_array($item) || !empty($item['imported'])) {
            continue;
        }
        $peak = array_map('intval', $item['peak'] ?? []);
        if (!in_array($month, $peak, true)) {
            continue;
        }
        $name = (string)($item['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $n = seasonalNormalize($name);
        $first = seasonalNormalize(explode(' ', $name)[0] ?? $name);
        // Skip if already on list or in stock (name or first token)
        $listed = isset($onListNorm[$n]) || isset($onListNorm[$first]);
        $stocked = isset($stockNorm[$n]) || isset($stockNorm[$first]);
        if ($listed || $stocked) {
            continue;
        }
        // Prefer items the household has bought before, or short common names
        $boost = $boughtBoost[$n] ?? $boughtBoost[$first] ?? 0;
        $common = mb_strlen($name) <= 14 ? 1 : 0;
        $score = $boost * 10 + $common * 5 + (count($peak) <= 3 ? 2 : 0);
        $suggest[] = [
            'name' => $name,
            'kind' => (string)($item['kind'] ?? ''),
            'status' => 'peak',
            'score' => $score,
            'bought_before' => $boost > 0,
        ];
    }
    usort($suggest, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));
    // Prefer previously bought, then top commons — max 8
    $preferBought = array_values(array_filter($suggest, static fn($s) => !empty($s['bought_before'])));
    $rest = array_values(array_filter($suggest, static fn($s) => empty($s['bought_before'])));
    $suggestAdd = array_slice(array_merge($preferBought, $rest), 0, 8);

    $tips = [
        'it' => [
            1 => 'Di stagione: agrumi, kiwi, carciofi, verze.',
            2 => 'Di stagione: radicchio, finocchi, pere, agrumi.',
            3 => 'Di stagione: asparagi, piselli, spinaci.',
            4 => 'Di stagione: asparagi, carciofi, fave, fragole.',
            5 => 'Di stagione: zucchine, fragole, ciliegie.',
            6 => 'Di stagione: albicocche, pesche, pomodori, melanzane.',
            7 => 'Di stagione: anguria, pesche, melanzane, pomodori.',
            8 => 'Di stagione: prugne, fichi, peperoni, basilico.',
            9 => 'Di stagione: uva, fichi, porcini, melograno.',
            10 => 'Di stagione: melograni, castagne, funghi, mele, pere.',
            11 => 'Di stagione: cachi, cavoli, broccoli, radicchio.',
            12 => 'Di stagione: arance, mandarini, cachi, verze.',
        ],
        'en' => [
            1 => 'In season: citrus, kiwi, artichokes, cabbages.',
            2 => 'In season: radicchio, fennel, pears, citrus.',
            3 => 'In season: asparagus, peas, spinach.',
            4 => 'In season: asparagus, artichokes, fava beans, strawberries.',
            5 => 'In season: zucchini, strawberries, cherries.',
            6 => 'In season: apricots, peaches, tomatoes, eggplant.',
            7 => 'In season: watermelon, peaches, eggplant, tomatoes.',
            8 => 'In season: plums, figs, peppers, basil.',
            9 => 'In season: grapes, figs, porcini, pomegranate.',
            10 => 'In season: pomegranates, chestnuts, mushrooms, apples, pears.',
            11 => 'In season: persimmons, cabbages, broccoli, radicchio.',
            12 => 'In season: oranges, mandarins, persimmons, cabbages.',
        ],
    ];
    $tipLang = in_array($lang, ['it', 'en'], true) ? $lang : 'en';
    $tip = $tips[$tipLang][$month] ?? $tips['en'][$month] ?? '';

    return [
        'month' => $month,
        'tip' => $tip,
        'out_of_season' => $outOfSeason,
        'suggest_add' => $suggestAdd,
        'source' => (string)($catalog['source'] ?? 'seasonal_produce_it'),
    ];
}

/**
 * Top inventory products unused for a long time.
 * Prefer opened / partially-used packs; idle = days since last use of that pack
 * (or since opened / added if never used after).
 *
 * @return list<array>
 */
function staleInventoryItems(PDO $db, int $limit = 3, int $minDays = 21): array {
    $limit = max(1, min(10, $limit));
    $minDays = max(7, min(365, $minDays));
    // Sealed full packs need a longer idle before they compete with opened leftovers
    $minDaysSealed = max($minDays, 60);

    $sql = "
        SELECT
            i.id AS inventory_id,
            p.id AS product_id,
            p.name,
            p.brand,
            p.category,
            p.unit,
            p.default_quantity,
            p.package_unit,
            p.image_url,
            i.quantity,
            i.location,
            i.added_at,
            i.updated_at,
            i.opened_at,
            (
              SELECT MAX(t.created_at) FROM transactions t
              WHERE t.product_id = p.id AND t.undone = 0
                AND t.type IN ('out', 'waste')
                AND IFNULL(t.notes, '') NOT LIKE '[Spostamento]%'
                AND (
                  i.opened_at IS NULL OR i.opened_at = ''
                  OR t.created_at >= i.opened_at
                )
            ) AS last_out
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0.001
          AND LOWER(IFNULL(p.category, '')) NOT IN ('igiene', 'pulizia', 'hygiene', 'cleaning')
    ";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $scored = [];
    foreach ($rows as $r) {
        if (function_exists('isInventoryDepleted') && isInventoryDepleted($r)) {
            continue;
        }
        $openedAt = trim((string)($r['opened_at'] ?? ''));
        $isOpened = $openedAt !== '';
        if (!$isOpened) {
            // Legacy: fractional qty counts as opened
            $qty = (float)$r['quantity'];
            $def = (float)($r['default_quantity'] ?? 0);
            $unit = (string)($r['unit'] ?? 'pz');
            if ($unit === 'conf') {
                $isOpened = abs($qty - round($qty)) > 0.01;
            } elseif ($def > 0) {
                $rem = abs($qty - round($qty / $def) * $def);
                $isOpened = $rem > ($def * 0.02) && $qty < ($def * 0.98);
            } elseif (in_array($unit, ['g', 'ml'], true) && $qty > 0 && $qty < 50) {
                // tiny leftover without package size — treat as opened remnant
                $isOpened = true;
            }
        }
        // Skip sealed pantry staples that sit forever (salt, sugar, water…)
        if (!$isOpened) {
            $n = mb_strtolower((string)$r['name'], 'UTF-8');
            if (preg_match('/\b(sale|salt|sel|zucchero|sugar|pepe|pepper|acqua|water|bicarbonat)/u', $n)) {
                continue;
            }
        }

        $ref = $r['last_out'] ?: ($openedAt !== '' ? $openedAt : ($r['added_at'] ?? null));
        if (!$ref) {
            continue;
        }
        $days = (int)round((time() - strtotime((string)$ref)) / 86400);
        $threshold = $isOpened ? $minDays : $minDaysSealed;
        if ($days < $threshold) {
            continue;
        }

        $scored[] = [
            'product_id' => (int)$r['product_id'],
            'inventory_id' => (int)$r['inventory_id'],
            'name' => (string)$r['name'],
            'brand' => (string)($r['brand'] ?? ''),
            'category' => (string)($r['category'] ?? ''),
            'unit' => (string)($r['unit'] ?? 'pz'),
            'quantity' => (float)$r['quantity'],
            'location' => (string)($r['location'] ?? 'dispensa'),
            'image_url' => $r['image_url'] ?? null,
            'last_out' => $r['last_out'],
            'last_added' => $r['added_at'],
            'opened_at' => $openedAt !== '' ? $openedAt : null,
            'is_opened' => $isOpened,
            'days_unused' => $days,
            'never_used' => empty($r['last_out']),
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        // Opened leftovers first, then longest idle
        if ($a['is_opened'] !== $b['is_opened']) {
            return $a['is_opened'] ? -1 : 1;
        }
        if ($a['days_unused'] !== $b['days_unused']) {
            return $b['days_unused'] <=> $a['days_unused'];
        }
        return strcmp($a['name'], $b['name']);
    });

    // One row per product (keep the idle-est / opened preference already sorted)
    $seen = [];
    $out = [];
    foreach ($scored as $item) {
        $pid = $item['product_id'];
        if (isset($seen[$pid])) {
            continue;
        }
        $seen[$pid] = true;
        $out[] = $item;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
