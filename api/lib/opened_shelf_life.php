<?php
/**
 * PAO — the period after opening, from DATA (data/opened_shelf_life.json).
 *
 * Why this exists: the printed date on a package is only valid while the package is
 * sealed. Once it is opened the clock that matters is the post-opening stability, and the
 * old code answered it in two different ways — a 200-line regex chain in database.php for
 * the stored date, an AI call (paid, non-deterministic, sometimes hallucinating "60 days
 * for yoghurt") for the rest. This is the deterministic half: one table, two thresholds per
 * macro-family and storage place, no model call and no network.
 *
 *   warn      the food starts to lose quality: "check it"
 *   critical  almost certainly spoiled: "probably gone"
 *
 * Sources: USDA FoodKeeper (post-opening storage), EFSA/EC guidance on "best before" vs
 * PAO, and Open Food Facts categories for the family mapping. The words are in the JSON,
 * never here (one vocabulary, one place to change a name).
 *
 * The *stored* shelf life (opened_at + N days, written in the DB when a package is opened)
 * keeps its own product-specific engine: this table decides the ALERT, so nothing that is
 * already in the pantry changes its date behind the user's back.
 */

/**
 * The table, read once per request. A missing or broken file is not fatal: the callers
 * fall back to the location defaults and the app keeps working.
 */
function openedShelfLifeData(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $path = dirname(__DIR__, 2) . '/data/opened_shelf_life.json';
    $raw  = is_readable($path) ? (string)file_get_contents($path) : '';
    $parsed = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($parsed) || empty($parsed['families'])) {
        EverLog::warn('PAO table missing or unreadable', ['event' => 'pao_table_missing', 'path' => $path]);
        $parsed = [
            'defaults' => [
                'frigo'    => ['warn' => 5,  'critical' => 10],
                'dispensa' => ['warn' => 20, 'critical' => 45],
                'freezer'  => ['warn' => 60, 'critical' => 120],
            ],
            'families'   => [],
            'categories' => [],
            'locations'  => [],
        ];
    }
    $data = $parsed;
    return $data;
}

/** 'frigo' | 'freezer' | 'dispensa' — a custom location is a pantry shelf by default. */
function openedShelfLifeLocationKey(string $location): string
{
    $loc = mb_strtolower(trim($location));
    if ($loc === '') {
        return 'dispensa';
    }
    $map = (array)(openedShelfLifeData()['locations'] ?? []);
    foreach ($map as $key => $aliases) {
        foreach ((array)$aliases as $alias) {
            if ($loc === mb_strtolower((string)$alias)) {
                return (string)$key;
            }
        }
    }
    return in_array($loc, ['frigo', 'freezer', 'dispensa'], true) ? $loc : 'dispensa';
}

/** One keyword of the table as a matcher: prefix (`biscott*`) or whole word, CJK aware. */
function openedShelfLifeKeywordMatches(string $haystack, string $keyword): bool
{
    $word = mb_strtolower(trim($keyword));
    if ($word === '' || $haystack === '') {
        return false;
    }
    if (preg_match('/[\x{3400}-\x{9FFF}]/u', $word)) {
        return mb_stripos($haystack, $word) !== false;
    }
    $prefix = str_ends_with($word, '*');
    if ($prefix) {
        $word = rtrim($word, '*');
    }
    $edge = '(?<![\p{L}\p{N}])';
    $tail = $prefix ? '[\p{L}\p{N}]*' : '';
    $re = '/' . $edge . preg_quote($word, '/') . $tail . '(?![\p{L}\p{N}])/iu';
    return preg_match($re, $haystack) === 1;
}


/**
 * The family a product belongs to: keywords first (they are specific), then the category,
 * then `other`. Returns the family row plus its `id`.
 */
function openedShelfLifeFamily(string $name, string $category): array
{
    $data = openedShelfLifeData();
    $n = mb_strtolower(trim($name));
    foreach ((array)($data['families'] ?? []) as $family) {
        $id = (string)($family['id'] ?? '');
        // Non-food is a category fact, not a word: it must not swallow "sale" or "sapone".
        if ($id === 'non_food') {
            continue;
        }
        foreach ((array)($family['match'] ?? []) as $keyword) {
            if (openedShelfLifeKeywordMatches($n, (string)$keyword)) {
                return $family + ['id' => $id, 'matched' => (string)$keyword];
            }
        }
    }
    $cat = mb_strtolower(trim($category));
    $wanted = (string)($data['categories'][$cat] ?? 'other');
    foreach ((array)($data['families'] ?? []) as $family) {
        if ((string)($family['id'] ?? '') === $wanted) {
            return $family + ['id' => $wanted, 'matched' => 'category:' . $cat];
        }
    }
    return ['id' => 'other', 'matched' => '', 'warn' => null, 'critical' => null];
}

/**
 * The two thresholds of one product in one place:
 * `['warn' => int, 'critical' => int, 'table_warn' => int, 'table_critical' => int,
 *   'family' => string, 'location' => string, 'icon' => string, 'cook_after' => int,
 *   'cook_hint_key' => string]`.
 * A family that says nothing about this place inherits the defaults, so a yoghurt that
 * ends up in the pantry keeps a short clock instead of borrowing a shelf life.
 *
 * `icon` is the family's own mark (a wine is not a soft drink, whatever its category says)
 * and `cook_after` is the day a drink stops being something to pour and becomes an
 * ingredient: from there the row is a kitchen job, not a waste decision.
 *
 * `$overrides` is the household's own map (`paoAverageMap()`, keyed `family|place`): when they
 * corrected this family in this place, their AVERAGE is what the pantry uses, and
 * `table_warn`/`table_critical` keep the data table's numbers so the edit sheet can say where
 * the value came from and offer to go back to it.
 *
 * @param array<string,array{warn:int,critical:int,sample:int}>|null $overrides
 */
