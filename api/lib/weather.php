<?php
/**
 * Weather for recipe generation (Open-Meteo — no API key).
 * Used optionally with “A ritmo mio” / Fuel Mode.
 */

function weatherEnabled(): bool {
    return env('WEATHER_ENABLED', 'false') === 'true';
}

function weatherConfiguredLocation(): ?array {
    $lat = trim((string)env('WEATHER_LAT', ''));
    $lon = trim((string)env('WEATHER_LON', ''));
    if ($lat === '' || $lon === '' || !is_numeric($lat) || !is_numeric($lon)) {
        return null;
    }
    $latF = (float)$lat;
    $lonF = (float)$lon;
    if ($latF < -90 || $latF > 90 || $lonF < -180 || $lonF > 180) {
        return null;
    }
    return [
        'lat'  => $latF,
        'lon'  => $lonF,
        'city' => trim((string)env('WEATHER_CITY', '')),
    ];
}

function weatherCachePath(float $lat, float $lon): string {
    $key = sprintf('%.2f_%.2f', $lat, $lon);
    return dirname(__DIR__, 2) . '/data/weather_cache_' . preg_replace('/[^0-9_\-.]/', '', $key) . '.json';
}

/** WMO weather interpretation codes → coarse mood for recipes. */
function weatherBucketFromCode(int $code, float $tempC, ?float $apparentC = null): string {
    $t = $apparentC !== null ? $apparentC : $tempC;
    if (in_array($code, [95, 96, 99], true)) {
        return 'stormy';
    }
    if (in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], true)) {
        return 'rainy';
    }
    if (in_array($code, [71, 73, 75, 77, 85, 86], true)) {
        return 'snowy';
    }
    if (in_array($code, [45, 48], true)) {
        return 'foggy';
    }
    if ($t >= 28) {
        return 'hot';
    }
    if ($t >= 22) {
        return 'warm';
    }
    if ($t <= 5) {
        return 'cold';
    }
    if ($t <= 12) {
        return 'chilly';
    }
    return 'mild';
}

function weatherLabelForBucket(string $bucket, string $lang = 'en'): string {
    $labels = [
        'en' => [
            'hot' => 'hot', 'warm' => 'warm', 'mild' => 'mild', 'chilly' => 'chilly',
            'cold' => 'cold', 'rainy' => 'rainy', 'snowy' => 'snowy', 'foggy' => 'foggy', 'stormy' => 'stormy',
        ],
        'it' => [
            'hot' => 'molto caldo', 'warm' => 'caldo', 'mild' => 'mite', 'chilly' => 'fresco',
            'cold' => 'freddo', 'rainy' => 'piovoso', 'snowy' => 'nevoso', 'foggy' => 'nebbioso', 'stormy' => 'temporalesco',
        ],
        'de' => [
            'hot' => 'sehr heiß', 'warm' => 'warm', 'mild' => 'mild', 'chilly' => 'kühl',
            'cold' => 'kalt', 'rainy' => 'regnerisch', 'snowy' => 'schneereich', 'foggy' => 'neblig', 'stormy' => 'gewittrig',
        ],
        'fr' => [
            'hot' => 'très chaud', 'warm' => 'chaud', 'mild' => 'doux', 'chilly' => 'frais',
            'cold' => 'froid', 'rainy' => 'pluvieux', 'snowy' => 'neigeux', 'foggy' => 'brumeux', 'stormy' => 'orageux',
        ],
        'es' => [
            'hot' => 'muy caluroso', 'warm' => 'cálido', 'mild' => 'suave', 'chilly' => 'fresco',
            'cold' => 'frío', 'rainy' => 'lluvioso', 'snowy' => 'nevado', 'foggy' => 'neblinoso', 'stormy' => 'tormentoso',
        ],
    ];
    $map = $labels[$lang] ?? $labels['en'];
    return $map[$bucket] ?? $bucket;
}

