<?php
/**
 * EverShelf — authentication, CORS, demo mode, scale gateway allowlist.
 */

require_once __DIR__ . '/env.php';

/** Effective API token: API_TOKEN takes precedence over legacy SETTINGS_TOKEN. */
function evershelfEffectiveApiToken(): string {
    $api = env('API_TOKEN');
    if ($api !== '') {
        return $api;
    }
    return env('SETTINGS_TOKEN', '');
}

function evershelfApiTokenRequired(): bool {
    return evershelfEffectiveApiToken() !== '';
}

function evershelfGetProvidedApiToken(): string {
    if (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_API_TOKEN'];
    }
    if (!empty($_SERVER['HTTP_X_SETTINGS_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_SETTINGS_TOKEN'];
    }
    if (isset($_GET['api_token'])) {
        return (string)$_GET['api_token'];
    }
    // Home Assistant ha-evershelf sends Authorization: Bearer (legacy)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (preg_match('/^Bearer\s+(\S+)/i', $authHeader, $m)) {
        return $m[1];
    }
    return evershelfGetProvidedApiTokenFromHeaders();
}

function evershelfApiTokenValid(): bool {
    $required = evershelfEffectiveApiToken();
    if ($required === '') {
        return true;
    }
    $provided = evershelfGetProvidedApiToken();
    return $provided !== '' && hash_equals($required, $provided);
}

function evershelfGetProvidedApiTokenFromHeaders(): string {
    return (string)($_SERVER['HTTP_X_API_TOKEN'] ?? $_SERVER['HTTP_X_SETTINGS_TOKEN'] ?? '');
}

/** Actions reachable without API token (telemetry + public probes). */
function evershelfPublicActions(): array {
    return [
        'ping',
        'app_bootstrap',
        'check_update',
        'report_error',
        'report_bug',
        'client_log',
        'gdrive_oauth_callback',
    ];
}

/** GET actions that mutate state — require auth when token is configured. */
function evershelfMutatingGetActions(): array {
    return ['db_cleanup', 'export_inventory'];
}

function evershelfDestructiveActions(): array {
    return [
        'save_settings', 'db_cleanup',
        'backup_now', 'backup_delete', 'backup_restore',
        'gdrive_push', 'gdrive_oauth_exchange',
        'migrate_units',
    ];
}

function evershelfActionNeedsAuth(string $action, string $method): bool {
    if (!evershelfApiTokenRequired()) {
        return false;
    }
    if (in_array($action, evershelfPublicActions(), true)) {
        return false;
    }
    if ($method === 'POST') {
        return true;
    }
    if ($method === 'GET' && in_array($action, evershelfMutatingGetActions(), true)) {
        return true;
    }
    if (in_array($action, ['get_logs', 'gemini_usage', 'get_client_log'], true)) {
        return true;
    }
    if (in_array($action, evershelfDestructiveActions(), true)) {
        return true;
    }
    // Protect all data reads when API token is set
    return true;
}

function evershelfRequireApiAuth(string $action, string $method): void {
    if (!evershelfActionNeedsAuth($action, $method)) {
        return;
    }
    if (evershelfApiTokenValid()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'            => false,
        'error'              => 'unauthorized',
        'api_token_required' => true,
    ]);
    exit;
}

function evershelfRequireAuthForSensitive(string $action): void {
    if (!evershelfApiTokenRequired()) {
        return;
    }
    if (evershelfApiTokenValid()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'unauthorized', 'api_token_required' => true]);
    exit;
}

