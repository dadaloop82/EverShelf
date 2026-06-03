#!/usr/bin/env php
<?php
/**
 * One-time security migration: GitHub token → encrypted .env, optional API_TOKEN.
 */
require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/github.php';

$envFile = dirname(__DIR__) . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, ".env not found\n");
    exit(1);
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES);
$vars = loadEnv();
$changed = false;

// Migrate legacy XOR token from previous index.php if still in git history
if (empty($vars['GH_ISSUE_TOKEN']) && empty($vars['GH_ISSUE_TOKEN_ENC'])) {
    $legacyEnc = '23580718460c2c444031290243627e7971622b29030a3e4d50001e45261659420b6e110a423f30447133205b425a577971561f32762b0b034e0b3e56106d5945020406254a3a4647592a1a611c66687a0b672043700f34757900014004';
    $legacyKey = 'D1sp3ns4!Ev3r#26';
    $encBin = hex2bin($legacyEnc);
    $plain = '';
    if ($encBin) {
        for ($i = 0; $i < strlen($encBin); $i++) {
            $plain .= chr(ord($encBin[$i]) ^ ord($legacyKey[$i % strlen($legacyKey)]));
        }
    }
    if ($plain !== '' && str_starts_with($plain, 'github_')) {
        $newKey = bin2hex(random_bytes(16));
        $enc = evershelfEncryptGhToken($plain, $newKey);
        $lines[] = '';
        $lines[] = '# GitHub Issues (migrated from legacy source — encrypted at rest)';
        $lines[] = 'GH_ISSUE_TOKEN_ENC=' . $enc;
        $lines[] = 'GH_ISSUE_TOKEN_KEY=' . $newKey;
        $changed = true;
        echo "Migrated GitHub token to GH_ISSUE_TOKEN_ENC\n";
    }
}

if (empty($vars['API_TOKEN']) && empty($vars['SETTINGS_TOKEN'])) {
    $token = bin2hex(random_bytes(24));
    $lines[] = '';
    $lines[] = '# API access token — required for all API calls when set (also used by kiosk/HA)';
    $lines[] = 'API_TOKEN=' . $token;
    $changed = true;
    echo "Generated API_TOKEN (save this for your devices): {$token}\n";
}

if ($changed) {
    file_put_contents($envFile, implode("\n", $lines) . "\n");
    chmod($envFile, 0640);
    echo "Updated .env\n";
} else {
    echo "No migration needed\n";
}
