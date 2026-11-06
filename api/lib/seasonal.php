<?php
/**
 * Produce seasonality — per language.
 *
 * The catalog is DATA and it is localized like the vocabulary: one file per language,
 * `data/seasonal_produce_<lang>.json`, and the app reads the one for the language the
 * household chose (falling back to English, then to Italian, so a missing translation
 * never leaves the feature empty). The month/region structure is identical in every
 * file; only the produce names are translated.
 *
 * Adding a language = dropping the file. Nothing in this code knows a produce name.
 */

/** The catalog file for a language, resolved like the vocabulary (lang → en → it). */
function seasonalProducePath(?string $lang = null): string {
    $dir  = dirname(__DIR__) . '/../data/';
    $lang = strtolower(trim((string)($lang ?? env('APP_LANG', 'it'))));
    $lang = (string)preg_replace('/[^a-z]/', '', $lang);
    foreach ([$lang, 'en', 'it'] as $candidate) {
        if ($candidate !== '' && is_readable($dir . 'seasonal_produce_' . $candidate . '.json')) {
            return $dir . 'seasonal_produce_' . $candidate . '.json';
        }
    }
    return $dir . 'seasonal_produce_it.json';
}

/** Languages that still need their own seasonal catalog (translators' checklist). */
function seasonalMissingLocales(): array {
    $dir = dirname(__DIR__) . '/../data/';
    $missing = [];
    foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
        if (!is_readable($dir . 'seasonal_produce_' . $lang . '.json')) {
            $missing[] = $lang;
        }
    }
    return $missing;
}

