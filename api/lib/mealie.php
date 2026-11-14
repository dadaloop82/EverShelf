<?php
/**
 * Mealie recipe manager integration with local offline cache.
 *
 * Env:
 *   MEALIE_URL, MEALIE_API_TOKEN — online API
 *   RECIPE_SOURCE — gemini | mealie | auto (default: auto if Mealie/cache available)
 *   MEALIE_OFFLINE — false | true | auto (default auto: fallback to cache when offline)
 *   MEALIE_CACHE_SYNC_DAYS — refresh interval for full sync (default 7)
 */

function mealieConfigured(): bool {
    $url = trim(env('MEALIE_URL', ''));
    $tok = trim(env('MEALIE_API_TOKEN', ''));
    return $url !== '' && $tok !== '';
}

function mealieBaseUrl(): string {
    return rtrim(trim(env('MEALIE_URL', '')), '/');
}

function mealieOfflineMode(): string {
    $m = strtolower(trim(env('MEALIE_OFFLINE', 'auto')));
    return in_array($m, ['true', 'false', 'auto'], true) ? $m : 'auto';
}

function mealieCacheSyncDays(): int {
    return max(1, (int)env('MEALIE_CACHE_SYNC_DAYS', '7'));
}

function mealieLoadCache(): array {
    if (!file_exists(MEALIE_CACHE_PATH)) {
        return ['synced_at' => 0, 'recipes' => []];
    }
    try {
        $data = json_decode(file_get_contents(MEALIE_CACHE_PATH), true) ?? [];
        if (!isset($data['recipes']) || !is_array($data['recipes'])) {
            $data['recipes'] = [];
        }
        $data['synced_at'] = (int)($data['synced_at'] ?? 0);
        return $data;
    } catch (\Throwable $e) {
        return ['synced_at' => 0, 'recipes' => []];
    }
}

function mealieSaveCache(array $cache): void {
    $cache['synced_at'] = (int)($cache['synced_at'] ?? time());
    file_put_contents(
        MEALIE_CACHE_PATH,
        json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function mealieHasCache(): bool {
    $cache = mealieLoadCache();
    return !empty($cache['recipes']);
}

function mealieUsable(): bool {
    return mealieConfigured() || mealieHasCache();
}

/** off | suggest | auto — missing recipe ingredients → shopping list */
function recipeShoppingMode(): string {
    $m = strtolower(trim(env('RECIPE_SHOPPING_MODE', 'off')));
    return in_array($m, ['off', 'suggest', 'auto'], true) ? $m : 'off';
}

function recipeEffectiveSource(): string {
    $src = strtolower(trim(env('RECIPE_SOURCE', '')));
    if (!in_array($src, ['gemini', 'mealie', 'auto'], true)) {
        return mealieUsable() ? 'auto' : 'gemini';
    }
    if ($src === 'mealie' && !mealieUsable()) {
        return 'gemini';
    }
    if ($src === 'auto' && !mealieUsable()) {
        return 'gemini';
    }
    return $src;
}

/**
 * The engine THIS request asked for, else the household's setting.
 *
 * The generator is opened by the household, and a Mealie answer is not the same dish as an AI
 * one: the client asks which engine to use and sends it here, so «auto» (Mealie first) can never
 * silently replace the AI the person pressed. An unusable Mealie still downgrades to the AI; the
 * other way round is NOT silent — an AI without a key answers `no_api_key`.
 */
function recipeRequestedSource(array $input): string {
    $asked = strtolower(trim((string)($input['recipe_source'] ?? '')));
    if (!in_array($asked, ['gemini', 'mealie', 'auto'], true)) {
        return recipeEffectiveSource();
    }
    if (($asked === 'mealie' || $asked === 'auto') && !mealieUsable()) {
        return 'gemini';
    }
    return $asked;
}

function mealieShouldTryOnline(): bool {
    if (!mealieConfigured()) {
        return false;
    }
    $mode = mealieOfflineMode();
    if ($mode === 'true') {
        return false;
    }
    return true;
}

function mealieCacheNeedsSync(): bool {
    if (!mealieConfigured()) {
        return false;
    }
    $cache = mealieLoadCache();
    if (empty($cache['recipes'])) {
        return true;
    }
    $age = time() - (int)($cache['synced_at'] ?? 0);
    return $age > mealieCacheSyncDays() * 86400;
}

/** @return array{success:bool,data?:mixed,error?:string,http_code?:int,offline?:bool} */
function mealieRequest(string $method, string $path, ?array $body = null): array {
    if (!mealieShouldTryOnline()) {
        return ['success' => false, 'error' => 'mealie_offline_mode', 'offline' => true];
    }
    if (!mealieConfigured()) {
        return ['success' => false, 'error' => 'mealie_not_configured'];
    }
    $url = mealieBaseUrl() . '/api' . ($path[0] === '/' ? $path : '/' . $path);
    $token = trim(env('MEALIE_API_TOKEN', ''));

    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['success' => false, 'error' => 'mealie_request_failed: ' . $err, 'offline' => true];
    }
    $data = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['detail'] ?? json_encode($data)) : $raw;
        return ['success' => false, 'error' => 'mealie_http_' . $code, 'detail' => $msg, 'http_code' => $code];
    }
    return ['success' => true, 'data' => $data, 'http_code' => $code];
}

