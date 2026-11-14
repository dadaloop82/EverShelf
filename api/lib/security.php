<?php
/**
 * EverShelf — authentication, CORS, demo mode, scale gateway allowlist.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/demo.php';

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

/** Where the "a token was already generated for this install" marker lives. */
function evershelfApiTokenMarker(): string {
    return dirname(__DIR__, 2) . '/data/api_token_autogen.json';
}

/**
 * Refuse a request whose `Host` is not in `ALLOWED_HOSTS` — the DNS-rebinding guard.
 *
 * A container published on the LAN can be reached by a page the household merely *visits*:
 * the attacker's DNS answers `evil.example → 192.168.1.10`, the browser therefore sends
 * `Host: evil.example`, and every same-origin decision the browser makes — CORS, cookies,
 * `Sec-Fetch-Site` — is satisfied, because from the browser's point of view the request IS
 * on evil.example. Only the server can tell, because it knows which names it answers to.
 * OPT-IN (`ALLOWED_HOSTS` empty = no enforcement): the set of names a household uses is not
 * knowable from the code — a bare IP, `.local`, a reverse proxy's domain, a tunnel. Set it
 * as a comma list; `*.example.lan` matches every subdomain.
 */
function evershelfEnforceAllowedHosts(): void {
    $raw = trim((string)env('ALLOWED_HOSTS', ''));
    if ($raw === '' || PHP_SAPI === 'cli') {
        return;
    }
    $host = strtolower((string)parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if (evershelfHostAllowed($host, $raw)) {
        return;
    }
    EverLog::warn('Request rejected: Host not in ALLOWED_HOSTS', [
        'event' => 'host_not_allowed',
        'host'  => $host,
    ]);
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'host_not_allowed']);
    exit;
}

/**
 * The rule of evershelfEnforceAllowedHosts(), on its own: is $host one of the names in the
 * comma list? An exact match, or a `*.suffix` entry (which never matches the bare suffix).
 * An empty host never matches; an empty list is handled by the caller (it means "off").
 */
function evershelfHostAllowed(string $host, string $rawList): bool {
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }
    $list = array_values(array_filter(array_map(
        static fn($v) => strtolower(trim((string)$v)),
        explode(',', $rawList)
    )));
    if (in_array($host, $list, true)) {
        return true;
    }
    foreach ($list as $entry) {
        if (!str_starts_with($entry, '*.')) {
            continue;
        }
        $suffix = substr($entry, 1); // ".example.lan"
        if (strlen($host) > strlen($suffix) + 1 && str_ends_with($host, $suffix)) {
            return true;
        }
    }
    return false;
}

/**
 * Mint an API token on the first start of an install that has none.
 *
 * `API_TOKEN=` empty was the documented default and it means "no authentication at all":
 * every action, `inventory_list` included, answered anyone who could reach the port. The
 * README says to set it, and the household that never read the README is exactly the one
 * running an open pantry on the LAN. So the token is generated here, written to `.env`
 * (or to the `app_settings.env_overrides` fallback when the file is read-only) and enforced
 * from this same request on; the one-time pairing code that lets a phone in is printed in
 * the log next to it and is shown under Settings → System → Security on a paired device.
 *
 * Two escape hatches, both explicit: `ALLOW_OPEN_LAN=true` keeps the old behaviour, and the
 * `data/api_token_autogen.json` marker means this never mints a *second* token — a rotating
 * token would lock every device out on every request.
 */
