<?php
/**
 * EverShelf — "recipe → shopping list" with pantry deduction (Q2).
 *
 * The AI already flags the ingredients it could not find in the pantry
 * (recipe['shopping_suggestions']), but that verdict is frozen at generation time
 * and carries no quantity: after a shopping trip the suggestion is stale, and a
 * pantry that only *partly* covers a recipe (200 g of rice for a 500 g risotto)
 * is never mentioned at all — the user re-types the difference by hand.
 *
 * This module recomputes the gap at the moment the button is pressed:
 *
 *     need (recipe quantity, normalised to g / ml / pz)
 *  −  have (in-stock rows of the same family, matched by product_id, then by the
 *           shopping-family key, then by the first significant name token — the
 *           exact rule stock_for_name() shows in the UI)
 *  =  what actually has to be bought
 *
 * …and inserts only that through shoppingAddItemsCore(), so the Bring! sync, the
 * purchase blocklist, the generic-name normalisation and the HA webhook keep
 * working exactly as they do for a manual add.
 *
 * Everything above the HTTP handler is pure (PDO + arrays in, arrays out) and is
 * covered by scripts/test-recipe-shopping.php.
 *
 * Action: recipe_shopping_add (POST, CSRF-protected, blocked in demo mode).
 * `dry_run: true` computes the same plan and writes nothing, which is what the
 * recipe page calls on render to draw the per-ingredient checkboxes.
 */

/** Canonical family of a unit: g (mass) · ml (volume) · pz (count) · '' (container/unknown). */
function evershelfUnitFamily(string $unit): string
{
    $u = mb_strtolower(trim($unit));
    if ($u === '') {
        return '';
    }
    if (in_array($u, ['g', 'gr', 'gramm', 'grammi', 'grammo', 'kg', 'chilo', 'chili',
                      'chilogrammi', 'hg', 'etto', 'etti', 'mg'], true)) {
        return 'g';
    }
    if (in_array($u, ['ml', 'millilitri', 'millilitro', 'cl', 'dl', 'l', 'lt', 'litri', 'litro'], true)) {
        return 'ml';
    }
    if (in_array($u, ['pz', 'pezzi', 'pezzo', 'pcs', 'unita', 'unità'], true)) {
        return 'pz';
    }
    return '';
}

/** Multiplier from a mass/volume/count unit to its canonical family base. */
function evershelfUnitFactor(string $unit): float
{
    static $factors = [
        'g' => 1.0, 'gr' => 1.0, 'gramm' => 1.0, 'grammi' => 1.0, 'grammo' => 1.0,
        'mg' => 0.001, 'hg' => 100.0, 'etto' => 100.0, 'etti' => 100.0,
        'kg' => 1000.0, 'chilo' => 1000.0, 'chili' => 1000.0, 'chilogrammi' => 1000.0,
        'ml' => 1.0, 'millilitri' => 1.0, 'millilitro' => 1.0, 'cl' => 10.0, 'dl' => 100.0,
        'l' => 1000.0, 'lt' => 1000.0, 'litri' => 1000.0, 'litro' => 1000.0,
        'pz' => 1.0, 'pezzi' => 1.0, 'pezzo' => 1.0, 'pcs' => 1.0, 'unita' => 1.0, 'unità' => 1.0,
    ];
    return $factors[mb_strtolower(trim($unit))] ?? 1.0;
}

/**
 * Convert a quantity into the canonical base (g / ml / pz) of its family.
 *
 * Pieces and containers (conf / busta / pacco / barattolo …) carry no size of their
 * own, so they are expanded with the product package when it is measured
 * (3 pz × 250 g = 750 g, and 1 conf of a 500 g product = 500 g). An amount that
 * cannot be expanded is honestly read as a piece count.
 *
 * @return array{qty:float,family:string}
 */
function evershelfBaseQty(float $qty, string $unit, float $packageSize = 0.0, string $packageUnit = ''): array
{
    $family = evershelfUnitFamily($unit);
    if ($family === 'g' || $family === 'ml') {
        return ['qty' => $qty * evershelfUnitFactor($unit), 'family' => $family];
    }
    // Piece counts and containers ('pz', 'conf', 'busta' …) both describe PACKAGES:
    // their real amount is quantity × package size — 3 pz of ricotta × 250 g = 750 g.
    $pkgFamily = evershelfUnitFamily($packageUnit);
    if ($packageSize > 0 && ($pkgFamily === 'g' || $pkgFamily === 'ml')) {
        return ['qty' => $qty * $packageSize * evershelfUnitFactor($packageUnit), 'family' => $pkgFamily];
    }
    return ['qty' => $qty, 'family' => $family !== '' ? $family : 'pz'];
}

