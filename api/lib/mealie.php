<?php
/**
 * Mealie recipe manager integration.
 * Requires MEALIE_URL and MEALIE_API_TOKEN in .env
 */

function mealieConfigured(): bool {
    $url = trim(env('MEALIE_URL', ''));
    $tok = trim(env('MEALIE_API_TOKEN', ''));
    return $url !== '' && $tok !== '';
}

function mealieBaseUrl(): string {
    return rtrim(trim(env('MEALIE_URL', '')), '/');
}

/** @return array{success:bool,data?:mixed,error?:string,http_code?:int} */
function mealieRequest(string $method, string $path, ?array $body = null): array {
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
        CURLOPT_TIMEOUT        => 30,
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
        return ['success' => false, 'error' => 'mealie_request_failed: ' . $err];
    }
    $data = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['detail'] ?? json_encode($data)) : $raw;
        return ['success' => false, 'error' => 'mealie_http_' . $code, 'detail' => $msg, 'http_code' => $code];
    }
    return ['success' => true, 'data' => $data, 'http_code' => $code];
}

function mealieListRecipes(string $query = '', int $limit = 20): array {
    $limit = max(1, min(100, $limit));
    $path = '/recipes?perPage=' . $limit . '&page=1';
    if ($query !== '') {
        $path .= '&search=' . rawurlencode($query);
    }
    $res = mealieRequest('GET', $path);
    if (!$res['success']) {
        return $res;
    }
    $items = $res['data']['items'] ?? $res['data'] ?? [];
    $out = [];
    foreach ($items as $r) {
        $out[] = [
            'id'          => $r['id'] ?? '',
            'slug'        => $r['slug'] ?? '',
            'name'        => $r['name'] ?? '',
            'description' => $r['description'] ?? '',
            'servings'    => $r['recipeYield'] ?? $r['yield'] ?? null,
            'total_time'  => $r['totalTime'] ?? null,
            'image'       => $r['image'] ?? null,
        ];
    }
    return ['success' => true, 'recipes' => $out, 'count' => count($out)];
}

function mealieGetRecipe(string $slugOrId): array {
    $slugOrId = trim($slugOrId);
    if ($slugOrId === '') {
        return ['success' => false, 'error' => 'missing_slug_or_id'];
    }
    $res = mealieRequest('GET', '/recipes/' . rawurlencode($slugOrId));
    if (!$res['success']) {
        return $res;
    }
    return ['success' => true, 'recipe' => mealieNormalizeRecipe($res['data'])];
}

function mealieNormalizeRecipe(array $r): array {
    $ingredients = [];
    foreach ($r['recipeIngredient'] ?? $r['ingredients'] ?? [] as $ing) {
        if (is_string($ing)) {
            $ingredients[] = ['raw' => $ing, 'name' => $ing, 'qty' => ''];
        } elseif (is_array($ing)) {
            $ingredients[] = [
                'raw'  => $ing['display'] ?? $ing['title'] ?? json_encode($ing),
                'name' => $ing['title'] ?? $ing['name'] ?? ($ing['display'] ?? ''),
                'qty'  => $ing['quantity'] ?? $ing['amount'] ?? '',
                'unit' => $ing['unit'] ?? '',
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

function mealieImportToEverShelf(PDO $db, string $slugOrId): array {
    $got = mealieGetRecipe($slugOrId);
    if (!$got['success']) {
        return $got;
    }
    $recipe = $got['recipe'];
    $today = date('Y-m-d');
    $payload = [
        'title'       => $recipe['title'],
        'description' => $recipe['description'],
        'persons'     => $recipe['servings'],
        'ingredients' => array_map(static function ($ing) {
            $qty = trim((string)($ing['qty'] ?? ''));
            $unit = trim((string)($ing['unit'] ?? ''));
            $label = $qty !== '' ? trim($qty . ' ' . $unit) : ($ing['raw'] ?? $ing['name']);
            return [
                'name'       => $ing['name'] ?: $ing['raw'],
                'qty'        => $label,
                'qty_number' => is_numeric($qty) ? (float)$qty : null,
            ];
        }, $recipe['ingredients']),
        'steps' => array_map(static fn($s) => $s['text'], $recipe['steps']),
        'source' => 'mealie',
        'mealie_slug' => $recipe['mealie_slug'],
    ];
    $stmt = $db->prepare(
        "INSERT INTO recipes (date, meal, recipe_json, created_at, is_favorite)
         VALUES (?, 'import', ?, datetime('now'), 0)
         ON CONFLICT(date, meal) DO UPDATE SET recipe_json = excluded.recipe_json, created_at = excluded.created_at"
    );
    $stmt->execute([$today, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    return [
        'success' => true,
        'recipe'  => $payload,
        'id'      => (int)$db->lastInsertId(),
        'imported_from' => 'mealie',
    ];
}

function mealieStatus(): array {
    return [
        'configured' => mealieConfigured(),
        'url'        => mealieConfigured() ? mealieBaseUrl() : null,
    ];
}
