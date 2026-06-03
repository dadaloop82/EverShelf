#!/usr/bin/env php
<?php
/**
 * Encrypt a GitHub Issues token for storage in .env as GH_ISSUE_TOKEN_ENC.
 *
 * Usage:
 *   php scripts/encrypt-gh-token.php 'ghp_xxxx' 'your-secret-key'
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/encrypt-gh-token.php <token> <key>\n");
    exit(1);
}
require_once __DIR__ . '/../api/lib/github.php';
echo evershelfEncryptGhToken($argv[1], $argv[2]) . "\n";