/** Trim trailing zeros of a rounded decimal ("1.50" → "1.5", "2.0" → "2"). */
function evershelfTrimNum(float $n, int $decimals = 1): string
{
    $s = number_format($n, $decimals, '.', '');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s;
}

/**
 * Human label in the natural unit of a family: "300 g", "1.5 kg", "2 pz".
 * Language-neutral on purpose — the same symbols are used in all six locales.
 */
function evershelfBaseLabel(float $qty, string $family): string
{
    $qty = round($qty, 3);
    if ($qty <= 0) {
        return '';
    }
    if ($family === 'g' || $family === 'ml') {
        if ($qty >= 1000) {
            return evershelfTrimNum(round($qty / 1000, 2), 2) . ($family === 'g' ? ' kg' : ' l');
        }
        return evershelfTrimNum(round($qty, 1), 1) . ' ' . $family;
    }
    $isWhole = abs($qty - round($qty)) < 0.001;
    return ($isWhole ? (string)(int)round($qty) : evershelfTrimNum(round($qty, 2), 2)) . ' pz';
}

/**
 * Parse a recipe quantity string ("500 g", "1,5 kg", "2 pz", "1 conf", "q.b.") into
 * {qty, unit} — same grammar as assets/js/app.js::_parseRecipeQtyString(), which is
 * what turns the AI's free text into a number in the first place.
 *
 * @return array{qty:float,unit:string} qty is 0 when the string carries no amount.
 */
function evershelfParseRecipeQty(string $qtyStr): array
{
    $pattern = '/(\d+(?:[.,]\d+)?)\s*(gramm\w*|gr|g|kg|chil\w*|millilitr\w*|ml|cl|dl|litr\w*|lt|l'
        . '|pezz\w*|pz|conf\w*|bust\w*|pacc\w*)/iu';
    if (!preg_match($pattern, $qtyStr, $m)) {
        return ['qty' => 0.0, 'unit' => ''];
    }
    $val = (float)str_replace(',', '.', $m[1]);
    $u   = mb_strtolower($m[2]);
    if (str_starts_with($u, 'kg') || str_starts_with($u, 'chil')) {
        return ['qty' => $val * 1000, 'unit' => 'g'];
    }
    if (str_starts_with($u, 'g')) {
        return ['qty' => $val, 'unit' => 'g'];
    }
    if (str_starts_with($u, 'millilitr') || $u === 'ml') {
        return ['qty' => $val, 'unit' => 'ml'];
    }
    if ($u === 'cl') {
        return ['qty' => $val * 10, 'unit' => 'ml'];
    }
    if ($u === 'dl') {
        return ['qty' => $val * 100, 'unit' => 'ml'];
    }
    if (str_starts_with($u, 'litr') || $u === 'lt' || $u === 'l') {
        return ['qty' => $val * 1000, 'unit' => 'ml'];
    }
    if (str_starts_with($u, 'pz') || str_starts_with($u, 'pezz')) {
        return ['qty' => $val, 'unit' => 'pz'];
    }
    return ['qty' => $val, 'unit' => $u]; // conf / busta / pacco: the size comes from the product
}

/**
 * Amount a recipe asks for, in canonical base units.
 * Prefers the AI's original text ("500 g"); falls back to the normalised
 * qty_number + unit fields a client may send instead.
 *
 * @param array<string,mixed> $ing
 * @return array{qty:float,family:string}
 */
function evershelfIngredientNeed(array $ing): array
{
    $parsed = evershelfParseRecipeQty((string)($ing['qty'] ?? ''));
    if ($parsed['qty'] > 0) {
        return evershelfBaseQty($parsed['qty'], $parsed['unit']);
    }
    $qty = (float)($ing['qty_number'] ?? 0);
    if ($qty > 0) {
        return evershelfBaseQty(
            $qty,
            (string)($ing['unit'] ?? $ing['inventory_unit'] ?? ''),
            (float)($ing['default_quantity'] ?? 0),
            (string)($ing['package_unit'] ?? '')
        );
    }
    return ['qty' => 0.0, 'family' => evershelfUnitFamily((string)($ing['unit'] ?? '')) ?: 'pz'];
}

