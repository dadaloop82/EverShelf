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
        'health_bridge_hello',
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

/**
 * POST actions that native clients and server-to-server integrations call
 * without the X-EverShelf-Request header. None of them has a browser session to
 * forge, so they keep the historical proof (that header *or* a JSON content
 * type):
 *   report_error       kiosk APK ErrorReporter + the PWA offline flush
 *   client_log         kiosk log upload + the PWA offline flush
 *   save_settings      kiosk APK SettingsActivity/SetupActivity (JSON only)
 *   health_ingest      Health Bridge / HA automation (X-Health-Token auth)
 *   ha_generate_recipe Home Assistant rest_command / evershelf component
 * Everything else must send the header, which assets/js/app.js sets on every
 * non-GET call; mcp-server and the kiosk set it explicitly.
 */
function evershelfCsrfExemptPostActions(): array {
    return [
        'report_error',
        'client_log',
        'save_settings',
        'health_ingest',
        'ha_generate_recipe',
    ];
}

/**
 * CSRF decision for a POST request. The header is mandatory; only the actions in
 * evershelfCsrfExemptPostActions() may fall back to a JSON content type. Neither
 * can be set by a cross-site <form> (the classic `enctype="text/plain"` trick
 * changes the body, not the headers it is allowed to send).
 */