function seasonalLoadCatalog(?string $lang = null): array {
    static $cache = [];
    $key = seasonalProducePath($lang);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!is_readable($key)) {
        $cache[$key] = ['items' => [], 'source' => ''];
        return $cache[$key];
    }
    $raw = json_decode((string)file_get_contents($key), true);
    $cache[$key] = is_array($raw) ? $raw : ['items' => [], 'source' => ''];
    return $cache[$key];
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
const SEASONAL_PRESERVED_HEADS = ['polpa', 'purea',
    // Dried herbs and spices are on the shelf all year: "Spezie Origano foglie" is not a
    // vegetable, and hiding it in October is the bug the household reported. A FRESH bunch
    // still carries its own name ("Basilico fresco") and is matched by the catalogue below.
    'origano', 'basilico', 'prezzemolo', 'salvia', 'rosmarino', 'alloro', 'timo',
    'maggiorana', 'aneto', 'erba', 'spezie', 'spezia', 'curcuma', 'paprika', 'zenzero',
    'cannella', 'zafferano', 'curry', 'senape', 'peperoncino', 'noce', 'chiodi',
];

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
    if (seasonalIsAllYearCrop($name) || seasonalIsPreserved($name)) {
        return false;
    }
    // Explicit pantry categories (conserve, latticini, altro…) never hide.
    // Empty/unknown category: trust the produce-name match so a mistagged
    // "Peperoni" cannot keep reappearing on the list after the user removes it.
    $cat = trim($category);
    if ($cat !== '' && !seasonalIsFreshCategory($cat)) {
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

/** Buyable generic key used for seasonal dismiss / dedupe. */
function seasonalGenericKey(string $name): string {
    $n = trim($name);
    if ($n === '') {
        return '';
    }
    if (function_exists('computeShoppingName')) {
        $g = trim((string)computeShoppingName($n));
        if ($g !== '') {
            return seasonalNormalize($g);
        }
    }
    return seasonalNormalize($n);
}

/** @return array<string,array{until:string}> */
function seasonalLoadDismissed(PDO $db): array {
    try {
        $raw = $db->query("SELECT value FROM app_settings WHERE key = 'seasonal_dismissed'")->fetchColumn();
        $map = $raw ? json_decode((string)$raw, true) : [];
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $k => $v) {
            $key = seasonalNormalize((string)$k);
            if ($key === '') {
                continue;
            }
            if (is_array($v) && !empty($v['until'])) {
                $out[$key] = ['until' => (string)$v['until']];
            } elseif (is_string($v) && $v !== '') {
                $out[$key] = ['until' => $v];
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function seasonalSaveDismissed(PDO $db, array $map): void {
    $db->prepare(
        "INSERT INTO app_settings (key, value, updated_at) VALUES ('seasonal_dismissed', ?, datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at"
    )->execute([json_encode($map, JSON_UNESCAPED_UNICODE)]);
}

/** True when a dismiss key is still active for the given calendar month. */
function seasonalIsDismissedKey(array $dismissed, string $key, ?int $month = null): bool {
    $key = seasonalNormalize($key);
    if ($key === '' || !isset($dismissed[$key]['until'])) {
        return false;
    }
    $until = (string)$dismissed[$key]['until'];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $until, $m)) {
        return false;
    }
    $month = $month ?? (int)date('n');
    $nowYm = ((int)date('Y') * 100) + $month;
    $untilYm = ((int)$m[1] * 100) + (int)$m[2];
    return $nowYm < $untilYm;
}

function seasonalIsDismissed(PDO $db, string $name, ?int $month = null): bool {
    $dismissed = seasonalLoadDismissed($db);
    $keys = array_filter([
        seasonalGenericKey($name),
        seasonalNormalize($name),
        seasonalNormalize(explode(' ', trim($name))[0] ?? ''),
    ]);
    foreach ($keys as $k) {
        if (seasonalIsDismissedKey($dismissed, $k, $month)) {
            return true;
        }
    }
    return false;
}

/**
 * Remember an out-of-season remove until the produce's next peak month,
 * so smart auto-add cannot resurrect it while it is still off-season.
 */
function seasonalMarkDismissed(PDO $db, string $name): void {
    $match = seasonalMatchProduce($name);
    $peak = array_map('intval', $match['item']['peak'] ?? []);
    $month = (int)date('n');
    $year = (int)date('Y');
    $nextPeak = null;
    sort($peak);
    foreach ($peak as $m) {
        if ($m > $month) {
            $nextPeak = $m;
            break;
        }
    }
    if ($nextPeak === null && $peak !== []) {
        $nextPeak = (int)$peak[0];
        $year++;
    }
    if ($nextPeak === null) {
        $nextPeak = $month;
        $year++;
    }
    $until = sprintf('%04d-%02d', $year, $nextPeak);
    $map = seasonalLoadDismissed($db);
    foreach ([$name, seasonalGenericKey($name)] as $label) {
        $gk = seasonalGenericKey($label);
        if ($gk === '') {
            continue;
        }
        $map[$gk] = ['until' => $until];
    }
    $nowStamp = sprintf('%04d-%02d', (int)date('Y'), $month);
    foreach ($map as $k => $entry) {
        if (((string)($entry['until'] ?? '')) <= $nowStamp) {
            unset($map[$k]);
        }
    }
    seasonalSaveDismissed($db, $map);
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

    // Stock names + kinds currently in the pantry (fresh produce only).
    $stockNorm = [];
    $stockKinds = [];
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
            $mStock = seasonalMatchProduce((string)$r['name'], $month);
            $kind = (string)($mStock['item']['kind'] ?? '');
            if ($kind !== '') {
                $stockKinds[$kind] = ($stockKinds[$kind] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {
        $rows = [];
    }

    // Purchases (in) and real consumption (out) — habits, not calendar filler.
    // Kept apart on purpose: "you usually buy it" and "you eat it often" are
    // different reasons and the card states one of them.
    $boughtBoost = [];
    $consumedBoost = [];
    $consumedKinds = [];
    $boughtKinds = [];
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
            $bn = seasonalNormalize((string)$b['name']);
            $boughtBoost[$bn] = (int)$b['c'];
            $mBuy = seasonalMatchProduce((string)$b['name'], $month);
            $bk = (string)($mBuy['item']['kind'] ?? '');
            if ($bk !== '') {
                $boughtKinds[$bk] = ($boughtKinds[$bk] ?? 0) + (int)$b['c'];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $used = $db->query("
            SELECT p.name, p.shopping_name, COUNT(*) AS c, SUM(t.quantity) AS qty
            FROM transactions t
            JOIN products p ON p.id = t.product_id
            WHERE t.type IN ('out','waste') AND t.undone = 0
              AND t.created_at >= date('now', '-180 days')
            GROUP BY p.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($used as $u) {
            $mUse = seasonalMatchProduce((string)$u['name'], $month);
            $uk = (string)($mUse['item']['kind'] ?? '');
            if ($uk !== '') {
                $consumedKinds[$uk] = ($consumedKinds[$uk] ?? 0) + max(1, (int)$u['c']);
            }
            // Same three keys the matcher probes (name, first token, buyable family).
            $usedName = (string)$u['name'];
            $hits = 1 + (int)$u['c'];
            foreach (array_unique([
                seasonalNormalize($usedName),
                seasonalNormalize(explode(' ', $usedName)[0] ?? $usedName),
                seasonalGenericKey((string)($u['shopping_name'] ?? '') ?: $usedName),
            ]) as $ukey) {
                if ($ukey !== '') {
                    $consumedBoost[$ukey] = ($consumedBoost[$ukey] ?? 0) + $hits;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $dismissed = seasonalLoadDismissed($db);
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
        $shopKey = seasonalGenericKey($name);
        if (seasonalIsDismissedKey($dismissed, $shopKey !== '' ? $shopKey : $n, $month)
            || seasonalIsDismissedKey($dismissed, $first, $month)) {
            continue;
        }
        // Skip if already on list or in stock (name, first token, or shopping generic).
        $listed = isset($onListNorm[$n]) || isset($onListNorm[$first])
            || ($shopKey !== '' && isset($onListNorm[$shopKey]));
        $stocked = isset($stockNorm[$n]) || isset($stockNorm[$first])
            || ($shopKey !== '' && isset($stockNorm[$shopKey]));
        if ($listed || $stocked) {
            continue;
        }

        $kind = (string)($item['kind'] ?? '');
        $boughtCount   = (int)($boughtBoost[$n] ?? $boughtBoost[$first] ?? ($shopKey !== '' ? ($boughtBoost[$shopKey] ?? 0) : 0));
        $consumedCount = (int)($consumedBoost[$n] ?? $consumedBoost[$first] ?? ($shopKey !== '' ? ($consumedBoost[$shopKey] ?? 0) : 0));
        $kindEat   = $kind !== '' ? (int)($consumedKinds[$kind] ?? 0) : 0;
        $kindBuy   = $kind !== '' ? (int)($boughtKinds[$kind] ?? 0) : 0;
        $kindStock = $kind !== '' ? (int)($stockKinds[$kind] ?? 0) : 0;

        // Score the evidence, not the calendar: a card must be justified by
        // something the household demonstrably does. Specific evidence first
        // (this produce, or its buyable family), the coarse kind-level habit as
        // a lower-ranked fallback — and nothing at all for a produce they never
        // touch, which is what made the old card look random.
        $score = 0;
        $reasonKey = '';
        if ($boughtCount > 0) {
            $score = 100 + min(40, $boughtCount * 8);
            $reasonKey = 'shopping.seasonal_reason_bought';
        } elseif ($consumedCount > 0) {
            $score = 90 + min(30, $consumedCount * 6);
            $reasonKey = 'shopping.seasonal_reason_often_eats';
        } elseif ($kindEat >= 3) {
            $score = 60 + min(20, $kindEat);
            $reasonKey = 'shopping.seasonal_reason_often_eats';
        } elseif ($kindBuy >= 2) {
            $score = 40 + min(15, $kindBuy);
            $reasonKey = 'shopping.seasonal_reason_same_kind';
        } else {
            continue;
        }
        // Boosters: they reorder already-justified cards, never create one.
        if ($kindStock > 0) {
            $score += 10;
        }
        if (count($peak) <= 3) {
            $score += 5;
        }
        $suggest[] = [
            'name' => $name,
            'kind' => $kind,
            'status' => 'peak',
            'score' => $score,
            'bought_before' => $boughtCount > 0,
            'reason_key' => $reasonKey,
        ];
    }
    usort($suggest, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));
    // One tip per buyable family ("Avocado" / "Avocado Hass" → single card).
    $seenGeneric = [];
    $suggestAdd = [];
    foreach ($suggest as $s) {
        $key = seasonalGenericKey((string)($s['name'] ?? ''));
        if ($key === '') {
            $key = seasonalNormalize((string)($s['name'] ?? ''));
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
        if (count($suggestAdd) >= 6) {
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
        $isOpened = staleRowLooksOpened($r);
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

/**
 * Legacy/fractional way to tell an opened pack without `opened_at`.
 * Shared by the idle list and the forgotten-expiry list so the two agree.
 */
function staleRowLooksOpened(array $row): bool {
    if (trim((string)($row['opened_at'] ?? '')) !== '') {
        return true;
    }
    $qty = (float)($row['quantity'] ?? 0);
    $def = (float)($row['default_quantity'] ?? 0);
    $unit = (string)($row['unit'] ?? 'pz');
    if ($unit === 'conf') {
        return abs($qty - round($qty)) > 0.01;
    }
    if ($def > 0) {
        $rem = abs($qty - round($qty / $def) * $def);
        return $rem > ($def * 0.02) && $qty < ($def * 0.98);
    }
    // tiny leftover without a package size — an opened remnant
    return in_array($unit, ['g', 'ml'], true) && $qty > 0 && $qty < 50;
}

/**
 * Forgotten stock that is about to expire: the N nearest expiry dates among the rows
 * nobody uses (never used, or idle for `$minDays`), with the date itself in the payload.
 *
 * The old "Dimenticati" list was a rotation of the longest-idle packs, which could show
 * a sealed box of salt while a yoghurt expiring tomorrow was missing: expiry first, then
 * the idle rule decides whether the row belongs here at all.
 *
 * @return list<array>
 */
function forgottenExpiringItems(PDO $db, int $limit = 5, int $minDays = 21): array {
    $limit = max(1, min(10, $limit));
    $minDays = max(7, min(365, $minDays));

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
            i.expiry_date,
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
          AND IFNULL(i.expiry_date, '') <> ''
          AND LOWER(IFNULL(p.category, '')) NOT IN ('igiene', 'pulizia', 'hygiene', 'cleaning')
        ORDER BY date(i.expiry_date) ASC
    ";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $today = strtotime(date('Y-m-d'));
    $out = [];
    foreach ($rows as $r) {
        if (function_exists('isInventoryDepleted') && isInventoryDepleted($r)) {
            continue;
        }
        $ref = $r['last_out'] ?: ($r['opened_at'] ?: ($r['added_at'] ?? null));
        $neverUsed = empty($r['last_out']);
        $days = $ref ? (int)round((time() - strtotime((string)$ref)) / 86400) : 0;
        // Used recently: not forgotten, whatever the expiry says.
        if (!$neverUsed && $days < $minDays) {
            continue;
        }
        $expiryTs = strtotime((string)$r['expiry_date']);
        if ($expiryTs === false) {
            continue;
        }
        $out[] = [
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
            'opened_at' => trim((string)($r['opened_at'] ?? '')) !== '' ? $r['opened_at'] : null,
            'is_opened' => staleRowLooksOpened($r),
            'days_unused' => $days,
            'never_used' => $neverUsed,
            'expiry_date' => (string)$r['expiry_date'],
            'days_to_expiry' => (int)round(($expiryTs - $today) / 86400),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $byExpiry = $a['days_to_expiry'] <=> $b['days_to_expiry'];
        return $byExpiry !== 0 ? $byExpiry : ($b['days_unused'] <=> $a['days_unused']);
    });

    return array_slice($out, 0, $limit);
}