/**
 * Every in-stock inventory row with the product fields needed to compare
 * quantities. Loaded once per request: a recipe has 5–20 ingredients and the
 * inventory table is small.
 *
 * Name matching reuses the shared `evershelfNameTokens()` (shopping_sync.php) so
 * "Carote" also matches "Carote Bio" / "Carote DOP" — one grammar, no drift.
 *
 * @return array<int,array<string,mixed>>
 */
function evershelfPantryRows(PDO $db): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = $db->query("
            SELECT i.product_id, i.quantity, p.unit, i.location,
                   p.name AS product_name, p.shopping_name,
                   p.default_quantity, p.package_unit
            FROM inventory i
            JOIN products p ON p.id = i.product_id
            WHERE i.quantity > 0
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}

/**
 * Stock available for one recipe ingredient, in canonical base units.
 *
 * Rows are matched by explicit product_id, then by the first significant name token
 * (product name or shopping_name) — the same rule the UI shows as "you already have
 * this". Only rows whose family matches the need (g/ml/pz) can be subtracted, so
 * 3 pieces of ricotta never cancel 250 g of ricotta.
 *
 * @param array<string,mixed> $ing
 * @return array{qty:float,family:string,rows:int,comparable:bool,product_id:?int,location:string,raw_name:string}
 */
function evershelfIngredientStock(PDO $db, array $ing, string $needFamily): array
{
    $all     = evershelfPantryRows($db);
    $matched = [];
    $pid     = (int)($ing['product_id'] ?? 0);

    if ($pid > 0) {
        foreach ($all as $row) {
            if ((int)$row['product_id'] === $pid) {
                $matched[] = $row;
            }
        }
    }
    if (!$matched) {
        $tokens = evershelfNameTokens((string)($ing['name'] ?? ''));
        $first  = $tokens[0] ?? '';
        if ($first !== '') {
            foreach ($all as $row) {
                $nameTok = evershelfNameTokens((string)$row['product_name']);
                $shopTok = evershelfNameTokens((string)($row['shopping_name'] ?? ''));
                if (($nameTok[0] ?? '') === $first || ($shopTok[0] ?? '') === $first) {
                    $matched[] = $row;
                }
            }
        }
    }

    $qty        = 0.0;
    $comparable = false;
    foreach ($matched as $row) {
        $base = evershelfBaseQty(
            (float)$row['quantity'],
            (string)$row['unit'],
            (float)$row['default_quantity'],
            (string)$row['package_unit']
        );
        if ($base['family'] !== $needFamily) {
            continue;
        }
        $qty += $base['qty'];
        $comparable = true;
    }

    return [
        'qty'        => round($qty, 3),
        'family'     => $needFamily,
        'rows'       => count($matched),
        'comparable' => $comparable,
        'product_id' => $matched ? (int)$matched[0]['product_id'] : null,
        'location'   => (string)($matched[0]['location'] ?? ''),
        'raw_name'   => (string)($matched[0]['product_name'] ?? ''),
    ];
}



/** Dedupe key of an ingredient name (significant tokens, so "Riso basmati" ≈ "Riso"). */
function evershelfRecipeNameKey(string $name): string
{
    $tokens = evershelfNameTokens($name);
    return $tokens !== [] ? implode(' ', $tokens) : mb_strtolower(trim($name));
}

/**
 * Is this name already on the shopping list? Lets the UI say "già in lista"
 * instead of silently re-adding (and re-notifying Bring!) what was just bought.
 *
 * @return array{in_list:bool,specification:string}
 */
function evershelfShoppingListLookup(PDO $db, string $name): array
{
    if (trim($name) === '') {
        return ['in_list' => false, 'specification' => ''];
    }
    $generic = shoppingResolveGenericName($db, $name, $name) ?: computeShoppingName($name);
    foreach (array_unique(array_filter([$name, $generic])) as $candidate) {
        $stmt = $db->prepare('SELECT specification FROM shopping_list WHERE lower(name) = lower(?) LIMIT 1');
        $stmt->execute([$candidate]);
        $spec = $stmt->fetchColumn();
        if ($spec !== false) {
            return ['in_list' => true, 'specification' => (string)$spec];
        }
    }
    return ['in_list' => false, 'specification' => ''];
}