function openedShelfLifeThresholds(string $name, string $category, string $location, ?array $overrides = null): array
{
    $loc = openedShelfLifeLocationKey($location);
    $family = openedShelfLifeFamily($name, $category);
    $spec = $family[$loc] ?? null;
    if (!is_array($spec)) {
        $spec = (array)(openedShelfLifeData()['defaults'][$loc] ?? ['warn' => 5, 'critical' => 10]);
    }
    $tableWarn = max(0, (int)($spec['warn'] ?? 5));
    $tableCritical = max($tableWarn + 1, (int)($spec['critical'] ?? 10));
    $familyId = (string)($family['id'] ?? 'other');
    // The household's own numbers win over the table's opinion — a fridge that keeps a yoghurt
    // 3-6 days is the app's guess, and the person in front of the shelf knows better.
    $own = is_array($overrides) ? ($overrides[$familyId . '|' . $loc] ?? null) : null;
    $warn = $tableWarn;
    $critical = $tableCritical;
    if (is_array($own) && (int)($own['warn'] ?? 0) > 0) {
        $warn = max(1, (int)$own['warn']);
        $critical = max($warn, (int)($own['critical'] ?? $warn));
    }
    return [
        'warn'           => $warn,
        'critical'       => $critical,
        'table_warn'     => $tableWarn,
        'table_critical' => $tableCritical,
        'own'            => is_array($own) ? (int)($own['sample'] ?? 0) : 0,
        'family'         => $familyId,
        'location'       => $loc,
        'icon'           => (string)($family['icon'] ?? ''),
        'cook_after'     => max(0, (int)($family['cook_after'] ?? 0)),
        'cook_hint_key'  => (string)($family['cook_hint_key'] ?? ''),
    ];
}

/**
 * The family's own icon, or '' when it has none (a soft drink stays the category emoji).
 * Used by the pantry list, which asks it only for beverages: the walk is a keyword scan.
 */
function openedShelfLifeFamilyIcon(string $name, string $category): string
{
    $family = openedShelfLifeFamily($name, $category);
    return (string)($family['icon'] ?? '');
}

/** 'ok' | 'warn' | 'critical' — the state machine of the banner, nothing else. */
function openedShelfLifeState(int $daysOpen, int $warn, int $critical): string
{
    if ($critical > 0 && $daysOpen >= $critical) {
        return 'critical';
    }
    if ($warn > 0 && $daysOpen >= $warn) {
        return 'warn';
    }
    return 'ok';
}

/**
 * Everything the UI needs about one opened row, or null when it was never opened.
 *
 * `cook_only` is the answer to "should I throw this away?": from `cook_after` days an
 * opened wine is no longer a glass to pour, but it is still an ingredient — so the row
 * asks to be cooked with, not binned. The flag is DATA (`cook_after` on the family), the
 * sentence the UI shows comes as `cook_hint_key`.
 *
 * `$overrides` carries the household's own PAO averages (`paoAverageMap()`): the edit sheet
 * writes them, and every clock this app shows must read them or two screens would disagree.
 *
 * @param array<string,mixed> $item inventory row (needs name, category, location, opened_at)
 * @param array<string,array{warn:int,critical:int,sample:int}>|null $overrides
 * @return array{days_open:int,warn:int,critical:int,family:string,state:string,score:float,
 *               icon:string,cook_only:bool,cook_after:int,cook_hint_key:string}|null
 */
function openedShelfLifeSummary(array $item, ?array $overrides = null): ?array
{
    $openedAt = trim((string)($item['opened_at'] ?? ''));
    if ($openedAt === '') {
        return null;
    }
    $ts = strtotime($openedAt);
    if ($ts === false) {
        return null;
    }
    $today = strtotime(date('Y-m-d'));
    $openedDay = strtotime(date('Y-m-d', $ts));
    $days = (int)max(0, round(($today - $openedDay) / 86400));

    $t = openedShelfLifeThresholds(
        (string)($item['name'] ?? ''),
        (string)($item['category'] ?? ''),
        (string)($item['location'] ?? ''),
        $overrides
    );
    $state = openedShelfLifeState($days, $t['warn'], $t['critical']);
    $score = $t['critical'] > 0 ? round($days / $t['critical'], 3) : 0.0;
    $cookAfter = (int)($t['cook_after'] ?? 0);
    $cookOnly = $cookAfter > 0 && $days >= $cookAfter;

    return [
        'days_open'     => $days,
        'warn'          => $t['warn'],
        'critical'      => $t['critical'],
        'family'        => $t['family'],
        'state'         => $state,
        'score'         => $score,
        'icon'          => (string)($t['icon'] ?? ''),
        'cook_only'     => $cookOnly,
        'cook_after'    => $cookAfter,
        'cook_hint_key' => $cookOnly ? (string)($t['cook_hint_key'] ?? '') : '',
    ];
}