function evershelfSendCorsHeaders(): void {
    $configured = env('CORS_ORIGIN', '');
    if ($configured === '') {
        // Same-origin SPA — do not emit wildcard CORS
        return;
    }
    if ($configured === '*') {
        header('Access-Control-Allow-Origin: *');
    } else {
        $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed   = array_filter(array_map('trim', explode(',', $configured)));
        if ($reqOrigin !== '' && in_array($reqOrigin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $reqOrigin);
            header('Vary: Origin');
        }
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-EverShelf-Request, X-API-Token, X-Settings-Token');
}

/** Read-only actions allowed in DEMO_MODE. */
function evershelfDemoReadOnlyActions(): array {
    return [
        'ping', 'check_update', 'health_check', 'get_settings', 'gemini_usage',
        'search_barcode', 'lookup_barcode', 'stock_for_name',
        'product_get', 'products_list', 'products_search', 'inventory_search',
        'inventory_list', 'inventory_summary', 'inventory_finished_items',
        'transactions_list', 'stats', 'monthly_stats', 'macro_stats',
        'consumption_predictions', 'inventory_anomalies', 'inventory_duplicate_loss_checks',
        'recent_popular_products', 'expiry_history', 'food_facts', 'opened_shelf_life',
        'bring_list', 'bring_suggest', 'shopping_list', 'shopping_suggest', 'smart_shopping',
        'recipes_list', 'chat_list', 'app_settings_get',
        'ha_sensor', 'ha_info', 'ha_shopping_items', 'ha_test', 'ha_calendar',
        'guess_category', 'get_shopping_price', 'get_all_shopping_prices',
        'backup_list', 'export_inventory',
    ];
}

function evershelfDemoBlocksAction(string $action, string $method): bool {
    if (env('DEMO_MODE') !== 'true') {
        return false;
    }
    if (in_array($action, evershelfDemoReadOnlyActions(), true)) {
        return false;
    }
    // Block all AI generation in demo (cost + writes)
    if (str_starts_with($action, 'gemini_') || in_array($action, [
        'generate_recipe', 'generate_recipe_stream', 'chat_to_recipe', 'recipe_from_ingredient',
    ], true)) {
        return true;
    }
    if ($method === 'POST') {
        return true;
    }
    if (in_array($action, evershelfMutatingGetActions(), true)) {
        return true;
    }
    return !in_array($action, evershelfDemoReadOnlyActions(), true);
}

/** Hosts allowed for scale WebSocket relay (SSRF guard). */
function evershelfAllowedScaleHosts(): array {
    $hosts = ['127.0.0.1', 'localhost', '::1'];
    $gw    = env('SCALE_GATEWAY_URL', '');
    if ($gw !== '') {
        $p = parse_url($gw);
        if (!empty($p['host'])) {
            $hosts[] = strtolower($p['host']);
        }
    }
    // Server's own LAN IP — gateway may bind here on kiosk LAN
    if (function_exists('gethostname')) {
        $lan = gethostbyname(gethostname());
        if ($lan && filter_var($lan, FILTER_VALIDATE_IP)) {
            $hosts[] = $lan;
        }
    }
    return array_values(array_unique($hosts));
}

function evershelfScaleHostAllowed(string $host): bool {
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }
    foreach (evershelfAllowedScaleHosts() as $allowed) {
        if ($host === strtolower($allowed)) {
            return true;
        }
    }
    // Allow private /24 only when host matches server's subnet (kiosk on same LAN)
    $serverIp = evershelfLocalLanIp();
    if ($serverIp !== '') {
        $subnet = implode('.', array_slice(explode('.', $serverIp), 0, 3));
        if (str_starts_with($host, $subnet . '.')) {
            return true;
        }
    }
    return false;
}

function evershelfLocalLanIp(): string {
    $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock) {
        @socket_connect($sock, '8.8.8.8', 53);
        @socket_getsockname($sock, $ip);
        socket_close($sock);
        if (isset($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
    }
    return '';
}

/**
 * True when the request comes from the EverShelf web UI on the same host.
 * Used to auto-provision API_TOKEN to the browser without manual .env copy.
 */
function evershelfIsSameOriginBrowser(): bool {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if ($host === '') {
        return false;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $oh = parse_url($origin, PHP_URL_HOST);
        return $oh && strtolower($oh) === $host;
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $rh = parse_url($referer, PHP_URL_HOST);
        return $rh && strtolower($rh) === $host;
    }

    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if (in_array($fetchSite, ['same-origin', 'same-site'], true)) {
        return true;
    }

    return false;
}

/** Auth for scale endpoints — EventSource cannot send headers; allow query token or same-origin UI. */
function evershelfRequireScaleAccess(): void {
    if (!evershelfApiTokenRequired()) {
        return;
    }
    if (evershelfApiTokenValid()) {
        return;
    }
    if (evershelfIsSameOriginBrowser()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized', 'api_token_required' => true]);
    exit;
}