function evershelfCsrfGuardAllows(string $action, string $header, string $contentType): bool {
    if ($header === '1') {
        return true;
    }
    if ($action === '' || !in_array($action, evershelfCsrfExemptPostActions(), true)) {
        return false;
    }
    return stripos($contentType, 'application/json') !== false;
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

/** Health Bridge phone gateway may auth health_ingest with X-Health-Token. */
function evershelfHealthIngestActions(): array {
    return ['health_ingest'];
}

function evershelfProvidedHealthToken(): string {
    if (!empty($_SERVER['HTTP_X_HEALTH_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_HEALTH_TOKEN'];
    }
    if (isset($_GET['health_token'])) {
        return (string)$_GET['health_token'];
    }
    return '';
}

function evershelfRequireApiAuth(string $action, string $method): void {
    if (!evershelfActionNeedsAuth($action, $method)) {
        return;
    }
    if (evershelfApiTokenValid()) {
        return;
    }
    // NOTE: no same-origin bypass for setup actions — `mealie_install` /
    // `mealie_configure` run docker and rewrite .env, and the headers used to
    // detect "same-origin" are attacker-controlled.
    // Optional Health Bridge token (phone gateway) for ingest only
    if (in_array($action, evershelfHealthIngestActions(), true)) {
        $ht = evershelfProvidedHealthToken();
        if ($ht !== '' && function_exists('healthBridgeTokenValid') && healthBridgeTokenValid(getDB(), $ht)) {
            return;
        }
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

/**
 * Baseline security headers for responses produced by PHP.
 *
 * The Apache config (.htaccess) sets the same headers for static files; these cover
 * deployments that answer PHP directly (php -S, another SAPI, or a proxy that strips
 * the web-server headers). A full CSP is not possible yet (inline handlers).
 */
function evershelfSendSecurityHeaders(): void {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    // Only advertise HSTS when the request really arrived over TLS.
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($https || $proto === 'https') {
        header('Strict-Transport-Security: max-age=31536000');
    }
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
    header('Access-Control-Allow-Headers: Content-Type, X-EverShelf-Request, X-API-Token, X-Settings-Token, X-Health-Token');
}

/** Read-only actions allowed in DEMO_MODE. */
function evershelfDemoReadOnlyActions(): array {
    return [
        'ping', 'check_update', 'health_check', 'get_settings', 'gemini_usage',
        'search_barcode', 'lookup_barcode', 'resolve_barcode', 'barcode_catalog_sync', 'stock_for_name',
        'product_get', 'products_list', 'products_search', 'inventory_search', 'ai_product_suggest',
        'inventory_list', 'inventory_summary', 'inventory_finished_items',
        'transactions_list', 'stats', 'monthly_stats', 'macro_stats',
        'consumption_predictions', 'inventory_anomalies', 'inventory_duplicate_loss_checks',
        'recent_popular_products', 'expiry_history', 'food_facts', 'opened_shelf_life',
        'bring_list', 'bring_suggest', 'shopping_list', 'shopping_suggest', 'smart_shopping',
        'seasonal_shopping_review', 'stale_inventory_items',
        'templates_list',
        'ai_test',
        'recipes_list', 'chat_list', 'app_settings_get', 'health_status',
        'ha_sensor', 'ha_info', 'ha_shopping_items', 'ha_test', 'ha_calendar',
        'ha_refresh_prices',
        'guess_category', 'get_shopping_price', 'get_all_shopping_prices',
        'backup_list', 'export_inventory', 'weather_get', 'weather_geocode',
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
        'ha_suggest_recipe', 'ha_generate_recipe',
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

/** Hosts allowed to be reached through the TTS proxy (SSRF guard). */
function evershelfAllowedTtsHosts(): array {
    $hosts = [];
    foreach (['HA_URL', 'TTS_URL'] as $envKey) {
        $u = env($envKey, '');
        if ($u !== '') {
            $h = parse_url($u, PHP_URL_HOST);
            if (!empty($h)) {
                $hosts[] = strtolower($h);
            }
        }
    }
    foreach (array_filter(array_map('trim', explode(',', env('TTS_ALLOWED_HOSTS', '')))) as $h) {
        $hosts[] = strtolower($h);
    }
    return array_values(array_unique($hosts));
}

/**
 * SSRF guard for tts_proxy. Only the configured HA/TTS hosts (plus optional
 * TTS_ALLOWED_HOSTS) and private hosts on the server's own LAN are reachable.
 * Cloud-metadata, loopback and public IPs require an explicit allowlist entry.
 */
function evershelfTtsUrlAllowed(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower(trim((string)$parts['host'], '[]'));
    if ($host === '') {
        return false;
    }
    // 1. Explicit allowlist from HA_URL / TTS_URL / TTS_ALLOWED_HOSTS.
    if (in_array($host, evershelfAllowedTtsHosts(), true)) {
        return true;
    }
    // 2. Numeric hosts: allow only private LAN addresses on the server's subnet.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        // Public or reserved (incl. 169.254.169.254 metadata) → must be explicit.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // Loopback must always be explicit (avoid hitting local services blindly).
        if (in_array($host, ['127.0.0.1', '::1'], true)) {
            return false;
        }
        $lan = evershelfLocalLanIp();
        if ($lan !== '' && !str_contains($host, ':')) {
            $subnet = implode('.', array_slice(explode('.', $lan), 0, 3));
            return str_starts_with($host, $subnet . '.');
        }
        return false;
    }
    // 3. Named hosts resolving to the server's LAN are allowed (e.g. ha.local).
    $resolved = @gethostbynamel($host) ?: [];
    $lan = evershelfLocalLanIp();
    if ($lan !== '' && $resolved !== []) {
        $subnet = implode('.', array_slice(explode('.', $lan), 0, 3));
        foreach ($resolved as $ip) {
            if (str_starts_with($ip, $subnet . '.')) {
                return true;
            }
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
 * True when the request looks like it comes from the EverShelf web UI on the same host.
 *
 * SECURITY: the underlying headers (`Origin`, `Referer`, `Sec-Fetch-Site`) are
 * client-controlled and can be forged by any HTTP client, so this must never gate
 * authentication or authorisation decisions. It is kept only for informational use.
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

/** Trusted reverse proxies (IP or CIDR), from the comma-separated TRUSTED_PROXIES. */
function evershelfTrustedProxies(): array {
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '')))));
    return $list;
}

function evershelfIpMatches(string $ip, string $entry): bool {
    if ($entry === $ip) {
        return true;
    }
    if (!str_contains($entry, '/')) {
        return false;
    }
    [$net, $bits] = array_pad(explode('/', $entry, 2), 2, '32');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || !filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $bits = max(0, min(32, (int)$bits));
    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
    return ((int)ip2long($ip) & $mask) === ((int)ip2long($net) & $mask);
}

/** True when an IP belongs to the configured trusted-proxy list. */
function evershelfIpIsTrusted(string $ip): bool {
    foreach (evershelfTrustedProxies() as $entry) {
        if (evershelfIpMatches($ip, $entry)) {
            return true;
        }
    }
    return false;
}

/**
 * Effective client IP for rate limiting.
 *
 * `X-Forwarded-For` is honoured only when the direct peer is a trusted proxy
 * (TRUSTED_PROXIES), walking the chain from right to left. Without a configured
 * proxy list the peer address wins, so a client can never pick its own bucket.
 */
function evershelfClientIp(): string {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (!evershelfIpIsTrusted($peer)) {
        return $peer;
    }
    $parts = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $candidate = $parts[$i];
        if (filter_var($candidate, FILTER_VALIDATE_IP) && !evershelfIpIsTrusted($candidate)) {
            return $candidate;
        }
    }
    return $peer;
}

/**
 * Auth for scale endpoints. EventSource cannot send headers, so the UI passes the
 * token in the query string (`_scaleAuthQuery()`); there is intentionally no
 * same-origin bypass because those headers are client-controlled.
 */
function evershelfRequireScaleAccess(): void {
    if (!evershelfApiTokenRequired()) {
        return;
    }
    if (evershelfApiTokenValid()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized', 'api_token_required' => true]);
    exit;
}
