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

/** True when $haystack starts with $prefix and the prefix is a whole word there. */
function seasonalStartsWithWord(string $haystack, string $prefix): bool {
    if ($prefix === '' || !str_starts_with($haystack, $prefix)) {
        return false;
    }
    if (mb_strlen($haystack) === mb_strlen($prefix)) {
        return true;
    }
    // "melone retato" starts with "melone" (space follows) but "melograno" does not.
    return !preg_match('/[\p{L}\p{N}]/u', mb_substr($haystack, mb_strlen($prefix), 1));
}

/**
 * First meaningful word of a normalized name — the head noun.
 *
 * Italian product names put the head noun first, so this is what decides
 * whether a catalogue entry describes the product at all: "miele di arancia"
 * is honey (matched), "cosce di pollo" is chicken (not cipollotto).
 */
function seasonalHeadToken(string $normalized): string {
    static $stop = [
        'di', 'del', 'della', 'dei', 'degli', 'delle', 'da', 'in', 'con', 'per', 'a', 'e',
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'al', 'allo', 'alla', 'ai',
        'agli', 'alle', 'su', 'se', 'che', 'non', 'ma', 'o', 'nel', 'nei', 'tra', 'fra',
        'bio', 'gusto', 'tipo', 'extra', 'senza', 'fresco', 'fresca', 'freschi', 'fresche',
        'biologico', 'biologica', 'biologiche', 'biologici',
    ];
    foreach (preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
        if (!preg_match('/\p{L}/u', $token)) {
            continue;   // pure numbers, "100%", …
        }
        if (mb_strlen($token) < 3 || in_array($token, $stop, true)) {
            continue;
        }
        return $token;
    }
    return '';
}

/**
 * Score how well one catalogue candidate identifies the queried product.
 * 0 means "not this product". Only the head noun may identify it, so a seller
 * description like "Budino gusto vaniglia da zuccherare" never becomes "zucca".
 */
function seasonalScoreCandidate(string $q, string $head, string $c): int {
    if ($c === $q || ($head !== '' && $c === $head)) {
        return 100;
    }
    if (seasonalStartsWithWord($q, $c) || seasonalStartsWithWord($c, $q)) {
        return 90;      // "zucca a pezzi" → "zucca", "melone" → "melone retato"
    }
    if ($head !== '' && mb_strlen($head) >= 4 && seasonalStartsWithWord($c, $head)) {
        return 85;      // "Cipolla Dorata Biologica" → "cipolla dorata"
    }
    // Inflected tail ("avocados" → "avocado", "melone" → "meloni") — still anchored
    // on the head noun, and only for a single-letter inflection. A two-letter tail
    // bridges different words: that is how "zucchero" became "zucca" (alias
    // "zucche", tail "ro") and the review card offered to remove the sugar.
    if (mb_strlen($c) >= 5) {
        $tail = null;
        if (str_starts_with($q, $c)) {
            $tail = mb_substr($q, mb_strlen($c));
        } elseif (str_starts_with($c, $q)) {
            $tail = mb_substr($c, mb_strlen($q));
        }
        if ($tail !== null && mb_strlen($tail) === 1 && !str_contains($tail, ' ')) {
            return 80;
        }
    }
    return 0;
}

/**
 * Keywords marking produce that was frozen / canned / dried / preserved.
 *
 * Seasonality is a property of fresh produce: frozen basil or peeled tomatoes
 * keep their shelf life all year, so they must never be flagged (nor removed)
 * for being "out of season".
 */
const SEASONAL_PRESERVED_PATTERN = '/surgelat|congelat|abbattut|scatola|barattol|in vetro|vasett|conserve|conservat|sottolio|sottaceto|salamoia|marinat|pelat|passata|sugo|concentrat|essiccat|disidratat|liofilizzat|secc[oh]|marmellat|confettur|sciroppat|al naturale/u';

/**
 * Head nouns that make a product a preserve on their own.
 *
 * "Polpa di pomodoro" is canned pulp, but "pesca noce piatta a polpa gialla"
 * describes a fresh variety — so a bare "polpa" only counts when it is the
 * head noun, never as a qualifier further down the name.
 */
const SEASONAL_PRESERVED_HEADS = ['polpa', 'purea'];

function seasonalIsPreserved(string $name): bool {
    $n = seasonalNormalize($name);
    if (preg_match(SEASONAL_PRESERVED_PATTERN, $n)) {
        return true;
    }
    return in_array(seasonalHeadToken($n), SEASONAL_PRESERVED_HEADS, true);
}