/**
 * Build the "recipe → shopping list" plan and, unless `dry_run`, insert the rows
 * that are actually missing through shoppingAddItemsCore().
 *
 * @param array<string,mixed> $recipe
 * @param array{only_missing?:bool,dry_run?:bool,selected?:array<int,string>,lang?:string} $opts
 * @return array{items:array<int,array<string,mixed>>,summary:array<string,int>}
 */
function evershelfRecipeShoppingPlan(PDO $db, array $recipe, array $opts = []): array
{
    $onlyMissing = array_key_exists('only_missing', $opts) ? (bool)$opts['only_missing'] : true;
    $dryRun      = !empty($opts['dry_run']);
    $lang        = (string)($opts['lang'] ?? 'en');

    $selected = [];
    foreach ((array)($opts['selected'] ?? []) as $sel) {
        $key = evershelfRecipeNameKey((string)$sel);
        if ($key !== '') {
            $selected[$key] = true;
        }
    }
    $hasSelection = $selected !== [];

    // Candidates: pantry ingredients first (they carry product_id and package data),
    // then the AI's "not in pantry" suggestions, deduped on significant tokens.
    $candidates = [];
    foreach (['ingredients', 'shopping_suggestions'] as $bucket) {
        foreach ((array)($recipe[$bucket] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $name = trim((string)($raw['name'] ?? ''));
            $key  = evershelfRecipeNameKey($name);
            if ($name === '' || $key === '' || isset($candidates[$key])) {
                continue;
            }
            $raw['name']      = $name;
            $candidates[$key] = $raw;
        }
    }

    $items   = [];
    $insert  = [];
    $summary = ['total' => 0, 'missing' => 0, 'partial' => 0, 'covered' => 0,
                'listed' => 0, 'selected' => 0, 'added' => 0, 'updated' => 0, 'skipped' => 0];

    foreach ($candidates as $key => $ing) {
        $need    = evershelfIngredientNeed($ing);
        $needQty = $need['qty'];
        $stock   = evershelfIngredientStock($db, $ing, $need['family']);
        $haveQty = $stock['qty'];

        // Coverage verdict. Stock in another unit (3 pz ricotta vs "250 g" asked)
        // counts as covered: never nag about something the pantry does hold.
        // Free staples (water, salt, pepper, oil) are never nagged about either —
        // strict pantry mode leaves them untracked, so they always look "missing".
        $isStaple = function_exists('recipeIsFreeStaple') && recipeIsFreeStaple((string)$ing['name']);
        if ($isStaple) {
            $state      = 'covered';
            $missingQty = 0.0;
        } elseif ($stock['rows'] > 0 && !$stock['comparable']) {
            $state      = 'covered';
            $missingQty = 0.0;
        } elseif ($needQty <= 0) {
            $state      = $haveQty > 0 ? 'covered' : 'missing';
            $missingQty = 0.0;
        } elseif ($haveQty >= $needQty) {
            $state      = 'covered';
            $missingQty = 0.0;
        } elseif ($haveQty > 0) {
            $state      = 'partial';
            $missingQty = $needQty - $haveQty;
        } else {
            $state      = 'missing';
            $missingQty = $needQty;
        }

        $lookup     = evershelfShoppingListLookup($db, (string)$ing['name']);
        $stateLabel = ($lookup['in_list'] && $state !== 'covered') ? 'listed' : $state;
        $wantKey    = $hasSelection
            ? isset($selected[$key])
            : (!$onlyMissing || $state !== 'covered');

        // What to buy: the gap — or the whole need when the user chose to ignore stock.
        $missingLabel = evershelfBaseLabel($missingQty, $need['family']);
        $specLabel    = $missingLabel !== ''
            ? $missingLabel
            : evershelfBaseLabel($needQty, $need['family']);
        if ($specLabel === '' && $state === 'missing') {
            // No amount in the recipe ("q.b."): keep the recipe's own wording.
            $fallback  = preg_replace('/\s+/u', ' ', trim((string)($ing['qty'] ?? ''))) ?? '';
            $specLabel = mb_substr($fallback, 0, 40);
        }
        $spec = $specLabel !== ''
            ? evershelfTr('recipes.from_recipe_qty', $lang, ['qty' => $specLabel])
            : evershelfTr('recipes.from_recipe', $lang);

        $attempted = false;
        if (!$dryRun && $wantKey) {
            if ($stateLabel === 'listed' || ($onlyMissing && $state === 'covered')) {
                $summary['skipped']++;
            } else {
                $insert[] = [
                    'name'          => (string)$ing['name'],
                    'rawName'       => $stock['raw_name'] !== '' ? $stock['raw_name'] : (string)$ing['name'],
                    'specification' => $spec,
                ];
                $attempted = true;
            }
        }

        $items[] = [
            'name'          => (string)$ing['name'],
            'raw_name'      => $stock['raw_name'] !== '' ? $stock['raw_name'] : (string)$ing['name'],
            'state'         => $stateLabel,
            'need'          => evershelfBaseLabel($needQty, $need['family']),
            'have'          => evershelfBaseLabel($haveQty, $need['family']),
            'missing'       => $missingLabel,
            'family'        => $need['family'],
            'product_id'    => $stock['product_id'],
            'location'      => $stock['location'],
            'in_list'       => $lookup['in_list'],
            'specification' => $spec,
            'staple'        => $isStaple,
            'selected'      => $wantKey,
            'attempted'     => $attempted,
        ];
        $summary['total']++;
        $summary[$stateLabel]++;
        if ($wantKey) {
            $summary['selected']++;
        }
    }

    if (!$dryRun && $insert !== []) {
        $res = shoppingAddItemsCore($db, $insert);
        $summary['added']    = (int)($res['added'] ?? 0);
        $summary['updated']  = (int)($res['updated'] ?? 0);
        $summary['skipped'] += (int)($res['skipped'] ?? 0);
    }
    // `attempted` is internal bookkeeping: after a real insert those rows are on the
    // list, so the UI keeps showing them ticked and labelled. Never returned.
    foreach ($items as $i => $item) {
        if (!$dryRun && !empty($item['attempted'])) {
            $items[$i]['in_list'] = true;
            if ($items[$i]['state'] !== 'covered') {
                $items[$i]['state'] = 'listed';
            }
        }
        unset($items[$i]['attempted']);
    }

    return ['items' => $items, 'summary' => $summary];
}



/**
 * POST ?action=recipe_shopping_add — router entry point.
 * Accepts a full recipe object, or a `recipe_id` which is then loaded from the
 * archive (so an old recipe keeps working even after the client was reloaded).
 */
function recipeShoppingAdd(PDO $db): void
{
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $recipe = is_array($input['recipe'] ?? null) ? $input['recipe'] : null;

    $recipeId = (int)($input['recipe_id'] ?? 0);
    if ($recipe === null && $recipeId > 0) {
        $stmt = $db->prepare('SELECT recipe_json FROM recipes WHERE id = ? LIMIT 1');
        $stmt->execute([$recipeId]);
        $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        $recipe  = is_array($decoded) ? $decoded : null;
    }
    if ($recipe === null || (empty($recipe['ingredients']) && empty($recipe['shopping_suggestions']))) {
        echo json_encode(['success' => false, 'error' => 'No recipe ingredients to plan']);
        return;
    }

    $opts = [
        'only_missing' => !isset($input['only_missing']) || !empty($input['only_missing']),
        'dry_run'      => !empty($input['dry_run']),
        'selected'     => is_array($input['selected'] ?? null) ? $input['selected'] : [],
        'lang'         => substr((string)($input['lang'] ?? 'en'), 0, 5),
    ];

    $plan = ['items' => [], 'summary' => []];
    try {
        dbWithRetry(function () use ($db, $recipe, $opts, &$plan): void {
            $plan = evershelfRecipeShoppingPlan($db, $recipe, $opts);
        });
    } catch (\PDOException $e) {
        EverLog::error('recipeShoppingAdd db error', [
            'event' => 'recipe_shopping_add_db_error',
            'msg'   => $e->getMessage(),
        ]);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database busy — please retry']);
        return;
    }

    // Only real writes are logged: the dry run happens on every recipe render.
    if (!$opts['dry_run']) {
        EverLog::info('recipe shopping add', [
            'event'   => 'recipe_shopping_add',
            'items'   => $plan['summary']['total'] ?? 0,
            'missing' => $plan['summary']['missing'] ?? 0,
            'partial' => $plan['summary']['partial'] ?? 0,
            'covered' => $plan['summary']['covered'] ?? 0,
            'added'   => $plan['summary']['added'] ?? 0,
            'updated' => $plan['summary']['updated'] ?? 0,
        ]);
    }

    echo json_encode(['success' => true, 'dry_run' => $opts['dry_run']] + $plan, JSON_UNESCAPED_UNICODE);
}