function weatherRecipeHints(string $bucket, string $lang = 'en'): string {
    $hints = [
        'en' => [
            'hot' => 'Prefer cold/no-cook or lightly cooked dishes: salads, gazpacho, cold pasta/rice, grilled fish/veg, yogurt bowls. Avoid heavy stews, oven roasting, and rich fried food.',
            'warm' => 'Lean toward fresh, light plates; limited oven use; plenty of vegetables and hydrating ingredients.',
            'mild' => 'Balanced comfort food is fine; no strong weather bias.',
            'chilly' => 'Prefer warm, comforting dishes: soups, risottos, gentle stews, baked dishes.',
            'cold' => 'Prioritize hot, warming meals: soups, stews, oven dishes, hearty carbs. Avoid cold raw-only plates as the main course.',
            'rainy' => 'Comfort food welcome: warm soups, creamy pastas, oven bakes. Cosy indoor cooking.',
            'snowy' => 'Hearty hot meals: stews, baked pasta, warming broths.',
            'foggy' => 'Warm aromatic dishes (soups, gently spiced plates) work well.',
            'stormy' => 'Simple comforting warm food; avoid long outdoor grilling assumptions.',
        ],
        'it' => [
            'hot' => 'Prediligi piatti freddi o poco cotti: insalate, gazpacho, pasta/riso freddi, pesce/verdure alla griglia, yogurt. Evita stufati pesanti, forno prolungato e fritti ricchi.',
            'warm' => 'Orientati a piatti freschi e leggeri; poco forno; molte verdure e ingredienti idratanti.',
            'mild' => 'Cibo bilanciato; nessuna preferenza meteo forte.',
            'chilly' => 'Prediligi piatti caldi e confortanti: zuppe, risotti, spezzatini leggeri, al forno.',
            'cold' => 'Priorità a pasti caldi: zuppe, stufati, forno, carboidrati sostanziosi. Evita piatti solo crudi/freddi come portata principale.',
            'rainy' => 'Comfort food: zuppe calde, paste cremose, al forno. Cucina “da casa”.',
            'snowy' => 'Pasti caldi e sostanziosi: stufati, pasta al forno, brodi.',
            'foggy' => 'Piatti caldi e aromatici (zuppe, spezie delicate).',
            'stormy' => 'Cibo semplice e caldo di conforto; evita grigliate outdoor.',
        ],
    ];
    $map = $hints[$lang] ?? $hints['en'];
    return $map[$bucket] ?? $hints['en']['mild'];
}

/**
 * @return array{ok:bool,error?:string,weather?:array}
 */
function weatherFetchCurrent(float $lat, float $lon, string $city = '', int $cacheTtlSec = 1800): array {
    $cacheFile = weatherCachePath($lat, $lon);
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtlSec) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['ok']) && !empty($cached['weather'])) {
            if ($city !== '' && empty($cached['weather']['city'])) {
                $cached['weather']['city'] = $city;
            }
            $cached['weather']['cached'] = true;
            return $cached;
        }
    }

    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => round($lat, 4),
        'longitude' => round($lon, 4),
        'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,is_day',
        'timezone' => 'auto',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'EverShelf/1.0 (recipe weather; +https://github.com/dadaloop82/EverShelf)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        return ['ok' => false, 'error' => $err !== '' ? $err : "http_$code"];
    }
    $data = json_decode($body, true);
    $cur = $data['current'] ?? null;
    if (!is_array($cur) || !isset($cur['temperature_2m'])) {
        return ['ok' => false, 'error' => 'invalid_response'];
    }

    $temp = (float)$cur['temperature_2m'];
    $apparent = isset($cur['apparent_temperature']) ? (float)$cur['apparent_temperature'] : null;
    $wmo = (int)($cur['weather_code'] ?? 0);
    $bucket = weatherBucketFromCode($wmo, $temp, $apparent);

    $weather = [
        'lat' => $lat,
        'lon' => $lon,
        'city' => $city,
        'temp_c' => round($temp, 1),
        'apparent_c' => $apparent !== null ? round($apparent, 1) : null,
        'humidity' => isset($cur['relative_humidity_2m']) ? (int)$cur['relative_humidity_2m'] : null,
        'wind_kmh' => isset($cur['wind_speed_10m']) ? round((float)$cur['wind_speed_10m'], 1) : null,
        'weather_code' => $wmo,
        'bucket' => $bucket,
        'is_day' => !empty($cur['is_day']),
        'time' => (string)($cur['time'] ?? ''),
        'source' => 'Open-Meteo',
        'cached' => false,
    ];

    $out = ['ok' => true, 'weather' => $weather];
    @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/**
 * Geocode a place name via Open-Meteo (no API key).
 * @return array{ok:bool,results?:array,error?:string}
 */
