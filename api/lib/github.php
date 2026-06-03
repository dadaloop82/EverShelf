<?php
/**
 * EverShelf — GitHub issue reporting token (encrypted at rest in .env).
 *
 * Configure ONE of:
 *   GH_ISSUE_TOKEN=ghp_...                    (plain, .env is gitignored)
 *   GH_ISSUE_TOKEN_ENC=... + GH_ISSUE_TOKEN_KEY=...  (AES-256-GCM, preferred)
 *
 * Generate encrypted value: php scripts/encrypt-gh-token.php 'ghp_xxx' 'your-secret-key'
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