/**
 * Product categories that describe fresh produce.
 *
 * Seasonality is only meaningful for the fruit & vegetable shelf: a jar of
 * dried oregano ("Origano foglie") or an orange-flavoured drink are correctly
 * named after a plant but are on the shelf all year, so they must never be
 * treated as out of season. EverShelf stores both the Italian labels used by
 * the UI and the raw Open Food Facts slugs imported from the product barcode.
 */
const SEASONAL_FRESH_CATEGORIES = [
    'frutta', 'verdura', 'verdure', 'ortofrutta', 'frutta e verdura', 'ortaggi',
];

function seasonalIsFreshCategory(string $category): bool {
    $c = mb_strtolower(trim($category));
    if ($c === '') {
        return false;
    }
    if (in_array($c, SEASONAL_FRESH_CATEGORIES, true)) {
        return true;
    }
    // Open Food Facts slugs: en:fruits, en:vegetables-and-their-products, …
    // Anything else (en:plant-based-foods-and-beverages, en:farming-products,
    // conserve, latticini…) is a pantry shelf, not the fresh produce crate.
    foreach (['fruit', 'vegetable', 'verdur', 'ortofrutta'] as $needle) {
        if (str_contains($c, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * Crops whose *harvest* is seasonal but which are on the shelf all year:
 * cured/stored alliums and roots (onions, garlic, potatoes, carrots),
 * fruit that keeps in cold storage (citrus) and greenhouse
 * staples (tomatoes). The catalogue is a harvest calendar — without this list
 * the app would stop suggesting onions in October, when every shop has them.
 *
 * Long enough to be a safe prefix, anchored on the head noun:
 * "mel[ae]" is deliberately *not* here, it only ever matched "melone" by accident.
 */
const SEASONAL_ALL_YEAR_HEADS = '/^(cipoll|scalogn|agli|porr|patat|carot|barbabietol|limon|aranc|mandarin|clementin|pomodor)/u';

/**
 * The other half of the all-year list (apples/pears, squash, turnips): stems too
 * short to be a prefix, because "mela" swallows "melanzane", "zucca" swallows
 * "zucchine" and "zucchero", and "rapa" swallows "rapanelli". "Zucchine" and
 * "melanzane" are exactly the summer produce this list exists to hide in winter,
 * so these are matched as whole head nouns instead, plurals included
 * ("Mele Fuji", "Zucca Delica", "Rape Rosse"). See seasonalIsAllYearCrop().
 */
const SEASONAL_ALL_YEAR_WORDS = ['rapa', 'rape', 'zucca', 'zucche', 'mela', 'mele', 'pera', 'pere'];

function seasonalIsAllYearCrop(string $name): bool {
    $head = seasonalHeadToken(seasonalNormalize($name));
    if ($head === '') {
        return false;
    }
    return in_array($head, SEASONAL_ALL_YEAR_WORDS, true)
        || (bool)preg_match(SEASONAL_ALL_YEAR_HEADS, $head);
}

/**
 * Should fresh produce be hidden from the shopping list because the current
 * month cannot supply it? Preserved forms (frozen, canned, dried), non-produce
 * categories and all-year crops (see above) always answer false, so pantry
 * staples keep working whatever the calendar says.
 */
function seasonalProduceOutOfSeason(string $name, string $category = '', ?int $month = null): bool {
    if (!seasonalIsFreshCategory($category) || seasonalIsAllYearCrop($name)) {
        return false;
    }
    $match = seasonalMatchProduce($name, $month);
    return $match !== null && ($match['status'] ?? '') === 'off';
}

/**
 * Ice cream / semifreddo are not "fresh produce" but are strongly seasonal for
 * most households. Hide them from smart auto-add outside late spring–summer.
 */
function seasonalIceCreamOutOfSeason(string $name, string $category = '', ?int $month = null): bool {
    $month = $month ?? (int)date('n');
    // Italy-oriented default: May–September is fine; the rest of the year is off.
    if ($month >= 5 && $month <= 9) {
        return false;
    }
    $hay = mb_strtolower(trim($name . ' ' . $category));
    return (bool)preg_match('/\b(gelato|semifreddo|ice[\s-]?cream|eis)\b/u', $hay);
}

/** True when the shopping list / smart engine should hide this name for the calendar. */
function seasonalShoppingItemOutOfSeason(string $name, string $category = '', ?int $month = null): bool {
    return seasonalProduceOutOfSeason($name, $category, $month)
        || seasonalIceCreamOutOfSeason($name, $category, $month);
}

/**
 * @return array{item:array,status:string,score:int}|null
 */
function seasonalMatchProduce(string $name, ?int $month = null): ?array {
    $month = $month ?? (int)date('n');
    $q = seasonalNormalize($name);
    if ($q === '' || mb_strlen($q) < 3 || seasonalIsPreserved($q)) {
        return null;
    }
    $head = seasonalHeadToken($q);
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
            $score = seasonalScoreCandidate($q, $head, $c);
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
 * The tip is a stable i18n key ('shopping.seasonal_tip_<month>') that the client
 * resolves through t(), so nothing user-visible is translated server-side and all
 * six locales get their own wording.
 *
 * @param list<array{name?:string,raw_name?:string}> $shoppingItems
 * @return array{month:int,tip_key:string,out_of_season:list,suggest_add:list,source:string}
 */
function seasonalReviewShopping(PDO $db, array $shoppingItems): array {
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
        if (function_exists('computeShoppingName')) {
            $gk = seasonalNormalize(computeShoppingName($label));
            if ($gk !== '') {
                $onListNorm[$gk] = true;
            }
        }
        if (seasonalIceCreamOutOfSeason($label)) {
            $outOfSeason[] = [
                'name' => $label,
                'raw_name' => (string)($row['raw_name'] ?? $label),
                'seasonal_name' => $label,
                'kind' => 'dessert',
                'status' => 'off',
            ];
            continue;
        }
        $match = seasonalMatchProduce($label, $month);
        if (!$match || $match['status'] !== 'off') {
            continue;
        }
        // Same rule the smart list applies (seasonalProduceOutOfSeason): cured or
        // stored alliums and roots, cold-storage fruit and greenhouse staples are
        // on the shelf all year. Without this the card asked to remove the onions
        // in October while the smart list kept suggesting them.
        // The list's category gate cannot be mirrored here — a shopping_list row
        // carries no category — so a jar of "Origano foglie" may still be flagged.
        if (seasonalIsAllYearCrop($label)) {
            continue;
        }
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
            SELECT p.name, p.category, p.shopping_name
            FROM inventory i
            JOIN products p ON p.id = i.product_id
            WHERE i.quantity > 0.001
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $stockNorm[seasonalNormalize((string)$r['name'])] = true;
            $sn = trim((string)($r['shopping_name'] ?? ''));
            if ($sn !== '') {
                $stockNorm[seasonalNormalize($sn)] = true;
            }
            if (function_exists('computeShoppingName')) {
                $gk = seasonalNormalize(computeShoppingName((string)$r['name']));
                if ($gk !== '') {
                    $stockNorm[$gk] = true;
                }
            }
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
        $shopKey = '';
        if (function_exists('computeShoppingName')) {
            $shopKey = seasonalNormalize(computeShoppingName($name));
        }
        // Skip if already on list or in stock (name, first token, or shopping generic).
        $listed = isset($onListNorm[$n]) || isset($onListNorm[$first])
            || ($shopKey !== '' && isset($onListNorm[$shopKey]));
        $stocked = isset($stockNorm[$n]) || isset($stockNorm[$first])
            || ($shopKey !== '' && isset($stockNorm[$shopKey]));
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
    $merged = array_merge($preferBought, $rest);
    // One tip per buyable family ("Avocado" / "Avocado Hass" → single card).
    $seenGeneric = [];
    $suggestAdd = [];
    foreach ($merged as $s) {
        $key = seasonalNormalize((string)($s['name'] ?? ''));
        if (function_exists('computeShoppingName')) {
            $gk = seasonalNormalize(computeShoppingName((string)($s['name'] ?? '')));
            if ($gk !== '') {
                $key = $gk;
            }
        }
        $first = seasonalNormalize(explode(' ', (string)($s['name'] ?? ''))[0] ?? '');
        if ($key === '' || isset($seenGeneric[$key]) || ($first !== '' && isset($seenGeneric[$first]))) {
            continue;
        }
        $seenGeneric[$key] = true;
        if ($first !== '') {
            $seenGeneric[$first] = true;
        }
        $suggestAdd[] = $s;
        if (count($suggestAdd) >= 8) {
            break;
        }
    }

    // Stable key: the client resolves it (see _localizeSeasonalTip in app.js), so the
    // payload stays language-neutral and every locale gets its own wording.
    $tipKey = 'shopping.seasonal_tip_' . $month;

    return [
        'month' => $month,
        'tip_key' => $tipKey,
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
