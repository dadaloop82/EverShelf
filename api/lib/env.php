<?php
/**
 * EverShelf — environment variable loader (.env).
 */

function loadEnv(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $envFile = dirname(__DIR__, 2) . '/.env';
    $cache = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $cache[trim($key)] = trim($val);
        }
    }
    return $cache;
}

function env(string $key, string $default = ''): string {
    $vars = loadEnv();
    return $vars[$key] ?? $default;
}

/** Push a single key into the in-memory env cache (after .env write). */
function envCacheSet(string $key, string $value): void {
    loadEnv();
    // Force reload on next call — callers should use loadEnv() return for batch updates
}
