<?php
/**
 * EverShelf — environment variable loader (.env + DB overrides).
 */

/**
 * The .env this installation reads and writes.
 *
 * Default: `<app>/.env`. A container can point it elsewhere with the
 * `EVERSHELF_ENV_FILE` environment variable (on Unraid the whole appdata folder
 * is mounted at /config and the file is /config/.env), which is what makes the
 * configuration survive a container update and stay writable from Settings.
 *
 * The variable is read from the real process environment, never from the file
 * itself: it decides *which* file to read, so it cannot come from that file.
 * The resolved path is cached for the request, because loadEnv() bridges the
 * file into getenv() and could otherwise shadow the override with an empty value.
 */
function evershelfEnvFile(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $override = getenv('EVERSHELF_ENV_FILE');
    if ($override === false || trim((string)$override) === '') {
        $override = $_SERVER['EVERSHELF_ENV_FILE'] ?? ($_ENV['EVERSHELF_ENV_FILE'] ?? '');
    }
    $override = trim((string)$override);
    $root = defined('EVERSHELF_ROOT') ? EVERSHELF_ROOT : dirname(__DIR__, 2);
    $resolved = $override !== '' ? $override : $root . '/.env';
    return $resolved;
}

/**
 * The configured .env, created from `.env.example` when it does not exist yet
 * (an empty /config folder on a fresh container). Best effort: a read-only
 * mount simply keeps the path and the caller reports what it can.
 */
function evershelfEnsureEnvFile(): string
{
    $file = evershelfEnvFile();
    if (is_file($file)) {
        return $file;
    }
    $example = (defined('EVERSHELF_ROOT') ? EVERSHELF_ROOT : dirname(__DIR__, 2)) . '/.env.example';
    if (is_file($example)) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@copy($example, $file)) {
            @chmod($file, 0664);
        }
    }
    return $file;
}

function loadEnv(bool $reload = false): array {
    static $cache = null;
    if (!$reload && $cache !== null) {
        return $cache;
    }
    $envFile = evershelfEnsureEnvFile();
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
    // Bridge .env into the process environment so getenv()/$_ENV agree with env().
    // Several components (logger levels, Gemini cost constants, DISPLAY_ERRORS) read
    // getenv(); without this bridge they silently ignored .env and stayed on their
    // hard-coded defaults. .env is the documented single source of truth, so it wins.
    foreach ($cache as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
    if ($reload) {
        // The file just changed on disk: the database fallbacks were saved against
        // the previous content, so their baselines must be re-evaluated too. Without
        // this a stale fallback survives the reload and keeps shadowing the file.
        loadEnvOverrides(true);
    }
    return $cache;
}

/**
 * The .env file exactly as it is on disk right now, plus its mtime.
 *
 * `loadEnv()` is cached for the request and bridged into getenv(); the override
 * bookkeeping needs the *file* value as it was written, so it reads the file
 * directly instead of trusting that cache.
 *
 * @return array{vars: array<string,string>, mtime: int}
 */
function evershelfEnvFileSnapshot(): array
{
    $file  = evershelfEnsureEnvFile();
    $vars  = [];
    $mtime = 0;
    if (is_file($file)) {
        $mtime = (int)@filemtime($file);
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $vars[trim($key)] = trim($val);
        }
    }
    return ['vars' => $vars, 'mtime' => $mtime];
}

/**
 * Was a database fallback saved against the .env as it is now?
 *
 * Each fallback entry records the .env value it was written against (`null` when
 * the key was absent from the file). When the file no longer matches, the user
 * edited .env by hand — that edit is the newer decision and must win. Without
 * this check the database copy shadowed .env forever (issue #298: `OPENAI_MODEL`
 * changed in .env was ignored after a Settings save had fallback-stored the old
 * value because the file was not writable by www-data).
 */
function evershelfEnvOverrideMatchesFile(?string $baseline, string $key, array $fileVars): bool
{
    $present = array_key_exists($key, $fileVars);
    if ($baseline === null) {
        return !$present;
    }
    return $present && $fileVars[$key] === $baseline;
}

/**
 * The stored override map exactly as persisted: values may be arrays carrying
 * their .env baseline, or legacy bare strings. Writers must start from this map,
 * not from the flattened one loadEnvOverrides() returns, or a prune/save would
 * downgrade every modern entry back to a legacy string.
 *
 * @return array<string,mixed>
 */