function evershelfEnsureApiToken(): void {
    if (PHP_SAPI === 'cli') {
        return; // cron and the CLI scripts have no HTTP surface to protect
    }
    // A public demo is not an install: minting a token there would lock every visitor
    // behind a pairing code, and the demo's writes are blocked at the router anyway.
    if (evershelfDemoRequested()) {
        return;
    }
    if (evershelfEffectiveApiToken() !== '') {
        return;
    }
    if (in_array(strtolower(trim((string)env('ALLOW_OPEN_LAN', ''))), ['1', 'true', 'yes', 'on'], true)) {
        return;
    }
    $marker = evershelfApiTokenMarker();
    if (is_file($marker)) {
        return; // generated once already: the operator removed it on purpose
    }

    $token = bin2hex(random_bytes(32));
    clearEnvOverrides(['API_TOKEN']);
    $via = 'env';
    $stored = evershelfWriteEnvFile(evershelfEnvFile(), ['API_TOKEN' => $token], dirname(__DIR__, 2) . '/.env.example');
    if ($stored) {
        loadEnv(true);
    } else {
        // Read-only .env (a mounted secret, a container layer): keep the value in the DB
        // override table, the same fallback save_settings() already uses.
        $via = 'override';
        $stored = saveEnvOverrides(['API_TOKEN' => $token]);
        loadEnvOverrides(true);
    }
    if (!$stored || evershelfEffectiveApiToken() === '') {
        // Never leave a half-configured install rotating a token: log and stay as we were.
        EverLog::error('API token autogeneration failed — set API_TOKEN by hand in .env', [
            'event'    => 'api_token_autogen_failed',
            'env_file' => evershelfEnvFile(),
        ]);
        return;
    }
    @file_put_contents($marker, json_encode([
        'created' => date('c'),
        'storage' => $via,
    ], JSON_PRETTY_PRINT));
    EverLog::warn('API token generated on first start (set ALLOW_OPEN_LAN=true to keep an open LAN install)', [
        'event' => 'api_token_autogenerated',
        'hint'  => 'pairing code below; Settings → System → Security on a paired device',
    ]);
    // Mint the code now so the pairing code lands in the log right after this line.
    if (function_exists('evershelfPairingEnsure')) {
        evershelfPairingEnsure();
    }
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
        // calendar_ics: a calendar client only knows how to GET a URL, so the
        // API token can never be presented. The handler authenticates with its own
        // read-only ICS_TOKEN (?token=…, hash_equals) and returns 403/404 otherwise.
        'calendar_ics',
        'telegram_webhook',
        // webpush_inbox + notification_action: both are reached by the SERVICE WORKER,
        // which has no page and cannot present the API token (#122). Each one carries
        // its own proof: the inbox a per-device token minted at subscription (SHA-256 on
        // disk), the action an HMAC token signed into the notification itself
        // (api/lib/push_actions.php, 12 h, one row, one action).
        'webpush_inbox',
        'notification_action',
        // support_qr: the donation link as a QR image. The native kiosk wizard
        // shows it *before* it can pair, so it can never present a token; the
        // rendered content comes from .env only (the amount must be one of the
        // published ones), so it cannot be abused as a generic QR renderer.
        'support_qr',
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
 *   report_error       kiosk APK ErrorReporter — builds already installed send no header
 *   client_log         public log sink (see evershelfPublicActions())
 *   save_settings      kiosk APK SettingsActivity/SetupActivity (JSON body only)
 *   health_ingest      Health Bridge / HA automation (X-Health-Token auth)
 *   ha_generate_recipe Home Assistant rest_command (JSON body only)
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
        'telegram_webhook',
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
    // Demo mode is a public, read-only showcase: `evershelfDemoBlocksAction()` is the
    // guard that protects it (no write ever reaches a handler), and asking a visitor
    // to pair before looking at the demo would defeat the point.
    if (evershelfDemoRequested()) {
        return false;
    }
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

/**
 * True when two URLs point at the same ORIGIN (scheme + host + port), not merely at a
 * string that starts the same way.
 *
 * `str_starts_with($url, 'http://ha.local')` also accepts `http://ha.local.evil.example/x`,
 * and the caller of this rule (the TTS proxy) is about to attach the Home Assistant or the
 * TTS bearer token to that request: a prefix match hands the household's token to any host
 * whose name begins with the configured one. Ports are normalised (80/443 are default), the
 * host must be present and case-insensitive, and a relative/invalid URL never matches.
 */
function evershelfSameOrigin(string $a, string $b): bool {
    $origin = static function (string $url): ?string {
        $p = parse_url(trim($url));
        if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($p['host']) . ':' . $port;
    };
    $oa = $origin($a);
    $ob = $origin($b);
    return $oa !== null && $ob !== null && hash_equals($ob, $oa);
}

function evershelfRequireAuthForSensitive(string $action): void {
    if (evershelfDemoRequested()) {
        return; // public demo: no token to present, nothing behind it but sample data
    }
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
    if (evershelfDemoRequested()) {
        // A demo exists to be EMBEDDED by the marketing site (an iframe on socly.it and
        // friends). `X-Frame-Options` has no "allow that other origin" value, so the demo
        // speaks `frame-ancestors` instead — `DEMO_FRAME_ANCESTORS`, default `*`, because a
        // public demo carries nothing but sample data.
        header('Content-Security-Policy: frame-ancestors ' . evershelfDemoFrameAncestors());
    } else {
        header('X-Frame-Options: SAMEORIGIN');
    }
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

/**
 * `app_settings` keys the client owns (shared across devices).
 *
 * The table also holds instance internals: `env_overrides` (a fallback copy of
 * environment values — real API keys and passwords once `.env` is read-only,
 * issue #298), the `migration_*` flags and the pairing state. Those must never
 * leave the server through `app_settings_get`, and a client must not be able to
 * write them through `app_settings_save`: rewriting `env_overrides` would change
 * what `env()` returns (provider, shopping mode, `API_TOKEN`), and clearing a
 * `migration_*` flag re-runs a data migration on the next boot.
 *
 * One list, read by both directions, so a new internal row cannot leak by default.
 * Every key the frontend writes must be listed here, or the two sides drift.
 */
function evershelfClientSettingsKeys(): array
{
    return [
        // Settings page state
        'review_confirmed',
        'meal_plan',
        'tts_voice',
        'user_prefs',
        // User data that used to live in localStorage (one-time migration + sync)
        'scan_history',
        'shopping_tags',
        'pinned_bring',
        'pref_use_loc',
        'pref_move_loc',
        'auto_added_bring',
        'bring_blocklist',
        'no_expiry_dismissed',
        'opened_check_snoozed',
        'family_sibling_confirmed',
        'shopping_plan_days',
        // Guided setup ledger (issue #301): answered once per instance, not per browser
        'setup_done',
        'setup_seen',
        // EverShelf Med (opt-in): the switch, the medical locations and the disclaimer
        // acceptance are instance-level on purpose — a new phone must not re-ask, and the
        // server needs the switch to keep medicines out of the food domains.
        'med_enabled',
        'medical_locations',
        'med_disclaimer_accepted',
        'med_setup_done',
        'med_country',
    ];
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
        // Public telemetry a visitor's browser sends while looking at the demo: the log
        // is how a broken demo is diagnosed from the server side.
        'client_log', 'report_error',
        'ping', 'check_update', 'health_check', 'get_settings', 'gemini_usage',
        'search_barcode', 'lookup_barcode', 'resolve_barcode', 'barcode_catalog_sync', 'stock_for_name',
        'product_get', 'products_list', 'products_search', 'inventory_search', 'ai_product_suggest',
        'inventory_list', 'inventory_summary', 'inventory_finished_items',
        'transactions_list', 'stats', 'monthly_stats', 'macro_stats', 'spend_stats',
        // Reads the screens issue themselves (not only the ones a payload mentions):
        // a read missing here is answered with the demo's no-op and the screen goes
        // quietly blank — `med_status` did, and it is the boot's medicine switch.
        'med_status', 'med_lookup', 'med_symptom_search',
        'shopping_dismissed', 'audit_state', 'tags_list', 'pao_get',
        'families_list', 'family_sibling_suggest', 'free_apis', 'get_ics_settings',
        'consumption_predictions', 'inventory_anomalies', 'inventory_duplicate_loss_checks',
        'recent_popular_products', 'expiry_history', 'food_facts', 'opened_shelf_life',
        'bring_list', 'bring_suggest', 'shopping_list', 'shopping_suggest', 'smart_shopping',
        'seasonal_shopping_review', 'stale_inventory_items',
        'templates_list',
        'ai_test',
        'recipes_list', 'chat_list', 'app_settings_get',
        // NOT here on purpose: `backup_list` / `export_inventory` / `export_monthly_report`
        // (the household's files and its spend ledger), `health_status` (the health
        // profile), `ha_*` (its Home Assistant) and
        // `weather_get` / `weather_geocode` (where the kitchen is). A demo carries none of
        // that, and `get_settings` blanks what it would otherwise reveal.
        'guess_category', 'get_shopping_price', 'get_all_shopping_prices',
    ];
}

function evershelfDemoBlocksAction(string $action, string $method): bool {
    if (!evershelfDemoRequested()) {
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

// =============================================================================
// ===== OUTBOUND REPORT REDACTION ============================================
// =============================================================================
// Bug reports leave the instance: data/error_reports.log is copied around by
// backups, and _createOrCommentGithubIssue() publishes to a public repository.
// Whatever a client (or PHP itself) puts in a report can carry a credential, and
// the documented auth even accepts `?api_token=…` in the URL — which is the
// `location.href` the PWA sends with every error. Redact at the boundary, cap the
// size, and only then let the text out.
//
// The patterns are deliberately blunt: losing a secret from a debug snippet is
// cheap, publishing it on GitHub is not.

/** Key names whose assigned value must never leave the instance. */
function evershelfSecretKeyPattern(): string {
    return 'api[_-]?token|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token'
         . '|health[_-]?token|gh[_-]?issue[_-]?token|token|password|passwd|pwd'
         . '|client[_-]?secret|secret|api[_-]?key|apikey|authorization|key';
}

/**
 * Remove credentials from a free-text string: `key=value` (query string, form
 * body, log line), `"key": "value"` (JSON), `Key: value` (headers), credentials
 * inside a URL, and tokens with a recognisable shape (GitHub, Google, OpenAI,
 * Slack). Idempotent — running it twice changes nothing.
 */
function evershelfRedactSecrets(string $text): string {
    if ($text === '') {
        return '';
    }
    $keys = evershelfSecretKeyPattern();

    // Header style: "Authorization: Bearer ghp_…", "X-Health-Token: …".
    $text = (string)preg_replace(
        '/((?:authorization|x-health-token|x-api-token|x-evershelf-token)\s*:\s*)(?:bearer\s+)?[^\s"\'<>]+/i',
        '$1[REDACTED]',
        $text
    );

    // JSON style: {"api_token": "…"} (the value may be any quoted string).
    $text = (string)preg_replace_callback(
        '/(["\'])([A-Za-z0-9_-]+)\1(\s*:\s*)(["\'])(?:[^"\'\\\\]|\\\\.)*\4/',
        static function (array $m) use ($keys): string {
            if (!preg_match('/^(?:' . $keys . ')$/i', $m[2])) {
                return $m[0];
            }
            return $m[1] . $m[2] . $m[1] . $m[3] . $m[4] . '[REDACTED]' . $m[4];
        },
        $text
    );

    // Query / form / "Key: value" style: api_token=abc, health_token: abc, key='abc'.
    // A boundary char is required so `monkey=1` is left alone; the value stops at
    // the usual delimiters and quotes (the opening quote is kept, the closing one
    // is left in place by the value class). The lookahead keeps the function
    // idempotent: `api_token=[REDACTED]` must not become `[REDACTED]]`.
    $text = (string)preg_replace_callback(
        '/(^|[\s?&,;(])((?:' . $keys . ')(?:\s*=\s*|\s*:\s*)(?:["\']?))(?!\[REDACTED\])([^\s,;&)\]}"\']+)/i',
        static function (array $m): string {
            return $m[1] . $m[2] . '[REDACTED]';
        },
        $text
    );

    // Credentials embedded in a URL: https://user:pass@host.
    $text = (string)preg_replace('#(https?://[^\s:@/]+):[^\s@/]+@#i', '$1:[REDACTED]@', $text);

    // Tokens with a recognisable shape, wherever they appear.
    return (string)preg_replace(
        [
            '/\bgh[pousr]_[A-Za-z0-9]{16,}\b/',
            '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
            '/\bAIza[0-9A-Za-z_\-]{30,}\b/',
            '/\bGOCSPX-[A-Za-z0-9_\-]{10,}\b/',
            '/\bsk-[A-Za-z0-9]{20,}\b/',
            '/\bxox[baprs]-[A-Za-z0-9\-]{10,}\b/',
        ],
        ['gh_[REDACTED]', 'github_pat_[REDACTED]', 'AIza[REDACTED]', 'GOCSPX-[REDACTED]', 'sk-[REDACTED]', 'xox[REDACTED]'],
        $text
    );
}

/**
 * Cut a string to $max bytes without leaving a dangling multi-byte sequence — a
 * report payload is JSON-encoded further down, and json_encode() fails on
 * invalid UTF-8.
 */
function evershelfTruncateUtf8(string $text, int $max): string {
    if ($max <= 0 || strlen($text) <= $max) {
        return $text;
    }
    $cut = substr($text, 0, $max);
    $cut = (string)preg_replace('/[\x80-\xBF]+$/', '', $cut); // trailing continuations
    return (string)preg_replace('/[\xC0-\xFF]$/', '', $cut);  // dangling lead byte
}

/**
 * Redact a report context recursively and bound it: strings ≤ 1 KB, ≤ 50 keys per
 * level, ≤ 5 levels deep. Whatever the client sent, the result stays JSON-safe.
 *
 * @param array<mixed> $context
 * @return array<mixed>
 */
function evershelfRedactContext(array $context, int $depth = 0): array {
    $out   = [];
    $count = 0;
    foreach ($context as $key => $value) {
        if ($count >= 50) {
            $out['_truncated'] = (count($context) - $count) . ' more keys omitted';
            break;
        }
        $count++;
        $safeKey = is_string($key) ? evershelfTruncateUtf8(evershelfRedactSecrets($key), 80) : $key;
        if (is_string($value)) {
            $out[$safeKey] = evershelfTruncateUtf8(evershelfRedactSecrets($value), 1024);
        } elseif (is_array($value)) {
            $out[$safeKey] = ($depth < 4) ? evershelfRedactContext($value, $depth + 1) : '[nested too deep]';
        } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            $out[$safeKey] = $value;
        } else {
            $out[$safeKey] = '[unprintable]';
        }
    }
    return $out;
}

/**
 * JSON for a report: redacted and capped at $maxBytes. Top-level keys are dropped
 * when the payload is too large, so the result is always valid JSON — a client
 * must not be able to push post_max_size (32 MB) into a log line or an issue.
 *
 * @param array<mixed> $context
 */
function evershelfReportContextJson(array $context, int $maxBytes = 4096): string {
    if ($context === []) {
        return '';
    }
    $safe  = evershelfRedactContext($context);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
           | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    $json = json_encode($safe, $flags);
    if (!is_string($json) || strlen($json) <= $maxBytes) {
        return is_string($json) ? $json : '';
    }

    $kept = [];
    foreach ($safe as $key => $value) {
        $kept[$key] = $value;
        $probe = json_encode($kept, $flags);
        if (is_string($probe) && strlen($probe) > $maxBytes - 64) {
            unset($kept[$key]);
            $kept['_truncated'] = 'context capped at ' . $maxBytes . ' bytes';
            break;
        }
    }
    $json = json_encode($kept, $flags);
    if (!is_string($json) || strlen($json) > $maxBytes) {
        return '{"_error":"context exceeds ' . $maxBytes . ' bytes"}';
    }
    return $json;
}