function weatherGeocode(string $query, string $lang = 'en', int $count = 6): array {
    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return ['ok' => false, 'error' => 'query_too_short'];
    }
    $url = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
        'name' => $query,
        'count' => max(1, min(10, $count)),
        'language' => $lang ?: 'en',
        'format' => 'json',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'EverShelf/1.0 (geocode; +https://github.com/dadaloop82/EverShelf)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        return ['ok' => false, 'error' => "http_$code"];
    }
    $data = json_decode($body, true);
    $results = [];
    foreach (($data['results'] ?? []) as $r) {
        if (!isset($r['latitude'], $r['longitude'], $r['name'])) {
            continue;
        }
        $parts = array_filter([
            $r['name'] ?? '',
            $r['admin1'] ?? '',
            $r['country'] ?? '',
        ]);
        $results[] = [
            'name' => (string)$r['name'],
            'label' => implode(', ', $parts),
            'lat' => (float)$r['latitude'],
            'lon' => (float)$r['longitude'],
            'country' => (string)($r['country_code'] ?? ''),
        ];
    }
    return ['ok' => true, 'results' => $results];
}

/** Current weather for configured location, or null if disabled / missing. */
function weatherGetForRecipes(): ?array {
    if (!weatherEnabled()) {
        return null;
    }
    $loc = weatherConfiguredLocation();
    if (!$loc) {
        return null;
    }
    $res = weatherFetchCurrent($loc['lat'], $loc['lon'], $loc['city']);
    return !empty($res['ok']) ? ($res['weather'] ?? null) : null;
}

/**
 * Prompt block for Gemini when Fuel Mode + weather are active.
 */
function weatherFuelPromptBlock(?array $weather, string $lang = 'it'): string {
    if (!$weather) {
        return '';
    }
    $bucket = (string)($weather['bucket'] ?? 'mild');
    $label = weatherLabelForBucket($bucket, $lang);
    $hint = weatherRecipeHints($bucket, in_array($lang, ['it', 'en'], true) ? $lang : 'en');
    $city = trim((string)($weather['city'] ?? ''));
    $place = $city !== '' ? $city : sprintf('lat %.2f, lon %.2f', $weather['lat'] ?? 0, $weather['lon'] ?? 0);
    $temp = $weather['temp_c'] ?? '?';
    $app = $weather['apparent_c'] ?? null;
    $appTxt = $app !== null ? " (percepita {$app}°C)" : '';

    return "\n\n🌤 METEO LOCALE (Open-Meteo) — obbligatorio per «A ritmo mio»:\n"
        . "→ Zona: {$place}\n"
        . "→ Condizioni: {$label}, {$temp}°C{$appTxt}\n"
        . "→ Adatta stile e cottura: {$hint}\n"
        . "→ In `fuel_why` cita brevemente come il meteo ha influenzato la scelta (1 frase).";
}

function weatherApiGet(): void {
    if (!weatherEnabled()) {
        echo json_encode(['success' => true, 'enabled' => false, 'weather' => null]);
        return;
    }
    $loc = weatherConfiguredLocation();
    if (!$loc) {
        echo json_encode(['success' => false, 'enabled' => true, 'error' => 'location_missing']);
        return;
    }
    $res = weatherFetchCurrent($loc['lat'], $loc['lon'], $loc['city']);
    if (empty($res['ok'])) {
        echo json_encode(['success' => false, 'enabled' => true, 'error' => $res['error'] ?? 'fetch_failed']);
        return;
    }
    echo json_encode(['success' => true, 'enabled' => true, 'weather' => $res['weather'], 'attribution' => 'Weather data by Open-Meteo.com (CC BY 4.0)']);
}

function weatherApiGeocode(): void {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        $input = json_decode(file_get_contents('php://input'), true);
        $q = trim((string)($input['q'] ?? ''));
    }
    $lang = trim((string)($_GET['lang'] ?? env('APP_LANG', 'en')));
    $res = weatherGeocode($q, $lang ?: 'en');
    if (empty($res['ok'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $res['error'] ?? 'geocode_failed']);
        return;
    }
    echo json_encode(['success' => true, 'results' => $res['results']]);
}
