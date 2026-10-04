<?php
/**
 * EverShelf — GitHub issue reporting token (encrypted at rest in .env).
 *
 * Configure ONE of:
 *   GH_ISSUE_TOKEN=ghp_...                    (plain, .env is gitignored)
 *   GH_ISSUE_TOKEN_ENC=... + GH_ISSUE_TOKEN_KEY=...  (AES-256-GCM, preferred)
 *
 * Generate encrypted value: php scripts/encrypt-gh-token.php 'ghp_xxx' 'your-secret-key'
 *
 * SECURITY NOTE — key derivation: the 256-bit AES key is `sha256(GH_ISSUE_TOKEN_KEY)`
 * with **no salt and no key stretching**, and the key is stored in the same `.env`
 * as the ciphertext. Encryption therefore only "hides" the token from accidental
 * disclosure (screen sharing, log dumps, backups of one file); it does NOT protect
 * against anyone who can read `.env`. That is acceptable for a self-hosted LAN tool,
 * but it is not equivalent to a real secret manager: keep `.env` at mode 600 and
 * outside your repository, and rotate the token if `.env` ever leaks.
 */

require_once __DIR__ . '/env.php';

function evershelfDecryptGhToken(string $encB64, string $key): string {
    $raw = base64_decode($encB64, true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain  = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        hash('sha256', $key, true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    return ($plain !== false) ? $plain : '';
}

function evershelfEncryptGhToken(string $plain, string $key): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        hash('sha256', $key, true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    return base64_encode($iv . $tag . $cipher);
}

/** Decode GitHub Issues token at runtime — never stored in source code. */
function _ghToken(): string {
    static $token = null;
    if ($token !== null) {
        return $token;
    }

    $plain = env('GH_ISSUE_TOKEN');
    if ($plain !== '') {
        $token = $plain;
        return $token;
    }

    $enc = env('GH_ISSUE_TOKEN_ENC');
    $key = env('GH_ISSUE_TOKEN_KEY');
    if ($enc !== '' && $key !== '') {
        $token = evershelfDecryptGhToken($enc, $key);
        return $token;
    }

    $token = '';
    return $token;
}

/**
 * Master switch for publishing reports to GitHub (REPORT_ENABLED).
 *
 * Off unless explicitly enabled: the reporter opens issues on a public
 * repository, so publishing is an opt-in on top of configuring the token — a
 * stray token in a shared .env must not be enough to publish error text that the
 * redaction in api/lib/security.php has not anticipated. `data/error_reports.log`
 * is written either way, so diagnostics are never lost, only not published.
 *
 * Accepts the flag as an argument so the behaviour is testable without touching
 * .env (see scripts/test-report-redaction.php).
 */
function _ghReportsEnabled(?string $flag = null): bool {
    $v = strtolower(trim($flag ?? (string)env('REPORT_ENABLED', 'false')));
    return in_array($v, ['1', 'true', 'on', 'yes'], true);
}