function mealieCacheKey(array $r): string {
    // Two shapes reach this function: a RAW Mealie payload (`slug` / `id`) and the
    // NORMALIZED recipe `mealieNormalizeRecipe()` builds (`mealie_slug` / `mealie_id`).
    // Reading only the raw keys made the key always '' and `mealieStoreInCache()` return
    // without storing anything, so `data/mealie_cache.json` stayed empty (sync reported
    // the full count, `recipes` was []), every suggestion ran a full sync inside the web
    // request and no Mealie recipe was ever proposed (issue #318).
    foreach (['slug', 'mealie_slug', 'id', 'mealie_id'] as $field) {
        $value = trim((string)($r[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * The readable name of a Mealie value that may be a plain string or a linked object.
 *
 * `unit` is a plain string on a hand-written ingredient, but a LINKED unit (any
 * ingredient Mealie parsed) is an object — `{id, name, plural_name, …}` — and casting
 * that to string produced the literal `Array` plus one "Array to string conversion"
 * warning per line (issue #319).
 */
function mealieLinkedName($value): string
{
    if (is_array($value)) {
        $value = $value['name'] ?? $value['plural_name'] ?? $value['title'] ?? '';
    }
    if (is_array($value)) {
        return '';
    }
    return trim((string)$value);
}

function mealieStoreInCache(array &$cache, array $normalized, ?array $raw = null): void {
    $key = mealieCacheKey($normalized);
    if ($key === '') {
        return;
    }
    $cache['recipes'][$key] = [
        'recipe'    => $normalized,
        'raw'       => $raw,
        'cached_at' => time(),
    ];
}

function mealieGetFromCache(string $slugOrId): ?array {
    $key = trim($slugOrId);
    if ($key === '') {
        return null;
    }
    $cache = mealieLoadCache();
    if (isset($cache['recipes'][$key])) {
        return $cache['recipes'][$key]['recipe'] ?? null;
    }
    foreach ($cache['recipes'] as $entry) {
        $r = $entry['recipe'] ?? [];
        if (($r['mealie_slug'] ?? '') === $key || ($r['mealie_id'] ?? '') === $key) {
            return $r;
        }
    }
    return null;
}

function mealieSyncCache(bool $force = false): array {
    if (!mealieConfigured()) {
        return ['success' => false, 'error' => 'mealie_not_configured'];
    }
    if (!$force && !mealieCacheNeedsSync()) {
        $cache = mealieLoadCache();
        return [
            'success'   => true,
            'skipped'   => true,
            'count'     => count($cache['recipes']),
            'synced_at' => $cache['synced_at'],
        ];
    }

    $cache = ['synced_at' => time(), 'recipes' => []];
    $page = 1;
    $perPage = 50;
    $total = 0;

    while (true) {
        $res = mealieRequest('GET', '/recipes?perPage=' . $perPage . '&page=' . $page);
        if (!$res['success']) {
            if ($total === 0 && mealieHasCache()) {
                $existing = mealieLoadCache();
                return [
                    'success'   => false,
                    'error'     => $res['error'] ?? 'sync_failed',
                    'from_cache'=> true,
                    'count'     => count($existing['recipes']),
                    'synced_at' => $existing['synced_at'],
                ];
            }
            return $res;
        }
        $items = $res['data']['items'] ?? $res['data'] ?? [];
        if (empty($items)) {
            break;
        }
        foreach ($items as $item) {
            $slug = trim((string)($item['slug'] ?? $item['id'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $detail = mealieRequest('GET', '/recipes/' . rawurlencode($slug));
            if (!$detail['success']) {
                continue;
            }
            $normalized = mealieNormalizeRecipe($detail['data']);
            mealieStoreInCache($cache, $normalized, $detail['data']);
            $total++;
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
        if ($page > 200) {
            break;
        }
    }

    mealieSaveCache($cache);
    return [
        'success'   => true,
        'count'     => $total,
        'synced_at' => $cache['synced_at'],
    ];
}

function mealieListRecipes(string $query = '', int $limit = 20): array {
    $limit = max(1, min(100, $limit));
    $q = mb_strtolower(trim($query));
    $out = [];
    $fromCache = false;

    if (mealieShouldTryOnline()) {
        $path = '/recipes?perPage=' . $limit . '&page=1';
        if ($query !== '') {
            $path .= '&search=' . rawurlencode($query);
        }
        $res = mealieRequest('GET', $path);
        if ($res['success']) {
            $items = $res['data']['items'] ?? $res['data'] ?? [];
            foreach ($items as $r) {
                $out[] = [
                    'id'          => $r['id'] ?? '',
                    'slug'        => $r['slug'] ?? '',
                    'name'        => $r['name'] ?? '',
                    'description' => $r['description'] ?? '',
                    'servings'    => $r['recipeYield'] ?? $r['yield'] ?? null,
                    'total_time'  => $r['totalTime'] ?? null,
                    'image'       => $r['image'] ?? null,
                    'from_cache'  => false,
                ];
            }
            return ['success' => true, 'recipes' => $out, 'count' => count($out), 'source' => 'online'];
        }
        if (($res['offline'] ?? false) || mealieOfflineMode() === 'auto') {
            $fromCache = true;
        } else {
            return $res;
        }
    } else {
        $fromCache = true;
    }

    if (!$fromCache) {
        return ['success' => false, 'error' => 'mealie_unavailable'];
    }

    $cache = mealieLoadCache();
    foreach ($cache['recipes'] as $entry) {
        $r = $entry['recipe'] ?? [];
        $name = mb_strtolower($r['title'] ?? '');
        if ($q !== '' && !str_contains($name, $q)) {
            $match = false;
            foreach ($r['ingredients'] ?? [] as $ing) {
                $in = mb_strtolower($ing['name'] ?? $ing['raw'] ?? '');
                if ($in !== '' && str_contains($in, $q)) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                continue;
            }
        }
        $out[] = [
            'id'          => $r['mealie_id'] ?? '',
            'slug'        => $r['mealie_slug'] ?? '',
            'name'        => $r['title'] ?? '',
            'description' => $r['description'] ?? '',
            'servings'    => $r['servings'] ?? null,
            'from_cache'  => true,
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return ['success' => true, 'recipes' => $out, 'count' => count($out), 'source' => 'cache'];
}

function mealieGetRecipe(string $slugOrId): array {
    $slugOrId = trim($slugOrId);
    if ($slugOrId === '') {
        return ['success' => false, 'error' => 'missing_slug_or_id'];
    }

    if (mealieShouldTryOnline()) {
        $res = mealieRequest('GET', '/recipes/' . rawurlencode($slugOrId));
        if ($res['success']) {
            $recipe = mealieNormalizeRecipe($res['data']);
            $cache = mealieLoadCache();
            mealieStoreInCache($cache, $recipe, $res['data']);
            mealieSaveCache($cache);
            return ['success' => true, 'recipe' => $recipe, 'from_cache' => false];
        }
        if (!($res['offline'] ?? false) && mealieOfflineMode() !== 'auto') {
            return $res;
        }
    }

    $cached = mealieGetFromCache($slugOrId);
    if ($cached) {
        return ['success' => true, 'recipe' => $cached, 'from_cache' => true];
    }
    return ['success' => false, 'error' => 'mealie_recipe_not_in_cache'];
}

function mealieNormalizeRecipe(array $r): array {
    $ingredients = [];
    foreach ($r['recipeIngredient'] ?? $r['ingredients'] ?? [] as $ing) {
        if (is_string($ing)) {
            $ingredients[] = ['raw' => $ing, 'name' => $ing, 'qty' => '', 'unit' => ''];
        } elseif (is_array($ing)) {
            $ingredients[] = [
                'raw'  => $ing['display'] ?? $ing['title'] ?? json_encode($ing),
                'name' => $ing['title'] ?? $ing['name'] ?? ($ing['display'] ?? ''),
                'qty'  => (string)($ing['quantity'] ?? $ing['amount'] ?? ''),
                'unit' => mealieLinkedName($ing['unit'] ?? ''),
            ];
        }
    }
    $steps = [];
    foreach ($r['recipeInstructions'] ?? $r['instructions'] ?? [] as $i => $step) {
        if (is_string($step)) {
            $steps[] = ['step' => $i + 1, 'text' => $step];
        } elseif (is_array($step)) {
            $steps[] = ['step' => $i + 1, 'text' => $step['text'] ?? $step['title'] ?? ''];
        }
    }
    return [
        'title'       => $r['name'] ?? '',
        'description' => $r['description'] ?? '',
        'servings'    => (int)($r['recipeYield'] ?? $r['yield'] ?? 4) ?: 4,
        'ingredients' => $ingredients,
        'steps'       => $steps,
        'source'      => 'mealie',
        'mealie_id'   => $r['id'] ?? '',
        'mealie_slug' => $r['slug'] ?? '',
        'image'       => $r['image'] ?? null,
    ];
}

function mealieTokens(string $text): array {
    $clean = mb_strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $text));
    $parts = preg_split('/\s+/', trim($clean)) ?: [];
    $stop = ['di','del','della','dei','con','per','e','il','la','g','gr','ml','kg','pz','qb'];
    return array_values(array_filter($parts, static fn($t) => mb_strlen($t) > 2 && !in_array($t, $stop, true)));
}

function mealieScoreRecipeForPantry(array $recipe, array $pantryItems, string $mustInclude = ''): float {
    $pantryNames = array_map(static fn($i) => mb_strtolower($i['name'] ?? ''), $pantryItems);
    $score = 0.0;
    $must = mb_strtolower(trim($mustInclude));
    $title = mb_strtolower($recipe['title'] ?? '');

    if ($must !== '') {
        $mustTokens = mealieTokens($must);
        $titleHit = str_contains($title, $must);
        $ingHit = false;
        foreach ($recipe['ingredients'] ?? [] as $ing) {
            $n = mb_strtolower($ing['name'] ?? $ing['raw'] ?? '');
            foreach ($mustTokens as $tok) {
                if ($tok !== '' && str_contains($n, $tok)) {
                    $ingHit = true;
                    break 2;
                }
            }
        }
        if (!$titleHit && !$ingHit) {
            return 0.0;
        }
        if ($titleHit) {
            $score += 40;
        }
        if ($ingHit) {
            $score += 30;
        }
    }

    foreach ($recipe['ingredients'] ?? [] as $ing) {
        $ingName = mb_strtolower($ing['name'] ?? $ing['raw'] ?? '');
        if ($ingName === '') {
            continue;
        }
        $ingTokens = mealieTokens($ingName);
        foreach ($pantryNames as $pName) {
            if ($pName === '') {
                continue;
            }
            foreach ($ingTokens as $tok) {
                if ($tok !== '' && str_contains($pName, $tok)) {
                    $score += 10;
                    break 2;
                }
            }
        }
    }
    return $score;
}

/**
 * How much of a Mealie recipe the pantry can really cook.
 *
 * The picker used to accept a dish on a bare score (+10 for every ingredient whose word appeared
 * in ANY pantry name, threshold 15), so a recipe with nine ingredients of which two happened to be
 * on a shelf was proposed as «cookable» — the household's report «mi propone ricette senza senso
 * con ingredienti che non ho». A proposal has to CARRY the dish: every ingredient that is not a
 * free staple (water, salt, pepper, oil) must be found on a shelf, and at most
 * `mealieProposalMaxMissing()` of them may be missing at all.
 *
 * @return array{total:int,matched:int,missing:string[],coverage:float}
 */
function mealieRecipePantryCoverage(array $recipe, array $pantryItems): array {
    $pantryNames = [];
    foreach ($pantryItems as $it) {
        $n = mb_strtolower(trim((string)($it['name'] ?? '')));
        if ($n !== '') {
            $pantryNames[] = $n;
        }
    }
    $total = 0;
    $matched = 0;
    $missing = [];
    foreach ($recipe['ingredients'] ?? [] as $ing) {
        $name = trim((string)($ing['name'] ?? ($ing['raw'] ?? '')));
        // Water/salt/pepper/oil live in every kitchen: the same rule the AI prompt states.
        if ($name === '' || recipeIsFreeStaple($name)) {
            continue;
        }
        $total++;
        $tokens = mealieTokens($name);
        $hit = false;
        foreach ($pantryNames as $pName) {
            foreach ($tokens as $tok) {
                if ($tok !== '' && str_contains($pName, $tok)) {
                    $hit = true;
                    break 2;
                }
            }
        }
        if ($hit) {
            $matched++;
        } else {
            $missing[] = $name;
        }
    }
    return [
        'total'    => $total,
        'matched'  => $matched,
        'missing'  => $missing,
        'coverage' => $total > 0 ? round($matched / $total, 2) : 0.0,
    ];
}

/** How many ingredients a Mealie proposal may lack (0 = all of them must be on a shelf). */
function mealieProposalMaxMissing(): int {
    return max(0, min(5, (int)env('MEALIE_RECIPE_MAX_MISSING', '0')));
}

function mealieRecipeToEverShelfFormat(array $mealieRecipe, int $persons): array {
    $servings = max(1, (int)($mealieRecipe['servings'] ?? 4));
    $scale = $persons / $servings;
    $ingredients = [];
    foreach ($mealieRecipe['ingredients'] ?? [] as $ing) {
        $qtyNum = is_numeric($ing['qty'] ?? null) ? (float)$ing['qty'] * $scale : null;
        $qtyLabel = trim(($ing['raw'] ?? '') !== '' ? $ing['raw'] : trim(($ing['qty'] ?? '') . ' ' . ($ing['unit'] ?? '')));
        $ingredients[] = [
            'name'       => $ing['name'] ?: ($ing['raw'] ?? ''),
            'qty'        => $qtyLabel,
            'qty_number' => $qtyNum,
            'from_pantry'=> false,
        ];
    }
    $steps = [];
    foreach ($mealieRecipe['steps'] ?? [] as $step) {
        $steps[] = is_array($step) ? ($step['text'] ?? '') : (string)$step;
    }
    return [
        'title'         => $mealieRecipe['title'] ?? '',
        'description'   => $mealieRecipe['description'] ?? '',
        'meal'          => 'libero',
        'persons'       => $persons,
        'prep_time'     => '',
        'cook_time'     => '',
        'tags'          => ['mealie'],
        'ingredients'   => $ingredients,
        'steps'         => $steps,
        'nutrition_note'=> '',
        'source'        => 'mealie',
        'mealie_slug'   => $mealieRecipe['mealie_slug'] ?? '',
        'from_mealie_cache' => !empty($mealieRecipe['from_cache']),
    ];
}

function mealiePickRecipe(array $pantryItems, int $persons, string $mustInclude = '', array $excludeTitles = []): ?array {
    if (!mealieUsable()) {
        return null;
    }
    if (mealieCacheNeedsSync() && mealieShouldTryOnline()) {
        mealieSyncCache(false);
    }
    $cache = mealieLoadCache();
    if (empty($cache['recipes'])) {
        return null;
    }

    $exclude = array_map(static fn($t) => mb_strtolower(trim($t)), $excludeTitles);
    $maxMissing = mealieProposalMaxMissing();
    $best = null;
    $bestScore = 0.0;
    $bestMissing = PHP_INT_MAX;
    $bestCoverage = null;
    $skipped = 0;   // candidates thrown away because the pantry cannot cook them

    foreach ($cache['recipes'] as $entry) {
        $recipe = $entry['recipe'] ?? null;
        if (!$recipe) {
            continue;
        }
        $title = mb_strtolower($recipe['title'] ?? '');
        if ($title !== '' && in_array($title, $exclude, true)) {
            continue;
        }
        $coverage = mealieRecipePantryCoverage($recipe, $pantryItems);
        // A dish needs at least two real ingredients on the shelf, and it may not leave more than
        // `$maxMissing` of them in the shop: a recipe the household cannot cook is not a recipe.
        if ($coverage['matched'] < 2 || $coverage['total'] < 2) {
            $skipped++;
            continue;
        }
        $missingCount = count($coverage['missing']);
        if ($missingCount > $maxMissing) {
            $skipped++;
            continue;
        }
        $score = mealieScoreRecipeForPantry($recipe, $pantryItems, $mustInclude);
        if (trim($mustInclude) !== '' && $score <= 0) {
            $skipped++;
            continue;
        }
        // Fewest missing wins (and full coverage beats a bigger score), then the usual score.
        $better = $best === null
            || $missingCount < $bestMissing
            || ($missingCount === $bestMissing && $score > $bestScore);
        if ($better) {
            $best = $recipe;
            $bestScore = $score;
            $bestMissing = $missingCount;
            $bestCoverage = $coverage;
        }
    }

    if (!$best || !$bestCoverage) {
        EverLog::info('No Mealie recipe the pantry can cook', [
            'event'      => 'mealie_pick_no_match',
            'candidates' => count($cache['recipes']),
            'skipped'    => $skipped,
            'max_missing'=> $maxMissing,
        ]);
        return null;
    }

    $best['from_cache'] = true;
    $out = mealieRecipeToEverShelfFormat($best, $persons);
    $out['pantry_coverage'] = $bestCoverage;
    EverLog::info('Mealie recipe proposed', [
        'event'    => 'mealie_pick',
        'title'    => (string)($out['title'] ?? ''),
        'missing'  => $bestCoverage['missing'],
        'coverage' => $bestCoverage['coverage'],
    ]);
    return $out;
}

function mealieTryRecipeGeneration(PDO $db, array $pantryItems, int $persons, array $opts = []): ?array {
    $source = recipeEffectiveSource();
    if ($source === 'gemini') {
        return null;
    }
    $must = trim((string)($opts['ingredient'] ?? ''));
    $exclude = $opts['exclude_titles'] ?? [];
    $recipe = mealiePickRecipe($pantryItems, $persons, $must, $exclude);
    if (!$recipe) {
        return null;
    }
    recipePostProcessGenerated($db, $recipe, $pantryItems);
    return $recipe;
}

function mealieImportToEverShelf(PDO $db, string $slugOrId): array {
    $got = mealieGetRecipe($slugOrId);
    if (!$got['success']) {
        return $got;
    }
    $recipe = $got['recipe'];
    $today = date('Y-m-d');
    $payload = mealieRecipeToEverShelfFormat($recipe, (int)($recipe['servings'] ?? 4));
    // 'import' was not a meal and not a slot either: it is `libero`, which is what an archived
    // recipe with no meal is everywhere else in the app (and it accepts many per day).
    $stmt = $db->prepare(
        "INSERT INTO recipes (date, meal, recipe_json, content_hash, created_at, is_favorite)
         VALUES (?, 'libero', ?, ?, datetime('now'), 0)"
    );
    $stmt->execute([$today, json_encode($payload, JSON_UNESCAPED_UNICODE), recipeArchiveContentHash($payload)]);
    return [
        'success'       => true,
        'recipe'        => $payload,
        'id'            => (int)$db->lastInsertId(),
        'imported_from' => 'mealie',
        'from_cache'    => !empty($got['from_cache']),
    ];
}

function mealieStatus(): array {
    $cache = mealieLoadCache();
    return [
        'configured'      => mealieConfigured(),
        'usable'          => mealieUsable(),
        'url'             => mealieConfigured() ? mealieBaseUrl() : null,
        'recipe_source'   => recipeEffectiveSource(),
        'offline_mode'    => mealieOfflineMode(),
        'cache_count'     => count($cache['recipes']),
        'cache_synced_at' => $cache['synced_at'] ?: null,
        'cache_needs_sync'=> mealieCacheNeedsSync(),
    ];
}

/* ===== EverShelf → Mealie: "le ricette già fatte vanno in automatico" =====
 *
 * The integration was one-way: Mealie could feed the generator, but the dishes this household
 * actually cooked stayed here. A cooked recipe is the best content a ricettario can receive —
 * it has been made, scaled and (maybe) rated — so it is pushed back with its ingredients and
 * its steps.
 *
 * The map of what has already been sent lives in data/mealie_pushed.json, keyed by TITLE: the
 * dish is the identity, not the row (cooking changes `used` flags and therefore the content
 * hash, and a re-save must not create a second copy in Mealie).
 */

function mealiePushStatePath(): string {
    return EVERSHELF_ROOT . '/data/mealie_pushed.json';
}

/** `title(lowercased) => { slug, name, at, archive_id, patched }` */
function mealiePushState(): array {
    $path = mealiePushStatePath();
    if (!is_file($path)) {
        return [];
    }
    $raw = json_decode((string)file_get_contents($path), true);
    $map = is_array($raw) ? ($raw['recipes'] ?? null) : null;
    return is_array($map) ? $map : [];
}

function mealiePushStateSave(array $map): void {
    $path = mealiePushStatePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(['recipes' => $map], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp = $path . '.part';
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    }
}

/** The dish's identity for the push map. */
function mealiePushKey(array $recipe): string {
    return mb_strtolower(trim((string)($recipe['title'] ?? '')));
}

/** "10 min" / "1 h 30" / "20m" → ISO-8601 "PT10M" ('' when it says nothing). */
function mealieIsoDuration($label): string {
    $s = mb_strtolower(trim((string)$label));
    if ($s === '') {
        return '';
    }
    $total = 0;
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:h|ore|ora|hours?|std|stunde)/', $s, $h)) {
        $total += (int)round((float)str_replace(',', '.', $h[1]) * 60);
        $s = trim(mb_substr($s, mb_strpos($s, $h[0]) + mb_strlen($h[0])));
    }
    if (preg_match('/(\d+)/', $s, $m)) {
        $total += (int)$m[1];
    }
    return $total > 0 ? 'PT' . $total . 'M' : '';
}

/**
 * EverShelf recipe → Mealie `PATCH /api/recipes/{slug}` payload.
 *
 * Only the fields Mealie accepts as plain strings: name, description, yield, times, the
 * ingredient lines and the instruction steps. Tags/notes are OBJECTS with their own validation
 * in Mealie, and a 422 there would lose the whole push — a correct recipe without tags is worth
 * more than a stylish one that never arrives.
 */
function mealieRecipeToMealiePayload(array $recipe): array {
    $ingredients = [];
    foreach (($recipe['ingredients'] ?? []) as $ing) {
        if (!is_array($ing)) {
            continue;
        }
        $name = trim((string)($ing['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $qty = trim((string)($ing['qty'] ?? ''));
        $ingredients[] = $qty !== '' ? ($qty . ' ' . $name) : $name;
    }
    $steps = [];
    foreach (($recipe['steps'] ?? []) as $step) {
        $text = is_array($step) ? (string)($step['text'] ?? $step['instruction'] ?? '') : (string)$step;
        $text = trim($text);
        if ($text !== '') {
            $steps[] = ['text' => $text];
        }
    }
    $payload = [
        'name'              => trim((string)($recipe['title'] ?? '')),
        'description'       => trim((string)($recipe['description'] ?? '')),
        'recipeYield'       => (string)max(1, (int)($recipe['persons'] ?? 1)),
        'recipeIngredient'  => $ingredients,
        'recipeInstructions'=> $steps,
    ];
    $prep = mealieIsoDuration($recipe['prep_time'] ?? '');
    $cook = mealieIsoDuration($recipe['cook_time'] ?? '');
    if ($prep !== '') {
        $payload['prepTime'] = $prep;
    }
    if ($cook !== '') {
        $payload['cookTime'] = $cook;
    }
    if (!empty($recipe['nutrition_note'])) {
        $payload['notes'] = [['text' => (string)$recipe['nutrition_note']]];
    }
    return $payload;
}

/** Has this dish been made? (at least one pantry row deducted — the same rule the sheet uses) */
function mealieRecipeLooksCooked(array $recipe): bool {
    foreach (($recipe['ingredients'] ?? []) as $ing) {
        if (is_array($ing) && !empty($ing['used']) && !empty($ing['product_id'])) {
            return true;
        }
    }
    return false;
}

/**
 * Push ONE recipe to Mealie: create it, then fill it. Idempotent by title.
 */
/**
 * The Mealie slug of an existing recipe with EXACTLY this name, or ''.
 *
 * Mealie refuses a duplicate name (409), and that refusal is useful information: the dish may
 * already be in the ricettario from a previous push or from a manual import. Updating that copy
 * is the right answer — a second "Sformatine al Tonno" helps nobody.
 */
function mealieFindRecipeSlugByName(string $name): string {
    $needle = mb_strtolower(trim($name));
    if ($needle === '') {
        return '';
    }
    $res = mealieRequest('GET', '/recipes?perPage=50&search=' . rawurlencode($name));
    if (empty($res['success'])) {
        return '';
    }
    $items = $res['data']['items'] ?? $res['data'] ?? [];
    if (!is_array($items)) {
        return '';
    }
    foreach ($items as $item) {
        if (mb_strtolower(trim((string)($item['name'] ?? ''))) === $needle) {
            return (string)($item['slug'] ?? '');
        }
    }
    return '';
}

function mealiePushRecipe(array $recipe, ?string $archiveId = null, bool $force = false): array {
    if (!mealieUsable()) {
        return ['success' => false, 'error' => 'mealie_not_usable'];
    }
    $name = trim((string)($recipe['title'] ?? ''));
    if ($name === '') {
        return ['success' => false, 'error' => 'missing_title'];
    }
    $key = mealiePushKey($recipe);
    $state = mealiePushState();
    if (!$force && isset($state[$key])) {
        return ['success' => true, 'skipped' => true, 'reason' => 'already_pushed', 'slug' => (string)($state[$key]['slug'] ?? '')];
    }

    // LOOK BEFORE CREATING. Mealie does NOT refuse a duplicate name: it accepts it and appends
    // "-1", so a create-first flow quietly fills the ricettario with twins (measured: the same
    // dish came back as "...-al-cookeo-1"). The name IS the identity here, so an existing one is
    // updated in place and nothing is created.
    $slug = mealieFindRecipeSlugByName($name);
    $createdNow = false;
    if ($slug === '') {
        $created = mealieRequest('POST', '/recipes', ['name' => $name]);
        // Mealie answers 201 with the new SLUG as a bare string (not an object): read both shapes.
        $createdData = $created['data'] ?? null;
        $slug = !empty($created['success'])
            ? (is_string($createdData) ? trim($createdData) : (string)($createdData['slug'] ?? ''))
            : '';
        $createdNow = $slug !== '';
        if ($slug === '') {
            EverLog::warn('Mealie push failed (create)', [
                'event' => 'mealie_push_create_failed',
                'name'  => $name,
                'error' => $created['error'] ?? 'unknown',
            ]);
            return empty($created['success']) ? $created : ['success' => false, 'error' => 'mealie_push_no_slug'];
        }
    }

    $patched = mealieRequest('PATCH', '/recipes/' . rawurlencode($slug), mealieRecipeToMealiePayload($recipe));
    $state[$key] = [
        'slug'       => $slug,
        'name'       => $name,
        'at'         => time(),
        'archive_id' => $archiveId,
        'patched'    => !empty($patched['success']),
        'created'    => $createdNow,
    ];
    mealiePushStateSave($state);
    EverLog::info('Recipe pushed to Mealie', [
        'event'      => 'mealie_push',
        'name'       => $name,
        'slug'       => $slug,
        'patched'    => !empty($patched['success']),
        'archive_id' => $archiveId,
    ]);
    return [
        'success' => true,
        'slug'    => $slug,
        'created' => $createdNow,
        'patched' => !empty($patched['success']),
        'warning' => empty($patched['success']) ? ($patched['error'] ?? 'patch_failed') : null,
    ];
}

/**
 * The automatic sweep: every COOKED archive recipe not yet in Mealie, a few per run.
 *
 * Called by the cron (a bounded number of HTTP calls, never on a page load) and on demand by
 * the user ("invia tutto"). Silence when Mealie is not configured: nothing to do, no log noise.
 */
function mealiePushPendingCooked(PDO $db, int $limit = 3): array {
    if (!mealieUsable()) {
        return ['success' => false, 'error' => 'mealie_not_usable', 'pushed' => [], 'count' => 0];
    }
    $limit = max(1, min(20, $limit));
    $rows = $db->query("SELECT id, recipe_json FROM recipes WHERE recipe_json LIKE '%\"used\":true%' ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $pushed = [];
    foreach ($rows as $row) {
        if (count($pushed) >= $limit) {
            break;
        }
        $recipe = json_decode((string)$row['recipe_json'], true);
        if (!is_array($recipe) || !mealieRecipeLooksCooked($recipe)) {
            continue;
        }
        $key = mealiePushKey($recipe);
        if ($key === '') {
            continue;
        }
        $state = mealiePushState();
        if (isset($state[$key])) {
            continue;
        }
        $res = mealiePushRecipe($recipe, (string)$row['id']);
        if (!empty($res['success']) && empty($res['skipped'])) {
            $pushed[] = ['id' => (int)$row['id'], 'name' => (string)($recipe['title'] ?? ''), 'slug' => $res['slug'] ?? ''];
        }
    }
    return ['success' => true, 'pushed' => $pushed, 'count' => count($pushed)];
}