function evershelfLoadEnvOverridesRaw(): array
{
    if (!function_exists('getDB')) {
        return [];
    }
    try {
        $row = getDB()->query("SELECT value FROM app_settings WHERE key = 'env_overrides'")->fetchColumn();
        if (!$row) {
            return [];
        }
        $decoded = json_decode((string)$row, true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Persist the override map, or remove the row when nothing is left.
 * One writer for the table, so a prune and a save cannot disagree.
 *
 * @param array<string,mixed> $entries
 */
function evershelfPersistEnvOverrides(array $entries): bool
{
    if (!function_exists('getDB')) {
        return false;
    }
    try {
        $db = getDB();
        if ($entries === []) {
            $db->exec("DELETE FROM app_settings WHERE key = 'env_overrides'");
            return true;
        }
        $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES ('env_overrides', ?, datetime('now'))
                      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
            ->execute([json_encode($entries, JSON_UNESCAPED_UNICODE)]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Settings saved to SQLite because .env was not writable, merged on read by env().
 *
 * New entries carry the .env value they were saved against:
 * `{"KEY":{"value":"…","env":"…"}}`, with `env => null` when the key was absent.
 * An entry whose baseline no longer matches the file is stale — the user changed
 * .env by hand — so the file wins and the stored copy is dropped (issue #298).
 *
 * Legacy entries are bare strings and have no baseline. There the .env mtime is
 * the discriminator: a file modified *after* the fallback row was stored is a
 * newer decision than the row, so the file wins. A read-only .env never moves,
 * which is exactly the install the fallback exists for (issue #261).
 *
 * @return array<string,string>
 */
function loadEnvOverrides(bool $reload = false): array {
    static $cache = null;
    if (!$reload && $cache !== null) {
        return $cache;
    }
    if (!function_exists('getDB')) {
        // bootstrap.php reads DISPLAY_ERRORS through env() *before* api/database.php is
        // loaded, so this branch runs first on every HTTP request. Answer "no overrides"
        // but do NOT cache that answer: getDB() exists a few lines later and the
        // overrides written by the fallback for a read-only .env must win for the rest
        // of the request. Caching the empty answer here silently ignored every setting
        // such an install had saved — it reported success and then showed the old value
        // (issue #261).
        return [];
    }
    if (!defined('DB_PATH')) {
        // `database.php` defines DB_PATH and, to know WHICH file it is, asks whether this
        // request is a demo — which reads `env()` before the constant exists. The
        // overrides live inside that database, so they cannot be answered yet: answer
        // "none" WITHOUT caching, or every setting saved through the read-only-.env
        // fallback is ignored for the whole request (the symptom: the app forgot that
        // the support question had been answered, that custom rooms exist, etc.).
        return [];
    }
    $cache = [];
    try {
        $stmt = getDB()->query("SELECT value, updated_at FROM app_settings WHERE key = 'env_overrides'");
        $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row || empty($row['value'])) {
            return $cache;
        }
        $decoded = json_decode((string)$row['value'], true);
        if (!is_array($decoded)) {
            return $cache;
        }
        $file    = evershelfEnvFileSnapshot();
        // app_settings.updated_at is datetime('now'), i.e. UTC.
        $savedAt = !empty($row['updated_at']) ? (int)strtotime((string)$row['updated_at'] . ' UTC') : 0;
        $stale   = [];
        $kept    = [];
        foreach ($decoded as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (is_array($v)) {
                if (!array_key_exists('value', $v)) {
                    continue;
                }
                $baseline = array_key_exists('env', $v) && is_string($v['env']) ? $v['env'] : null;
                if (!evershelfEnvOverrideMatchesFile($baseline, $k, $file['vars'])) {
                    $stale[] = $k;
                    continue;
                }
                $cache[$k] = (string)$v['value'];
                $kept[$k]  = $v; // keep the baseline when the map is written back
                continue;
            }
            if (is_string($v) || is_numeric($v)) {
                // Legacy bare string: the file's mtime is the only baseline we have.
                if ($file['mtime'] > 0 && $savedAt > 0 && $file['mtime'] > $savedAt
                    && array_key_exists($k, $file['vars'])) {
                    $stale[] = $k;
                    continue;
                }
                $cache[$k] = (string)$v;
                $kept[$k]  = $v;
            }
        }
        if ($stale !== []) {
            evershelfPersistEnvOverrides($kept);
            if (class_exists('EverLog')) {
                // Keys only: these values are secrets often enough (API keys).
                EverLog::info('stale settings override ignored — the .env file was edited by hand', [
                    'event' => 'settings_override_stale',
                    'keys'  => $stale,
                ]);
            }
        }
    } catch (Throwable $e) {
        $cache = []; // DB may be unavailable during early bootstrap
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

/**
 * Clean a value that is about to be written into a .env line.
 * Removes CR/LF/NUL so a value can never inject extra keys or comment out lines.
 */
function evershelfSanitizeEnvValue(string $value): string {
    $value = str_replace(["\r", "\n", "\0"], '', $value);
    return trim($value);
}

/**
 * Merge $updates (ENV_KEY => value) into the .env file **surgically**:
 *   - existing keys are replaced in place (order preserved),
 *   - comments and blank lines are kept verbatim,
 *   - unknown keys are appended at the end,
 *   - values are sanitized against newline injection.
 * When the file is missing it is seeded from .env.example (if present).
 * Returns true on success, false if the file could not be written.
 *
 * @param array<string,string> $updates
 */
function evershelfWriteEnvFile(string $envFile, array $updates, string $templateFile = ''): bool {
    // Drop keys that are not valid env names; sanitize the rest.
    foreach ($updates as $k => $v) {
        if (!preg_match('/^[A-Z0-9_]+$/', (string)$k)) {
            unset($updates[$k]);
            continue;
        }
        $updates[$k] = evershelfSanitizeEnvValue((string)$v);
    }
    if ($updates === []) {
        return true;
    }

    $source = $envFile;
    if (!file_exists($source) && $templateFile !== '' && file_exists($templateFile)) {
        $source = $templateFile; // seed comments/order from the example
    }
    $existing = file_exists($source) ? file($source, FILE_IGNORE_NEW_LINES) : [];

    $seen = [];
    $out  = [];
    foreach ($existing as $line) {
        $trim = ltrim($line);
        if ($trim === '' || $trim[0] === '#' || strpos($trim, '=') === false) {
            $out[] = $line; // blank line or comment — keep verbatim
            continue;
        }
        [$key] = explode('=', $trim, 2);
        $key = trim($key);
        if (array_key_exists($key, $updates)) {
            $out[] = $key . '=' . $updates[$key];
            $seen[$key] = true;
        } else {
            $out[] = $line;
        }
    }
    foreach ($updates as $k => $v) {
        if (!isset($seen[$k])) {
            $out[] = $k . '=' . $v;
        }
    }

    $ok = file_put_contents($envFile, implode("\n", $out) . "\n", LOCK_EX) !== false;
    if ($ok) {
        loadEnv(true); // drop the cached copy so env() reflects the new file this request
    }
    return $ok;
}

/**
 * Persist env overrides when .env is not writable (merged on read via env()).
 *
 * Each value is stored together with the .env value it was saved against, so a
 * later hand edit of the file can invalidate the copy instead of being shadowed
 * by it (issue #298).
 */
function saveEnvOverrides(array $updates): bool {
    if (empty($updates) || !function_exists('getDB')) {
        return false;
    }
    try {
        // Trigger the stale-entry pruning first, then start from the map exactly as
        // stored so the surviving entries keep their .env baseline.
        loadEnvOverrides(true);
        $current = evershelfLoadEnvOverridesRaw();
        $file    = evershelfEnvFileSnapshot();
        foreach ($updates as $key => $val) {
            $key = (string)$key;
            $current[$key] = [
                'value' => (string)$val,
                'env'   => array_key_exists($key, $file['vars']) ? $file['vars'][$key] : null,
            ];
        }
        if (!evershelfPersistEnvOverrides($current)) {
            return false;
        }
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
        // Prune stale entries first, then work on the raw map (see the helper).
        loadEnvOverrides(true);
        $current = evershelfLoadEnvOverridesRaw();
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
        evershelfPersistEnvOverrides($current);
        loadEnvOverrides(true);
    } catch (Throwable $e) {
        // best effort
    }
}
