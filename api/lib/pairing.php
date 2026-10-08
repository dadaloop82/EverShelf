<?php
/**
 * EverShelf — one-time pairing code for the API-token bootstrap.
 *
 * `app_bootstrap` used to hand `API_TOKEN` to any client that sent
 * `Sec-Fetch-Site: same-origin` — a header any HTTP client can forge. The token is
 * now disclosed only after presenting the one-time code printed in the server log
 * *and* shown on Settings → System → Security of an already-paired device
 * (`pairing_code` action, authenticated), so it is never returned to an anonymous
 * request.
 *
 * Set `API_BOOTSTRAP_OPEN=true` to restore the old behaviour on a fully trusted LAN
 * (see SECURITY.md) — this re-exposes the token to anyone who can reach the port.
 */

/** How long a printed code stays valid. */
const EVERSHELF_PAIRING_TTL = 1800;

/** Failed attempts tolerated before the code is burned (brute-force guard). */
const EVERSHELF_PAIRING_MAX_ATTEMPTS = 50;

function evershelfPairingFilePath(): string
{
    return EVERSHELF_ROOT . '/data/api_pairing.json';
}

/** @return array<string,mixed> */
function evershelfPairingRead(): array
{
    $path = evershelfPairingFilePath();
    if (!file_exists($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function evershelfPairingWrite(array $state): void
{
    $path = evershelfPairingFilePath();
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($path, 0600);
}

/** True when $state holds a code that is still valid and unused. */
function evershelfPairingFresh(array $state): bool
{
    return !empty($state['code'])
        && isset($state['created_ts'])
        && (time() - (int)$state['created_ts']) < EVERSHELF_PAIRING_TTL;
}

/**
 * Return the active pairing code, creating (and printing) one when absent/expired.
 *
 * @return array{code: string, expires_in: int}
 */
function evershelfPairingEnsure(): array
{
    $state = evershelfPairingRead();
    if (evershelfPairingFresh($state)) {
        return [
            'code'       => (string)$state['code'],
            'expires_in' => EVERSHELF_PAIRING_TTL - (time() - (int)$state['created_ts']),
        ];
    }

    $code = bin2hex(random_bytes(4)); // 8 hex chars ≈ 4.3e9 combinations
    evershelfPairingWrite(['code' => $code, 'created_ts' => time(), 'attempts' => 0]);

    $minutes = (int)(EVERSHELF_PAIRING_TTL / 60);
    // The message contains the literal words "pairing code" so the obvious
    //   grep -i "pairing code" logs/evershelf_*.log
    // finds it; `event` keeps the stable machine-readable key for log tooling.
    // Also remind operators the same code is on a paired device under Settings.
    EverLog::warn('API pairing code', [
        'event'       => 'api_pairing_code',
        'code'        => $code,
        'ttl_seconds' => EVERSHELF_PAIRING_TTL,
        'hint'        => 'Settings → System → Security on a paired device',
    ]);
    // Also to stderr so container users see it with `docker logs evershelf`.
    error_log("[EverShelf] API pairing code: {$code} (valid {$minutes} min; also Settings → System → Security on a paired device)");

    return ['code' => $code, 'expires_in' => EVERSHELF_PAIRING_TTL];
}

/**
 * Authenticated status for Settings → Security: current (or freshly minted) code.
 * Never call this from an anonymous request — the router requires the API token.
 *
 * GET  ?action=pairing_code
 * POST ?action=pairing_code  body { "refresh": true } — burn and mint a new code
 *
 * @return void (JSON to stdout)
 */
function pairingCodeStatus(): void
{
    if (!evershelfApiTokenRequired()) {
        echo json_encode([
            'success'         => true,
            'pairing_needed'  => false,
            'reason'          => 'open',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $input = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    $refresh = !empty($_GET['refresh']) || !empty($input['refresh']);
    if ($refresh) {
        evershelfPairingWrite([]);
        EverLog::info('API pairing code refreshed from Settings', [
            'event' => 'api_pairing_code_refresh',
        ]);
    }

    $pair = evershelfPairingEnsure();
    echo json_encode([
        'success'        => true,
        'pairing_needed' => true,
        'code'           => $pair['code'],
        'expires_in'     => $pair['expires_in'],
        'ttl_seconds'    => EVERSHELF_PAIRING_TTL,
    ], JSON_UNESCAPED_UNICODE);
}

/** Check a presented code; the first success consumes it. */
function evershelfPairingConsume(string $code): bool
{
    $code  = strtolower(trim($code));
    $state = evershelfPairingRead();
    if ($code === '' || !evershelfPairingFresh($state)) {
        return false;
    }
    if (!hash_equals(strtolower((string)$state['code']), $code)) {
        $state['attempts'] = (int)($state['attempts'] ?? 0) + 1;
        // Burn the code after too many failures; a fresh one is printed on demand.
        evershelfPairingWrite($state['attempts'] >= EVERSHELF_PAIRING_MAX_ATTEMPTS
            ? []
            : $state);
        return false;
    }
    evershelfPairingWrite([]);
    return true;
}
