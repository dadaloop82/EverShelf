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
    $slug = trim((string)($r['slug'] ?? ''));
    if ($slug !== '') {
        return $slug;
    }
    return trim((string)($r['id'] ?? ''));
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
                'unit' => (string)($ing['unit'] ?? ''),
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
    $best = null;
    $bestScore = 0.0;

    foreach ($cache['recipes'] as $entry) {
        $recipe = $entry['recipe'] ?? null;
        if (!$recipe) {
            continue;
        }
        $title = mb_strtolower($recipe['title'] ?? '');
        if ($title !== '' && in_array($title, $exclude, true)) {
            continue;
        }
        $score = mealieScoreRecipeForPantry($recipe, $pantryItems, $mustInclude);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $recipe;
        }
    }

    if (!$best || $bestScore < 15) {
        return null;
    }
    $best['from_cache'] = true;
    return mealieRecipeToEverShelfFormat($best, $persons);
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
    $stmt = $db->prepare(
        "INSERT INTO recipes (date, meal, recipe_json, created_at, is_favorite)
         VALUES (?, 'import', ?, datetime('now'), 0)
         ON CONFLICT(date, meal) DO UPDATE SET recipe_json = excluded.recipe_json, created_at = excluded.created_at"
    );
    $stmt->execute([$today, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
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
