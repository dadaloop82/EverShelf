<?php
/**
 * EverShelf — one-time pairing code for the API-token bootstrap.
 *
 * `app_bootstrap` used to hand `API_TOKEN` to any client that sent
 * `Sec-Fetch-Site: same-origin` — a header any HTTP client can forge. The token is
 * now disclosed only after presenting the one-time code printed in the server log,
 * so it is never returned to an anonymous request.
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
    EverLog::warn('api_pairing_code', ['code' => $code, 'ttl_seconds' => EVERSHELF_PAIRING_TTL]);
    // Also to stderr so container users see it with `docker logs evershelf`.
    error_log("[EverShelf] API pairing code: {$code} (valid {$minutes} min)");

    return ['code' => $code, 'expires_in' => EVERSHELF_PAIRING_TTL];
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
