<?php
/**
 * EverShelf — environment variable loader (.env + DB overrides).
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

/** @return array<string,string> */
function loadEnvOverrides(bool $reload = false): array {
    static $cache = null;
    if (!$reload && $cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!function_exists('getDB')) {
        return $cache;
    }
    try {
        $row = getDB()->query("SELECT value FROM app_settings WHERE key = 'env_overrides'")->fetchColumn();
        if ($row) {
            $decoded = json_decode((string)$row, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    if (is_string($k) && (is_string($v) || is_numeric($v))) {
                        $cache[$k] = (string)$v;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // DB may be unavailable during early bootstrap
    }
    return $cache;
}

function env(string $key, string $default = ''): string {
    $overrides = loadEnvOverrides();
    if (array_key_exists($key, $overrides)) {
        return $overrides[$key];
    }
    $vars = loadEnv();
    return $vars[$key] ?? $default;
}

/** Persist env overrides when .env is not writable (merged on read via env()). */
function saveEnvOverrides(array $updates): bool {
    if (empty($updates) || !function_exists('getDB')) {
        return false;
    }
    try {
        $db = getDB();
        $current = loadEnvOverrides(true);
        foreach ($updates as $key => $val) {
            $current[(string)$key] = (string)$val;
        }
        $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES ('env_overrides', ?, datetime('now'))
                      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
            ->execute([json_encode($current, JSON_UNESCAPED_UNICODE)]);
        loadEnvOverrides(true);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Remove keys from DB overrides after a successful .env write. */
function clearEnvOverrides(array $envKeys): void {
    if (empty($envKeys) || !function_exists('getDB')) {
        return;
    }
    try {
        $current = loadEnvOverrides(true);
        $changed = false;
        foreach ($envKeys as $key) {
            if (isset($current[$key])) {
                unset($current[$key]);
                $changed = true;
            }
        }
        if (!$changed) {
            return;
        }
        $db = getDB();
        if (empty($current)) {
            $db->exec("DELETE FROM app_settings WHERE key = 'env_overrides'");
        } else {
            $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES ('env_overrides', ?, datetime('now'))
                          ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                ->execute([json_encode($current, JSON_UNESCAPED_UNICODE)]);
        }
        loadEnvOverrides(true);
    } catch (Throwable $e) {
        // best effort
    }
}
