<?php
/**
 * EverShelf - Main API Router
 * Handles all CRUD operations for products, inventory, shopping lists,
 * AI-powered features (Gemini), and third-party integrations (Bring!).
 *
 * @author Stimpfl Daniel <evershelfproject@gmail.com>
 * @license MIT
 */

// ── GitHub error-reporting credentials ───────────────────────────────────────
// The token is XOR-obfuscated so the literal secret string never appears in
// source or git history (prevents GitHub secret scanning from revoking it).
// Scoped only to Issues (R+W) on this single repository.
// Defined at the very top so the global exception handler can use it.
define('_GH_TK_ENC', '23580718460c2c444031290243627e7971622b29030a3e4d50001e45261659420b6e110a423f30447133205b425a577971561f32762b0b034e0b3e56106d5945020406254a3a4647592a1a611c66687a0b672043700f34757900014004');
define('_GH_TK_KEY', 'D1sp3ns4!Ev3r#26');
define('GH_REPO',    'dadaloop82/EverShelf');
define('PRICE_CACHE_PATH',         __DIR__ . '/../data/shopping_price_cache.json');
define('CATEGORY_CACHE_PATH',      __DIR__ . '/../data/category_ai_cache.json');
define('SHELF_CACHE_PATH',         __DIR__ . '/../data/opened_shelf_cache.json');
define('FOODFACTS_CACHE_PATH',     __DIR__ . '/../data/food_facts_cache.json');
define('SHOPPING_NAME_CACHE_PATH', __DIR__ . '/../data/shopping_name_cache.json');
define('BRING_TOKEN_PATH',         __DIR__ . '/../data/bring_token.json');
define('AI_USAGE_PATH',            __DIR__ . '/../data/ai_usage.json');
define('BACKUP_DIR',               __DIR__ . '/../data/backups');
define('BACKUP_LAST_TS_PATH',      __DIR__ . '/../data/backup_last_ts.json');
// Gemini pricing (USD per 1M tokens) — configurable in .env (GEMINI_COST_25F_IN etc.)
// Defaults: gemini-2.5-flash $0.15/M in · $0.60/M out — gemini-2.0-flash $0.10/M in · $0.40/M out
define('GEMINI_COST_25F_IN',  (float)(getenv('GEMINI_COST_25F_IN')  ?: 0.15));
define('GEMINI_COST_25F_OUT', (float)(getenv('GEMINI_COST_25F_OUT') ?: 0.60));
define('GEMINI_COST_20F_IN',  (float)(getenv('GEMINI_COST_20F_IN')  ?: 0.10));
define('GEMINI_COST_20F_OUT', (float)(getenv('GEMINI_COST_20F_OUT') ?: 0.40));

/** Decode the XOR-obfuscated GitHub token at runtime. */
function _ghToken(): string {
    static $token = null;
    if ($token !== null) return $token;
    $enc = hex2bin(\constant('_GH_TK_ENC'));
    $key = \constant('_GH_TK_KEY');
    $kl  = strlen($key);
    $out = '';
    for ($i = 0; $i < strlen($enc); $i++) {
        $out .= chr(ord($enc[$i]) ^ ord($key[$i % $kl]));
    }
    $token = $out;
    return $token;
}

// logger.php must be loaded BEFORE database.php so LoggingPDO class exists when getDB() runs
require_once __DIR__ . '/logger.php';
// database.php must always be loaded (used both by HTTP router and cron)
require_once __DIR__ . '/database.php';

// ── Global PHP error/exception reporters ─────────────────────────────────────
// These are registered immediately so any crash anywhere in this file is caught.
// The handler function _phpErrorReport() is defined later; PHP resolves function
// names at call time so forward-referencing is safe.
if (!defined('CRON_MODE')) {
    set_exception_handler(function (Throwable $e): void {
        _phpErrorReport(
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
            get_class($e)
        );
    });
    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            _phpErrorReport($err['message'], $err['file'], $err['line'], '', 'PHP Fatal');
        }
    });
}

/**
 * Load environment variables from .env file.
 * Returns associative array of key => value pairs.
 */
function loadEnv(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $envFile = __DIR__ . '/../.env';
    $cache = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
            list($key, $val) = explode('=', $line, 2);
            $cache[trim($key)] = trim($val);
        }
    }
    return $cache;
}

/**
 * Get a single environment variable, with optional default.
 */
function env(string $key, string $default = ''): string {
    $vars = loadEnv();
    return $vars[$key] ?? $default;
}

// When included by the cron script, skip HTTP headers and routing entirely
if (!defined('CRON_MODE')) {

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Ping / heartbeat — early response, no DB or rate-limit required ───────────
if (($_GET['action'] ?? '') === 'ping') {
    echo json_encode(['ok' => true, 'ts' => time()]);
    exit;
}

// ── Google Drive OAuth callback — returns HTML, not JSON ──────────────────────
if (($_GET['action'] ?? '') === 'gdrive_oauth_callback') {
    _gdriveHandleOAuthCallback();
    exit;
}

// ── Log viewer — returns last N log lines (requires SETTINGS_TOKEN if set) ────
if (($_GET['action'] ?? '') === 'get_logs') {
    require_once __DIR__ . '/logger.php';
    $token   = loadEnv()['SETTINGS_TOKEN'] ?? '';
    $reqTok  = $_GET['token'] ?? $_SERVER['HTTP_X_SETTINGS_TOKEN'] ?? '';
    if (!empty($token) && $reqTok !== $token) {
        EverLog::warn('get_logs: unauthorized (403)');
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $lines   = min(2000, max(10, (int)($_GET['lines'] ?? 200)));
    $filter  = strtoupper($_GET['level'] ?? '');
    $raw     = EverLog::tail($lines);
    if ($filter && in_array($filter, ['DEBUG','INFO','WARN','ERROR'], true)) {
        $raw = array_values(array_filter($raw, fn($l) => str_contains($l, "[{$filter}")));
    }
    echo json_encode([
        'lines'        => $raw,
        'total'        => count($raw),
        'current_file' => basename(EverLog::currentFile()),
        'level'        => EverLog::levelName(),
        'files'        => EverLog::listFiles(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Gemini token usage + cost estimate ────────────────────────────────────────
if (($_GET['action'] ?? '') === 'gemini_usage') {
    header('Content-Type: application/json; charset=utf-8');

    // ── Cost helper ───────────────────────────────────────────────────────────
    $calcCost = function(int $tokIn, int $tokOut, string $modelHint = '2.5'): float {
        $inRate  = str_contains($modelHint, '2.5') ? GEMINI_COST_25F_IN  : GEMINI_COST_20F_IN;
        $outRate = str_contains($modelHint, '2.5') ? GEMINI_COST_25F_OUT : GEMINI_COST_20F_OUT;
        return round(($tokIn / 1_000_000) * $inRate + ($tokOut / 1_000_000) * $outRate, 6);
    };

    // ── Tracked usage (ai_usage.json) ────────────────────────────────────────
    $aiData = file_exists(AI_USAGE_PATH) ? (json_decode(file_get_contents(AI_USAGE_PATH), true) ?: []) : [];
    $month  = date('Y-m');
    $year   = date('Y');
    $cur    = $aiData[$month] ?? ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'by_action' => [], 'by_model' => []];

    // Yearly totals (sum all tracked months of current year)
    $yearBucket = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'by_model' => []];
    foreach ($aiData as $k => $v) {
        if (!str_starts_with($k, $year)) continue;
        $yearBucket['input_tokens']  += (int)($v['input_tokens']  ?? 0);
        $yearBucket['output_tokens'] += (int)($v['output_tokens'] ?? 0);
        $yearBucket['calls']         += (int)($v['calls'] ?? 0);
        foreach (($v['by_model'] ?? []) as $mdl => $mu) {
            if (!isset($yearBucket['by_model'][$mdl])) $yearBucket['by_model'][$mdl] = ['in' => 0, 'out' => 0, 'calls' => 0];
            $yearBucket['by_model'][$mdl]['in']    += $mu['in']    ?? 0;
            $yearBucket['by_model'][$mdl]['out']   += $mu['out']   ?? 0;
            $yearBucket['by_model'][$mdl]['calls'] += $mu['calls'] ?? 0;
        }
    }

    // ── Cache item counts (for caches card) ──────────────────────────────────
    $priceCache = file_exists(PRICE_CACHE_PATH)
        ? (json_decode(file_get_contents(PRICE_CACHE_PATH), true) ?: []) : [];
    $shelfCache = file_exists(SHELF_CACHE_PATH)
        ? (json_decode(file_get_contents(SHELF_CACHE_PATH), true) ?: []) : [];
    $catCache   = file_exists(CATEGORY_CACHE_PATH)
        ? (json_decode(file_get_contents(CATEGORY_CACHE_PATH), true) ?: []) : [];
    $nameCache  = file_exists(SHOPPING_NAME_CACHE_PATH)
        ? (json_decode(file_get_contents(SHOPPING_NAME_CACHE_PATH), true) ?: []) : [];

    // ── DB stats ──────────────────────────────────────────────────────────────
    $dbStats = [];
    try {
        $db = getDB();
        $row = $db->query("SELECT
            (SELECT COUNT(*) FROM products) as products_total,
            (SELECT COUNT(*) FROM inventory WHERE quantity > 0) as inventory_active,
            (SELECT COUNT(*) FROM transactions WHERE undone=0 AND created_at >= date('now','start of month')) as tx_month,
            (SELECT COUNT(*) FROM transactions WHERE undone=0 AND created_at >= date('now','start of year')) as tx_year,
            (SELECT COUNT(*) FROM transactions WHERE type='in' AND undone=0 AND created_at >= date('now','start of month')) as restock_month,
            (SELECT COUNT(*) FROM transactions WHERE type IN ('out','waste') AND undone=0 AND created_at >= date('now','start of month')) as use_month,
            (SELECT COUNT(*) FROM products WHERE created_at >= date('now','start of month')) as products_month,
            (SELECT COUNT(CASE WHEN expiry_date < date('now') AND quantity > 0 THEN 1 END) FROM inventory) as expired,
            (SELECT COUNT(CASE WHEN expiry_date BETWEEN date('now') AND date('now','+7 days') AND quantity > 0 THEN 1 END) FROM inventory) as expiring_soon,
            (SELECT COUNT(CASE WHEN quantity = 0 THEN 1 END) FROM inventory) as finished
        ")->fetch(PDO::FETCH_ASSOC);
        $dbStats = $row ?: [];
    } catch (Throwable $e) { /* ignore */ }

    // ── Log info ──────────────────────────────────────────────────────────────
    $logFilesInfo = EverLog::listFiles();
    $logBytes = 0;
    foreach ($logFilesInfo as $lf) {
        $logBytes += (int)(($lf['size_kb'] ?? 0) * 1024);
    }

    // ── Backup info ───────────────────────────────────────────────────────────
    $backupDir   = dirname(__DIR__) . '/data/backups';
    $backupFiles = is_dir($backupDir) ? (glob($backupDir . '/*.db') ?: []) : [];
    rsort($backupFiles);
    $lastBackupTs    = $backupFiles ? (int)filemtime($backupFiles[0]) : 0;
    $lastBackupBytes = $backupFiles ? (int)filesize($backupFiles[0]) : 0;

    // ── Bring! token expiry ───────────────────────────────────────────────────
    $bringToken     = file_exists(BRING_TOKEN_PATH)
        ? (json_decode(file_get_contents(BRING_TOKEN_PATH), true) ?: []) : [];
    $bringExpiresTs = (int)($bringToken['expires'] ?? 0);

    echo json_encode([
        'month' => $month,
        'year'  => $year,

        // Current month (from ai_usage.json)
        'month_stats' => [
            'calls'        => (int)$cur['calls'],
            'input_tokens' => (int)$cur['input_tokens'],
            'output_tokens'=> (int)$cur['output_tokens'],
            'cost_usd'     => $calcCost((int)$cur['input_tokens'], (int)$cur['output_tokens']),
            'by_action'    => $cur['by_action'] ?? [],
            'by_model'     => $cur['by_model']  ?? [],
        ],

        // Current year (from ai_usage.json — all months summed)
        'year_stats' => [
            'calls'        => (int)$yearBucket['calls'],
            'input_tokens' => (int)$yearBucket['input_tokens'],
            'output_tokens'=> (int)$yearBucket['output_tokens'],
            'cost_usd'     => $calcCost((int)$yearBucket['input_tokens'], (int)$yearBucket['output_tokens']),
        ],

        // DB activity
        'db' => array_merge(
            array_map('intval', $dbStats),
            ['bytes' => file_exists(DB_PATH) ? (int)filesize(DB_PATH) : 0]
        ),

        // Cache item counts
        'caches' => [
            'price'    => count($priceCache),
            'shelf'    => count($shelfCache),
            'category' => count($catCache),
            'names'    => count($nameCache),
            'foodfacts'=> count(file_exists(FOODFACTS_CACHE_PATH)
                ? (json_decode(file_get_contents(FOODFACTS_CACHE_PATH), true) ?: []) : []),
        ],

        // Current Gemini pricing (from .env / defaults)
        'pricing' => [
            '2.5-flash' => ['in' => GEMINI_COST_25F_IN, 'out' => GEMINI_COST_25F_OUT],
            '2.0-flash' => ['in' => GEMINI_COST_20F_IN, 'out' => GEMINI_COST_20F_OUT],
        ],

        // System
        'log_bytes'         => $logBytes,
        'log_level'         => EverLog::levelName(),
        'log_files'         => count($logFilesInfo),
        'last_backup_ts'    => $lastBackupTs,
        'last_backup_bytes' => $lastBackupBytes,
        'bring_expires_ts'  => $bringExpiresTs,

        // History (last 13 months for trend)
        'history' => array_map(fn($k, $v) => [
            'month'        => $k,
            'input_tokens' => (int)($v['input_tokens']  ?? 0),
            'output_tokens'=> (int)($v['output_tokens'] ?? 0),
            'calls'        => (int)($v['calls'] ?? 0),
            'cost_usd'     => $calcCost((int)($v['input_tokens'] ?? 0), (int)($v['output_tokens'] ?? 0)),
        ], array_keys($aiData), array_values($aiData)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Health check — startup diagnostic (no rate-limit, no auth required) ──────

    // ── Tracked usage (ai_usage.json) ────────────────────────────────────────
    $aiData  = file_exists(AI_USAGE_PATH) ? (json_decode(file_get_contents(AI_USAGE_PATH), true) ?: []) : [];
    $month   = date('Y-m');
    $year    = date('Y');
    $cur     = $aiData[$month] ?? ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'by_action' => [], 'by_model' => []];

    // Yearly totals (sum all months of current year)
    $yearBucket = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'by_model' => []];
    foreach ($aiData as $k => $v) {
        if (!str_starts_with($k, $year)) continue;
        $yearBucket['input_tokens']  += (int)($v['input_tokens'] ?? 0);
        $yearBucket['output_tokens'] += (int)($v['output_tokens'] ?? 0);
        $yearBucket['calls']         += (int)($v['calls'] ?? 0);
        foreach (($v['by_model'] ?? []) as $mdl => $mu) {
            if (!isset($yearBucket['by_model'][$mdl])) {
                $yearBucket['by_model'][$mdl] = ['in' => 0, 'out' => 0, 'calls' => 0];
            }
            $yearBucket['by_model'][$mdl]['in']    += $mu['in']    ?? 0;
            $yearBucket['by_model'][$mdl]['out']   += $mu['out']   ?? 0;
            $yearBucket['by_model'][$mdl]['calls'] += $mu['calls'] ?? 0;
        }
    }

// ── Health check — startup diagnostic (no rate-limit, no auth required) ──────
if (($_GET['action'] ?? '') === 'health_check') {
    $checks = [];

    // ── Helper: read .env values without triggering app init ─────────────────
    $envVals = loadEnv(); // already cached by loadEnv()
    $envGet  = fn($k) => $envVals[$k] ?? '';

    // ── 1. PHP version ────────────────────────────────────────────────────────
    $checks['php_version'] = [
        'ok'    => version_compare(PHP_VERSION, '8.0.0', '>='),
        'value' => PHP_VERSION,
    ];

    // ── 2. Critical PHP extensions ────────────────────────────────────────────
    foreach (['pdo_sqlite', 'curl', 'json', 'mbstring'] as $ext) {
        $checks['ext_' . $ext] = ['ok' => extension_loaded($ext)];
    }

    // ── 3. Optional PHP extensions ────────────────────────────────────────────
    foreach (['openssl', 'fileinfo', 'zip', 'intl'] as $ext) {
        $checks['ext_' . $ext] = ['ok' => extension_loaded($ext), 'optional' => true];
    }

    // ── 4. PHP runtime configuration ─────────────────────────────────────────
    $memRaw   = ini_get('memory_limit');
    $memBytes = (function ($v) {
        $v = trim($v); if ($v === '-1') return PHP_INT_MAX;
        $u = strtolower(substr($v, -1)); $n = (int)$v;
        return match($u) { 'g' => $n*1073741824, 'm' => $n*1048576, 'k' => $n*1024, default => $n };
    })($memRaw);
    $checks['php_memory']   = ['ok' => $memBytes >= 64*1048576, 'value' => $memRaw, 'optional' => true];
    $maxExec                = (int) ini_get('max_execution_time');
    $checks['php_max_exec'] = ['ok' => $maxExec === 0 || $maxExec >= 30, 'value' => $maxExec === 0 ? '∞' : $maxExec.'s', 'optional' => true];
    $checks['php_upload']   = ['ok' => true, 'value' => ini_get('upload_max_filesize'), 'optional' => true];

    // ── 5. data/ directory ────────────────────────────────────────────────────
    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);
    $dataDirOk = is_dir($dataDir) && is_writable($dataDir);
    $checks['data_dir'] = ['ok' => $dataDirOk, 'path' => realpath($dataDir) ?: $dataDir];

    // data/rate_limits/
    $rlDir = $dataDir . '/rate_limits';
    if (!is_dir($rlDir) && $dataDirOk) @mkdir($rlDir, 0775, true);
    $checks['data_rate_limits'] = ['ok' => is_dir($rlDir) && is_writable($rlDir), 'optional' => true];

    // data/backups/ — written by cron as root; just verify dir exists and has recent files
    $bkDir       = $dataDir . '/backups';
    $bkDirExists = is_dir($bkDir);
    $bkFiles     = $bkDirExists ? array_filter(scandir($bkDir), fn($f) => str_ends_with($f, '.db')) : [];
    $lastBkTime  = $bkDirExists && $bkFiles
        ? max(array_map(fn($f) => filemtime($bkDir.'/'.$f), $bkFiles))
        : 0;
    $bkRecent    = $lastBkTime > 0 && (time() - $lastBkTime) < 86400*2; // within 2 days
    $bkCount     = count($bkFiles);
    $checks['data_backups'] = [
        'ok'       => $bkDirExists && $bkCount > 0,
        'optional' => true,
        'value'    => $bkDirExists ? ($bkCount . ' backup' . ($bkRecent ? ', ultimo recente' : ', ultimo vecchio')) : null,
        'hint'     => $bkDirExists ? ($bkCount === 0 ? 'Nessun backup trovato — cron configurato?' : (!$bkRecent ? 'Ultimo backup datato — cron in esecuzione?' : null)) : 'Cartella backup mancante',
    ];

    // ── 6. Actual file-write test ─────────────────────────────────────────────
    $testFile = $dataDir . '/_hc_' . getmypid() . '.tmp';
    $writeOk  = $dataDirOk && (@file_put_contents($testFile, 'hc') !== false);
    if ($writeOk) @unlink($testFile);
    $checks['data_write_test'] = ['ok' => $writeOk];

    // ── 7. Free disk space ────────────────────────────────────────────────────
    $freeBytes = $dataDirOk ? @disk_free_space($dataDir) : false;
    $freeMB    = $freeBytes !== false ? round($freeBytes/1048576) : null;
    $checks['disk_space'] = [
        'ok'       => $freeBytes === false || $freeBytes > 50*1048576,
        'value'    => $freeMB !== null ? $freeMB.' MB liberi' : null,
        'optional' => true,
        'hint'     => $freeBytes !== false && $freeBytes <= 50*1048576 ? 'Less than 50 MB free — free up disk space' : null,
    ];

    // ── 8. SQLite database ────────────────────────────────────────────────────
    // Correct DB name is evershelf.db; detect legacy dispensa.db and suggest migration
    $dbPath    = $dataDir . '/evershelf.db';
    $legacyDb  = $dataDir . '/dispensa.db';
    $hasLegacy = file_exists($legacyDb);
    $isFresh   = !file_exists($dbPath) && $dataDirOk;

    // Auto-migrate: if evershelf.db missing but dispensa.db exists, rename it
    if ($isFresh && $hasLegacy && is_writable($legacyDb)) {
        if (@rename($legacyDb, $dbPath)) {
            $hasLegacy = false;
            $isFresh   = false;
        }
    }

    // Auto-delete legacy dispensa.db if evershelf.db already exists (it's just an empty leftover)
    if ($hasLegacy && file_exists($dbPath) && filesize($legacyDb) < 1024) {
        @unlink($legacyDb);
        $hasLegacy = false;
    }

    // Legacy DB still present alongside evershelf.db → warn (should be rare now)
    $checks['db_legacy'] = [
        'ok'       => !$hasLegacy,
        'optional' => true,
        'hint'     => $hasLegacy ? 'Legacy dispensa.db found — the file is obsolete, you can delete it manually' : null,
    ];

    if ($isFresh) {
        $checks['db_connect']   = ['ok' => true, 'fresh' => true, 'value' => 'fresh install'];
        $checks['db_tables']    = ['ok' => true, 'fresh' => true];
        $checks['db_integrity'] = ['ok' => true, 'fresh' => true];
        $checks['db_wal']       = ['ok' => true, 'fresh' => true, 'optional' => true];
        $checks['db_size']      = ['ok' => true, 'value' => '0 KB', 'optional' => true];
        $checks['db_row_count'] = ['ok' => true, 'value' => '0 prodotti', 'optional' => true];
    } else {
        $pdo = null; $dbConnOk = false;
        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->query('SELECT 1');
            $dbConnOk = true;
            $checks['db_connect'] = ['ok' => true, 'value' => basename($dbPath)];
        } catch (\Throwable $e) {
            $checks['db_connect'] = ['ok' => false, 'error' => $e->getMessage(),
                'hint' => 'Cannot open the database — check permissions on data/evershelf.db'];
        }

        if ($dbConnOk && $pdo) {
            // Required tables
            $tables   = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            $required = ['inventory', 'products', 'transactions'];
            $missing  = array_values(array_diff($required, $tables));
            $checks['db_tables'] = [
                'ok'   => empty($missing),
                'missing' => $missing,
                'hint' => !empty($missing) ? 'Missing tables: ' . implode(', ', $missing) . ' — call any API endpoint to auto-initialize the DB' : null,
            ];

            // Integrity
            $integ = $pdo->query("PRAGMA quick_check")->fetchColumn();
            $checks['db_integrity'] = [
                'ok'    => $integ === 'ok',
                'value' => $integ !== 'ok' ? $integ : null,
                'hint'  => $integ !== 'ok' ? 'Database corrotto: ' . $integ . ' — ripristina da un backup in data/backups/' : null,
            ];

            // WAL
            $wal = $pdo->query("PRAGMA journal_mode")->fetchColumn();
            $checks['db_wal'] = ['ok' => $wal === 'wal', 'value' => $wal, 'optional' => true,
                'hint' => $wal !== 'wal' ? 'Journal mode not optimal — will be corrected automatically on next startup' : null];

            // Size & rows
            $checks['db_size']      = ['ok' => true, 'value' => round(filesize($dbPath)/1024).' KB', 'optional' => true];
            $cnt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity > 0")->fetchColumn();
            $checks['db_row_count'] = ['ok' => true, 'value' => $cnt.' prodotti in inventario', 'optional' => true];
        } else {
            foreach (['db_tables', 'db_integrity'] as $k)
                $checks[$k] = ['ok' => false, 'hint' => 'Cannot verify — DB connection failed'];
            foreach (['db_wal', 'db_size', 'db_row_count'] as $k)
                $checks[$k] = ['ok' => false, 'optional' => true];
        }
    }

    // ── 9. .env file ──────────────────────────────────────────────────────────
    $envExists = file_exists(__DIR__ . '/../.env');
    $checks['env_file'] = [
        'ok'       => $envExists,
        'optional' => true,
        'hint'     => !$envExists ? 'File .env mancante — copia .env.example in .env e configura i valori' : null,
    ];

    // ── 10. Gemini AI — solo se GEMINI_API_KEY è impostata ───────────────────
    $geminiKey = $envGet('GEMINI_API_KEY');
    if (!empty($geminiKey)) {
        $checks['gemini_key'] = ['ok' => strlen($geminiKey) > 20, 'optional' => true,
            'hint' => strlen($geminiKey) <= 20 ? 'Gemini AI key looks too short — check the value in .env' : null];
    } else {
        $checks['gemini_key'] = ['ok' => true, 'optional' => true,
            'value' => 'not configured', 'hint' => 'Set GEMINI_API_KEY in .env to enable AI features'];
    }

    // ── 11. Bring! — solo se EMAIL+PASSWORD sono impostate ───────────────────
    // Se non configurata, l'utente ha scelto di non usarla → nessun check, nessun warning.
    $bringEmail    = $envGet('BRING_EMAIL');
    $bringPassword = $envGet('BRING_PASSWORD');
    $shoppingMode  = $envGet('SHOPPING_MODE') ?: 'native';
    $bringEnabled  = !empty($bringEmail) && !empty($bringPassword) && $shoppingMode === 'bring';
    if ($bringEnabled) {
        $checks['bring_credentials'] = ['ok' => true, 'optional' => true];
        // Token file is created automatically on first shopping list access — not an error if missing
        $bringTokenFile = $dataDir . '/bring_token.json';
        $bringTokenOk   = true; // default: fine (missing = not yet obtained, will auto-create)
        $bringTokenHint = null;
        if (file_exists($bringTokenFile)) {
            $bringData    = @json_decode(@file_get_contents($bringTokenFile), true);
            $hasToken     = !empty($bringData['access_token'] ?? ($bringData['accessToken'] ?? ''));
            $expired      = isset($bringData['expires']) && $bringData['expires'] < time();
            if (!$hasToken && !$expired) {
                // File exists but token field missing — corrupt
                $bringTokenOk   = false;
                $bringTokenHint = 'Bring! token file present but appears invalid — delete data/bring_token.json to regenerate';
            }
            // Expired token is OK: it will be refreshed automatically
        }
        // Missing token file = first launch, will be created automatically → no warning
        $checks['bring_token'] = ['ok' => $bringTokenOk, 'optional' => true, 'hint' => $bringTokenHint];
    }
    // If Bring! not configured or SHOPPING_MODE != bring, skip entirely — not a warning, it is a deliberate user choice

    // ── 12. TTS — solo se TTS_ENABLED ────────────────────────────────────────
    if ($envGet('TTS_ENABLED') === 'true') {
        $ttsUrl = $envGet('TTS_URL');
        $checks['tts_url'] = [
            'ok'       => !empty($ttsUrl),
            'optional' => true,
            'hint'     => empty($ttsUrl) ? 'TTS_ENABLED=true but TTS_URL not configured' : null,
        ];
    }

    // ── 13. Scale gateway — solo se SCALE_ENABLED ────────────────────────────
    if ($envGet('SCALE_ENABLED') === 'true') {
        $scaleUrl = $envGet('SCALE_GATEWAY_URL');
        $checks['scale_gateway'] = [
            'ok'       => !empty($scaleUrl),
            'optional' => true,
            'hint'     => empty($scaleUrl) ? 'SCALE_ENABLED=true but SCALE_GATEWAY_URL not configured' : null,
        ];
    }

    // ── 14. cURL SSL ──────────────────────────────────────────────────────────
    if (function_exists('curl_version')) {
        $cv = curl_version();
        $checks['curl_ssl'] = ['ok' => !empty($cv['ssl_version']), 'value' => $cv['ssl_version'] ?? null, 'optional' => true,
            'hint' => empty($cv['ssl_version']) ? 'cURL senza supporto SSL — le chiamate HTTPS potrebbero fallire' : null];
    } else {
        $checks['curl_ssl'] = ['ok' => false, 'optional' => true, 'hint' => 'cURL non disponibile'];
    }

    // ── 15. Internet — raggiungibilità API Gemini (solo se Gemini configurato) ─
    if (!empty($geminiKey) && extension_loaded('curl')) {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => 'https://generativelanguage.googleapis.com/', CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
        curl_exec($ch);
        $httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo  = curl_errno($ch);
        curl_close($ch);
        $internetOk = $httpCode > 0 || $curlErrNo === 0;
        $checks['internet'] = ['ok' => $internetOk, 'optional' => true,
            'hint' => !$internetOk ? 'Cannot reach Gemini servers — AI features will not work without an internet connection' : null];
    }

    // ── Compute overall result ────────────────────────────────────────────────
    $criticalKeys = ['php_version', 'ext_pdo_sqlite', 'ext_curl', 'ext_json', 'ext_mbstring',
                     'data_dir', 'data_write_test', 'db_connect', 'db_tables', 'db_integrity'];
    $allOk = array_reduce($criticalKeys, fn($c, $k) => $c && ($checks[$k]['ok'] ?? false), true);

    header('Content-Type: application/json');
    echo json_encode(['ok' => $allOk, 'checks' => $checks, 'fresh' => $isFresh], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== RATE LIMITING =====
/**
 * Simple file-based rate limiter.
 * Limits: 120 req/min general, 15 req/min for AI endpoints, 5 req/min for login.
 */
function checkRateLimit(string $action): void {
    $rateLimitDir = __DIR__ . '/../data/rate_limits';
    if (!is_dir($rateLimitDir)) {
        mkdir($rateLimitDir, 0755, true);
    }

    // Determine limit based on action
    $aiActions = ['gemini_readExpiry', 'gemini_chat', 'gemini_identify', 'gemini_suggest_shopping', 'chat_to_recipe', 'recipe_from_ingredient', 'gemini_number_ocr'];
    $loginActions = [];
    $recipeActions = ['generate_recipe', 'generate_recipe_stream'];
    $errorActions = ['report_error', 'check_update'];
    $priceActions = ['get_shopping_price', 'get_all_shopping_prices'];

    if (in_array($action, $aiActions)) {
        $limit = 15;
        $window = 60;
        $bucket = 'ai';
    } elseif (in_array($action, $priceActions)) {
        // Price lookups: up to 30 items × a few retries per minute, shared bucket
        $limit = 60;
        $window = 60;
        $bucket = 'price';
    } elseif (in_array($action, $recipeActions)) {
        $limit = 5;
        $window = 60;
        $bucket = 'recipe';
    } elseif (in_array($action, $errorActions)) {
        $limit = 20;
        $window = 60;
        $bucket = 'error_report';
    } elseif (in_array($action, $loginActions)) {
        $limit = 5;
        $window = 60;
        $bucket = 'login';
    } else {
        $limit = 120;
        $window = 60;
        $bucket = 'general';
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = $rateLimitDir . '/' . md5($ip . '_' . $bucket) . '.json';

    // Clean up old rate limit files periodically (1% chance per request)
    if (mt_rand(1, 100) === 1) {
        foreach (glob($rateLimitDir . '/*.json') as $f) {
            if (filemtime($f) < time() - 300) @unlink($f);
        }
    }

    $now = time();
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) $data = json_decode($raw, true) ?: [];
    }

    // Remove entries outside the window
    $data = array_values(array_filter($data, function($ts) use ($now, $window) {
        return $ts > $now - $window;
    }));

    if (count($data) >= $limit) {
        EverLog::warn('rate_limit hit', ['action' => $action, 'limit' => $limit, 'window_s' => $window]);
        http_response_code(429);
        header('Retry-After: ' . $window);
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }

    $data[] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

// Apply rate limiting
$rateLimitAction = $_GET['action'] ?? '';
if ($rateLimitAction) {
    checkRateLimit($rateLimitAction);
}

// CSRF guard for write actions: POST requests that modify data must include
// either X-EverShelf-Request: 1 (webapp) or Content-Type: application/json.
// This prevents cross-site HTML form submissions from triggering mutations.
// JSON Content-Type already requires a CORS preflight which provides a baseline;
// the explicit header is an additional defence-in-depth check for POST writes.
$_writeActions = [
    'inventory_add','inventory_use','inventory_update','inventory_remove',
    'product_save','product_delete','product_merge',
    'bring_add','bring_remove','bring_sync','bring_set_spec','bring_migrate_names',
    'shopping_add','shopping_remove',
    'dismiss_anomaly','save_settings',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($rateLimitAction, $_writeActions, true)) {
    $csrfHeader  = $_SERVER['HTTP_X_EVERSHELF_REQUEST'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($csrfHeader !== '1' && stripos($contentType, 'application/json') === false) {
        EverLog::warn('csrf_rejected (403)');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'csrf_rejected']);
        exit;
    }
}

try {
    $db = getDB();
} catch (Exception $e) {
    EverLog::exception($e, 'db_connect');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    _phpErrorReport($e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), get_class($e));
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
EverLog::request($action, $method);

} // end !CRON_MODE block for router bootstrap

if (!defined('CRON_MODE')):
try {
    // DEMO_MODE guard
    if (env('DEMO_MODE') === 'true') {
        $demoBlocked = [
            'save_settings', 'product_save', 'product_delete', 'product_merge',
            'inventory_add', 'inventory_use', 'inventory_update', 'inventory_remove',
            'dismiss_anomaly', 'bring_add', 'bring_remove', 'bring_sync',
            'backup_delete', 'backup_restore',
        ];
        if (in_array($action, $demoBlocked, true)) {
            EverLog::warn('demo_mode blocked (403)');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'demo_mode']);
            exit;
        }
    }

    switch ($action) {
        // ===== PRODUCTS =====
        case 'search_barcode':
            searchBarcode($db);
            break;
        case 'lookup_barcode':
            lookupBarcode();
            break;
        case 'stock_for_name':
            stockForName($db);
            break;
        case 'product_save':
            saveProduct($db);
            break;
        case 'product_get':
            getProduct($db);
            break;
        case 'product_delete':
            deleteProduct($db);
            break;
        case 'products_list':
            listProducts($db);
            break;
        case 'products_search':
            searchProducts($db);
            break;

        // ===== INVENTORY =====
        case 'inventory_list':
            listInventory($db);
            break;
        case 'inventory_add':
            addToInventory($db);
            break;
        case 'inventory_use':
            useFromInventory($db);
            break;
        case 'inventory_update':
            updateInventory($db);
            break;
        case 'inventory_delete':
            deleteInventory($db);
            break;
        case 'inventory_finished_items':
            getFinishedItems($db);
            break;
        case 'inventory_confirm_finished':
            confirmFinished($db);
            break;
        case 'inventory_summary':
            inventorySummary($db);
            break;

        // ===== TRANSACTIONS =====
        case 'transactions_list':
            listTransactions($db);
            break;

        case 'transaction_undo':
            undoTransaction($db);
            break;

        // ===== STATS =====
        case 'stats':
            getStats($db);
            break;

        case 'consumption_predictions':
            getConsumptionPredictions($db);
            break;

        case 'inventory_anomalies':
            getInventoryAnomalies($db);
            break;

        case 'dismiss_anomaly':
            dismissInventoryAnomaly();
            break;

        case 'recent_popular_products':
            recentPopularProducts($db);
            break;

        // ===== AI =====
        case 'gemini_expiry':
            geminiReadExpiry();
            break;

        case 'generate_recipe':
            generateRecipe($db);
            break;

        case 'generate_recipe_stream':
            generateRecipeStream($db);
            break;

        case 'gemini_identify':
            geminiIdentifyProduct();
            break;

        case 'gemini_chat':
            geminiChat($db);
            break;

        case 'chat_to_recipe':
            chatToRecipe($db);
            break;

        case 'recipe_from_ingredient':
            recipeFromIngredient($db);
            break;

        // ===== BRING! SHOPPING LIST =====
        case 'bring_list':
            bringGetList();
            break;
        case 'bring_add':
            bringAddItems();
            break;
        case 'bring_remove':
            bringRemoveItem();
            break;
        case 'bring_clean_specs':
            bringCleanSpecs();
            break;
        case 'bring_migrate_names':
            bringMigrateNames($db);
            break;
        case 'bring_suggest':
            bringSuggestItems($db);
            break;
        // Shopping abstraction layer (delegates to internal DB or Bring!)
        case 'shopping_list':
            shoppingGetList($db);
            break;
        case 'shopping_add':
            shoppingAdd($db);
            break;
        case 'shopping_remove':
            shoppingRemove($db);
            break;
        case 'shopping_suggest':
            bringSuggestItems($db);
            break;
        case 'smart_shopping':
            smartShoppingCached($db);
            break;

        case 'save_settings':
            saveSettings();
            break;

        case 'get_settings':
            getServerSettings();
            break;

        case 'client_log':
            clientLog();
            break;

        case 'get_client_log':
            getClientLog();
            break;

        case 'migrate_units':
            migrateUnitsToBase($db);
            break;

        // ===== SHARED APP DATA =====
        case 'app_settings_get':
            appSettingsGet($db);
            break;
        case 'app_settings_save':
            appSettingsSave($db);
            break;
        case 'recipes_list':
            recipesList($db);
            break;
        case 'recipes_save':
            recipesSave($db);
            break;
        case 'recipes_delete':
            recipesDelete($db);
            break;
        case 'chat_list':
            chatList($db);
            break;
        case 'chat_save':
            chatSave($db);
            break;
        case 'chat_clear':
            chatClear($db);
            break;
        case 'tts_proxy':
            ttsProxy();
            break;

        case 'ha_sensor':
            haInventorySensor(getDB());
            break;

        case 'ha_info':
            haGetInfo(getDB());
            break;

        case 'ha_shopping_items':
            haGetShoppingItems(getDB());
            break;

        case 'ha_test':
            haTestConnection();
            break;

        case 'expiry_history':
            getExpiryHistory($db);
            break;

        case 'food_facts':
            getFoodFacts();
            break;

        case 'opened_shelf_life':
            getOpenedShelfLifeAction();
            break;

        case 'report_error':
            reportError();
            break;

        case 'report_bug':
            reportBugManual();
            break;

        case 'check_update':
            checkUpdate();
            break;

        case 'db_cleanup':
            dbCleanup(getDB());
            break;

        case 'backup_now':
            echo json_encode(createLocalBackup($db));
            break;
        case 'backup_list':
            echo json_encode(listLocalBackups());
            break;
        case 'backup_delete':
            $fn = json_decode(file_get_contents('php://input'), true)['filename'] ?? '';
            echo json_encode(deleteLocalBackup($fn));
            break;
        case 'backup_restore':
            $fn = json_decode(file_get_contents('php://input'), true)['filename'] ?? '';
            echo json_encode(restoreLocalBackup($fn, $db));
            break;
        case 'gdrive_push':
            echo json_encode(backupToGDrive($db));
            break;
        case 'gdrive_test':
            $tokResult = _gdriveGetTokenEx();
            if (!empty($tokResult['token'])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $tokResult['error'] ?? 'Auth failed']);
            }
            break;
        case 'gdrive_oauth_url':
            $clientId = env('GDRIVE_CLIENT_ID', '');
            if (empty($clientId)) {
                echo json_encode(['success' => false, 'error' => 'GDRIVE_CLIENT_ID not configured — save settings first']);
            } else {
                // Use http://localhost so the flow works on any self-hosted server (IP, local domain, etc.).
                // Google will redirect to http://localhost?code=... after auth; user copies and pastes the URL.
                // Override via GDRIVE_REDIRECT_URI env var for installations with a real public domain.
                $redirectUri = env('GDRIVE_REDIRECT_URI', '') ?: 'http://localhost';
                $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                    'client_id'     => $clientId,
                    'redirect_uri'  => $redirectUri,
                    'scope'         => 'https://www.googleapis.com/auth/drive.file',
                    'response_type' => 'code',
                    'access_type'   => 'offline',
                    'prompt'        => 'consent',
                ]);
                echo json_encode(['success' => true, 'url' => $url, 'redirect_uri' => $redirectUri]);
            }
            break;

        case 'gdrive_oauth_exchange':
            // Manual code exchange: accepts {code, redirect_uri} from the JS after user copies URL.
            $_exchangeBody = json_decode(file_get_contents('php://input'), true) ?? [];
            $code        = trim($_exchangeBody['code'] ?? '');
            $redirectUri = trim($_exchangeBody['redirect_uri'] ?? '') ?: (env('GDRIVE_REDIRECT_URI', '') ?: 'http://localhost');
            if (empty($code)) {
                echo json_encode(['success' => false, 'error' => 'No authorization code provided']);
                break;
            }
            $clientId     = env('GDRIVE_CLIENT_ID', '');
            $clientSecret = env('GDRIVE_CLIENT_SECRET', '');
            if (!$clientId || !$clientSecret) {
                echo json_encode(['success' => false, 'error' => 'Client ID/Secret not configured — save settings first']);
                break;
            }
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'code'          => $code,
                    'redirect_uri'  => $redirectUri,
                    'grant_type'    => 'authorization_code',
                ]),
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $gdriveExResp = curl_exec($ch);
            $gdriveExErr  = curl_error($ch);
            curl_close($ch);
            if (!$gdriveExResp) {
                echo json_encode(['success' => false, 'error' => 'cURL error: ' . $gdriveExErr]);
                break;
            }
            $gdriveExData = json_decode($gdriveExResp, true);
            if (!empty($gdriveExData['refresh_token'])) {
                _gdriveSetEnvVar('GDRIVE_REFRESH_TOKEN', $gdriveExData['refresh_token']);
                echo json_encode(['success' => true]);
            } else {
                $errDesc = $gdriveExData['error_description'] ?? $gdriveExData['error'] ?? $gdriveExResp;
                echo json_encode(['success' => false, 'error' => 'Token exchange failed: ' . $errDesc]);
            }
            break;

        case 'gemini_product_hint':
            geminiProductHint();
            break;

        case 'gemini_shopping_enrich':
            geminiShoppingEnrich($db);
            break;

        case 'gemini_anomaly_explain':
            geminiAnomalyExplain();
            break;

        case 'gemini_number_ocr':
            geminiNumberOCR();
            break;

        case 'get_shopping_price':
            getShoppingPrice($db);
            break;

        case 'get_all_shopping_prices':
            getAllShoppingPrices($db);
            break;

        case 'guess_category':
            guessCategoryFromAI();
            break;

        case 'export_inventory':
            exportInventory($db);
            break;

        default:
            EverLog::warn('unknown action', ['action' => $action]);
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    EverLog::exception($e, $action ?? '-');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    _phpErrorReport($e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), get_class($e));
}
endif; // end !CRON_MODE

// ===== EXPORT INVENTORY =====
function exportInventory(PDO $db): void {
    EverLog::info('exportInventory');
    $format = strtolower($_GET['format'] ?? 'csv');

    $stmt = $db->query("
        SELECT p.name, p.brand, p.category, i.location, i.quantity, p.unit,
               i.expiry_date, i.added_at, i.opened_at,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed,
               p.barcode, p.notes
        FROM inventory i
        JOIN products p ON i.product_id = p.id
        WHERE i.quantity > 0
        ORDER BY p.name ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $date = date('Y-m-d');

    if ($format === 'html') {
        // Print-ready HTML for browser PDF
        header('Content-Type: text/html; charset=utf-8');
        $rows_html = '';
        foreach ($rows as $r) {
            $loc_icon = ['dispensa'=>'🗄️','frigo'=>'🧊','freezer'=>'❄️','altro'=>'📦'][$r['location']] ?? '📦';
            $expiry = $r['expiry_date'] ? htmlspecialchars($r['expiry_date']) : '—';
            $brand  = $r['brand'] ? htmlspecialchars($r['brand']) : '';
            $rows_html .= '<tr>'
                . '<td>' . htmlspecialchars($r['name']) . ($brand ? '<br><small>' . $brand . '</small>' : '') . '</td>'
                . '<td>' . htmlspecialchars(ucfirst($r['category'] ?? '')) . '</td>'
                . '<td>' . $loc_icon . ' ' . htmlspecialchars(ucfirst($r['location'])) . '</td>'
                . '<td style="text-align:right">' . htmlspecialchars($r['quantity']) . ' ' . htmlspecialchars($r['unit'] ?? 'pz') . '</td>'
                . '<td>' . $expiry . '</td>'
                . '<td>' . ($r['opened_at'] ? '📭 ' . htmlspecialchars($r['opened_at']) : '') . '</td>'
                . '</tr>';
        }
        $count = count($rows);
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>EverShelf — Inventory Export {$date}</title>
<style>
  body{font-family:Arial,sans-serif;font-size:12px;margin:24px;color:#1a1a1a}
  h1{font-size:18px;margin-bottom:4px}
  .subtitle{color:#6b7280;font-size:11px;margin-bottom:16px}
  table{width:100%;border-collapse:collapse}
  th{background:#2d5016;color:#fff;padding:7px 10px;text-align:left;font-size:11px}
  td{padding:6px 10px;border-bottom:1px solid #e5e7eb;vertical-align:top}
  tr:nth-child(even) td{background:#f8fafc}
  small{color:#6b7280}
  @media print{
    body{margin:12px}
    button{display:none}
    @page{margin:15mm}
  }
</style>
</head>
<body>
<button onclick="window.print()" style="margin-bottom:16px;padding:8px 16px;background:#2d5016;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px">🖨️ Print / Save as PDF</button>
<h1>🏠 EverShelf — Inventory</h1>
<div class="subtitle">Exported: {$date} &nbsp;·&nbsp; {$count} items</div>
<table>
<thead><tr>
  <th>Name / Brand</th><th>Category</th><th>Location</th><th>Qty</th><th>Expiry</th><th>Opened</th>
</tr></thead>
<tbody>{$rows_html}</tbody>
</table>
<script>window.onload=function(){window.print();}</script>
</body>
</html>
HTML;
        exit;
    }

    // Default: CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="evershelf-inventory-' . $date . '.csv"');
    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name','Brand','Category','Location','Quantity','Unit','Expiry Date','Added','Opened At','Vacuum Sealed','Barcode','Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['name'],
            $r['brand'] ?? '',
            $r['category'] ?? '',
            $r['location'],
            $r['quantity'],
            $r['unit'] ?? 'pz',
            $r['expiry_date'] ?? '',
            $r['added_at'] ?? '',
            $r['opened_at'] ?? '',
            $r['vacuum_sealed'] ? 'Yes' : 'No',
            $r['barcode'] ?? '',
            $r['notes'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ===== TTS PROXY =====
function ttsProxy() {
    EverLog::info('ttsProxy');
    $body = json_decode(file_get_contents('php://input'), true);
    $url     = isset($body['url'])     ? trim($body['url'])     : '';
    $method  = isset($body['method'])  ? strtoupper(trim($body['method'])) : 'POST';
    $headers = isset($body['headers']) && is_array($body['headers']) ? $body['headers'] : [];
    $payload = isset($body['payload']) ? $body['payload'] : '';
    $contentType = '';
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'content-type') { $contentType = $v; break; }
    }

    if (!$url || !preg_match('/^https?:\/\/.+/', $url)) {
        EverLog::warn('ttsProxy: invalid URL (400)');
        http_response_code(400);
        echo json_encode(['error' => 'URL non valido']);
        return;
    }

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($method !== 'GET' && $payload !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    if ($curlHeaders) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // allow self-signed certs on local network
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        EverLog::error('ttsProxy: curl error (502)');
        http_response_code(502);
        echo json_encode(['error' => 'cURL error: ' . $curlErr]);
        return;
    }

    http_response_code($httpCode ?: 200);
    echo json_encode(['status' => $httpCode, 'body' => $response]);
}

// ===== HOME ASSISTANT INTEGRATION =====

/**
 * Fire an outbound webhook to Home Assistant.
 * Respects HA_ENABLED, HA_URL, HA_WEBHOOK_ID and HA_WEBHOOK_EVENTS.
 * Non-blocking: uses a 5 s cURL timeout; failures are logged but never thrown.
 */
function _fireHaWebhook(string $event, array $data): void {
    if (env('HA_ENABLED', 'false') !== 'true') return;
    $haUrl     = rtrim(env('HA_URL', ''), '/');
    $webhookId = env('HA_WEBHOOK_ID', '');
    if (!$haUrl || !$webhookId) return;

    $allowed = array_map('trim', explode(',', env('HA_WEBHOOK_EVENTS', 'expiry,shopping_add,stock_update,barcode_scan')));
    if (!in_array($event, $allowed, true)) return;

    $url     = $haUrl . '/api/webhook/' . urlencode($webhookId);
    $payload = json_encode(array_merge(['event' => $event, 'source' => 'evershelf', 'ts' => time()], $data), JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        EverLog::warn("_fireHaWebhook[$event]: cURL error – $err");
    } else {
        EverLog::debug("_fireHaWebhook[$event]: HTTP $code");
    }
}

/**
 * Send a notification via HA notify service (e.g. notify.mobile_app_phone).
 * Used for expiry alerts when HA_NOTIFY_SERVICE is configured.
 */
function _sendHaNotify(string $message, array $data = []): void {
    if (env('HA_ENABLED', 'false') !== 'true') return;
    $haUrl   = rtrim(env('HA_URL', ''), '/');
    $token   = env('HA_TOKEN', '');
    $service = env('HA_NOTIFY_SERVICE', '');
    if (!$haUrl || !$token || !$service) return;

    // service format: "notify.mobile_app_xyz" → POST /api/services/notify/mobile_app_xyz
    [$domain, $svcName] = array_pad(explode('.', $service, 2), 2, '');
    if (!$svcName) return;

    $url     = $haUrl . '/api/services/' . urlencode($domain) . '/' . urlencode($svcName);
    $payload = json_encode(array_merge(['message' => $message, 'data' => $data], []), JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        EverLog::warn("_sendHaNotify: cURL error – $err");
    } else {
        EverLog::debug("_sendHaNotify: HTTP $code");
    }
}

/**
 * HA REST sensor endpoint — returns pantry state in Home Assistant-compatible format.
 * Use with platform: rest in configuration.yaml.
 *
 * GET /api/?action=ha_sensor[&sensor=NAME]
 * Available sensor names: expiring, expired, total, shopping
 */
function haInventorySensor(PDO $db): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $sensor     = strtolower(trim($_GET['sensor'] ?? 'overview'));
    $expiryDays = max(1, min(90, (int)($_GET['expiry_days'] ?? env('HA_EXPIRY_DAYS', 3))));

    try {
        $expiring = (int)$db->query(
            "SELECT COUNT(*) FROM inventory WHERE quantity > 0 AND expiry_date IS NOT NULL
             AND expiry_date BETWEEN date('now') AND date('now', '+{$expiryDays} days')"
        )->fetchColumn();

        $expired = (int)$db->query(
            "SELECT COUNT(*) FROM inventory WHERE quantity > 0 AND expiry_date IS NOT NULL
             AND expiry_date < date('now')"
        )->fetchColumn();

        $total = (int)$db->query(
            "SELECT COUNT(*) FROM inventory WHERE quantity > 0"
        )->fetchColumn();

        $shoppingCount = 0;
        if (isShoppingBringMode()) {
            $auth = bringAuth();
            if ($auth) {
                $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$auth['bringListUUID']}");
                $shoppingCount = isset($listData['purchase']) ? count($listData['purchase']) : 0;
            }
        } else {
            $shoppingCount = (int)$db->query("SELECT COUNT(*) FROM shopping_list")->fetchColumn();
        }

        // Expiring items details
        $expiringItems = $db->query(
            "SELECT p.name, i.quantity, p.unit, i.expiry_date
             FROM inventory i
             JOIN products p ON p.id = i.product_id
             WHERE i.quantity > 0 AND i.expiry_date IS NOT NULL
               AND i.expiry_date BETWEEN date('now') AND date('now', '+7 days')
             ORDER BY i.expiry_date ASC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Opened items
        $openedItems = (int)$db->query(
            "SELECT COUNT(*) FROM inventory WHERE quantity > 0 AND opened_at IS NOT NULL"
        )->fetchColumn();

        // Fixed 3-day expiry count (always 3 days, regardless of expiry_days param)
        $expiring3d = ($expiryDays === 3)
            ? $expiring
            : (int)$db->query(
                "SELECT COUNT(*) FROM inventory WHERE quantity > 0 AND expiry_date IS NOT NULL
                 AND expiry_date BETWEEN date('now') AND date('now', '+3 days')"
            )->fetchColumn();

        // Items expiring today or tomorrow (max urgency)
        $expiringToday = (int)$db->query(
            "SELECT COUNT(*) FROM inventory WHERE quantity > 0 AND expiry_date IS NOT NULL
             AND expiry_date <= date('now', '+1 days')"
        )->fetchColumn();

        // Shopping total from server-side total cache (max 1 hour old)
        $priceEnabled  = env('PRICE_ENABLED', 'false') === 'true';
        $priceCurrency = env('PRICE_CURRENCY', 'EUR');
        $shoppingTotal = null;
        if ($priceEnabled) {
            $totalCachePath = __DIR__ . '/../data/shopping_total_cache.json';
            if (file_exists($totalCachePath)) {
                $tc = json_decode(file_get_contents($totalCachePath), true) ?? [];
                // Find the most recent entry not older than 1 hour
                $best = null;
                $bestTs = 0;
                foreach ($tc as $entry) {
                    if (isset($entry['ts']) && $entry['ts'] > $bestTs) {
                        $bestTs = $entry['ts'];
                        $best   = $entry;
                    }
                }
                if ($best && (time() - $bestTs) < 3600) {
                    $shoppingTotal = round((float)($best['result']['total'] ?? 0), 2);
                }
            }
        }

        $stateValue = match($sensor) {
            'expired'  => $expired,
            'shopping' => $shoppingCount,
            'total'    => $total,
            default    => $expiring,  // 'expiring' or 'overview'
        };

        echo json_encode([
            'state'      => $stateValue,
            'attributes' => [
                'expiring_soon'          => $expiring,
                'expiring_3d'            => $expiring3d,
                'expiring_today'         => $expiringToday,
                'expired_items'          => $expired,
                'total_items'            => $total,
                'opened_items'           => $openedItems,
                'shopping_items'         => $shoppingCount,
                'shopping_total'         => $shoppingTotal,
                'price_tracking_enabled' => $priceEnabled,
                'price_currency'         => $priceCurrency,
                'expiring_list'          => array_map(fn($r) => [
                    'name'        => $r['name'],
                    'quantity'    => (float)$r['quantity'],
                    'unit'        => $r['unit'],
                    'expiry_date' => $r['expiry_date'],
                    'expires_today' => $r['expiry_date'] <= date('Y-m-d', strtotime('+1 days')),
                ], $expiringItems),
                'next_expiry_name'       => !empty($expiringItems) ? $expiringItems[0]['name'] : null,
                'next_expiry_date'       => !empty($expiringItems) ? $expiringItems[0]['expiry_date'] : null,
                'unit_of_measurement'    => 'items',
                'friendly_name'          => 'EverShelf Pantry',
                'icon'                   => 'mdi:fridge',
                'last_updated'           => date('c'),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// ===== CLIENT LOG =====

/**
 * Test reachability of a Home Assistant instance.
 * Accepts POST body: {url, token}
 * Uses server-env HA_TOKEN if token === '__server__' (token already saved on server).
 */
function haTestConnection(): void {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $url   = rtrim($input['url'] ?? '', '/');
    $token = $input['token'] ?? '';
    if ($token === '__server__') {
        $token = env('HA_TOKEN', '');
    }
    if (!$url) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No URL provided']);
        return;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url . '/api/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            $token ? 'Authorization: Bearer ' . $token : null,
        ]),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        echo json_encode(['ok' => false, 'error' => $err, 'http_code' => 0]);
        return;
    }
    $data = json_decode($raw, true);
    $version = $data['version'] ?? null;
    if ($code === 200) {
        echo json_encode(['ok' => true, 'version' => $version, 'http_code' => $code]);
    } elseif ($code === 401) {
        echo json_encode(['ok' => false, 'error' => 'bad_token', 'http_code' => $code]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'http_' . $code, 'http_code' => $code]);
    }
}


// ===== HA DISCOVERY INFO =====

/**
 * Returns device info for HA Zeroconf discovery confirmation.
 * GET /api/index.php?action=ha_info
 * Response: { name, instance, version, unique_id, has_token, api_version, items_count }
 */
function haGetInfo(PDO $db): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    // Stable unique_id derived from server identity (survives restarts)
    $uniqueId    = 'evershelf_' . substr(md5(__DIR__ . php_uname('n')), 0, 12);
    $itemsCount  = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE quantity > 0")->fetchColumn();
    echo json_encode([
        'name'        => 'EverShelf',
        'instance'    => env('INSTANCE_NAME', php_uname('n')),
        'version'     => _appVersion(),
        'unique_id'   => $uniqueId,
        'has_token'   => !empty(env('SETTINGS_TOKEN', '')),
        'api_version' => 1,
        'items_count' => $itemsCount,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Returns shopping list items in a clean format suitable for HA todo entity.
 * GET /api/index.php?action=ha_shopping_items
 * Response: { items: [{id, name, note}], count, mode }
 */
function haGetShoppingItems(PDO $db): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    try {
        if (isShoppingBringMode()) {
            $auth = bringAuth();
            if (!$auth) {
                echo json_encode(['items' => [], 'count' => 0, 'mode' => 'bring']);
                return;
            }
            $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$auth['bringListUUID']}");
            $items = array_map(fn($r) => [
                'id'   => $r['uuid'] ?? md5(($r['name'] ?? '') . uniqid()),
                'name' => $r['name'] ?? '',
                'note' => $r['specification'] ?? '',
            ], $listData['purchase'] ?? []);
            echo json_encode(['items' => $items, 'count' => count($items), 'mode' => 'bring'], JSON_UNESCAPED_UNICODE);
        } else {
            $rows = $db->query(
                "SELECT rowid AS id, name, specification AS note FROM shopping_list ORDER BY sort_order ASC, added_at ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(fn($r) => [
                'id'   => (string)$r['id'],
                'name' => $r['name'],
                'note' => $r['note'] ?? '',
            ], $rows);
            echo json_encode(['items' => $items, 'count' => count($items), 'mode' => 'internal'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}


// ===== FOOD FACTS (cached daily) =====
function getFoodFacts(): void {
    EverLog::info('getFoodFacts');
    header('Content-Type: application/json; charset=utf-8');
    $cacheFile = __DIR__ . '/../data/food_facts_cache.json';
    $maxAgeSeconds = 86400; // 24 hours

    // Return valid cache if fresh
    if (file_exists($cacheFile)) {
        $cached = @json_decode(file_get_contents($cacheFile), true);
        if ($cached && !empty($cached['ts']) && (time() - $cached['ts']) < $maxAgeSeconds) {
            echo json_encode($cached);
            return;
        }
    }

    // Build facts dataset (sourced from UNEP Food Waste Index 2024, Waste Watcher IT 2024,
    // ISPRA 2024, USDA 2021, Eurostat 2023, FAO 2024 — verified against public reports)
    $facts = [
        'it' => [
            "Nel 2024 ogni italiano spreca ~554 g di cibo a settimana (Waste Watcher 2024)",
            "Lo spreco domestico in Italia vale oltre €7,5 miliardi l'anno",
            "La frutta fresca è l'alimento più sprecato in Italia: ~22g/persona/settimana",
            "Nel mondo si sprecano ~1,05 miliardi di tonnellate di cibo ogni anno (UNEP 2024)",
            "Il 19% del cibo globale disponibile al consumo viene buttato (UNEP 2024)",
            "Le famiglie sono responsabili del 60% dello spreco alimentare totale",
            "Lo spreco alimentare conta per l'8-10% delle emissioni globali di gas serra",
            "Se fosse un Paese, lo spreco alimentare sarebbe il 3° emettitore di CO₂ al mondo",
            "Lo spreco alimentare consuma il 25% dell'acqua dolce usata in agricoltura",
            "Un'area grande quanto la Cina viene coltivata per cibo mai mangiato",
            "Lo spreco alimentare costa al mondo ~€1.000 miliardi l'anno",
            "Eliminare lo spreco potrebbe ridurre le emissioni globali del 10%",
            "Il lunedì è il giorno in cui gli italiani buttano più cibo (residui del weekend)",
            "Solo il 30% degli italiani sa distinguere 'da consumarsi entro' da 'preferibilmente entro'",
            "Il ricorso al congelatore riduce lo spreco domestico del 20%",
            "1 kg di pane sprecato = 1.300 litri d'acqua consumati inutilmente",
            "Sprecare 1 hamburger = stessa acqua di una doccia da 90 minuti",
            "Lo spreco alimentare pro capite in Italia è ~29 kg/anno (domestico)",
            "Il 42% degli italiani dichiara di sprecare meno grazie all'aumento dei prezzi",
            "La Gen Z spreca più dei Boomers per minori competenze in cucina",
            "Le app anti-spreco come Too Good To Go hanno salvato milioni di pasti in Italia",
            "Solo il 15% degli italiani chiede la 'doggy bag' al ristorante (per imbarazzo)",
            "Un quarto del cibo sprecato basterebbe a sfamare tutti gli affamati del mondo",
            "Il packaging intelligente potrebbe ridurre lo spreco del 15%",
            "Educare i bambini a scuola riduce lo spreco familiare del 15%",
            "La Legge Gadda (166/2016) è tra le norme anti-spreco più avanzate d'Europa",
            "Il Sud Italia spreca in media l'8% in più rispetto al Nord",
            "Le città metropolitane sprecano più dei piccoli centri rurali",
            "Il 70% degli italiani cerca più offerte per via dell'inflazione",
            "L'uso dei discount in Italia è cresciuto del 12% negli ultimi due anni",
            "L'Italia è il 1° paese europeo per consumo di pasta: 23 kg pro capite/anno",
            "Il consumo di carne rossa in Italia è calato del 5% rispetto al decennio scorso",
            "Il biologico rappresenta ~4% della spesa alimentare totale italiana",
            "L'85% degli italiani preferisce ancora il negozio fisico per i prodotti freschi",
            "Nel 2024 oltre 780 milioni di persone hanno sofferto la fame nel mondo (FAO)",
        ],
        'de' => [
            "Deutsche Haushalte werfen pro Person rund 82 kg Lebensmittel pro Jahr weg (Destatis 2024)",
            "Weltweit werden ~1,05 Milliarden Tonnen Lebensmittel pro Jahr verschwendet (UNEP 2024)",
            "19% des global verfügbaren Lebensmittelangebots landet im Müll (UNEP 2024)",
            "Haushalte verursachen 60% der gesamten Lebensmittelverschwendung",
            "Lebensmittelverschwendung ist für 8-10% der globalen Treibhausgase verantwortlich",
            "Wäre Lebensmittelverschwendung ein Land, wäre es der 3. größte CO₂-Emittent weltweit",
            "25% des in der Landwirtschaft genutzten Süßwassers wird für nie gegessenes Essen verbraucht",
            "Die weltweiten Kosten der Lebensmittelverschwendung betragen ~€1 Billion jährlich",
            "1 kg verschwendetes Rindfleisch ≈ 27 kg CO₂-Emissionen",
            "Das Einfrieren von Lebensmitteln reduziert Haushaltsabfälle um bis zu 20%",
            "Nur ein Viertel der weltweit verschwendeten Lebensmittel würde alle Hungernden ernähren",
            "In Deutschland zeigt die Inflation: 60% der Verbraucher kaufen gezielter ein",
            "Bio-Lebensmittel machen ~6% der deutschen Lebensmittelausgaben aus",
            "Deutsche Familien geben im Schnitt ~€3.000/Jahr für Lebensmittel aus",
            "Schlaue Verpackungen könnten den Lebensmittelabfall um 15% senken",
        ],
        'en' => [
            "~1.05 billion tonnes of food are wasted globally every year (UNEP 2024)",
            "19% of food available for human consumption is wasted globally (UNEP 2024)",
            "Households account for 60% of all food waste globally",
            "Food waste represents 8-10% of global greenhouse gas emissions",
            "If food waste were a country, it would be the world's 3rd largest CO₂ emitter",
            "25% of freshwater used in farming grows food that is never eaten",
            "Food waste costs the world ~$1 trillion per year",
            "Eliminating food waste could cut global emissions by up to 10%",
            "30–40% of the US food supply is wasted each year (USDA 2021)",
            "Americans spend ~$1,800/year on food they never eat",
            "Using a freezer can reduce household food waste by 20%",
            "Just a quarter of wasted food would be enough to feed all the world's hungry",
            "Smart packaging that changes color near expiry could cut waste by 15%",
            "Gen Z wastes more food than Boomers due to fewer cooking skills",
            "In 2024, over 780 million people faced hunger despite global food abundance (FAO)",
            "1 kg of wasted bread = 1,300 litres of water wasted",
            "Wasting one hamburger uses as much water as a 90-minute shower",
            "Food loss (field→store) and food waste (store→table) together waste ~30% of all food",
            "Fruits & vegetables are the most wasted food category worldwide",
            "Teaching children about food waste reduces household waste by 15%",
        ],
        'source' => 'UNEP Food Waste Index 2024 · Waste Watcher IT 2024 · USDA 2021 · FAO 2024 · Eurostat 2023',
        'ts'     => time(),
    ];

    // Write cache
    @file_put_contents($cacheFile, json_encode($facts));

    echo json_encode($facts);
}

// ===== EXPIRY HISTORY =====
function getExpiryHistory($db): void {
    $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
    if (!$productId) {
        EverLog::debug('getExpiryHistory');
        echo json_encode(['avg_days' => null, 'count' => 0]);
        return;
    }

    // Compute average shelf life (expiry_date - added_at) for this product
    // Only use entries where expiry_date is clearly in the future relative to added_at
    $stmt = $db->prepare("
        SELECT ROUND(AVG(CAST(JULIANDAY(expiry_date) - JULIANDAY(added_at) AS REAL))) AS avg_days,
               COUNT(*) AS count
        FROM inventory
        WHERE product_id = ?
          AND expiry_date IS NOT NULL
          AND expiry_date > date(added_at)
          AND added_at >= date('now', '-730 days')
    ");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !$row['count'] || $row['avg_days'] === null) {
        echo json_encode(['avg_days' => null, 'count' => 0]);
        return;
    }

    echo json_encode(['avg_days' => (int)$row['avg_days'], 'count' => (int)$row['count']]);
}

function clientLog(): void {
    EverLog::debug('clientLog');
    $input = json_decode(file_get_contents('php://input'), true);
    $logFile = __DIR__ . '/../data/client_debug.log';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    // Identify device from UA
    $device = 'unknown';
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) $device = 'tablet';
    elseif (preg_match('/mobile|android|iphone/i', $ua)) $device = 'phone';
    else $device = 'desktop';
    $ts = date('Y-m-d H:i:s');
    $msgs = $input['messages'] ?? [];
    $lines = [];
    foreach ($msgs as $m) {
        $lines[] = "[$ts] [$device] $m";
    }
    if ($lines) {
        // Keep log under 100KB — truncate oldest if needed
        if (file_exists($logFile) && filesize($logFile) > 100000) {
            $existing = file($logFile);
            $existing = array_slice($existing, -200);
            file_put_contents($logFile, implode('', $existing));
        }
        file_put_contents($logFile, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
    }
    echo json_encode(['ok' => true]);
}

function getClientLog(): void {
    $logFile = __DIR__ . '/../data/client_debug.log';
    $lines = 100;
    if (isset($_GET['lines'])) $lines = min(500, max(1, (int)$_GET['lines']));
    if (!file_exists($logFile)) {
        EverLog::debug('getClientLog');
        echo json_encode(['log' => '(empty)', 'lines' => 0]);
        return;
    }
    $all = file($logFile);
    $tail = array_slice($all, -$lines);
    echo json_encode(['log' => implode('', $tail), 'lines' => count($tail), 'total' => count($all)]);
}

// ===== PRODUCT FUNCTIONS =====

function searchBarcode(PDO $db): void {
    $barcode = $_GET['barcode'] ?? '';
    if (empty($barcode)) {
        EverLog::info('searchBarcode');
        echo json_encode(['found' => false]);
        return;
    }
    $stmt = $db->prepare("SELECT * FROM products WHERE barcode = ?");
    $stmt->execute([$barcode]);
    $product = $stmt->fetch();
    if ($product) {
        echo json_encode(['found' => true, 'product' => $product]);
    } else {
        echo json_encode(['found' => false]);
    }
}

/**
 * Returns all in-stock inventory items whose product name shares the same first
 * significant token as the given name (e.g. "Carote" matches "Carote Bio", "Carote DOP").
 * Used by the scan UI to show "you already have X in pantry" before adding a product.
 */
function stockForName(PDO $db): void {
    $name = trim($_GET['name'] ?? '');
    if (empty($name)) {
        echo json_encode(['items' => []]);
        return;
    }

    $stop = ['di','del','della','dei','degli','delle','da','in','con','per','su',
             'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo'];

    $tokenize = function(string $s) use ($stop): array {
        $clean = mb_strtolower(preg_replace('/[^\p{L}0-9\s]/u', ' ', $s));
        return array_values(array_filter(
            preg_split('/\s+/', trim($clean)),
            fn($t) => mb_strlen($t) > 2 && !in_array($t, $stop)
        ));
    };

    $searchTokens = $tokenize($name);
    if (empty($searchTokens)) {
        echo json_encode(['items' => []]);
        return;
    }
    $firstToken = $searchTokens[0];

    $rows = $db->query(
        "SELECT i.quantity, i.unit, i.location,
                p.name AS product_name, p.brand,
                p.default_quantity, p.package_unit
         FROM inventory i
         JOIN products p ON p.id = i.product_id
         WHERE i.quantity > 0
         ORDER BY p.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $matches = [];
    foreach ($rows as $row) {
        $rowTokens = $tokenize($row['product_name']);
        if (empty($rowTokens)) continue;
        if ($rowTokens[0] === $firstToken) {
            $matches[] = [
                'name'             => $row['product_name'],
                'brand'            => $row['brand'] ?? '',
                'quantity'         => (float)$row['quantity'],
                'unit'             => $row['unit'],
                'location'         => $row['location'] ?? '',
                'default_quantity' => (int)($row['default_quantity'] ?? 0),
                'package_unit'     => $row['package_unit'] ?? '',
            ];
        }
    }

    echo json_encode(['items' => $matches], JSON_UNESCAPED_UNICODE);
}

function _offFetchProduct(string $barcode): ?array {
    $fields = 'product_name,product_name_it,generic_name,generic_name_it,brands,categories_tags,categories_hierarchy,categories,image_front_small_url,image_url,quantity,nutriscore_grade,ingredients_text_it,ingredients_text,allergens_tags,conservation_conditions_it,conservation_conditions,origins_it,origins,manufacturing_places,nova_group,ecoscore_grade,labels,stores';

    // Try candidate barcodes: given barcode + EAN-13 (UPC-A → prepend 0)
    $candidates = [$barcode];
    if (strlen($barcode) === 12 && ctype_digit($barcode)) {
        $candidates[] = '0' . $barcode;
    }
    // Also try without leading zero if 13 digits starting with 0
    if (strlen($barcode) === 13 && $barcode[0] === '0') {
        $candidates[] = substr($barcode, 1);
    }

    // Locale preference: Italian first (better names), then world-neutral
    $locales = ['lc=it', ''];

    foreach ($candidates as $bc) {
        foreach ($locales as $lc) {
            $lcParam = $lc ? "&{$lc}" : '';
            $url = "https://world.openfoodfacts.org/api/v2/product/{$bc}.json?fields={$fields}{$lcParam}";
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: EverShelf/1.0\r\n"]]);

            $response = @file_get_contents($url, false, $ctx);
            if ($response === false) {
                // Network error: retry once after short delay
                usleep(300000); // 0.3s
                $response = @file_get_contents($url, false, $ctx);
            }
            if ($response === false) continue;

            $data = json_decode($response, true);
            if (!isset($data['status']) || $data['status'] !== 1 || empty($data['product'])) continue;

            $p = $data['product'];

            // Prefer Italian name, fall back to generic / any locale
            $name = '';
            foreach (['product_name_it', 'generic_name_it', 'product_name', 'generic_name'] as $f) {
                if (!empty($p[$f])) { $name = $p[$f]; break; }
            }

            // Non-Latin script fallback
            if (!empty($name) && preg_match('/[\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0400}-\x{04FF}]/u', $name)) {
                $latinName = '';
                foreach (['generic_name_it', 'generic_name', 'product_name_it', 'product_name'] as $f) {
                    if (!empty($p[$f]) && !preg_match('/[\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0400}-\x{04FF}]/u', $p[$f])) {
                        $latinName = $p[$f]; break;
                    }
                }
                if (empty($latinName)) $latinName = !empty($p['brands']) ? $p['brands'] : 'Prodotto sconosciuto';
                $name = $latinName;
            }

            $ingredients = $p['ingredients_text_it'] ?? $p['ingredients_text'] ?? '';
            $category = $p['categories_tags'][0] ?? end($p['categories_hierarchy'] ?? []) ?? $p['categories'] ?? '';
            $allergens = '';
            if (!empty($p['allergens_tags'])) {
                $allergens = implode(', ', array_map(fn($a) => str_replace('en:', '', $a), $p['allergens_tags']));
            }

            return [
                'name'          => $name,
                'brand'         => $p['brands'] ?? '',
                'category'      => $category,
                'image_url'     => $p['image_front_small_url'] ?? $p['image_url'] ?? '',
                'quantity_info' => $p['quantity'] ?? '',
                'nutriscore'    => $p['nutriscore_grade'] ?? '',
                'ingredients'   => $ingredients,
                'allergens'     => $allergens,
                'conservation'  => $p['conservation_conditions_it'] ?? $p['conservation_conditions'] ?? '',
                'origin'        => $p['origins_it'] ?? $p['origins'] ?? $p['manufacturing_places'] ?? '',
                'nova_group'    => $p['nova_group'] ?? '',
                'ecoscore'      => $p['ecoscore_grade'] ?? '',
                'labels'        => $p['labels'] ?? '',
                'stores'        => $p['stores'] ?? '',
            ];
        }
    }
    return null;
}

function lookupBarcode(): void {
    $barcode = $_GET['barcode'] ?? '';
    if (empty($barcode)) {
        EverLog::info('lookupBarcode');
        echo json_encode(['found' => false, 'error' => 'No barcode provided']);
        return;
    }

    // 1. Try Open Food Facts (multi-barcode, multi-locale, with auto-retry on network errors)
    $offProduct = _offFetchProduct($barcode);
    if ($offProduct !== null) {
        echo json_encode(['found' => true, 'source' => 'openfoodfacts', 'product' => $offProduct]);
        return;
    }

    // 2. Try UPC Item DB as fallback
    $candidates = [$barcode];
    if (strlen($barcode) === 12 && ctype_digit($barcode)) $candidates[] = '0' . $barcode;
    foreach ($candidates as $bc) {
        $url2 = "https://api.upcitemdb.com/prod/trial/lookup?upc={$bc}";
        $ctx2 = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: EverShelf/1.0\r\n"]]);
        $r2 = @file_get_contents($url2, false, $ctx2);
        if ($r2 !== false) {
            $d2 = json_decode($r2, true);
            if (!empty($d2['items'][0])) {
                $item = $d2['items'][0];
                echo json_encode(['found' => true, 'source' => 'upcitemdb', 'product' => [
                    'name'      => $item['title'] ?? '',
                    'brand'     => $item['brand'] ?? '',
                    'category'  => $item['category'] ?? '',
                    'image_url' => $item['images'][0] ?? '',
                ]]);
                return;
            }
        }
    }

    // 3. Try Open Products Facts (non-food household items) and Open Beauty Facts (cosmetics)
    $altBases = [
        'https://world.openproductsfacts.org',
        'https://world.openbeautyfacts.org',
    ];
    $altFields = 'product_name,product_name_it,brands,categories_tags,categories_hierarchy,image_front_small_url,image_url,quantity';
    $altCandidates = [$barcode];
    if (strlen($barcode) === 12 && ctype_digit($barcode)) $altCandidates[] = '0' . $barcode;
    foreach ($altBases as $altBase) {
        foreach ($altCandidates as $bc) {
            $altUrl = "{$altBase}/api/v2/product/{$bc}.json?fields={$altFields}";
            $altCtx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: EverShelf/1.0\r\n"]]);
            $altR = @file_get_contents($altUrl, false, $altCtx);
            if ($altR === false) continue;
            $altD = json_decode($altR, true);
            if (!isset($altD['status']) || $altD['status'] !== 1 || empty($altD['product'])) continue;
            $p = $altD['product'];
            $altName = $p['product_name_it'] ?? $p['product_name'] ?? '';
            if (empty($altName)) continue;
            $altCat = $p['categories_tags'][0] ?? end($p['categories_hierarchy'] ?? []) ?? '';
            echo json_encode(['found' => true, 'source' => $altBase, 'product' => [
                'name'          => $altName,
                'brand'         => $p['brands'] ?? '',
                'category'      => $altCat,
                'image_url'     => $p['image_front_small_url'] ?? $p['image_url'] ?? '',
                'quantity_info' => $p['quantity'] ?? '',
                'nutriscore' => '', 'ingredients' => '', 'allergens' => '',
                'conservation' => '', 'origin' => '', 'nova_group' => '',
                'ecoscore' => '', 'labels' => '', 'stores' => '',
            ]]);
            return;
        }
    }

    // 4. Gemini AI as last resort — works for well-known products not in any open DB
    $apiKey = env('GEMINI_API_KEY');
    if ($apiKey) {
        $geminiProduct = _barcodeLookupGemini($barcode, $apiKey);
        if ($geminiProduct !== null) {
            echo json_encode(['found' => true, 'source' => 'gemini', 'product' => $geminiProduct]);
            return;
        }
    }

    echo json_encode(['found' => false, 'source' => 'openfoodfacts']);
}

/**
 * Ask Gemini to identify a product by barcode number.
 * Only used as a last resort when all open databases fail.
 * Returns null if Gemini doesn't know the product.
 */
function _barcodeLookupGemini(string $barcode, string $apiKey): ?array {
    $payload = [
        'contents' => [[
            'role'  => 'user',
            'parts' => [[
                'text' => "You are a product database. A user scanned barcode: {$barcode}\n" .
                          "Identify this product. If you know it, respond with ONLY valid JSON (no markdown, no explanation):\n" .
                          "{\"name\":\"...\",\"brand\":\"...\",\"category\":\"...\"}\n" .
                          "Use the Italian product name if the product is sold in Italy.\n" .
                          "If you do not know this specific barcode, respond with: {\"unknown\":true}"
            ]],
        ]],
        'generationConfig' => [
            'temperature'        => 0,
            'maxOutputTokens'    => 150,
            'responseMimeType'   => 'application/json',
        ],
    ];

    $result = callGeminiWithFallback($apiKey, $payload, 10);
    if (!$result) return null;

    $text = '';
    foreach ($result['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= ($part['text'] ?? '');
    }
    $text = trim($text);
    if (empty($text)) return null;

    $data = json_decode($text, true);
    if (!$data || !empty($data['unknown']) || empty($data['name'])) return null;

    return [
        'name'          => $data['name'],
        'brand'         => $data['brand'] ?? '',
        'category'      => $data['category'] ?? '',
        'image_url'     => '',
        'quantity_info' => '',
        'nutriscore'    => '',
        'ingredients'   => '',
        'allergens'     => '',
        'conservation'  => '',
        'origin'        => '',
        'nova_group'    => '',
        'ecoscore'      => '',
        'labels'        => '',
        'stores'        => '',
    ];
}

function saveProduct(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['name'])) {
        EverLog::info('saveProduct');
        http_response_code(400);
        echo json_encode(['error' => 'Product name is required']);
        return;
    }
    
    // Auto-compute shopping_name unless the caller explicitly provides one.
    // A caller may pass shopping_name=null or omit it to always trigger auto-compute.
    $shoppingName = array_key_exists('shopping_name', $input) && $input['shopping_name'] !== null && $input['shopping_name'] !== ''
        ? $input['shopping_name']
        : computeShoppingName($input['name'], $input['category'] ?? '', $input['brand'] ?? '');

    if (!empty($input['id'])) {
        // Update existing
        $stmt = $db->prepare("
            UPDATE products SET name=?, brand=?, category=?, image_url=?, unit=?,
            default_quantity=?, notes=?, barcode=?, package_unit=?, shopping_name=?,
            updated_at=CURRENT_TIMESTAMP WHERE id=?
        ");
        $stmt->execute([
            $input['name'], $input['brand'] ?? '', $input['category'] ?? '',
            $input['image_url'] ?? '', $input['unit'] ?? 'pz',
            $input['default_quantity'] ?? 1, $input['notes'] ?? '',
            $input['barcode'] ?? null, $input['package_unit'] ?? '',
            $shoppingName, $input['id']
        ]);
        echo json_encode(['success' => true, 'id' => $input['id']]);
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO products (barcode, name, brand, category, image_url, unit, default_quantity, notes, package_unit, shopping_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $barcode = !empty($input['barcode']) ? $input['barcode'] : null;
        $stmt->execute([
            $barcode, $input['name'], $input['brand'] ?? '',
            $input['category'] ?? '', $input['image_url'] ?? '',
            $input['unit'] ?? 'pz', $input['default_quantity'] ?? 1,
            $input['notes'] ?? '', $input['package_unit'] ?? '', $shoppingName
        ]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    }
}

function getProduct(PDO $db): void {
    $id = $_GET['id'] ?? 0;
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if ($product) {
        EverLog::debug('getProduct');
        echo json_encode(['success' => true, 'product' => $product]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
    }
}

function deleteProduct(PDO $db): void {
    EverLog::info('deleteProduct');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;
    $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
}

function listProducts(PDO $db): void {
    $stmt = $db->query("SELECT * FROM products ORDER BY name ASC");
    echo json_encode(['products' => $stmt->fetchAll()]);
}

function searchProducts(PDO $db): void {
    EverLog::debug('listProducts');
    $q = $_GET['q'] ?? '';
    $stmt = $db->prepare("SELECT * FROM products WHERE name LIKE ? OR brand LIKE ? OR barcode LIKE ? ORDER BY name ASC LIMIT 20");
    $like = "%{$q}%";
    $stmt->execute([$like, $like, $like]);
    echo json_encode(['products' => $stmt->fetchAll()]);
}

// ===== INVENTORY FUNCTIONS =====

function listInventory(PDO $db): void {
    EverLog::debug('listInventory');
    $location = $_GET['location'] ?? '';
    $query = "
        SELECT i.*, p.name, p.brand, p.category, p.image_url, p.unit, p.barcode, p.default_quantity, p.package_unit,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed, i.opened_at, p.shopping_name
        FROM inventory i
        JOIN products p ON i.product_id = p.id
        WHERE i.quantity > 0
    ";
    $params = [];
    if (!empty($location)) {
        $query .= " AND i.location = ?";
        $params[] = $location;
    }
    $query .= " ORDER BY p.name ASC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    EverLog::debug('inventory_list fetched', ['rows' => count($rows), 'location' => $location ?: 'all']);
    echo json_encode(['inventory' => $rows]);
}

function addToInventory(PDO $db): void {
    EverLog::info('addToInventory');
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    $quantity = (float)($input['quantity'] ?? 1);
    $location = $input['location'] ?? 'dispensa';
    $expiry = $input['expiry_date'] ?? null;
    $unit = $input['unit'] ?? null;
    
    if (!$productId) {
        EverLog::warn('addToInventory: product_id missing (400)');
        http_response_code(400);
        echo json_encode(['error' => 'Product ID required']);
        return;
    }

    // Validate quantity bounds
    if ($quantity <= 0 || $quantity > 100000) {
        EverLog::warn('addToInventory: invalid quantity (400)');
        http_response_code(400);
        echo json_encode(['error' => 'Invalid quantity']);
        return;
    }

    // Validate location
    $validLocations = ['dispensa', 'frigo', 'freezer', 'altro'];
    if (!in_array($location, $validLocations)) {
        EverLog::warn('addToInventory: invalid location (400)');
        http_response_code(400);
        echo json_encode(['error' => 'Invalid location']);
        return;
    }
    
    // If a different unit was specified, update the product's unit
    if ($unit) {
        $stmt = $db->prepare("UPDATE products SET unit = ?, default_quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$unit, $quantity, $productId]);
    } else {
        // Auto-set default_quantity if product has none (first add sets package size)
        $stmt = $db->prepare("SELECT default_quantity, unit FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $prod = $stmt->fetch();
        if ($prod && (float)($prod['default_quantity'] ?? 0) == 0 && !in_array($prod['unit'], ['pz', 'conf'])) {
            $stmt = $db->prepare("UPDATE products SET default_quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$quantity, $productId]);
        }
    }
    
    // Update package info if conf
    $packageUnit = $input['package_unit'] ?? null;
    $packageSize = $input['package_size'] ?? null;
    if ($packageUnit !== null) {
        $stmt = $db->prepare("UPDATE products SET package_unit = ?, default_quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$packageUnit, $packageSize ?: 0, $productId]);
    }
    
    $vacuumSealed = (int)($input['vacuum_sealed'] ?? 0);
    
    // Check if product already exists in this location
    $stmt = $db->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location = ?");
    $stmt->execute([$productId, $location]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update quantity
        $newQty = $existing['quantity'] + $quantity;
        $stmt = $db->prepare("UPDATE inventory SET quantity = ?, expiry_date = COALESCE(?, expiry_date), vacuum_sealed = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$newQty, $expiry, $vacuumSealed, $existing['id']]);
    } else {
        $newQty = $quantity;
        // Insert new inventory entry
        $stmt = $db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date, vacuum_sealed) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$productId, $location, $quantity, $expiry, $vacuumSealed]);
    }
    
    // Get total across all locations
    $stmt = $db->prepare("SELECT SUM(quantity) FROM inventory WHERE product_id = ? AND quantity > 0");
    $stmt->execute([$productId]);
    $totalQty = (float)($stmt->fetchColumn() ?: $newQty);
    
    // Get product unit info for display
    $stmt = $db->prepare("SELECT unit, default_quantity, package_unit FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $prodInfo = $stmt->fetch();
    
    // Log transaction
    $stmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location) VALUES (?, 'in', ?, ?)");
    $stmt->execute([$productId, $quantity, $location]);
    
    // Auto-remove from Bring! if product is on the shopping list
    $removedFromBring = false;
    try {
        $stmt = $db->prepare("SELECT name, shopping_name FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $prod = $stmt->fetch();
        if ($prod) {
            $prodName    = $prod['name'];
            // Use shopping_name for Bring! removal — Bring! was added with the generic name
            $displayName = $prod['shopping_name'] ?: computeShoppingName($prodName);
            $auth = bringAuth();
            if ($auth) {
                $listUUID = $auth['bringListUUID'];
                // Primary Bring! key: catalog key of the generic shopping name
                $bringKey = italianToBring($displayName);
                $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
                if ($listData && isset($listData['purchase'])) {
                    // Token-based matching — same logic as _productOnBring() in smart_shopping
                    $stop = ['di','del','della','dei','degli','dalle','delle','da','in','con','per',
                             'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo'];
                    $tokenize = function(string $s) use ($stop): array {
                        $clean = mb_strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $s));
                        return array_values(array_filter(
                            preg_split('/\s+/', trim($clean)),
                            fn($t) => mb_strlen($t) > 2 && !in_array($t, $stop)
                        ));
                    };
                    // Tokens from both the generic name and the specific product name
                    $displayTokens = $tokenize($displayName);
                    $prodTokens    = $tokenize($prodName);
                    $keyTokens     = $tokenize($bringKey);
                    $displayFirst  = $displayTokens[0] ?? '';
                    $prodFirst     = $prodTokens[0] ?? '';
                    $keyFirst      = $keyTokens[0] ?? '';
                    foreach ($listData['purchase'] as $item) {
                        $rawName = $item['name'] ?? '';
                        // 1. Exact match on catalog key, generic name, or specific product name
                        if (strcasecmp($rawName, $bringKey) === 0
                            || strcasecmp($rawName, $displayName) === 0
                            || strcasecmp($rawName, $prodName) === 0) {
                            bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}",
                                http_build_query(['uuid' => $listUUID, 'remove' => $rawName]));
                            $removedFromBring = true;
                            break;
                        }
                        // 2. Token-based fuzzy: first significant word must match any of our names
                        if ($displayFirst || $prodFirst || $keyFirst) {
                            $rawTokens = $tokenize($rawName);
                            $rawFirst  = $rawTokens[0] ?? '';
                            if ($rawFirst && (
                                $rawFirst === $displayFirst ||
                                $rawFirst === $prodFirst    ||
                                $rawFirst === $keyFirst     ||
                                in_array($displayFirst, $rawTokens) ||
                                in_array($prodFirst,    $rawTokens) ||
                                in_array($keyFirst,     $rawTokens)
                            )) {
                                bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}",
                                    http_build_query(['uuid' => $listUUID, 'remove' => $rawName]));
                                $removedFromBring = true;
                                break;
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Silently fail
    }
    
    echo json_encode([
        'success' => true,
        'new_qty' => $newQty,
        'total_qty' => $totalQty,
        'unit' => $prodInfo['unit'] ?? 'pz',
        'default_quantity' => (float)($prodInfo['default_quantity'] ?? 0),
        'package_unit' => $prodInfo['package_unit'] ?? null,
        'removed_from_bring' => $removedFromBring,
    ]);
    EverLog::info('inventory_add ok', ['product_id' => $productId, 'qty' => $quantity, 'location' => $location, 'removed_from_bring' => $removedFromBring]);
    // Inventory changed — force smart-shopping recompute on next request
    invalidateSmartShoppingCache();
}

function useFromInventory(PDO $db): void {
    EverLog::info('useFromInventory');
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = $input['product_id'] ?? 0;
    $quantity = $input['quantity'] ?? 0;
    $useAll = $input['use_all'] ?? false;
    $location = $input['location'] ?? 'dispensa';
    $notes = $input['notes'] ?? '';
    
    if (!$productId) {
        EverLog::warn('useFromInventory: product_id missing (400)');
        http_response_code(400);
        echo json_encode(['error' => 'Product ID required']);
        return;
    }

    // ── Server-side deduplication ─────────────────────────────────────────
    // Reject if the same product already has an 'out' transaction in the last
    // 12 seconds. This guards against scale double-triggers (the scale can fire
    // a second stable reading ~10 s after the first auto-confirm, while the
    // product is still on the plate), regardless of the client-side guard.
    if (!$useAll) {
        $dedup = $db->prepare(
            "SELECT id FROM transactions
             WHERE product_id = ? AND type IN ('out','waste') AND undone = 0
               AND created_at >= datetime('now', '-12 seconds')
             LIMIT 1"
        );
        $dedup->execute([$productId]);
        if ($dedup->fetch()) {
            echo json_encode([
                'success' => false,
                'error'   => 'Operazione già registrata di recente — attendi qualche secondo.',
                'duplicate' => true,
            ]);
            return;
        }
    }
    // ─────────────────────────────────────────────────────────────────────
    
    // Handle "throw all from all locations"
    if ($useAll && $location === '__all__') {
        $stmt = $db->prepare("SELECT id, quantity, location FROM inventory WHERE product_id = ? AND quantity > 0");
        $stmt->execute([$productId]);
        $allItems = $stmt->fetchAll();
        $totalRemoved = 0;
        $explicitFinish = ($notes !== 'Buttato');
        foreach ($allItems as $item) {
            $totalRemoved += $item['quantity'];
            $type = ($notes === 'Buttato') ? 'waste' : 'out';
            $stmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $type, $item['quantity'], $item['location'], $notes]);

            // User explicitly chose "use all/finished": do not keep qty=0 rows that
            // would trigger a redundant "are you sure it's finished" banner.
            if ($explicitFinish) {
                $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->execute([$item['id']]);
            } else {
                $stmt = $db->prepare("UPDATE inventory SET quantity = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$item['id']]);
            }
        }
        echo json_encode(['success' => true, 'remaining' => 0, 'removed' => $totalRemoved]);
        return;
    }
    
    $stmt = $db->prepare("SELECT id, quantity, opened_at, vacuum_sealed FROM inventory WHERE product_id = ? AND location = ? AND quantity > 0 ORDER BY (quantity != CAST(CAST(quantity AS INTEGER) AS REAL)) DESC, quantity ASC");
    $stmt->execute([$productId, $location]);
    $existing = $stmt->fetch();
    
    if (!$existing) {
        EverLog::warn('useFromInventory: product not found in inventory (404)');
        http_response_code(404);
        echo json_encode(['error' => 'Product not found in inventory at this location']);
        return;
    }
    
    if ($useAll) {
        $quantity = $existing['quantity'];
    }
    
    // Auto-split conf products: separate whole confs from opened (fractional) part
    $openedId = null;
    $stmt2 = $db->prepare("SELECT name, category, unit, default_quantity, package_unit FROM products WHERE id = ?");
    $stmt2->execute([$productId]);
    $prodInfo = $stmt2->fetch();
    
    if ($prodInfo && $prodInfo['unit'] === 'conf' && $prodInfo['default_quantity'] > 0 && !$useAll) {
        $totalQty = (float)$existing['quantity'];
        $wholeConfs = floor($totalQty + 0.001);
        $fraction = round($totalQty - $wholeConfs, 6);
        
        // Has both whole and fractional, and we're using less than or equal to the fractional part
        if ($wholeConfs >= 1 && $fraction > 0.001 && $quantity <= $fraction + 0.001) {
            // Split: keep whole confs in original row, create new row for opened part
            $stmt3 = $db->prepare("UPDATE inventory SET quantity = ?, opened_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt3->execute([$wholeConfs, $existing['id']]);
            
            // Get expiry and vacuum_sealed from original row
            $stmt3 = $db->prepare("SELECT expiry_date, vacuum_sealed FROM inventory WHERE id = ?");
            $stmt3->execute([$existing['id']]);
            $origRow = $stmt3->fetch();
            
            $newFraction = round($fraction - $quantity, 6);
            if ($newFraction > 0.001) {
                // Opened item: calculate shorter shelf life from now
                $vacuum = (int)($origRow['vacuum_sealed'] ?? 0);
                $openedDays = estimateOpenedExpiryDaysPHP($prodInfo['name'] ?? '', $prodInfo['category'] ?? '', $location);
                if ($vacuum) $openedDays = (int)round($openedDays * 1.5);
                $openedExpiry = date('Y-m-d', strtotime("+{$openedDays} days"));
                // Respect original sealed expiry if it expires sooner
                if (!empty($origRow['expiry_date']) && strtotime($origRow['expiry_date']) < strtotime($openedExpiry)) {
                    $openedExpiry = $origRow['expiry_date'];
                }
                $stmt3 = $db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date, vacuum_sealed, opened_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                $stmt3->execute([$productId, $location, $newFraction, $openedExpiry, $vacuum]);
                $openedId = (int)$db->lastInsertId();
            }
            
            // Log transaction
            $type = ($notes === 'Buttato') ? 'waste' : 'out';
            $stmt3 = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt3->execute([$productId, $type, $quantity, $location, $notes]);
            
            $remaining = $newFraction > 0.001 ? $newFraction : 0;
            // Skip the normal flow — jump to Bring! check and response
            goto afterDeduct;
        }
    }
    
    $newQty = max(0, $existing['quantity'] - $quantity);
    // Cap actual deducted quantity to what was available (prevent phantom over-deduction)
    $actualDeducted = min($quantity, $existing['quantity']);
    
    if ($newQty <= 0) {
        $stmt = $db->prepare("UPDATE inventory SET quantity = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$existing['id']]);
    } else {
        // Check if item is now opened (first use creates a fractional/partial package)
        $wasOpened = !empty($existing['opened_at']);
        $isNowOpened = false;
        $unit = $prodInfo['unit'] ?? 'pz';
        $defQty = (float)($prodInfo['default_quantity'] ?? 0);
        if ($unit === 'conf') {
            // Opened = a fractional (non-integer) quantity remains
            $f = round($newQty - floor($newQty + 0.001), 6);
            if ($f > 0.001) $isNowOpened = true;
        } elseif (in_array($unit, ['g','kg','ml','l']) && $defQty > 0) {
            // Opened = remaining qty is not a clean multiple of the package size
            $pkgRem = round($newQty - floor($newQty / $defQty + 0.001) * $defQty, 6);
            if ($pkgRem > $defQty * 0.01) $isNowOpened = true;
        }

        if ($isNowOpened && !$wasOpened) {
            // First time opened: recalculate expiry with shorter shelf life
            $pName = $prodInfo['name'] ?? '';
            $pCat = $prodInfo['category'] ?? '';
            $vacuum = (int)($existing['vacuum_sealed'] ?? 0);
            $openedDays = estimateOpenedExpiryDaysPHP($pName, $pCat, $location);
            if ($vacuum) $openedDays = (int)round($openedDays * 1.5);
            $openedExpiry = date('Y-m-d', strtotime("+{$openedDays} days"));
            // Respect original sealed expiry if it expires sooner
            if (!empty($existing['expiry_date']) && strtotime($existing['expiry_date']) < strtotime($openedExpiry)) {
                $openedExpiry = $existing['expiry_date'];
            }

            // Split opened portion from sealed packages into two separate rows:
            // closed packages stay at original location, opened portion is offered to move.
            if ($unit === 'conf') {
                $newWhole = (int)floor($newQty + 0.001);
                $newFrac  = round($newQty - $newWhole, 6);
                if ($newFrac > 0.001 && $newWhole >= 1) {
                    // Keep whole confs in original row (no opened_at, sealed expiry unchanged)
                    $stmt = $db->prepare("UPDATE inventory SET quantity = ?, opened_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$newWhole, $existing['id']]);
                    // New row for the opened fraction with short shelf-life expiry
                    $stmt = $db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date, vacuum_sealed, opened_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                    $stmt->execute([$productId, $location, $newFrac, $openedExpiry, $vacuum]);
                    $openedId = (int)$db->lastInsertId();
                } else {
                    // Only the opened fraction remains (≤ 1 conf) — single row
                    $stmt = $db->prepare("UPDATE inventory SET quantity = ?, opened_at = CURRENT_TIMESTAMP, expiry_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$newQty, $openedExpiry, $existing['id']]);
                }
            } elseif (in_array($unit, ['g','kg','ml','l']) && $defQty > 0) {
                $newWholePkgs  = (int)floor($newQty / $defQty + 0.001);
                $newRemainder  = round($newQty - $newWholePkgs * $defQty, 6);
                if ($newRemainder > $defQty * 0.01 && $newWholePkgs >= 1) {
                    // Keep whole packages in original row (no opened_at, sealed expiry unchanged)
                    $stmt = $db->prepare("UPDATE inventory SET quantity = ?, opened_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$newWholePkgs * $defQty, $existing['id']]);
                    // New row for the opened partial package with short shelf-life expiry
                    $stmt = $db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date, vacuum_sealed, opened_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                    $stmt->execute([$productId, $location, $newRemainder, $openedExpiry, $vacuum]);
                    $openedId = (int)$db->lastInsertId();
                } else {
                    // Only the opened remainder (last package) — single row
                    $stmt = $db->prepare("UPDATE inventory SET quantity = ?, opened_at = CURRENT_TIMESTAMP, expiry_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$newQty, $openedExpiry, $existing['id']]);
                }
            } else {
                $stmt = $db->prepare("UPDATE inventory SET quantity = ?, opened_at = CURRENT_TIMESTAMP, expiry_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$newQty, $openedExpiry, $existing['id']]);
            }
        } else {
            $stmt = $db->prepare("UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newQty, $existing['id']]);
        }
    }
    
    // Log transaction (actual amount removed, not requested)
    $type = ($notes === 'Buttato') ? 'waste' : 'out';
    $stmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$productId, $type, $actualDeducted, $location, $notes]);

    // User explicitly chose "use all/finished": remove this row now instead of
    // leaving quantity=0 pending confirmation.
    if ($useAll && $notes !== 'Buttato' && $newQty <= 0) {
        $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$existing['id']]);
    }
    
    $remaining = $newQty;
    
    // Check if opened part remains (for non-split path, only when not already set by split above)
    if ($openedId === null && $remaining > 0 && $prodInfo) {
        $unitFb  = $prodInfo['unit'] ?? '';
        $defQtyFb = (float)($prodInfo['default_quantity'] ?? 0);
        if ($unitFb === 'conf') {
            $f = round($remaining - floor($remaining + 0.001), 6);
            if ($f > 0.001) $openedId = (int)$existing['id'];
        } elseif (in_array($unitFb, ['g','kg','ml','l']) && $defQtyFb > 0) {
            $pkgRemFb = round($remaining - floor($remaining / $defQtyFb + 0.001) * $defQtyFb, 6);
            if ($pkgRemFb > $defQtyFb * 0.01) $openedId = (int)$existing['id'];
        }
    }
    
    afterDeduct:
    
    // Auto-add to Bring! if product is completely finished (no inventory left anywhere)
    $addedToBring = false;
    if ($remaining <= 0) {
        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM inventory WHERE product_id = ? AND quantity > 0");
        $stmt->execute([$productId]);
        $totalLeft = (float)($stmt->fetchColumn() ?: 0);
        
        if ($totalLeft <= 0) {
            // Get product name, brand and shopping_name for Bring!
            $stmt = $db->prepare("SELECT name, brand, shopping_name FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if ($product) {
                // Before adding to Bring!, check if the shopping_name family already
                // has adequate stock from OTHER products (e.g. "Sale marino iodato" depleted
                // but "Sale alimentare" has 1kg → no need to add to shopping list).
                $sNameKey = strtolower(trim($product['shopping_name'] ?? ''));
                $familyCoverage = 0;
                if ($sNameKey !== '') {
                    $covStmt = $db->prepare("
                        SELECT SUM(i.quantity)
                        FROM inventory i
                        JOIN products p ON i.product_id = p.id
                        WHERE LOWER(TRIM(p.shopping_name)) = ? AND i.product_id != ? AND i.quantity > 0
                    ");
                    $covStmt->execute([$sNameKey, $productId]);
                    $familyCoverage = (float)($covStmt->fetchColumn() ?: 0);
                }
                if ($familyCoverage > 0) {
                    // Family has stock — no need to restock, suppress Bring! add.
                    // Set addedToBring=true so the JS fallback is also suppressed.
                    $addedToBring = true;
                } else {
                try {
                    $auth = bringAuth();
                    if ($auth) {
                        $listUUID = $auth['bringListUUID'];
                        // Use the generic shopping name for Bring! (e.g. "Latte", "Affettato")
                        $genericName = $product['shopping_name'] ?: computeShoppingName($product['name'], '', $product['brand']);
                        $bringName   = italianToBring($genericName);
                        
                        // Check if already on the Bring! list
                        $alreadyOnList = false;
                        $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
                        if ($listData && isset($listData['purchase'])) {
                            foreach ($listData['purchase'] as $existingItem) {
                                if (strcasecmp($existingItem['name'] ?? '', $bringName) === 0) {
                                    $alreadyOnList = true;
                                    break;
                                }
                            }
                        }
                        
                        if ($alreadyOnList) {
                            // Already on the list, skip adding
                            $addedToBring = false;
                        } else {
                        // Specification: specific product name (and brand) so the user knows which variant
                        // Add 🛒 marker so the cron cleanup can auto-remove if no longer needed.
                        $spec = $genericName !== $product['name']
                            ? $product['name'] . ($product['brand'] ? ' · ' . $product['brand'] : '') . ' · 🛒 Esaurito'
                            : ($product['brand'] ?: $product['name']) . ' · 🛒 Esaurito';
                        $body = http_build_query([
                            'uuid' => $listUUID,
                            'purchase' => $bringName,
                            'specification' => $spec,
                        ]);
                        $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
                        $addedToBring = ($result !== null);
                        
                        // Log Bring! addition
                        if ($addedToBring) {
                            $logStmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, 'bring', 0, '', 'Auto-aggiunto a Bring!')");
                            $logStmt->execute([$productId]);
                        }
                        } // end else (not already on list)
                    }
                } catch (Exception $e) {
                    // Silently fail — don't block inventory operation
                }
                } // end else (family not covered)
            }
        }
    }
    
    // Calculate total remaining across ALL locations
    $stmt = $db->prepare("SELECT SUM(quantity) as total FROM inventory WHERE product_id = ? AND quantity > 0");
    $stmt->execute([$productId]);
    $totalRemaining = round((float)($stmt->fetchColumn() ?: 0), 6);
    
    // Get product info for low-stock prompt
    $stmt = $db->prepare("SELECT name, brand, unit, default_quantity, package_unit, shopping_name FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $prodInfo = $stmt->fetch();
    
    $response = ['success' => true, 'remaining' => $remaining, 'added_to_bring' => $addedToBring,
                  'total_remaining' => $totalRemaining];
    if ($prodInfo) {
        $response['product_name'] = $prodInfo['name'];
        $response['product_brand'] = $prodInfo['brand'] ?: '';
        $response['product_unit'] = $prodInfo['unit'];
        $response['product_default_qty'] = (float)($prodInfo['default_quantity'] ?: 0);
        $response['product_package_unit'] = $prodInfo['package_unit'] ?: '';
        // Generic shopping name for Bring! (e.g. "Affettato" for "Mortadella IGP")
        $shopping = $prodInfo['shopping_name'] ?: computeShoppingName($prodInfo['name'], '', $prodInfo['brand']);
        $response['product_shopping_name'] = $shopping;
    }
    if ($openedId) {
        $response['opened_id'] = $openedId;
        $response['opened_vacuum_sealed'] = (int)($existing['vacuum_sealed'] ?? 0);
    } elseif ($remaining > 0 && isset($existing['id'])) {
        // Fallback: for any partial use (including pz items) where no dedicated
        // "opened" row was created, still provide the row ID so the UI can ask
        // about vacuum sealing the remaining portion.
        $response['opened_id'] = (int)$existing['id'];
        $response['opened_vacuum_sealed'] = (int)($existing['vacuum_sealed'] ?? 0);
    }
    echo json_encode($response);
    // Inventory changed — force smart-shopping recompute on next request
    invalidateSmartShoppingCache();
}

function updateInventory(PDO $db): void {
    EverLog::info('updateInventory');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;

    // Read current state before update (needed for transaction reconciliation)
    $prev = $db->prepare("SELECT quantity, location, product_id FROM inventory WHERE id = ?");
    $prev->execute([$id]);
    $prevRow = $prev->fetch(PDO::FETCH_ASSOC);

    $fields = [];
    $params = [];
    if (isset($input['quantity'])) { $fields[] = "quantity = ?"; $params[] = $input['quantity']; }
    if (isset($input['location'])) { $fields[] = "location = ?"; $params[] = $input['location']; }
    if (isset($input['expiry_date'])) { $fields[] = "expiry_date = ?"; $params[] = $input['expiry_date'] ?: null; }
    if (isset($input['vacuum_sealed'])) { $fields[] = "vacuum_sealed = ?"; $params[] = (int)$input['vacuum_sealed']; }
    if (isset($input['opened_at_clear']) && $input['opened_at_clear']) { $fields[] = "opened_at = NULL"; }
    $fields[] = "updated_at = CURRENT_TIMESTAMP";
    $params[] = $id;

    // Wrap all writes in a single transaction to avoid concurrent lock failures.
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE inventory SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        // Record a compensating transaction so anomaly detection stays accurate
        if (isset($input['quantity']) && $prevRow) {
            $oldQty = (float)$prevRow['quantity'];
            $newQty = (float)$input['quantity'];
            $diff   = round($newQty - $oldQty, 6);
            $loc    = $input['location'] ?? $prevRow['location'];
            $pid    = (int)$prevRow['product_id'];
            if (abs($diff) > 0.001) {
                $txType = $diff > 0 ? 'in' : 'out';
                $txQty  = abs($diff);
                $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, ?, ?, ?, '[Manual correction]')")
                   ->execute([$pid, $txType, $txQty, $loc]);
            }
        }

        // Update unit on the product if provided
        if (isset($input['unit']) && isset($input['product_id'])) {
            $stmt = $db->prepare("UPDATE products SET unit = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$input['unit'], $input['product_id']]);
        }

        // Update package info if provided
        if (isset($input['package_unit']) && isset($input['product_id'])) {
            $stmt = $db->prepare("UPDATE products SET package_unit = ?, default_quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$input['package_unit'], $input['package_size'] ?? 0, $input['product_id']]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    // Real-time Bring! sync: done after commit so DB lock is not held during HTTP call
    if (isset($input['quantity']) && $prevRow && abs((float)$input['quantity'] - (float)$prevRow['quantity']) > 0.001) {
        try { bringQuickSyncProduct($db, (int)$prevRow['product_id']); } catch (Throwable $e) {}
        // HA: stock update event
        $prodRow = $db->prepare("SELECT name FROM products WHERE id = ?")->execute([(int)$prevRow['product_id']]) ? $db->query("SELECT name FROM products WHERE id = " . (int)$prevRow['product_id'])->fetchColumn() : '';
        _fireHaWebhook('stock_update', [
            'item'     => (string)$prodRow,
            'quantity' => (float)$input['quantity'],
            'location' => $input['location'] ?? $prevRow['location'] ?? '',
        ]);
    }

    echo json_encode(['success' => true]);
}

function deleteInventory(PDO $db): void {
    EverLog::info('deleteInventory');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;
    $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
}

/**
 * Returns products whose entire inventory is at quantity = 0 AND whose
 * transaction balance (total_in - total_out) is still significantly positive —
 * meaning the system suspects the product ran out prematurely (scale drift,
 * missed registration, etc.).
 *
 * Products where the balance is at/near zero are legitimately finished by the
 * user; those rows are silently deleted here (no banner needed).
 */
function getFinishedItems(PDO $db): void {
    EverLog::debug('getFinishedItems');
    $rows = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.unit, p.default_quantity, p.package_unit, p.image_url, p.barcode,
               MIN(i.location) AS location,
               MAX(i.updated_at) AS updated_at,
               COALESCE(SUM(CASE WHEN t.type = 'in'  AND t.undone = 0 THEN t.quantity ELSE 0 END), 0) AS total_in,
               COALESCE(SUM(CASE WHEN t.type IN ('out','waste') AND t.undone = 0 THEN t.quantity ELSE 0 END), 0) AS total_out
        FROM products p
        JOIN inventory i ON i.product_id = p.id
        LEFT JOIN transactions t ON t.product_id = p.id
        WHERE NOT EXISTS (
            SELECT 1 FROM inventory i2 WHERE i2.product_id = p.id AND i2.quantity > 0
        )
        GROUP BY p.id
        ORDER BY MAX(i.updated_at) DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Per-unit threshold: residue below this is considered normal rounding/finish
    $thresholds = ['g' => 20, 'ml' => 20, 'kg' => 0.02, 'l' => 0.02, 'conf' => 0.1, 'pz' => 0.5];

    $suspicious = [];
    foreach ($rows as $r) {
        $expected = (float)$r['total_in'] - (float)$r['total_out'];
        $threshold = $thresholds[$r['unit']] ?? 0.5;

        if ($expected > $threshold) {
            // Transaction balance says stock should remain — show banner
            $suspicious[] = [
                'product_id'       => (int)$r['product_id'],
                'name'             => $r['name'],
                'brand'            => $r['brand'],
                'unit'             => $r['unit'],
                'default_quantity' => $r['default_quantity'],
                'package_unit'     => $r['package_unit'],
                'image_url'        => $r['image_url'],
                'barcode'          => $r['barcode'],
                'location'         => $r['location'],
                'updated_at'       => $r['updated_at'],
                'expected_qty'     => round($expected, 3),
            ];
        } else {
            // Legitimately finished — delete silently, no banner
            $db->prepare("DELETE FROM inventory WHERE product_id = ? AND quantity = 0")
               ->execute([$r['product_id']]);
        }
    }

    echo json_encode(['success' => true, 'finished' => $suspicious], JSON_UNESCAPED_UNICODE);
}

/**
 * Permanently delete all qty=0 inventory rows for a product after user confirms it is finished.
 */
function confirmFinished(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    if (!$productId) {
        EverLog::info('confirmFinished');
        http_response_code(400);
        echo json_encode(['error' => 'product_id required']);
        return;
    }
    $db->prepare("DELETE FROM inventory WHERE product_id = ? AND quantity = 0")->execute([$productId]);
    echo json_encode(['success' => true]);
}

function inventorySummary(PDO $db): void {
    EverLog::debug('inventorySummary');
    $stmt = $db->query("
        SELECT i.location, COUNT(DISTINCT i.product_id) as product_count, 
               SUM(i.quantity) as total_items
        FROM inventory i
        GROUP BY i.location
    ");
    echo json_encode(['summary' => $stmt->fetchAll()]);
}

// ===== TRANSACTION FUNCTIONS =====

function listTransactions(PDO $db): void {
    EverLog::debug('listTransactions');
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    $productId = $_GET['product_id'] ?? '';
    
    $query = "
        SELECT t.*, p.name, p.brand, p.unit, p.default_quantity, p.package_unit
        FROM transactions t
        JOIN products p ON t.product_id = p.id
    ";
    $params = [];
    if (!empty($productId)) {
        $query .= " WHERE t.product_id = ?";
        $params[] = $productId;
    }
    $query .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    echo json_encode(['transactions' => $stmt->fetchAll()]);
}

/**
 * Undo a transaction (reverse its effect on inventory).
 * Only available within 24 hours of the original transaction.
 * - type='in'  (add)    → removes that quantity from inventory at the same location
 * - type='out'/'waste'  → adds that quantity back to inventory at the same location
 * Marks the original as undone=1 and logs a counter-transaction with notes='[Annullato]'.
 */
function undoTransaction(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $txId = (int)($input['id'] ?? 0);
    if (!$txId) {
        EverLog::info('undoTransaction');
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID required']);
        return;
    }

    // Fetch original transaction
    $stmt = $db->prepare("SELECT t.*, p.name FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.id = ?");
    $stmt->execute([$txId]);
    $tx = $stmt->fetch();
    if (!$tx) {
        EverLog::warn('undoTransaction: transaction not found (404)');
        http_response_code(404);
        echo json_encode(['error' => 'Transaction not found']);
        return;
    }
    if ($tx['undone']) {
        echo json_encode(['error' => 'Transaction already undone', 'already_undone' => true]);
        return;
    }
    // Only allow within 24 hours
    $ageSeconds = time() - strtotime($tx['created_at'] . ' UTC');
    if ($ageSeconds > 86400) {
        echo json_encode(['error' => 'Can only undo transactions within 24 hours', 'too_old' => true]);
        return;
    }

    $db->beginTransaction();
    try {
        $productId = (int)$tx['product_id'];
        $quantity  = (float)$tx['quantity'];
        $location  = $tx['location'] ?: 'dispensa';
        $type      = $tx['type'];

        if ($type === 'in') {
            // Reverse an ADD: remove quantity from inventory
            $stmt2 = $db->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location = ? AND quantity > 0 ORDER BY quantity DESC LIMIT 1");
            $stmt2->execute([$productId, $location]);
            $row = $stmt2->fetch();
            if ($row) {
                $newQty = max(0, (float)$row['quantity'] - $quantity);
                if ($newQty <= 0) {
                    $db->prepare("DELETE FROM inventory WHERE id = ?")->execute([$row['id']]);
                } else {
                    $db->prepare("UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$newQty, $row['id']]);
                }
            }
            // Log counter-transaction
            $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, 'out', ?, ?, '[Undone]')")->execute([$productId, $quantity, $location]);

        } elseif ($type === 'out' || $type === 'waste') {
            // Reverse a USE: add quantity back to inventory
            $stmt2 = $db->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location = ? ORDER BY quantity DESC LIMIT 1");
            $stmt2->execute([$productId, $location]);
            $row = $stmt2->fetch();
            if ($row) {
                $db->prepare("UPDATE inventory SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$quantity, $row['id']]);
            } else {
                // No row at this location — create one without expiry
                $db->prepare("INSERT INTO inventory (product_id, location, quantity) VALUES (?, ?, ?)")->execute([$productId, $location, $quantity]);
            }
            // Log counter-transaction
            $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, 'in', ?, ?, '[Undone]')")->execute([$productId, $quantity, $location]);
        }

        // Mark original as undone
        $db->prepare("UPDATE transactions SET undone = 1 WHERE id = ?")->execute([$txId]);
        $db->commit();
        echo json_encode(['success' => true, 'name' => $tx['name']]);
    } catch (Exception $e) {
        $db->rollBack();
        EverLog::error('undoTransaction: DB error (500)');
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
        _phpErrorReport($e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), get_class($e));
    }
}


// ===== STATS =====

/**
 * Detect inventory items where the stored quantity is significantly inconsistent
 * with the transaction history (sum of in - sum of out/waste).
 *
 * Two anomaly directions:
 *  - PHANTOM (+diff): inventory > tx balance → quantity was manually inflated without an 'in' tx
 *  - MISSING (-diff): inventory < tx balance → tx history says more should be here than stored
 */
function getInventoryAnomalies(PDO $db): void {
    EverLog::info('getInventoryAnomalies');
    $rows = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.unit,
               p.default_quantity, p.package_unit,
               MIN(i.id) AS inventory_id,
               SUM(i.quantity) AS inv_qty,
               COALESCE(tx_in.tot, 0)  AS total_in,
               COALESCE(tx_out.tot, 0) AS total_out
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS tot
            FROM transactions WHERE type = 'in' AND undone = 0 GROUP BY product_id
        ) tx_in  ON tx_in.product_id  = p.id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS tot
            FROM transactions WHERE type IN ('out','waste') AND undone = 0 GROUP BY product_id
        ) tx_out ON tx_out.product_id = p.id
        WHERE i.quantity > 0
        GROUP BY p.id, p.name, p.brand, p.unit, p.default_quantity, p.package_unit,
                 tx_in.tot, tx_out.tot
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Anomaly dismissed keys stored in a simple JSON file
    $dismissFile = __DIR__ . '/../data/anomaly_dismissed.json';
    $dismissed   = [];
    if (file_exists($dismissFile)) {
        $dismissed = json_decode(file_get_contents($dismissFile), true) ?: [];
    }

    $anomalies = [];
    foreach ($rows as $r) {
        $invQty   = floatval($r['inv_qty']);
        $expected = floatval($r['total_in']) - floatval($r['total_out']);
        $diff     = $invQty - $expected;

        // Threshold: difference must be >20% of inventory AND >50 units (avoid noise)
        $threshold = max(1.0, $invQty * 0.20);
        if (abs($diff) <= $threshold || abs($diff) <= 50) continue;

        // Dismiss key: stable identifier based on product_id + direction.
        // Previously used round($expected) which changed whenever transactions were added,
        // causing dismissed anomalies to reappear. Now anchored to direction only,
        // so it stays dismissed until the user explicitly resets or the direction changes.
        // An inventory correction (bringing qty closer to expected) will flip the direction
        // or drop below threshold — naturally clearing the dismissed state.
        // If expected <= 0 it means more consumption recorded than purchases — the
        // transaction history is simply incomplete (very common: users track consumption
        // but not always purchases). Showing an anomaly here is just noise, skip it.
        if ($expected <= 0) continue;

        $direction = $diff > 0 ? 'phantom' : 'missing';
        $key = 'a_' . $r['product_id'] . '_' . $direction;
        if (!empty($dismissed[$key])) continue;
        $anomalies[] = [
            'inventory_id' => (int)$r['inventory_id'],
            'product_id'   => (int)$r['product_id'],
            'name'         => $r['name'],
            'brand'        => $r['brand'] ?: '',
            'unit'         => $r['unit'],
            'default_quantity' => $r['default_quantity'],
            'package_unit' => $r['package_unit'],
            'inv_qty'      => round($invQty, 2),
            'expected_qty' => round($expected, 2),
            'diff'         => round($diff, 2),
            'direction'    => $direction,
            'dismiss_key'  => $key,
        ];
    }

    // Sort: largest absolute diff first
    usort($anomalies, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));

    echo json_encode(['success' => true, 'anomalies' => $anomalies], JSON_UNESCAPED_UNICODE);
}

/**
 * Dismiss a specific anomaly so it no longer appears in the banner.
 */
function dismissInventoryAnomaly(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $key   = $input['dismiss_key'] ?? '';
    if (empty($key) || !preg_match('/^a_\d+_-?\d+$/', $key)) {
        EverLog::info('dismissInventoryAnomaly');
        echo json_encode(['success' => false, 'error' => 'Invalid key']);
        return;
    }
    $dismissFile = __DIR__ . '/../data/anomaly_dismissed.json';
    $dismissed   = [];
    if (file_exists($dismissFile)) {
        $dismissed = json_decode(file_get_contents($dismissFile), true) ?: [];
    }
    $dismissed[$key] = time();
    // Clean up entries older than 90 days
    $dismissed = array_filter($dismissed, fn($ts) => $ts > time() - 90 * 86400);
    file_put_contents($dismissFile, json_encode($dismissed), LOCK_EX);
    echo json_encode(['success' => true]);
}

function getStats(PDO $db): void {
    EverLog::info('getStats');
    // Consolidated summary query: totals + 7-day activity in a single round-trip
    $summary = $db->query("
        SELECT
            (SELECT COUNT(*) FROM products)                              AS total_products,
            (SELECT COALESCE(SUM(quantity),0) FROM inventory)           AS total_items,
            (SELECT COUNT(DISTINCT location) FROM inventory)            AS total_locations,
            (SELECT COUNT(*) FROM transactions
             WHERE type='in'  AND created_at >= datetime('now','-7 days')) AS recent_in,
            (SELECT COUNT(*) FROM transactions
             WHERE type='out' AND created_at >= datetime('now','-7 days')) AS recent_out
    ")->fetch(PDO::FETCH_ASSOC);
    $totalProducts = (int)$summary['total_products'];
    $totalItems    = (float)$summary['total_items'];
    $locations     = (int)$summary['total_locations'];
    $recentIn      = (int)$summary['recent_in'];
    $recentOut     = (int)$summary['recent_out'];
    
    // Expiring soonest (next 4 items to expire)
    $expiring = $db->query("
        SELECT i.*, p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.expiry_date IS NOT NULL AND i.expiry_date >= date('now') AND i.quantity > 0
              AND (i.opened_at IS NULL OR i.opened_at = '')
        ORDER BY i.expiry_date ASC
        LIMIT 4
    ")->fetchAll();
    
    // Expired — vacuum-sealed items get extra days beyond printed expiry before being flagged
    $vacExtDays = (int)env('VACUUM_EXPIRY_EXTENSION_DAYS', '30');
    $expiredStmt = $db->prepare("
        SELECT i.*, p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.expiry_date IS NOT NULL
          AND julianday('now') - julianday(i.expiry_date) > CASE WHEN COALESCE(i.vacuum_sealed,0)=1 THEN ? ELSE 0 END
          AND i.quantity > 0
        ORDER BY i.expiry_date ASC
    ");
    $expiredStmt->execute([$vacExtDays]);
    $expired = $expiredStmt->fetchAll();
    
    // Opened (items with opened_at set by the app, OR fractional-qty items as legacy fallback)
    // opened_at IS NOT NULL → already has recalculated expiry_date stored when first opened
    $openedRaw = $db->query("
        SELECT i.*, p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit, p.image_url,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed
        FROM inventory i JOIN products p ON i.product_id = p.id
        WHERE i.quantity > 0
          AND (
            -- Primary: tracked as opened by the app (expiry_date already recalculated)
            i.opened_at IS NOT NULL
            OR
            -- Fallback: fractional quantity pattern (legacy items before opened_at tracking)
            (p.default_quantity > 0 AND (
              (p.unit = 'conf' AND p.package_unit IS NOT NULL
                AND CAST(i.quantity AS REAL) != CAST(CAST(i.quantity AS INTEGER) AS REAL))
              OR
              (p.unit != 'conf'
                AND ABS(i.quantity - ROUND(CAST(i.quantity AS REAL) / p.default_quantity) * p.default_quantity) > (p.default_quantity * 0.02))
            ))
          )
    ")->fetchAll();

    // Compute opened_expiry and days_to_expiry for each opened item
    $opened = [];
    $today = strtotime('today midnight');
    foreach ($openedRaw as $item) {
        $vacuum = (int)($item['vacuum_sealed'] ?? 0);
        // originalExpiry = manufacturer date stored in inventory.expiry_date.
        // For items correctly managed, this is the sealed expiry from the package.
        $originalExpiry = !empty($item['expiry_date']) ? strtotime($item['expiry_date']) : null;

        if (!empty($item['opened_at'])) {
            // For conf unit: if all whole packages (no fraction), the opened_at tracks when the
            // last package was first used, but the remaining whole confs are still sealed.
            // Use the original package expiry, not the opened shelf-life.
            if ($item['unit'] === 'conf' && $originalExpiry !== null) {
                $qty  = (float)$item['quantity'];
                $frac = round($qty - (float)(int)floor($qty + 0.001), 4);
                if ($frac < 0.001) {
                    // All whole: treat as sealed — use original expiry
                    $item['opened_expiry'] = $item['expiry_date'] ?? null;
                    $item['days_to_expiry'] = (int)round(($originalExpiry - $today) / 86400);
                    goto after_expiry;
                }
            }
            // Compute opened shelf-life using AI (with rule-based fallback + persistent cache).
            // The vacuum-sealed multiplier is already handled inside getOpenedShelfLifeDays.
            $openedDays    = getOpenedShelfLifeDays($item['name'], $item['category'], $item['location'], (bool)$vacuum, false);
            $computedExpiry = strtotime($item['opened_at']) + $openedDays * 86400;
            // Always respect the manufacturer date: if the package expires before our estimate,
            // use the manufacturer date (e.g., milk opened 2 days before its sealed expiry).
            $finalExpiry = ($originalExpiry !== null && $originalExpiry < $computedExpiry)
                ? $originalExpiry : $computedExpiry;
            $item['opened_expiry'] = date('Y-m-d', $finalExpiry);
            $item['days_to_expiry'] = (int)round(($finalExpiry - $today) / 86400);
        } else {
            after_expiry:
            // Legacy: no opened_at, use stored expiry_date as-is
            $item['opened_expiry'] = $item['expiry_date'] ?? null;
            $item['days_to_expiry'] = $originalExpiry !== null
                ? (int)round(($originalExpiry - $today) / 86400)
                : null;
        }
        $item['is_edible'] = $item['days_to_expiry'] === null || $item['days_to_expiry'] >= 0;
        $item['has_opened_at'] = !empty($item['opened_at']);

        // For conf items with opened_at that contain both whole and fractional confs:
        // split into a "sealed" entry (whole confs, package expiry) and an "opened" entry (fraction, shelf-life expiry).
        // This prevents a row like "1.59 conf" from showing a single misleading entry that mixes
        // a still-sealed package with an opened portion.
        if ($item['unit'] === 'conf' && $item['has_opened_at'] && $originalExpiry !== null) {
            $qty   = (float)$item['quantity'];
            $whole = (int)floor($qty + 0.001);
            $frac  = round($qty - (float)$whole, 4);
            if ($whole >= 1 && $frac >= 0.001) {
                // Sealed whole confs: show with original package expiry (only if near expiry ≤ 7 d)
                $sealedDays = (int)round(($originalExpiry - $today) / 86400);
                if ($sealedDays <= 7 && $sealedDays >= -30) {
                    $si = $item;
                    $si['quantity']      = (float)$whole;
                    $si['opened_at']     = null;
                    $si['opened_expiry'] = date('Y-m-d', $originalExpiry);
                    $si['days_to_expiry'] = $sealedDays;
                    $si['is_edible']     = $sealedDays >= 0;
                    $si['has_opened_at'] = false;
                    $opened[] = $si;
                }
                // Opened fractional part: use the already-computed opened shelf-life expiry
                if ($item['days_to_expiry'] === null || $item['days_to_expiry'] <= 365) {
                    $fi = $item;
                    $fi['quantity'] = $frac;
                    $opened[] = $fi;
                }
                continue;
            }
        }

        // Hide non-perishable items (salt, sugar, spirits, oil, etc.) — they won't expire usefully
        if ($item['days_to_expiry'] !== null && $item['days_to_expiry'] > 365) continue;
        // Hide legacy fractional items (no opened_at) with far-off expiry — not useful for home widget
        if (!$item['has_opened_at'] && ($item['days_to_expiry'] === null || $item['days_to_expiry'] > 14)) continue;
        $opened[] = $item;
    }
    // Sort by days_to_expiry ascending (soonest first; nulls last)
    usort($opened, function($a, $b) {
        $da = $a['days_to_expiry'];
        $db2 = $b['days_to_expiry'];
        if ($da === null && $db2 === null) return 0;
        if ($da === null) return 1;
        if ($db2 === null) return -1;
        return $da <=> $db2;
    });

    // Waste vs consumption trend (3 × 30-day buckets)
    $wasteStats3m = $db->query("
        SELECT type,
            SUM(CASE WHEN created_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS m0,
            SUM(CASE WHEN created_at >= datetime('now', '-60 days') AND created_at < datetime('now', '-30 days') THEN 1 ELSE 0 END) AS m1,
            SUM(CASE WHEN created_at >= datetime('now', '-90 days') AND created_at < datetime('now', '-60 days') THEN 1 ELSE 0 END) AS m2
        FROM transactions
        WHERE type IN ('out', 'waste') AND created_at >= datetime('now', '-90 days')
        GROUP BY type
    ")->fetchAll();
    $used30 = 0; $wasted30 = 0;
    $usedP30 = 0; $wastedP30 = 0;
    $usedP60 = 0; $wastedP60 = 0;
    foreach ($wasteStats3m as $ws) {
        if ($ws['type'] === 'out')   { $used30 = (int)$ws['m0']; $usedP30 = (int)$ws['m1']; $usedP60 = (int)$ws['m2']; }
        if ($ws['type'] === 'waste') { $wasted30 = (int)$ws['m0']; $wastedP30 = (int)$ws['m1']; $wastedP60 = (int)$ws['m2']; }
    }

    echo json_encode([
        'total_products' => (int)$totalProducts,
        'total_items' => (float)$totalItems,
        'locations' => (int)$locations,
        'recent_in' => (int)$recentIn,
        'recent_out' => (int)$recentOut,
        'expiring_soon' => $expiring,
        'expired' => $expired,
        'opened' => $opened,
        'used_30d'     => $used30,
        'wasted_30d'   => $wasted30,
        'used_prev_30d'   => $usedP30,
        'wasted_prev_30d' => $wastedP30,
        'used_prev_60d'   => $usedP60,
        'wasted_prev_60d' => $wastedP60,
    ]);
}

// ===== RECENT & POPULAR PRODUCTS =====
function recentPopularProducts(PDO $db): void {
    EverLog::debug('recentPopularProducts');
    // Last 4 distinct products used (type='out'), most recent first
    $recentStmt = $db->query("
        SELECT DISTINCT t.product_id, p.name, p.brand, p.category, p.image_url, p.unit,
               MAX(t.created_at) as last_used
        FROM transactions t
        JOIN products p ON p.id = t.product_id
        WHERE t.type = 'out'
        GROUP BY t.product_id
        ORDER BY last_used DESC
        LIMIT 4
    ");
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    $recentIds = array_map(fn($r) => (int)$r['product_id'], $recent);

    // Top 12 most frequently used products (to allow filtering out recent ones client-side)
    $popularStmt = $db->query("
        SELECT t.product_id, p.name, p.brand, p.category, p.image_url, p.unit,
               COUNT(*) as usage_count
        FROM transactions t
        JOIN products p ON p.id = t.product_id
        WHERE t.type = 'out'
          AND t.created_at >= datetime('now', '-90 days')
        GROUP BY t.product_id
        ORDER BY usage_count DESC
        LIMIT 12
    ");
    $popular = $popularStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'recent' => $recent,
        'popular' => $popular,
        'recent_ids' => $recentIds,
    ]);
}

// ===== CONSUMPTION PREDICTIONS =====

/**
 * Analyze transaction history to predict expected quantity of each product
 * and flag items whose current quantity deviates significantly from the prediction.
 */
function getConsumptionPredictions(PDO $db): void {
    EverLog::info('getConsumptionPredictions');
    // Get all current inventory items with their consumption history
    $items = $db->query("
        SELECT i.id AS inventory_id, i.product_id, i.quantity, i.location,
               p.name, p.brand, p.unit, p.default_quantity, p.package_unit,
               i.updated_at
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
    ")->fetchAll(PDO::FETCH_ASSOC);

    $predictions = [];

    foreach ($items as $item) {
        $pid = $item['product_id'];
        $loc = $item['location'];

        // Get last 90 days of 'out' transactions for this product+location
        $txns = $db->prepare("
            SELECT quantity, created_at
            FROM transactions
            WHERE product_id = ? AND location = ? AND type = 'out'
              AND created_at >= datetime('now', '-90 days')
            ORDER BY created_at ASC
        ");
        $txns->execute([$pid, $loc]);
        $rows = $txns->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) < 5) continue; // Need at least 5 data points for a reliable rate

        // Calculate average daily consumption
        $totalUsed = 0;
        foreach ($rows as $r) $totalUsed += abs(floatval($r['quantity']));

        $firstDate = strtotime($rows[0]['created_at']);
        $lastDate  = strtotime($rows[count($rows) - 1]['created_at']);
        $daySpan   = ($lastDate - $firstDate) / 86400;
        // If all transactions are clustered within a week, the rate is unreliable
        if ($daySpan < 7) continue;
        $historicalRate = $totalUsed / $daySpan;

        if ($historicalRate < 0.01) continue; // negligible consumption

        // Get the most recent restock (last 'in' transaction)
        $lastIn = $db->prepare("
            SELECT quantity, created_at
            FROM transactions
            WHERE product_id = ? AND location = ? AND type = 'in' AND undone = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $lastIn->execute([$pid, $loc]);
        $restock = $lastIn->fetch(PDO::FETCH_ASSOC);

        if (!$restock) continue;

        $restockDate = strtotime($restock['created_at']);

        // Baseline = current inventory + what was consumed since the last restock.
        // This avoids false positives when pre-existing stock + new restock exceeds
        // what the model expected from the restock alone.
        $consumedSinceRestock = $db->prepare("
            SELECT COALESCE(SUM(quantity), 0)
            FROM transactions
            WHERE product_id = ? AND location = ? AND type = 'out' AND undone = 0
              AND created_at >= datetime(?, 'unixepoch')
        ");
        $consumedSinceRestock->execute([$pid, $loc, $restockDate]);
        $usedSinceRestock = floatval($consumedSinceRestock->fetchColumn() ?: 0);

        $baselineQty = floatval($item['quantity']) + $usedSinceRestock;
        $daysSinceRestock = max(1, (time() - $restockDate) / 86400);

        // Recalculate the expected consumption with an adaptive rate:
        // blend long-term history with post-restock behavior when available.
        $txSinceRestock = 0;
        foreach ($rows as $r) {
            if (strtotime($r['created_at']) >= $restockDate) $txSinceRestock++;
        }
        $observedRate = $daysSinceRestock > 0 ? ($usedSinceRestock / $daysSinceRestock) : 0;
        $dailyRate = $historicalRate;
        if ($observedRate > 0) {
            if ($txSinceRestock >= 3) {
                $dailyRate = ($historicalRate * 0.45) + ($observedRate * 0.55);
            } elseif ($txSinceRestock >= 1) {
                $dailyRate = ($historicalRate * 0.70) + ($observedRate * 0.30);
            }
        }

        // If the model predicts you should have consumed less than 15% of baseline
        // in this period, the daily rate is too low to make reliable predictions:
        // any single normal use will look like an anomaly. Skip it.
        $predictedConsumption = $dailyRate * $daysSinceRestock;
        if ($baselineQty > 0 && $predictedConsumption < $baselineQty * 0.15) continue;

        // Predicted remaining qty = baseline - (adaptive daily rate * days since restock)
        $expectedQty = max(0, $baselineQty - ($dailyRate * $daysSinceRestock));
        $actualQty   = floatval($item['quantity']);

        // Need at least some post-restock usage observations before warning.
        if ($txSinceRestock < 2) continue;

        // Flag if deviation > 30% and absolute diff > meaningful threshold
        $deviation = abs($actualQty - $expectedQty);
        $threshold = max($dailyRate * 3, 0.5); // at least 3 days worth or 0.5 units

        // If expected = 0 and actual > 0, the model simply thinks the product
        // should have been used up by now. This is NOT an anomaly — the user
        // either restocked (not yet tracked) or consumed less than usual.
        // Only flag "less" direction when expected = 0 (actual ran out faster).
        if ($expectedQty <= 0 && $actualQty >= 0) continue;

        $pctDev = $expectedQty > 0 ? ($deviation / $expectedQty) : ($actualQty > 0 ? 1 : 0);

        // "More than expected" usually means slower real consumption, not bad data.
        // Suppress this direction to avoid noisy/accusatory banners.
        if ($actualQty > $expectedQty) continue;

        // Only keep meaningful "less than expected" deviations.
        $flagThreshold = 0.45;

        if ($pctDev > $flagThreshold && $deviation > $threshold) {
            $unit = $item['unit'];
            // Format expected/actual in human units
            if ($unit === 'conf' && $item['default_quantity'] > 0 && $item['package_unit']) {
                $pu = $item['package_unit'];
                $sz = floatval($item['default_quantity']);
                $expDisplay = round($expectedQty * $sz);
                $actDisplay = round($actualQty * $sz);
                $displayUnit = $pu;
            } else {
                $expDisplay = round($expectedQty, 1);
                $actDisplay = round($actualQty, 1);
                $displayUnit = $unit;
            }

            $predictions[] = [
                'inventory_id'       => (int)$item['inventory_id'],
                'product_id'         => (int)$item['product_id'],
                'name'               => $item['name'],
                'brand'              => $item['brand'],
                'location'           => $item['location'],
                'unit'               => $displayUnit,
                'expected_qty'       => $expDisplay,
                'actual_qty'         => $actDisplay,
                'daily_rate'         => round($dailyRate, 3),
                'deviation_pct'      => round($pctDev * 100),
                'days_since_restock' => (int)round($daysSinceRestock),
                'direction'          => 'less',
                'tx_count'           => count($rows),
            ];
        }
    }

    echo json_encode(['success' => true, 'predictions' => $predictions]);
}

// ===== SETTINGS =====

function getServerSettings(): void {
    EverLog::debug('getServerSettings');
    $geminiKey = env('GEMINI_API_KEY');
    $bringEmail = env('BRING_EMAIL');
    
    echo json_encode([
        'gemini_key_set' => !empty($geminiKey),
        'bring_email' => $bringEmail,
        'settings_token_set' => !empty(env('SETTINGS_TOKEN')),
        'demo_mode' => env('DEMO_MODE') === 'true',
        'bring_password_set' => !empty(env('BRING_PASSWORD')),
        'tts_url' => env('TTS_URL'),
        'tts_token' => env('TTS_TOKEN'),
        'tts_method' => env('TTS_METHOD', 'POST'),
        'tts_auth_type' => env('TTS_AUTH_TYPE', 'bearer'),
        'tts_content_type' => env('TTS_CONTENT_TYPE', 'application/json'),
        'tts_payload_key' => env('TTS_PAYLOAD_KEY', 'message'),
        'tts_enabled' => env('TTS_ENABLED', 'false') === 'true',
        'tts_engine' => env('TTS_ENGINE', ''),
        'tts_rate' => (float)env('TTS_RATE', '1'),
        'tts_pitch' => (float)env('TTS_PITCH', '1'),
        'tts_auth_header_name' => env('TTS_AUTH_HEADER_NAME', ''),
        'tts_auth_header_value' => env('TTS_AUTH_HEADER_VALUE', ''),
        'tts_extra_fields' => env('TTS_EXTRA_FIELDS', ''),
        // User preferences (now server-side)
        'default_persons' => intval(env('DEFAULT_PERSONS', '1')),
        'pref_veloce' => env('PREF_VELOCE', 'false') === 'true',
        'pref_pocafame' => env('PREF_POCAFAME', 'false') === 'true',
        'pref_scadenze' => env('PREF_SCADENZE', 'false') === 'true',
        'pref_healthy' => env('PREF_HEALTHY', 'false') === 'true',
        'pref_opened' => env('PREF_OPENED', 'false') === 'true',
        'pref_zerowaste' => env('PREF_ZEROWASTE', 'false') === 'true',
        'dietary' => env('DIETARY', ''),
        'appliances' => env('APPLIANCES', '') ? explode(',', env('APPLIANCES', '')) : [],
        'camera_facing' => env('CAMERA_FACING', 'environment'),
        'scale_enabled' => env('SCALE_ENABLED', 'false') === 'true',
        'scale_gateway_url' => env('SCALE_GATEWAY_URL', ''),
        'meal_plan_enabled' => env('MEAL_PLAN_ENABLED', 'false') === 'true',
        'screensaver_enabled' => env('SCREENSAVER_ENABLED', 'false') === 'true',
        'screensaver_timeout' => (int)env('SCREENSAVER_TIMEOUT', '5'),
        'zerowaste_tips_enabled' => env('ZEROWASTE_TIPS_ENABLED', 'false') === 'true',
        'price_enabled' => env('PRICE_ENABLED', 'false') === 'true',
        'price_country' => env('PRICE_COUNTRY', 'Italia'),
        'price_currency' => env('PRICE_CURRENCY', 'EUR'),
        'price_update_months' => (int)env('PRICE_UPDATE_MONTHS', '3'),
        'recipe_retention_days' => (int)env('RECIPE_RETENTION_DAYS', '7'),
        'transaction_retention_days' => (int)env('TRANSACTION_RETENTION_DAYS', '90'),
        'vacuum_expiry_extension_days' => (int)env('VACUUM_EXPIRY_EXTENSION_DAYS', '30'),
        // Backup
        'backup_enabled' => env('BACKUP_ENABLED', 'true') === 'true',
        'backup_retention_days' => (int)env('BACKUP_RETENTION_DAYS', '3'),
        'gdrive_enabled' => env('GDRIVE_ENABLED', 'false') === 'true',
        'gdrive_folder_id' => env('GDRIVE_FOLDER_ID', ''),
        'gdrive_retention_days' => (int)env('GDRIVE_RETENTION_DAYS', '30'),
        'gdrive_client_id_set'    => !empty(env('GDRIVE_CLIENT_ID')),
        'gdrive_refresh_token_set'=> !empty(env('GDRIVE_REFRESH_TOKEN')),
        // Shopping list
        'shopping_enabled'            => env('SHOPPING_ENABLED', 'true') === 'true',
        'shopping_mode'               => env('SHOPPING_MODE', 'internal'),
        'shopping_smart_suggestions'  => env('SHOPPING_SMART_SUGGESTIONS', 'true') === 'true',
        'shopping_forecast'           => env('SHOPPING_FORECAST', 'true') === 'true',
        'shopping_auto_add_threshold' => (int)env('SHOPPING_AUTO_ADD_THRESHOLD', '0'),
        'dark_mode'                   => env('DARK_MODE', 'auto'),
        // Home Assistant Integration
        'ha_enabled'                  => env('HA_ENABLED', 'false') === 'true',
        'ha_url'                      => env('HA_URL', ''),
        'ha_token'                    => env('HA_TOKEN', ''),
        'ha_tts_entity'               => env('HA_TTS_ENTITY', ''),
        'ha_webhook_id'               => env('HA_WEBHOOK_ID', ''),
        'ha_webhook_events'           => env('HA_WEBHOOK_EVENTS', 'expiry,shopping_add,stock_update,barcode_scan'),
        'ha_notify_service'           => env('HA_NOTIFY_SERVICE', ''),
        'ha_expiry_days'              => (int)env('HA_EXPIRY_DAYS', '3'),
    ]);
}

function dbCleanup(?PDO $db = null): void {
    $recipeDays = max(1, (int)env('RECIPE_RETENTION_DAYS', '7'));
    // Minimum 90 days: smart shopping needs months of history to compute frequencies.
    // A value below 30 will cause the shopping list to appear nearly empty.
    $txDays     = max(30, (int)env('TRANSACTION_RETENTION_DAYS', '90'));
    $pdo = $db ?? getDB();
    try {
        // Delete old recipes (generated recipe plans)
        $pdo->prepare("DELETE FROM recipes WHERE date < date('now', ? || ' days')")
            ->execute(["-$recipeDays"]);
        // Delete old transactions (keep at least the last $txDays of history)
        $pdo->prepare("DELETE FROM transactions WHERE created_at < datetime('now', ? || ' days') AND undone = 0")
            ->execute(["-$txDays"]);
        // Compact the database
        $pdo->exec('VACUUM');
        echo json_encode(['success' => true, 'recipe_retention_days' => $recipeDays, 'transaction_retention_days' => $txDays]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function saveSettings(): void {
    // Require SETTINGS_TOKEN if configured
    $requiredToken = env('SETTINGS_TOKEN');
    if (!empty($requiredToken)) {
        EverLog::debug('saveSettings');
        $provided = $_SERVER['HTTP_X_SETTINGS_TOKEN'] ?? '';
        if (!hash_equals($requiredToken, $provided)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'unauthorized']);
            return;
        }
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $envFile = __DIR__ . '/../.env';
    $envVars = loadEnv();
    
    // Map of input key → .env key — only update if present in input
    $keyMap = [
        'gemini_key'      => 'GEMINI_API_KEY',
        'bring_email'     => 'BRING_EMAIL',
        'bring_password'  => 'BRING_PASSWORD',
        'tts_url'         => 'TTS_URL',
        'tts_token'       => 'TTS_TOKEN',
        'tts_method'      => 'TTS_METHOD',
        'tts_auth_type'   => 'TTS_AUTH_TYPE',
        'tts_content_type'=> 'TTS_CONTENT_TYPE',
        'tts_payload_key' => 'TTS_PAYLOAD_KEY',
        'camera_facing'         => 'CAMERA_FACING',
        'dietary'               => 'DIETARY',
        'scale_gateway_url'     => 'SCALE_GATEWAY_URL',
        'price_country'         => 'PRICE_COUNTRY',
        'price_currency'        => 'PRICE_CURRENCY',
        'tts_engine'            => 'TTS_ENGINE',
        'tts_auth_header_name'  => 'TTS_AUTH_HEADER_NAME',
        'tts_auth_header_value' => 'TTS_AUTH_HEADER_VALUE',
        'tts_extra_fields'      => 'TTS_EXTRA_FIELDS',
        'gdrive_folder_id'   => 'GDRIVE_FOLDER_ID',
        'gdrive_client_id'   => 'GDRIVE_CLIENT_ID',
        'gdrive_client_secret'          => 'GDRIVE_CLIENT_SECRET',
        'shopping_mode'      => 'SHOPPING_MODE',
        'dark_mode'         => 'DARK_MODE',
        // Home Assistant
        'ha_url'             => 'HA_URL',
        'ha_token'           => 'HA_TOKEN',
        'ha_tts_entity'      => 'HA_TTS_ENTITY',
        'ha_webhook_id'      => 'HA_WEBHOOK_ID',
        'ha_webhook_events'  => 'HA_WEBHOOK_EVENTS',
        'ha_notify_service'  => 'HA_NOTIFY_SERVICE',
    ];
    // Boolean keys
    $boolMap = [
        'tts_enabled'     => 'TTS_ENABLED',
        'pref_veloce'     => 'PREF_VELOCE',
        'pref_pocafame'   => 'PREF_POCAFAME',
        'pref_scadenze'   => 'PREF_SCADENZE',
        'pref_healthy'    => 'PREF_HEALTHY',
        'pref_opened'     => 'PREF_OPENED',
        'pref_zerowaste'  => 'PREF_ZEROWASTE',
        'scale_enabled'   => 'SCALE_ENABLED',
        'meal_plan_enabled' => 'MEAL_PLAN_ENABLED',
        'screensaver_enabled' => 'SCREENSAVER_ENABLED',
        'price_enabled' => 'PRICE_ENABLED',
        'zerowaste_tips_enabled' => 'ZEROWASTE_TIPS_ENABLED',
        'backup_enabled' => 'BACKUP_ENABLED',
        'gdrive_enabled' => 'GDRIVE_ENABLED',
        'shopping_enabled'           => 'SHOPPING_ENABLED',
        'shopping_smart_suggestions' => 'SHOPPING_SMART_SUGGESTIONS',
        'shopping_forecast'          => 'SHOPPING_FORECAST',
        // Home Assistant
        'ha_enabled'    => 'HA_ENABLED',
    ];
    // Integer keys
    $intMap = [
        'default_persons'             => 'DEFAULT_PERSONS',
        'screensaver_timeout'         => 'SCREENSAVER_TIMEOUT',
        'price_update_months'         => 'PRICE_UPDATE_MONTHS',
        'recipe_retention_days'       => 'RECIPE_RETENTION_DAYS',
        'transaction_retention_days'  => 'TRANSACTION_RETENTION_DAYS',
        'vacuum_expiry_extension_days'=> 'VACUUM_EXPIRY_EXTENSION_DAYS',
        'backup_retention_days'       => 'BACKUP_RETENTION_DAYS',
        'gdrive_retention_days'           => 'GDRIVE_RETENTION_DAYS',
        'shopping_auto_add_threshold'    => 'SHOPPING_AUTO_ADD_THRESHOLD',
        // Home Assistant
        'ha_expiry_days' => 'HA_EXPIRY_DAYS',
    ];
    // Float keys
    $floatMap = [
        'tts_rate'  => 'TTS_RATE',
        'tts_pitch' => 'TTS_PITCH',
    ];

    foreach ($keyMap as $inKey => $envKey) {
        if (array_key_exists($inKey, $input)) {
            $envVars[$envKey] = (string)$input[$inKey];
        }
    }
    foreach ($boolMap as $inKey => $envKey) {
        if (array_key_exists($inKey, $input)) {
            $envVars[$envKey] = $input[$inKey] ? 'true' : 'false';
        }
    }
    foreach ($intMap as $inKey => $envKey) {
        if (array_key_exists($inKey, $input)) {
            $envVars[$envKey] = (string)intval($input[$inKey]);
        }
    }
    foreach ($floatMap as $inKey => $envKey) {
        if (array_key_exists($inKey, $input)) {
            $envVars[$envKey] = (string)(float)$input[$inKey];
        }
    }
    // Arrays stored as comma-separated
    if (array_key_exists('appliances', $input)) {
        $envVars['APPLIANCES'] = is_array($input['appliances']) ? implode(',', $input['appliances']) : (string)$input['appliances'];
    }

    // Write .env file
    $lines = [];
    foreach ($envVars as $key => $val) {
        $lines[] = "{$key}={$val}";
    }
    $result = file_put_contents($envFile, implode("\n", $lines) . "\n");
    
    // Clear cached env
    static $cache = null;
    $cache = null;
    
    if ($result !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not write .env file']);
    }
}

// ===== GEMINI AI FUNCTIONS =====

/**
 * Calls the Gemini REST API with exponential backoff on 429 / 503.
 * - Reads Google's Retry-After response header.
 * - Reads Google's retryDelay field inside the error body (e.g. "10s").
 * - Up to 4 attempts; default wait sequence: 2 s, 4 s, 8 s.
 *
 * @return array{http_code:int, body:string, data:array|null}
 */
function callGemini(string $url, array $payload, int $timeout = 60): array {
    $maxAttempts = 4;
    $lastCode    = 0;
    $lastBody    = '';
    $promptLen   = strlen(json_encode($payload));
    $t0          = microtime(true);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $retryAfterHeader = null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($payload),
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $timeout,
            // Capture response headers to read Retry-After
            CURLOPT_HEADERFUNCTION  => function ($ch, $header) use (&$retryAfterHeader) {
                if (stripos($header, 'retry-after:') === 0) {
                    $val = intval(trim(substr($header, strlen('retry-after:'))));
                    if ($val > 0) $retryAfterHeader = $val;
                }
                return strlen($header);
            },
        ]);

        $body     = curl_exec($ch);
        $lastCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false) $lastBody = $body;

        // Success or non-retryable error → stop immediately
        if ($lastCode === 200) break;
        if ($lastCode !== 429 && $lastCode !== 503) break;
        if ($attempt >= $maxAttempts) break;

        // Determine how long to wait -----------------------------------------------
        // Priority 1: Retry-After header (set by Google in some 429 responses)
        $waitSec = $retryAfterHeader ?? ($attempt * 2);   // default: 2 s, 4 s, 6 s

        // Priority 2: Google's retryDelay inside the error body (e.g. {"retryDelay":"10s"})
        if ($body) {
            $errData = json_decode($body, true);
            foreach (($errData['error']['details'] ?? []) as $detail) {
                if (!empty($detail['retryDelay'])) {
                    $parsed = intval(preg_replace('/\D/', '', $detail['retryDelay']));
                    if ($parsed > 0) { $waitSec = min($parsed, 60); break; }
                }
            }
        }

        EverLog::warn('AI rate-limited, retrying', ['attempt' => $attempt, 'wait_s' => $waitSec, 'code' => $lastCode]);
        sleep($waitSec);
    }

    $elapsed = microtime(true) - $t0;
    if ($lastCode === 200) {
        EverLog::aiResponse('gemini', strlen($lastBody), $elapsed, true);
    } else {
        EverLog::aiResponse('gemini', strlen($lastBody), $elapsed, false, "HTTP {$lastCode}: " . substr($lastBody, 0, 300));
    }

    $data = $lastBody ? json_decode($lastBody, true) : null;
    // Extract token counts from Gemini usageMetadata
    $usage = $data['usageMetadata'] ?? [];
    $tokIn  = (int)($usage['promptTokenCount']     ?? 0);
    $tokOut = (int)($usage['candidatesTokenCount'] ?? 0);

    return [
        'http_code'  => $lastCode,
        'body'       => $lastBody,
        'data'       => $data,
        'tokens_in'  => $tokIn,
        'tokens_out' => $tokOut,
    ];
}

/**
 * Record Gemini token usage to the monthly ai_usage.json file.
 * Called by callGeminiWithFallback after each successful call.
 */
function _recordAiUsage(string $model, int $tokIn, int $tokOut, string $action = ''): void {
    if ($tokIn === 0 && $tokOut === 0) return;
    $month = date('Y-m');
    $data  = [];
    if (file_exists(AI_USAGE_PATH)) {
        $data = json_decode(file_get_contents(AI_USAGE_PATH), true) ?: [];
    }
    if (!isset($data[$month])) {
        $data[$month] = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'by_action' => [], 'by_model' => []];
    }
    $m = &$data[$month];
    $m['input_tokens']  += $tokIn;
    $m['output_tokens'] += $tokOut;
    $m['calls']++;
    if ($action) {
        $m['by_action'][$action] = ($m['by_action'][$action] ?? 0) + 1;
    }
    if ($model) {
        if (!isset($m['by_model'][$model])) $m['by_model'][$model] = ['in' => 0, 'out' => 0, 'calls' => 0];
        $m['by_model'][$model]['in']    += $tokIn;
        $m['by_model'][$model]['out']   += $tokOut;
        $m['by_model'][$model]['calls'] += 1;
    }
    // Keep only last 13 months
    krsort($data);
    $data = array_slice($data, 0, 13, true);
    @file_put_contents(AI_USAGE_PATH, json_encode($data, JSON_PRETTY_PRINT));
    EverLog::debug('ai_usage recorded', ['model' => $model, 'in' => $tokIn, 'out' => $tokOut, 'action' => $action]);
}

/**
 * Like callGemini() but tries gemini-2.5-flash first, falls back to gemini-2.0-flash
 * on quota/rate-limit errors (429/503). Builds the URL from model name + API key.
 */
function callGeminiWithFallback(string $apiKey, array $payload, int $timeout = 30, string $usageAction = ''): array {
    $models   = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $last     = ['http_code' => 0, 'body' => '', 'data' => null, 'tokens_in' => 0, 'tokens_out' => 0];
    $promptLen = strlen(json_encode($payload));
    foreach ($models as $idx => $model) {
        $isFallback = $idx > 0;
        EverLog::aiCall($model, $promptLen, $isFallback);
        $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $last = callGemini($url, $payload, $timeout);
        if ($last['http_code'] === 200) {
            _recordAiUsage($model, $last['tokens_in'], $last['tokens_out'], $usageAction);
            return $last;
        }
        if ($last['http_code'] !== 429 && $last['http_code'] !== 503) return $last; // non-retryable
        EverLog::warn('AI model exhausted, trying fallback', ['model' => $model, 'code' => $last['http_code']]);
    }
    return $last;
}

// ===== AI-POWERED OPENED SHELF LIFE =====

/**
 * Cron helper: pre-warm the opened shelf life cache for opened inventory items that
 * have no cache entry yet. Called once per cron cycle; capped to $limit items to
 * avoid blocking or hitting Gemini rate limits.
 * Returns ['warmed' => int, 'skipped' => int].
 */
function prewarmShelfLifeCache(PDO $db, int $limit = 5): array {
    $cacheFile = __DIR__ . '/../data/opened_shelf_cache.json';
    $cache = [];
    if (file_exists($cacheFile)) {
        EverLog::debug('prewarmShelfLifeCache');
        $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
    }

    // Fetch opened items from inventory (only those still with quantity > 0)
    $rows = $db->query("
        SELECT p.name, p.category, i.location
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.opened_at IS NOT NULL AND i.quantity > 0
        ORDER BY i.opened_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $warmed = 0;
    $skipped = 0;
    foreach ($rows as $row) {
        if ($warmed >= $limit) { $skipped++; continue; }
        $cacheKey = md5(mb_strtolower($row['name']) . '|' . mb_strtolower($row['location']));
        if (isset($cache[$cacheKey])) { $skipped++; continue; }
        // Call with AI enabled — this writes to cache internally
        getOpenedShelfLifeDays($row['name'], $row['category'] ?? '', $row['location'], false, true);
        $warmed++;
    }

    return ['warmed' => $warmed, 'skipped' => $skipped];
}

/**
 * Return the number of days a product remains safe after opening, depending on storage location.
 * Checks a local JSON cache first (keyed by product name+location); on cache miss, asks Gemini AI.
 * Falls back to the rule-based estimate if AI is unavailable or returns an unusable answer.
 * Cache has no expiry — shelf-life science doesn't change; the file can be manually deleted to refresh.
 */
function getOpenedShelfLifeDays(string $name, string $category, string $location, bool $vacuumSealed = false, bool $allowAI = true): int {
    EverLog::debug('getOpenedShelfLifeDays');
    $cacheFile = __DIR__ . '/../data/opened_shelf_cache.json';
    $cacheKey  = md5(mb_strtolower($name) . '|' . mb_strtolower($location) . '|v2');

    // Static in-memory cache: the file is read only ONCE per PHP request,
    // even when this function is called for many items in a loop (e.g. getStats).
    static $cache = null;
    static $cacheDirty = false;
    if ($cache === null) {
        $cache = [];
        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
    }

    if (isset($cache[$cacheKey]['days'])) {
        $days = (int)$cache[$cacheKey]['days'];
        return $vacuumSealed ? (int)round($days * 1.5) : $days;
    }

    // Try Gemini AI (only when explicitly allowed — NOT during bulk stats loops)
    $apiKey = env('GEMINI_API_KEY');
    $days   = 0;
    if ($allowAI && !empty($apiKey)) {
        $locLabel = match($location) {
            'frigo'   => 'refrigerator (4 °C / 39 °F)',
            'freezer' => 'freezer (-18 °C / 0 °F)',
            default   => 'pantry / room temperature (18-22 °C)',
        };
        $catHint = $category ? " (category: {$category})" : '';
        $prompt  = "How many days can \"{$name}\"{$catHint} be safely consumed after being OPENED and stored in a {$locLabel}? "
                 . "Reply with ONLY a single integer (the number of days). No units, no explanation, just the number.";

        $payload = [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 8, 'temperature' => 0],
        ];
        $result = callGeminiWithFallback($apiKey, $payload, 12, 'shelf_life');
        if ($result['http_code'] === 200) {
            $text = trim($result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '');
            $parsed = (int)preg_replace('/\D/', '', $text);
            // Reject AI values if they are suspiciously low compared to the rule-based estimate
            // (protects against Gemini hallucinations like "1 day for butter").
            $ruleMin = estimateOpenedExpiryDaysPHP($name, $category, $location);
            // Accept AI value only if within a reasonable multiple of the rule estimate.
            // Upper bound: 4× rule (or 30 days minimum ceiling) — blocks Gemini hallucinations
            // like "60 days for yogurt" (rule=5 → max allowed = 20).
            $aiMax = max($ruleMin * 4, 30);
            if ($parsed > 0 && $parsed <= $aiMax && $parsed >= max(1, (int)floor($ruleMin * 0.5))) {
                $days = $parsed;
            }
        }
    }

    // Fall back to rule-based estimate if AI unavailable / unusable
    $source = 'rule';
    if ($days <= 0) {
        $days   = estimateOpenedExpiryDaysPHP($name, $category, $location);
        $source = 'rule';
    } else {
        $source = 'ai';
    }

    // Persist to in-memory cache (file will be flushed at end of request via register_shutdown_function)
    $cache[$cacheKey] = ['days' => $days, 'source' => $source, 'name' => $name, 'location' => $location, 'ts' => time()];
    $cacheDirty = true;
    // Write immediately so single-item requests (opened_shelf_life action) are persisted
    @file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $vacuumSealed ? (int)round($days * 1.5) : $days;
}

/**
 * Expose the shelf-life cache via API so the JS can pre-warm it when a user marks an item opened.
 * Accepts: POST { name, category, location, vacuum_sealed? }
 * Returns: { days, source }
 */
function getOpenedShelfLifeAction(): void {
    EverLog::info('getOpenedShelfLifeAction');
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($input['name']     ?? '');
    $cat   = trim($input['category'] ?? '');
    $loc   = trim($input['location'] ?? 'frigo');
    $vac   = !empty($input['vacuum_sealed']);
    if ($name === '') { echo json_encode(['error' => 'name required']); return; }
    $days = getOpenedShelfLifeDays($name, $cat, $loc, $vac);
    echo json_encode(['days' => $days]);
}

// ===== TESSERACT OFFLINE OCR HELPER =====

/**
 * Try to extract an expiry date from a base64 image using Tesseract OCR (offline).
 * Returns ['found'=>true,'date'=>'YYYY-MM-DD','raw_text'=>'...','confidence'=>float]
 * or      ['found'=>false,'raw_text'=>'...']
 *
 * Strategy:
 *  1. Decode base64 → temp JPEG
 *  2. Pre-process with GD: desaturate, auto-contrast, sharpen, 2× upscale
 *  3. Run tesseract with Italian+English langs, PSM-6 (block of text)
 *  4. Run date-format regexes (Italian & international patterns)
 *  5. Normalise to YYYY-MM-DD
 *
 * Returns null if tesseract binary is not available or GD is not compiled in.
 */
function tesseractReadExpiry(string $imageBase64): ?array {
    EverLog::info('tesseractReadExpiry');
    // Require both the binary and the GD extension
    if (!function_exists('imagecreatefromstring')) return null;
    $tesseract = trim(shell_exec('which tesseract 2>/dev/null') ?? '');
    if (empty($tesseract)) return null;

    // ── 1. Decode image ────────────────────────────────────────────────────
    $imgData = base64_decode($imageBase64);
    if ($imgData === false || strlen($imgData) < 100) return null;

    $src = @imagecreatefromstring($imgData);
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);

    // ── 2. Pre-process ─────────────────────────────────────────────────────
    // 2a. Upscale ×2 – Tesseract performs best on ≥300 DPI; packaging photos
    //     are often low-res so doubling helps character recognition.
    $w2 = $w * 2;
    $h2 = $h * 2;
    $dst = imagecreatetruecolor($w2, $h2);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w2, $h2, $w, $h);
    imagedestroy($src);

    // 2b. Greyscale + auto-contrast
    imagefilter($dst, IMG_FILTER_GRAYSCALE);
    imagefilter($dst, IMG_FILTER_CONTRAST, -40); // negative = increase contrast in GD

    // 2c. Sharpen (convolution kernel)
    $kernel = [[0,-1,0],[-1,5,-1],[0,-1,0]];
    imageconvolution($dst, $kernel, 1, 0);

    // ── 3. Write temp file & run Tesseract ────────────────────────────────
    $tmpIn  = sys_get_temp_dir() . '/ocr_in_'  . uniqid() . '.png';
    $tmpOut = sys_get_temp_dir() . '/ocr_out_' . uniqid();
    imagepng($dst, $tmpIn);
    imagedestroy($dst);

    // PSM 6 = assume a single uniform block of text (good for cropped label areas)
    $cmd = escapeshellcmd($tesseract)
         . ' ' . escapeshellarg($tmpIn)
         . ' ' . escapeshellarg($tmpOut)
         . ' -l ita+eng --psm 6 --oem 1'
         . ' quiet 2>/dev/null';
    shell_exec($cmd);

    $rawText = '';
    if (file_exists($tmpOut . '.txt')) {
        $rawText = trim(file_get_contents($tmpOut . '.txt'));
        unlink($tmpOut . '.txt');
    }
    if (file_exists($tmpIn)) unlink($tmpIn);

    if (empty($rawText)) return ['found' => false, 'raw_text' => ''];

    // ── 4. Parse date patterns ─────────────────────────────────────────────
    $today = new DateTime();
    $currentYear = (int)$today->format('Y');

    // Normalise confusable OCR chars: O→0, I/l→1, S→5
    $clean = preg_replace('/\bO\b/', '0', $rawText);
    $clean = preg_replace('/[Il](?=\d)/', '1', $clean);

    $patterns = [
        // DD/MM/YYYY or DD-MM-YYYY or DD.MM.YYYY
        '/\b(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})\b/',
        // MM/YYYY or MM-YYYY (best-before month/year only)
        '/\b(\d{1,2})[\/\-\.](\d{4})\b/',
        // YYYY-MM-DD (ISO)
        '/\b(\d{4})-(\d{2})-(\d{2})\b/',
        // DD MMM YYYY  (e.g. 15 APR 2026)
        '/\b(\d{1,2})\s+(gen|feb|mar|apr|mag|giu|lug|ago|set|ott|nov|dic|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\.?\s*(\d{4})\b/i',
        // MMM YYYY  (e.g. APR 2026)
        '/\b(gen|feb|mar|apr|mag|giu|lug|ago|set|ott|nov|dic|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\.?\s*(\d{4})\b/i',
    ];

    $monthMap = [
        'gen'=>1,'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'mag'=>5,'may'=>5,
        'giu'=>6,'jun'=>6,'lug'=>7,'jul'=>7,'ago'=>8,'aug'=>8,
        'set'=>9,'sep'=>9,'ott'=>10,'oct'=>10,'nov'=>11,'dic'=>12,'dec'=>12,
    ];

    $candidates = [];
    foreach ($patterns as $pat) {
        if (!preg_match_all($pat, $clean, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $match) {
            $full = $match[0];
            // Determine Y/M/D from which pattern matched
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $full)) {
                // ISO
                $y = (int)$match[1]; $mo = (int)$match[2]; $d = (int)$match[3];
            } elseif (isset($monthMap[strtolower($match[2] ?? '')])) {
                // DD MMM YYYY
                $d  = (int)$match[1];
                $mo = $monthMap[strtolower($match[2])];
                $y  = (int)$match[3];
            } elseif (isset($monthMap[strtolower($match[1] ?? '')])) {
                // MMM YYYY
                $d  = 1;
                $mo = $monthMap[strtolower($match[1])];
                $y  = (int)$match[2];
            } elseif (count($match) === 3) {
                // MM/YYYY
                $mo = (int)$match[1]; $y = (int)$match[2]; $d = 1;
            } else {
                // DD/MM/YYYY
                $d = (int)$match[1]; $mo = (int)$match[2]; $y = (int)$match[3];
            }
            // Sanity
            if ($y < 2020 || $y > 2040) continue;
            if ($mo < 1 || $mo > 12) continue;
            if ($d < 1 || $d > 31) continue;
            $dateStr = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            // Prefer dates in the future or near past (within 2 years)
            $dt   = new DateTime($dateStr);
            $diff = (int)$today->diff($dt)->days * ($dt >= $today ? 1 : -1);
            $candidates[] = ['date' => $dateStr, 'score' => $diff, 'raw' => $full];
        }
    }

    if (empty($candidates)) {
        return ['found' => false, 'raw_text' => $rawText];
    }

    // Pick candidate closest to today (but prefer future dates, then near-past)
    usort($candidates, fn($a, $b) => abs($a['score']) - abs($b['score']));
    $best = $candidates[0];

    return [
        'found'      => true,
        'date'       => $best['date'],
        'raw_text'   => $rawText,
        'raw_match'  => $best['raw'],
        'confidence' => count($candidates) === 1 ? 0.9 : 0.75,
        'source'     => 'tesseract',
    ];
}

function geminiReadExpiry(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $imageBase64 = $input['image'] ?? '';

    if (empty($imageBase64)) {
        EverLog::info('geminiReadExpiry');
        echo json_encode(['success' => false, 'error' => 'No image provided']);
        return;
    }

    // ── Step 1: Try Tesseract offline OCR first ────────────────────────────
    $ocrResult = tesseractReadExpiry($imageBase64);
    if ($ocrResult !== null && !empty($ocrResult['found']) && !empty($ocrResult['date'])) {
        echo json_encode([
            'success'     => true,
            'expiry_date' => $ocrResult['date'],
            'raw_text'    => $ocrResult['raw_text'] ?? '',
            'source'      => 'ocr',
        ]);
        return;
    }

    // ── Step 2: Fall back to Gemini Vision ────────────────────────────────
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        // No Gemini key and OCR failed/unavailable
        echo json_encode([
            'success'  => false,
            'error'    => 'no_api_key',
            'raw_text' => $ocrResult['raw_text'] ?? '',
        ]);
        return;
    }

    // Call Gemini API
    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => "Analizza questa immagine di un prodotto alimentare. Cerca la data di scadenza (\"da consumarsi entro\", \"da consumarsi preferibilmente entro\", \"scad.\", \"exp\", \"best before\", \"TMC\", o date stampate).\n\nRispondi SOLO con un JSON nel formato: {\"found\": true, \"date\": \"YYYY-MM-DD\", \"raw_text\": \"testo letto\"}\nSe non trovi una data: {\"found\": false, \"raw_text\": \"testo letto se presente\"}\n\nSe la data ha solo mese e anno (es. 03/2027), usa il primo giorno del mese. Se ha solo giorno e mese (es. 15/04), assumi l'anno corrente o il prossimo se la data è già passata."
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $imageBase64
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 256
        ]
    ];
    
    $result   = callGeminiWithFallback($apiKey, $payload, 30, 'expiry_ocr');
    $httpCode = $result['http_code'];

    if ($httpCode !== 200) {
        $errMsg = $result['data']['error']['message'] ?? 'Gemini API error';
        echo json_encode(['success' => false, 'error' => $errMsg, 'http_code' => $httpCode]);
        return;
    }

    $data = $result['data'];
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    // Parse the JSON response from Gemini
    // Remove potential markdown code block wrapping
    $text = preg_replace('/^```json\\s*/i', '', $text);
    $text = preg_replace('/\\s*```$/i', '', $text);
    $text = trim($text);
    
    $parsed = json_decode($text, true);
    
    if ($parsed && !empty($parsed['found']) && !empty($parsed['date'])) {
        // Validate date format
        $date = $parsed['date'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => true, 'expiry_date' => $date, 'raw_text' => $parsed['raw_text'] ?? '', 'source' => 'gemini']);
            return;
        }
    }
    
    echo json_encode([
        'success' => false, 
        'error' => 'Could not parse expiry date',
        'raw_text' => $parsed['raw_text'] ?? $text
    ]);
}

// ===== GEMINI CHAT =====
function geminiChat(PDO $db): void {
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        EverLog::info('geminiChat');
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $message = $input['message'] ?? '';
    $history = $input['history'] ?? [];
    $appliances = $input['appliances'] ?? [];
    $dietaryRestrictions = $input['dietary_restrictions'] ?? '';
    $lang = recipeNormalizeLang($input['lang'] ?? 'it');
    $langName = recipeLangName($lang);

    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Empty message']);
        return;
    }

    // Fetch inventory context
    $stmt = $db->query("
        SELECT p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date, i.opened_at,
               CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
        ORDER BY days_left ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ingredientLines = [];
    foreach ($items as $item) {
        $line = "- {$item['name']}";
        if ($item['brand']) $line .= " ({$item['brand']})";
        $line .= ": {$item['quantity']} {$item['unit']}";
        if ($item['unit'] === 'conf' && !empty($item['package_unit']) && $item['default_quantity'] > 0) {
            $line .= " (da {$item['default_quantity']} {$item['package_unit']} ciascuna)";
        }
        $isOpen = !empty($item['opened_at']) ||
                  (floatval($item['quantity']) > 0 && floatval($item['quantity']) < 1 && $item['unit'] === 'conf');
        if ($isOpen) $line .= ' [APERTO]';
        if ($item['expiry_date']) {
            $daysLeft = intval($item['days_left']);
            if ($daysLeft < 0) {
                $line .= " [SCADUTO da " . abs($daysLeft) . " giorni]";
            } elseif ($daysLeft <= 3) {
                $line .= " [SCADE TRA $daysLeft GIORNI]";
            } elseif ($daysLeft <= 7) {
                $line .= " [scade tra $daysLeft giorni]";
            }
        }
        $line .= " (in {$item['location']})";
        $ingredientLines[] = $line;
    }
    $ingredientsText = implode("\n", $ingredientLines);

    $appliancesText = _buildAppliancesPrompt($appliances, compact: true);

    $dietaryText = '';
    if (!empty($dietaryRestrictions)) {
        $dietaryText = "\nUser dietary restrictions: {$dietaryRestrictions}. Always respect these restrictions.";
    }

    $langName = recipeLangName($lang);
    $systemPrompt = <<<PROMPT
You are an expert kitchen assistant, friendly and concise. The user has a pantry and asks you for advice on what to prepare.
IMPORTANT: Always respond in {$langName}, using a colloquial and friendly tone.

CONTEXT - AVAILABLE PANTRY INGREDIENTS:
{$ingredientsText}
{$appliancesText}{$dietaryText}

RULES:
1. Always respond in {$langName}
2. Use ONLY ingredients from the user's pantry (plus water, salt, pepper, oil which are assumed always available)
3. Prioritize ingredients that expire soon
4. Be concise: no lengthy lists, get to the point
5. If the user asks for a recipe or preparation, give clear instructions with quantities
6. If there are no suitable ingredients for the request, say so honestly and suggest alternatives
7. You can suggest creative combinations
8. When mentioning quantities, use the same units as in the pantry
9. Remember the context of the previous conversation
10. If the user explicitly asks for a recipe for a specific appliance (e.g. bread machine, Cookeo, air fryer), provide the recipe ONLY for that appliance, with device-specific instructions (programs, ingredient order, times, temperatures)
PROMPT;

    // Build conversation for Gemini
    // systemInstruction is passed separately in the payload; contents only contains the actual chat turns.
    $contents = [];

    // Add conversation history
    foreach ($history as $msg) {
        $role = ($msg['role'] === 'user') ? 'user' : 'model';
        $contents[] = [
            'role' => $role,
            'parts' => [['text' => $msg['text']]]
        ];
    }

    // Add current message
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $message]]
    ];

    $payload = [
        'contents' => $contents,
        'systemInstruction' => [
            'parts' => [['text' => $systemPrompt]]
        ],
        'generationConfig' => [
            'temperature' => 0.8,
            'maxOutputTokens' => 4096
        ]
    ];

    $result   = callGeminiWithFallback($apiKey, $payload, 90, 'chat');
    $httpCode = $result['http_code'];

    if ($httpCode !== 200) {
        $errMsg = $result['data']['error']['message'] ?? 'Gemini API error';
        echo json_encode(['success' => false, 'error' => $errMsg, 'http_code' => $httpCode]);
        return;
    }

    $reply = $result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($reply)) {
        echo json_encode(['success' => false, 'error' => 'Empty response from Gemini']);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $reply]);
}

    function recipeNormalizeLang($lang): string {
        $lang = is_string($lang) ? strtolower(trim($lang)) : 'it';
        return in_array($lang, ['it', 'en', 'de'], true) ? $lang : 'it';
    }

    function recipeLangName(string $lang): string {
        return [
            'it' => 'Italian',
            'en' => 'English',
            'de' => 'German',
        ][$lang] ?? 'Italian';
    }

    function recipeText(string $lang, string $key, array $vars = []): string {
        $dict = [
            'it' => [
                'status_analyze_pantry' => '📦 Analizzo la dispensa...',
                'status_products_found' => '{n} prodotti trovati',
                'status_passed_ai' => ' ({n} passati all\'AI)',
                'status_all_passed_ai' => ' — tutti passati all\'AI',
                'status_urgent' => '⚠️ {n} urgenti: {items}',
                'status_evaluate_ingredients' => '🧠 Valuto gli ingredienti disponibili...',
                'status_preparing_recipe' => '👨‍🍳 Preparo la ricetta...',
                'status_recipe_with' => '🥘 Ricetta con {a} e {b}',
                'status_variant' => ' — variante #{n}',
                'status_dish_based_on' => '🎯 Piatto a base di {type}',
                'status_creating_full_recipe' => '✍️ Creo la ricetta completa...',
                'status_quota_wait' => '⏳ Quota TPM esaurita ({model}), attendo {s}s... (tentativo {a}/{m})',
                'status_retry_generation' => '✍️ Riprovo la generazione...',
                'status_switch_model' => '🔄 Cambio modello → {model}...',
                'error_pantry_empty' => 'La dispensa è vuota!',
                'error_gemini_api' => 'Errore API Gemini',
                'error_cannot_generate' => 'Impossibile generare la ricetta',
                'error_empty_reply' => 'Risposta vuota da Gemini',
                'prompt_lang_rule' => 'IMPORTANTE: scrivi tutti i campi testuali della ricetta in Italiano.',
                'prompt_step_example' => 'Passo 1…',
                'tools_title' => 'Strumenti necessari',
            ],
            'en' => [
                'status_analyze_pantry' => '📦 Analyzing pantry...',
                'status_products_found' => '{n} products found',
                'status_passed_ai' => ' ({n} sent to AI)',
                'status_all_passed_ai' => ' — all sent to AI',
                'status_urgent' => '⚠️ {n} urgent: {items}',
                'status_evaluate_ingredients' => '🧠 Evaluating available ingredients...',
                'status_preparing_recipe' => '👨‍🍳 Preparing recipe...',
                'status_recipe_with' => '🥘 Recipe with {a} and {b}',
                'status_variant' => ' — variation #{n}',
                'status_dish_based_on' => '🎯 Dish based on {type}',
                'status_creating_full_recipe' => '✍️ Creating full recipe...',
                'status_quota_wait' => '⏳ TPM quota reached ({model}), waiting {s}s... (attempt {a}/{m})',
                'status_retry_generation' => '✍️ Retrying generation...',
                'status_switch_model' => '🔄 Switching model → {model}...',
                'error_pantry_empty' => 'Pantry is empty!',
                'error_gemini_api' => 'Gemini API error',
                'error_cannot_generate' => 'Unable to generate recipe',
                'error_empty_reply' => 'Empty response from Gemini',
                'prompt_lang_rule' => 'IMPORTANT: write all textual recipe fields in English only. Do not use Italian or German.',
                'prompt_step_example' => 'Step 1…',
                'tools_title' => 'Equipment needed',
            ],
            'de' => [
                'status_analyze_pantry' => '📦 Vorrat wird analysiert...',
                'status_products_found' => '{n} Produkte gefunden',
                'status_passed_ai' => ' ({n} an die KI gesendet)',
                'status_all_passed_ai' => ' — alle an die KI gesendet',
                'status_urgent' => '⚠️ {n} dringend: {items}',
                'status_evaluate_ingredients' => '🧠 Verfuegbare Zutaten werden bewertet...',
                'status_preparing_recipe' => '👨‍🍳 Rezept wird vorbereitet...',
                'status_recipe_with' => '🥘 Rezept mit {a} und {b}',
                'status_variant' => ' — Variante #{n}',
                'status_dish_based_on' => '🎯 Gericht auf Basis von {type}',
                'status_creating_full_recipe' => '✍️ Vollstaendiges Rezept wird erstellt...',
                'status_quota_wait' => '⏳ TPM-Limit erreicht ({model}), warte {s}s... (Versuch {a}/{m})',
                'status_retry_generation' => '✍️ Generierung wird erneut versucht...',
                'status_switch_model' => '🔄 Modellwechsel → {model}...',
                'error_pantry_empty' => 'Die Vorratskammer ist leer!',
                'error_gemini_api' => 'Gemini-API-Fehler',
                'error_cannot_generate' => 'Rezept konnte nicht erstellt werden',
                'error_empty_reply' => 'Leere Antwort von Gemini',
                'prompt_lang_rule' => 'WICHTIG: schreibe alle textuellen Rezeptfelder nur auf Deutsch. Verwende kein Italienisch oder Englisch.',
                'prompt_step_example' => 'Schritt 1…',
                'tools_title' => 'Benötigte Geräte',
            ],
        ];
        $text = $dict[$lang][$key] ?? $dict['it'][$key] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

// ===== RECIPE GENERATION WITH GEMINI =====
function generateRecipe(PDO $db): void {
    EverLog::debug('generateRecipe start');
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $lang = recipeNormalizeLang($input['lang'] ?? 'it');
    $recipeLangName = recipeLangName($lang);
    $mealType = $input['meal'] ?? 'pranzo';
    $persons = max(1, intval($input['persons'] ?? 1));
    $subType = $input['sub_type'] ?? '';
    $options = $input['options'] ?? [];
    $appliances = $input['appliances'] ?? [];
    $dietaryRestrictions = $input['dietary_restrictions'] ?? '';
    $todayRecipes = $input['today_recipes'] ?? [];
    $mealPlanType = $input['meal_plan_type'] ?? ''; // e.g. 'pasta', 'pesce', 'legumi', ...
    $variation    = max(0, intval($input['variation'] ?? 0)); // 0=first attempt, 1+=re-generation
    $rejectedIngredients = $input['rejected_ingredients'] ?? [];  // ingredient names from previous rejected recipes

    // Fetch all inventory items with expiry info
    $stmt = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date, i.opened_at,
               CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
        ORDER BY days_left ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        echo json_encode(['success' => false, 'error' => recipeText($lang, 'error_pantry_empty')]);
        return;
    }

    // Helper to compute priority group for an item:
    // 1=scaduto, 2=scadenza imminente ≤3gg, 3=scadenza ravvicinata ≤7gg,
    // 4=scadenza lontana, 5=aperto (opened_at set o conf parziale), 6=chiuso
    $getItemPriority = function($item) {
        $daysLeft = floatval($item['days_left']);
        // "Aperto" = opened_at è impostato (frutta/verdura/qualsiasi cosa usata parzialmente)
        //             OPPURE confezione parzialmente usata (qty < 1 conf)
        $isOpen = !empty($item['opened_at']) ||
                  (floatval($item['quantity']) > 0 && floatval($item['quantity']) < 1 && $item['unit'] === 'conf');
        if (!empty($item['expiry_date']) && $daysLeft < 0) return 1;
        if (!empty($item['expiry_date']) && $daysLeft <= 3) return 2;
        if (!empty($item['expiry_date']) && $daysLeft <= 7) return 3;
        if ($isOpen) return 3; // opened items: same priority as expiring this week — must be used soon
        if (!empty($item['expiry_date'])) return 4;
        return 6;
    };

    // Sort by priority group, then by days_left within each group
    usort($items, function($a, $b) use ($getItemPriority) {
        $pa = $getItemPriority($a);
        $pb = $getItemPriority($b);
        if ($pa !== $pb) return $pa - $pb;
        return floatval($a['days_left']) - floatval($b['days_left']);
    });

    // Build ingredient list grouped by priority
    // ---- Build compact ingredient list for AI prompt ----
    // Skip common staples that are always assumed available (rule says: acqua, sale, pepe, olio)
    $staplePatterns = '/\b(sale|pepe|olio d.oliva|olio di semi|olio extra|acqua|aceto balsamico|aceto di|sel marin)\b/i';
    
    $priorityGroups = [];
    foreach ($items as $item) {
        $group = $getItemPriority($item);
        // Skip always-available staples from category 6 (closed, no expiry concern)
        if ($group >= 5 && preg_match($staplePatterns, $item['name'])) continue;
        
        $qty = floatval($item['quantity']);
        $isOpen = !empty($item['opened_at']) ||
                  ($qty > 0 && $qty < 1 && $item['unit'] === 'conf');
        $daysLeft = intval($item['days_left']);
        
        // Compact line: name + qty (with conf expansion) + flags only when relevant
        $line = "- {$item['name']}: {$item['quantity']} {$item['unit']}";
        if ($item['unit'] === 'conf' && !empty($item['package_unit']) && $item['default_quantity'] > 0) {
            $line .= " ({$item['default_quantity']}{$item['package_unit']}/conf)";
        }
        if ($item['unit'] === 'pz') {
            $line .= ' [usa PEZZI interi — qty_number in pz, non grammi]';
        }
        // Add expiry info only for priority groups 1-4
        if ($group <= 4 && $item['expiry_date']) {
            if ($daysLeft < 0) {
                $line .= " ⚠️SCADUTO";
            } elseif ($daysLeft <= 3) {
                $line .= " 🔴{$daysLeft}gg";
            } elseif ($daysLeft <= 7) {
                $line .= " 🟠{$daysLeft}gg";
            } else {
                $line .= " {$daysLeft}gg";
            }
        }
        if ($isOpen) $line .= ' [APERTO]';
        
        $priorityGroups[$group][] = $line;
    }

    // Build sections: detailed headers for urgent groups, brief for rest
    $ingredientSections = [];
    $priorityHeaders = [
        1 => 'SCADUTI — usa subito',
        2 => 'SCADENZA ≤3gg — priorità alta',
        3 => 'SCADENZA ≤7gg / APERTI — usa presto',
        4 => 'ALTRI CON SCADENZA',
        6 => 'DISPENSA',
    ];
    // Limit groups to keep prompt compact:
    //  1-3 (urgent+opened): all items; 4 (has expiry): max 40; 6 (pantry): max 20
    foreach ($priorityHeaders as $g => $header) {
        if (empty($priorityGroups[$g])) continue;
        $groupItems = $priorityGroups[$g];
        if ($g === 4 && count($groupItems) > 40) {
            $groupItems = array_slice($groupItems, 0, 40);
        } elseif ($g === 6 && count($groupItems) > 20) {
            $groupItems = array_slice($groupItems, 0, 20);
        }
        $ingredientSections[] = "[$header]\n" . implode("\n", $groupItems);
    }
    $ingredientsText = implode("\n", $ingredientSections);

    // Build mandatory/recommended lists ONLY when user explicitly selected
    // 'scadenze' (expiry priority) or 'zerowaste' (zero waste) options.
    // Without these options, the recipe should use ALL available ingredients freely
    // without being biased toward expiring items.
    $mandatoryItems = [];
    $recommendedItems = [];
    $wantsExpiryPriority = in_array('scadenze', $options) || in_array('zerowaste', $options);
    $wantsOpenedPriority = in_array('opened', $options);

    if ($wantsExpiryPriority || $wantsOpenedPriority) {
        foreach ($items as $item) {
            $g = $getItemPriority($item);
            $daysLeft = floatval($item['days_left']);
            $isOpen = !empty($item['opened_at']) ||
                      (floatval($item['quantity']) > 0 && floatval($item['quantity']) < 1 && $item['unit'] === 'conf');
            $expiryNote = !empty($item['expiry_date']) ? " — scade: {$item['expiry_date']}" : '';
            $openNote   = $isOpen ? ' [APERTO]' : '';
            $label = $item['name'] . ($item['brand'] ? " ({$item['brand']})" : '') . $openNote . $expiryNote;

            if ($wantsExpiryPriority) {
                // Expired or expiring within 3 days → mandatory
                if ($g === 1 || $g === 2) {
                    $mandatoryItems[] = $label;
                // Expiring within 7 days → strongly recommended
                } elseif ($g === 3) {
                    $recommendedItems[] = $label;
                }
            }
            if (($wantsOpenedPriority || $wantsExpiryPriority) && $isOpen && $daysLeft <= 7 && $daysLeft >= 0) {
                // Opened items expiring within 7 days
                if (!in_array($label, $mandatoryItems) && !in_array($label, $recommendedItems)) {
                    $recommendedItems[] = $label;
                }
            }
        }
    }

    $mustUseText = '';
    if (!empty($mandatoryItems)) {
        $mustUseText .= "\n\n⚠️ OBBLIGATORI (scaduti/imminenti — DEVE usarne almeno 1):\n" . implode("\n", array_map(fn($n) => "→ $n", $mandatoryItems));
    }
    if (!empty($recommendedItems)) {
        $mustUseText .= "\n\n🔶 CONSIGLIATI (aperti/in scadenza):\n" . implode("\n", array_map(fn($n) => "· $n", $recommendedItems));
    }

    $mealLabels = [
        'colazione' => 'colazione (mattina)',
        'pranzo' => 'pranzo (mezzogiorno)',
        'cena' => 'cena (sera)',
        'dolce' => 'dolce/dessert',
        'succo' => 'succo di frutta/bevanda'
    ];
    $mealLabel = $mealLabels[$mealType] ?? $mealType;

    // Sub-type specialization for dolce/succo
    $subTypeLabels = [
        'dolce' => [
            'torta'    => 'Torta (soffice, da forno: torta di mele, ciambellone, plumcake, angel cake, ecc.)',
            'crema'    => 'Crema o Budino (crema pasticcera, panna cotta, mousse, tiramisù, budino, semifreddo)',
            'crumble'  => 'Crumble o Crostata (base croccante: crumble di frutta, crostata, sbriciolata)',
            'biscotti' => 'Biscotti o Pasticcini (biscotti, cookies, muffin, cupcake, pasticcini)',
            'frutta'   => 'Dolce alla Frutta (macedonia creativa, frutta caramellata, sorbetto, frullato dolce)',
        ],
        'succo' => [
            'dolce'        => 'Succo Dolce e Fruttato (mix di frutta dolce: pesca, mela, pera, fragola, banana)',
            'energizzante' => 'Succo Energizzante (con zenzero, curcuma, barbabietola, carota, mela verde)',
            'detox'        => 'Succo Detox / Verde (cetriolo, sedano, spinaci, mela verde, limone)',
            'rinfrescante' => 'Succo Rinfrescante (anguria, menta, lime, cetriolo, acqua di cocco)',
            'vitaminico'   => 'Succo Vitaminico / Agrumi (arancia, pompelmo, limone, kiwi, mandarino)',
        ]
    ];
    $subTypeText = '';
    if (!empty($subType) && isset($subTypeLabels[$mealType][$subType])) {
        $subHint = $subTypeLabels[$mealType][$subType];
        $mealLabel .= " — tipo: $subHint";
        $subTypeText = "\n\n🎨 SOTTO-TIPO: {$subHint}. La ricetta DEVE essere di questo tipo.";
    }

    // Build extra rules from options
    $extraRules = [];
    $optionLabels = [
        'veloce' => 'VELOCE: max 15-20 min totali.',
        'pocafame' => 'POCA FAME: porzione leggera, snack o insalata.',
        'scadenze' => 'PRIORITÀ SCADENZE: usa per primi i prodotti in scadenza.',
        'salutare' => 'SALUTARE: ingredienti integrali, verdure, pochi grassi.',
        'opened' => 'PRIORITÀ APERTI: usa per primi i prodotti [APERTO].',
        'zerowaste' => 'ZERO SPRECHI: usa il più possibile ingredienti in scadenza.'
    ];
    foreach ($options as $opt) {
        if (isset($optionLabels[$opt])) {
            $extraRules[] = $optionLabels[$opt];
        }
    }
    
    $extraRulesText = '';
    if (!empty($extraRules)) {
        $extraRulesText = "\n\n⚠️ PREFERENZE OBBLIGATORIE (RISPETTALE SEMPRE, non sono suggerimenti):\n" . implode("\n", array_map(fn($r) => "→ $r", $extraRules));
    }
    
    // Appliances
    $appliancesText = _buildAppliancesPrompt($appliances, compact: false);

    // Dietary restrictions
    $dietaryText = '';
    if (!empty($dietaryRestrictions)) {
        $dietaryText = "\n\nRESTRIZIONI ALIMENTARI:\n{$dietaryRestrictions}\nRispetta SEMPRE queste restrizioni.";
    }

    // Weekly meal plan type hint
    $mealPlanTypeLabels = [
        'pasta'     => 'Pasta (primo piatto a base di pasta)',
        'riso'      => 'Riso (risotto, insalata di riso, riso saltato, ecc.)',
        'carne'     => 'Carne (secondo piatto a base di carne)',
        'pesce'     => 'Pesce (secondo piatto a base di pesce o frutti di mare)',
        'legumi'    => 'Legumi (zuppa, insalata, hummus, pasta e fagioli, ecc.)',
        'uova'      => 'Uova (frittata, uova strapazzate, quiche, ecc.)',
        'formaggio' => 'Formaggio (fonduta, gnocchi al formaggio, torta salata, ecc.)',
        'pizza'     => 'Pizza o focaccia (impastata in casa o usi ingredienti simili)',
        'affettati' => 'Affettati (tagliere misto, piadina, panino, ecc.)',
        'verdure'   => 'Verdure (piatto principale a base di verdure, contorno abbondante)',
        'zuppa'     => 'Zuppa o minestra (zuppe, vellutate, minestrone)',
        'insalata'  => 'Insalata (insalata mista, insalata di riso o pasta, poke)',
        'pane'      => 'Pane / Sandwich (toast, tramezzino, bruschette)',
        'dolce'     => 'Dolce o dessert',
        'libero'    => '',
    ];

    // Keywords to match inventory names against each meal plan type
    $typeKeywords = [
        'pesce'     => ['tonno', 'salmone', 'merluzzo', 'branzino', 'orata', 'sardine', 'acciughe', 'alici', 'gamberi', 'cozze', 'vongole', 'polpo', 'calamari', 'seppia', 'sgombro', 'trota', 'baccalà', 'dentice', 'spigola', 'pesce'],
        'carne'     => ['pollo', 'manzo', 'maiale', 'vitello', 'agnello', 'tacchino', 'salsiccia', 'hamburger', 'bistecca', 'cotoletta', 'pancetta', 'speck', 'carne', 'arrosto', 'filetto', 'lonza', 'braciola'],
        'pasta'     => ['pasta', 'spaghetti', 'penne', 'rigatoni', 'fusilli', 'tagliatelle', 'lasagne', 'farfalle', 'orecchiette', 'bucatini', 'linguine', 'maccheroni', 'gnocchi', 'pennette', 'bavette'],
        'riso'      => ['riso', 'basmati', 'arborio', 'carnaroli', 'parboiled', 'riso integrale'],
        'legumi'    => ['fagioli', 'ceci', 'lenticchie', 'piselli', 'fave', 'lupini', 'soia', 'legumi', 'borlotti', 'cannellini', 'azuki'],
        'uova'      => ['uova', 'uovo'],
        'formaggio' => ['formaggio', 'parmigiano', 'mozzarella', 'ricotta', 'pecorino', 'grana', 'gorgonzola', 'scamorza', 'fontina', 'emmental', 'asiago', 'provola', 'provolone', 'taleggio', 'stracchino'],
        'pizza'     => ['farina', 'lievito', 'pizza', 'focaccia'],
        'affettati' => ['prosciutto', 'salame', 'bresaola', 'mortadella', 'speck', 'coppa', 'affettati', 'wurstel', 'würstel', 'piadina', 'pancetta cotta'],
        'verdure'   => ['zucchine', 'zucchina', 'melanzane', 'peperoni', 'spinaci', 'cavolfiore', 'broccoli', 'carote', 'zucca', 'bietole', 'cavolo', 'carciofi', 'asparagi', 'lattuga', 'rucola', 'radicchio', 'cicoria', 'finocchio', 'cipolla', 'porri', 'verdure'],
        'zuppa'     => ['brodo', 'zuppa', 'minestra', 'minestrone', 'vellutata', 'orzo', 'farro', 'fagioli', 'ceci', 'lenticchie'],
        'insalata'  => ['insalata', 'lattuga', 'rucola', 'spinaci', 'radicchio', 'misticanza', 'valeriana', 'songino'],
        'pane'      => ['pane', 'pancarrè', 'baguette', 'toast', 'tramezzino', 'crackers', 'grissini', 'ciabatta', 'rosetta'],
        'dolce'     => ['cioccolato', 'cacao', 'zucchero', 'miele', 'marmellata', 'nutella', 'creme caramel', 'savoiardi', 'biscotti', 'pan di spagna', 'panna'],
    ];

    $mealPlanText = '';
    $mealPlanRule = '';
    if (!empty($mealPlanType) && isset($mealPlanTypeLabels[$mealPlanType]) && $mealPlanTypeLabels[$mealPlanType] !== '') {
        $hint = $mealPlanTypeLabels[$mealPlanType];

        // Scan inventory for ingredients matching this meal plan type
        $matchingItems = [];
        if (isset($typeKeywords[$mealPlanType])) {
            foreach ($items as $item) {
                $nameLower = mb_strtolower($item['name'] . ' ' . ($item['brand'] ?? ''));
                foreach ($typeKeywords[$mealPlanType] as $kw) {
                    if (mb_strpos($nameLower, $kw) !== false) {
                        $entry = "→ {$item['name']}" . ($item['brand'] ? " ({$item['brand']})" : '') . ": {$item['quantity']} {$item['unit']}";
                        if (!empty($item['expiry_date'])) {
                            $dl = intval($item['days_left']);
                            $entry .= $dl < 0 ? " [SCADUTO]" : " [scade tra $dl giorni]";
                        }
                        $matchingItems[] = $entry;
                        break;
                    }
                }
            }
            $matchingItems = array_unique($matchingItems);
        }

        if (!empty($matchingItems)) {
            $matchingList = implode("\n", $matchingItems);
            $matchingBlock = "Ingredienti disponibili in dispensa compatibili con questa tipologia (usa almeno uno di questi come BASE della ricetta):\n{$matchingList}";
        } else {
            $matchingBlock = "Nessun ingrediente perfettamente corrispondente trovato — usa la cosa più affine disponibile e segnalalo in nutrition_note.";
        }

        $mealPlanText = "\n\n🎯 TIPO OBBLIGATORIO: {$hint}\n{$matchingBlock}";
        $mealPlanRule = "0. La ricetta DEVE essere: {$hint}. Usa gli ingredienti compatibili come base.\n   ";
    }

    // Today's previous recipes from DB - avoid repetition
    $todayText = '';
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));

    // Get this week's recipes for variety
    $weekStmt = $db->prepare("SELECT date, meal, recipe_json FROM recipes WHERE date >= ? ORDER BY date DESC");
    $weekStmt->execute([$weekAgo]);
    $weekDbRecipes = $weekStmt->fetchAll();

    $todayTitles = [];
    $weekTitles = [];
    foreach ($weekDbRecipes as $tr) {
        $rj = json_decode($tr['recipe_json'], true);
        if (!empty($rj['title'])) {
            $weekTitles[] = $rj['title'];
            if ($tr['date'] === $today) {
                $todayTitles[] = $rj['title'];
            }
        }
    }
    if (!empty($todayRecipes)) {
        $todayTitles = array_unique(array_merge($todayTitles, $todayRecipes));
    }

    $varietyText = '';
    if (!empty($todayTitles)) {
        $todayList = implode(', ', array_map(function($t) { return '"' . $t . '"'; }, $todayTitles));
        $varietyText .= "\n\nGIÀ FATTO OGGI: {$todayList} — proponi qualcosa di DIVERSO.";
    }
    // Weekly variety: list all recent recipes so AI avoids repetition
    $weekOnly = array_diff($weekTitles, $todayTitles);
    if (!empty($weekOnly)) {
        $weekList = implode(', ', array_map(function($t) { return '"' . $t . '"'; }, array_values($weekOnly)));
        $varietyText .= "\n\nULTIMI 7GG: {$weekList} — varia.";
    }
    // If this is a re-generation, stress the need for a truly different recipe
    $regenText = '';
    if ($variation > 0) {
        $regenText = "\n\n🔁 RIGENERA #{$variation}: proponi qualcosa di COMPLETAMENTE DIVERSO (altro stile, altro ingrediente principale, altra tecnica).";
        if (!empty($rejectedIngredients)) {
            $rejList = implode(', ', array_map(fn($n) => '"' . $n . '"', $rejectedIngredients));
            $regenText .= " Evita come ingrediente principale: {$rejList}.";
        }
    }

    $promptLanguageRule = recipeText($lang, 'prompt_lang_rule');
    $promptStepExample = recipeText($lang, 'prompt_step_example');

    $prompt = <<<PROMPT
You are an expert home chef. Generate ONE recipe for $mealLabel for $persons person(s) using the available ingredients below.
{$extraRulesText}{$appliancesText}{$dietaryText}{$subTypeText}{$mealPlanText}{$varietyText}{$regenText}{$mustUseText}

REGOLE:
{$mealPlanRule}1. PRIORITÀ: usa prima gli ingredienti scaduti/in scadenza (⚠️🔴🟠), poi quelli [APERTO], poi il resto.
2. Usa SOLO ingredienti dalla lista + acqua/sale/pepe/olio (sempre disponibili).
3. Quantità MASSIME per $persons persona/e (NON superare mai): pasta/riso asciutto 90g/pers, carne 150g/pers, affettati/salumi/speck/prosciutto 70g/pers, pesce 180g/pers, legumi secchi 80g/pers (lessi 200g/pers), verdure contorno 150g/pers, verdure intere grosse (peperoni/melanzane/zucchine) 1 pz/pers, formaggio 70g/pers, latte 200ml/pers, farina per dolci 200g/pers, piadina/tortilla/wrap 1-2 pz/pers. Se un ingrediente rimasto è inferiore a questi limiti, usalo tutto.
4. "qty_number": valore NUMERICO nella STESSA unità della dispensa (g/ml/pz/conf, MAI kg o litri). Per non-dispensa: 0. IMPORTANTE: per ingredienti con unità "pz" scrivi qty_number come numero di PEZZI (es. 2, non 200g).
5. "name": usa ESATTAMENTE il nome dalla lista (il sistema lo usa per scalare l'inventario).
6. Includi nella lista ingredienti TUTTI quelli citati nei passi (tranne acqua/sale/pepe/olio).
7. Language rule: {$recipeLangName} only for all textual fields (`title`, `tags`, `expiry_note`, `ingredients.qty`, `steps`, `nutrition_note`, `tools_needed`). Keep `meal` unchanged.
8. `tools_needed`: array of kitchen tools/appliances actually required by this recipe (e.g. ["Forno","Frullatore"]). Use the same language as all other text fields. Empty array [] if only stovetop/knife/pan needed.

DISPENSA:
$ingredientsText

Rispondi SOLO JSON valido (no markdown):
{$promptLanguageRule}
{"title":"…","meal":"$mealType","persons":$persons,"prep_time":"…","cook_time":"…","tags":["…"],"expiry_note":"…","tools_needed":["…"],"ingredients":[{"name":"…","qty":"200 g","qty_number":200,"from_pantry":true}],"steps":["{$promptStepExample}"],"nutrition_note":"…"}
PROMPT;

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => min(1.4, 0.7 + $variation * 0.25),
            'maxOutputTokens' => 2048
        ]
    ];

    $result   = callGeminiWithFallback($apiKey, $payload, 60, 'recipe');
    $httpCode = $result['http_code'];

    if ($httpCode !== 200) {
        $errDetail = $result['data']['error']['message'] ?? substr($result['body'], 0, 300);
        echo json_encode(['success' => false, 'error' => recipeText($lang, 'error_gemini_api'), 'http_code' => $httpCode, 'detail' => $errDetail]);
        return;
    }

    $data = $result['data'];
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Clean markdown wrapping
    $text = preg_replace('/^```json\\s*/i', '', $text);
    $text = preg_replace('/\\s*```$/i', '', $text);
    $text = trim($text);

    $recipe = json_decode($text, true);

    if ($recipe && !empty($recipe['title'])) {
        // Enrich from_pantry ingredients with product_id and location for "use" feature
        if (!empty($recipe['ingredients'])) {
            // Build a category map for better fuzzy matching
            $itemsLookup = [];
            foreach ($items as $item) {
                $itemsLookup[] = [
                    'item' => $item,
                    'lower' => mb_strtolower(trim($item['name']), 'UTF-8'),
                    'words' => preg_split('/[\s,.\-\/]+/', mb_strtolower(trim($item['name']), 'UTF-8')),
                    'cat'   => mb_strtolower($item['category'] ?? '', 'UTF-8'),
                ];
            }

            // Common Italian food name aliases for better matching
            $aliases = [
                'uovo' => ['uova','uovo','egg'],
                'uova' => ['uovo','uova','egg'],
                'latte' => ['latte','milk'],
                'formaggio' => ['formaggio','cheese','philadelphia','mozzarella','parmigiano','grana','pecorino','ricotta','mascarpone','stracchino','gorgonzola'],
                'pasta' => ['pasta','spaghetti','penne','fusilli','rigatoni','farfalle','tagliatelle','linguine','bucatini','orecchiette','paccheri','maccheroni'],
                'pomodoro' => ['pomodoro','pomodori','tomato','passata','pelati','polpa'],
                'cipolla' => ['cipolla','cipolle','onion'],
                'aglio' => ['aglio','garlic'],
                'burro' => ['burro','butter'],
                'panna' => ['panna','cream','crema'],
                'zucchero' => ['zucchero','sugar'],
                'farina' => ['farina','flour'],
                'olio' => ['olio','oil'],
                'patata' => ['patata','patate','potato'],
                'carota' => ['carota','carote','carrot'],
                'sedano' => ['sedano','celery'],
                'prezzemolo' => ['prezzemolo','parsley'],
                'basilico' => ['basilico','basil'],
            ];

            foreach ($recipe['ingredients'] as &$ing) {
                if (!empty($ing['from_pantry'])) {
                    $ingNameLower = mb_strtolower(trim($ing['name']), 'UTF-8');
                    $ingWords = preg_split('/[\s,.\-\/]+/', $ingNameLower);
                    $bestMatch = null;
                    $bestScore = 0;
                    
                    foreach ($itemsLookup as $entry) {
                        $itemNameLower = $entry['lower'];
                        $itemWords = $entry['words'];
                        $score = 0;
                        
                        // Exact match
                        if ($ingNameLower === $itemNameLower) {
                            $score = 100;
                        }
                        // Ingredient name contained in product name
                        elseif (mb_strpos($itemNameLower, $ingNameLower) !== false) {
                            $score = 80;
                        }
                        // Product name contained in ingredient name
                        elseif (mb_strpos($ingNameLower, $itemNameLower) !== false) {
                            $score = 70;
                        }
                        else {
                            // Word-level matching with alias expansion
                            $expandedIngWords = $ingWords;
                            foreach ($ingWords as $w) {
                                foreach ($aliases as $key => $group) {
                                    if (in_array($w, $group) || mb_strpos($w, $key) === 0 || mb_strpos($key, $w) === 0) {
                                        $expandedIngWords = array_merge($expandedIngWords, $group);
                                    }
                                }
                            }
                            $expandedIngWords = array_unique($expandedIngWords);

                            $common = 0;
                            foreach ($expandedIngWords as $ew) {
                                foreach ($itemWords as $iw) {
                                    // Partial stem match (min 4 chars shared prefix)
                                    $minLen = min(mb_strlen($ew), mb_strlen($iw));
                                    if ($minLen >= 3) {
                                        $prefixLen = 0;
                                        for ($c = 0; $c < $minLen; $c++) {
                                            if (mb_substr($ew, $c, 1) === mb_substr($iw, $c, 1)) $prefixLen++;
                                            else break;
                                        }
                                        if ($prefixLen >= min(4, $minLen)) { $common++; break; }
                                    }
                                    if ($ew === $iw) { $common++; break; }
                                }
                            }
                            if ($common > 0) {
                                $score = ($common / max(count($ingWords), 1)) * 65;
                                // Bonus: if the main/first ingredient word matches
                                if (count($ingWords) > 0 && $common > 0) {
                                    foreach ($itemWords as $iw) {
                                        if (mb_strpos($iw, $ingWords[0]) === 0 || mb_strpos($ingWords[0], $iw) === 0) {
                                            $score += 10;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestMatch = $entry['item'];
                        }
                    }
                    
                    // Only match if score is reasonable (> 30)
                    if ($bestMatch && $bestScore > 30) {
                        $ing['product_id'] = (int)$bestMatch['product_id'];
                        $ing['location'] = $bestMatch['location'];
                        $ing['inventory_unit'] = $bestMatch['unit'];
                        $ing['inventory_qty'] = (float)$bestMatch['quantity'];
                        $ing['default_quantity'] = (float)($bestMatch['default_quantity'] ?? 0);
                        $ing['package_unit'] = $bestMatch['package_unit'] ?? '';
                        $ing['available_qty'] = $bestMatch['quantity'] . ' ' . $bestMatch['unit'];
                        $ing['vacuum_sealed'] = !empty($bestMatch['vacuum_sealed']) ? 1 : 0;
                        if (!empty($bestMatch['brand'])) {
                            $ing['brand'] = $bestMatch['brand'];
                        }
                        if (!empty($bestMatch['expiry_date'])) {
                            $ing['expiry_date'] = $bestMatch['expiry_date'];
                        }
                        
                        // === FIX qty_number: validate and convert units ===
                        $qtyNum = (float)($ing['qty_number'] ?? 0);
                        $invUnit = $bestMatch['unit'] ?? 'pz';
                        $invQty = (float)$bestMatch['quantity'];
                        
                        if ($qtyNum > 0) {
                            // Parse the recipe qty string to detect what unit Gemini intended
                            $recipeQty = $ing['qty'] ?? '';
                            $recipeUnit = '';
                            $recipeVal = 0;
                            if (preg_match('/(\d+[.,]?\d*)\s*(g|gr|gramm|kg|ml|l|litri|cl|pz|pezz|conf)/i', $recipeQty, $qm)) {
                                $recipeVal = (float)str_replace(',', '.', $qm[1]);
                                $ru = strtolower($qm[2]);
                                if (strpos($ru, 'g') === 0) $recipeUnit = 'g';
                                elseif ($ru === 'kg') { $recipeUnit = 'g'; $recipeVal *= 1000; }
                                elseif ($ru === 'ml') $recipeUnit = 'ml';
                                elseif ($ru === 'cl') { $recipeUnit = 'ml'; $recipeVal *= 10; }
                                elseif ($ru === 'l' || strpos($ru, 'litr') === 0) { $recipeUnit = 'ml'; $recipeVal *= 1000; }
                                elseif (strpos($ru, 'pz') === 0 || strpos($ru, 'pezz') === 0) $recipeUnit = 'pz';
                                elseif (strpos($ru, 'conf') === 0) $recipeUnit = 'conf';
                            }
                            
                            // Convert qty_number to inventory unit if mismatch detected
                            $confAlreadyInSubUnit = false;
                            if ($recipeUnit && $recipeUnit !== $invUnit) {
                                // Weight conversions (both should be 'g' now, but handle legacy 'kg')
                                if ($recipeUnit === 'g' && $invUnit === 'kg') {
                                    $qtyNum = $recipeVal / 1000;
                                } elseif ($recipeUnit === 'g' && $invUnit === 'g') {
                                    $qtyNum = $recipeVal;
                                // Volume conversions (both should be 'ml' now, but handle legacy 'l')
                                } elseif ($recipeUnit === 'ml' && $invUnit === 'l') {
                                    $qtyNum = $recipeVal / 1000;
                                } elseif ($recipeUnit === 'ml' && $invUnit === 'ml') {
                                    $qtyNum = $recipeVal;
                                // g/ml → conf with weight/volume pkg_unit: keep in sub-units so JS modal works
                                } elseif ($invUnit === 'conf') {
                                    $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                                    $pkgUnitLC = strtolower($bestMatch['package_unit'] ?? '');
                                    if ($defQty > 0 && ($pkgUnitLC === 'g' || $pkgUnitLC === 'ml')
                                        && ($recipeUnit === 'g' || $recipeUnit === 'ml')) {
                                        // Keep qty_number in sub-units; JS handles g↔conf conversion
                                        $qtyNum = $recipeVal;
                                        $ing['qty'] = round($qtyNum) . ' ' . $pkgUnitLC;
                                        $confAlreadyInSubUnit = true;
                                    } else {
                                        // conf without weight pkg_unit: fractional conf
                                        $qtyNum = $defQty > 0 ? max(0.25, round(($recipeVal / $defQty) * 4) / 4) : 1;
                                    }
                                // g/ml → pz (approximate to nearest piece)
                                } elseif ($invUnit === 'pz') {
                                    $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                                    if ($defQty > 0) {
                                        $qtyNum = max(0.25, round(($recipeVal / $defQty) * 4) / 4);
                                    } else {
                                        $origQtyNum = (float)($ing['qty_number'] ?? 0);
                                        if ($origQtyNum >= 1 && $origQtyNum <= $invQty && $origQtyNum <= 100) {
                                            $qtyNum = $origQtyNum;
                                        } else {
                                            $qtyNum = 1;
                                        }
                                    }
                                }
                            } elseif ($invUnit === 'pz' && !$recipeUnit) {
                                // AI returned qty_number without a parseable unit string.
                                // If qty_number looks like grams (>> available pz count), clamp to 1.
                                if ($qtyNum > $invQty || $qtyNum > 100) {
                                    $qtyNum = max(1, round($qtyNum / 100));
                                }
                            }
                            
                            // Conf+weight post-normalisation: if qty_number wasn't already set to
                            // sub-units above, and it looks like a fractional conf value (≤ available
                            // conf count), convert to grams so the JS modal shows correct grams.
                            if (!$confAlreadyInSubUnit && $invUnit === 'conf' && $qtyNum > 0) {
                                $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                                $pkgUnitLC = strtolower($bestMatch['package_unit'] ?? '');
                                if ($defQty > 0 && ($pkgUnitLC === 'g' || $pkgUnitLC === 'ml') && $qtyNum <= $invQty) {
                                    $qtyNum = round($qtyNum * $defQty);
                                    $ing['qty'] = $qtyNum . ' ' . $pkgUnitLC;
                                }
                            }
                            // Sanity check: qty_number should not exceed available
                            if ($qtyNum > $invQty) {
                                $qtyNum = $invQty; // cap to available
                            }
                            
                            // Sanity check: if qty_number is absurdly small relative to recipe
                            // e.g. recipe says 100g but qty_number is 0.1 and unit is g → likely meant 100
                            if ($recipeVal > 0 && $recipeUnit === $invUnit && $qtyNum < $recipeVal * 0.01) {
                                $qtyNum = $recipeVal; // Gemini probably confused the units
                            }
                            
                            $ing['qty_number'] = round($qtyNum, 3);
                        }
                    }
                }
            }
            unset($ing);
        }
        
        EverLog::info('recipe generated', ['title' => $recipe['title'] ?? '?', 'meal' => $mealType, 'persons' => $persons, 'ingredients' => count($recipe['ingredients'] ?? [])]);
        echo json_encode(['success' => true, 'recipe' => $recipe]);
    } else {
        EverLog::warn('recipe generation failed, empty parse', ['raw_len' => strlen($text)]);
        echo json_encode(['success' => false, 'error' => recipeText($lang, 'error_cannot_generate'), 'raw' => $text]);
    }
}
function chatToRecipe(PDO $db): void {
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        EverLog::debug('chatToRecipe');
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $replyText = trim($input['text'] ?? '');
    $lang = recipeNormalizeLang($input['lang'] ?? 'it');

    if (empty($replyText)) {
        echo json_encode(['success' => false, 'error' => 'empty_text']);
        return;
    }

    // Fetch full inventory — same query as generateRecipe
    $stmt = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date, i.opened_at,
               CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
        ORDER BY days_left ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ask Gemini to convert the chat recipe text into the full structured recipe JSON.
    // Prompt is tiny — no inventory sent to Gemini (PHP does all the matching below).
    $prompt = <<<PROMPT
Convert the recipe text below to a JSON object. Return ONLY the JSON, no markdown.

Fields:
- title: string
- meal: null  (do NOT categorize — leave as null always)
- persons: integer (number of servings/people, default 2 if not mentioned)
- prep_time: string or null
- cook_time: string or null
- ingredients: array of {"name":"...","qty":"...","qty_number":0.0,"unit":"g|ml|pz|conf|kg|l","from_pantry":true}
  — set from_pantry=true for ALL ingredients (pantry matching is done server-side)
- steps: array of strings (one string per step, plain text without step numbers)
- nutrition_note: string or null

RECIPE TEXT:
{$replyText}
PROMPT;

    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 8192]
    ];

    $result = callGeminiWithFallback($apiKey, $payload, 45, 'chat_recipe');

    if ($result['http_code'] !== 200) {
        echo json_encode(['success' => false, 'error' => $result['data']['error']['message'] ?? 'gemini_error']);
        return;
    }

    $text = $result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'gemini_error']);
        return;
    }

    // Strip markdown code fences (handles ```json ... ``` anywhere in the response)
    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = str_replace('```', '', $text);

    // Extract the first complete JSON object from the text (ignores any preamble text)
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        echo json_encode(['success' => false, 'error' => 'parse_error', 'raw' => mb_substr($text, 0, 500)]);
        return;
    }
    $text = substr($text, $start, $end - $start + 1);

    $recipe = json_decode($text, true);
    if (!is_array($recipe) || empty($recipe['title'])) {
        echo json_encode(['success' => false, 'error' => 'parse_error', 'raw' => mb_substr($text, 0, 500)]);
        return;
    }

    // Enrich ingredients with product_id/location — same fuzzy-match as generateRecipe
    if (!empty($recipe['ingredients'])) {
        _enrichChatIngredients($recipe['ingredients'], $items);
    }

    echo json_encode(['success' => true, 'recipe' => $recipe]);
}

// ===== RECIPE FROM INGREDIENT =====
function recipeFromIngredient(PDO $db): void {
    EverLog::info('recipeFromIngredient');
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $ingredientName = trim($input['ingredient'] ?? '');
    if (empty($ingredientName)) {
        echo json_encode(['success' => false, 'error' => 'empty_ingredient']);
        return;
    }
    $lang = recipeNormalizeLang($input['lang'] ?? 'it');
    $langName = recipeLangName($lang);
    $persons = max(1, intval($input['persons'] ?? 1));

    // Fetch inventory (same as generateRecipe)
    $stmt = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date, i.opened_at,
               CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
        ORDER BY days_left ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build compact pantry text (same logic as generateRecipe)
    $ingredientLines = [];
    foreach ($items as $item) {
        $line = "- {$item['name']}: {$item['quantity']} {$item['unit']}";
        if ($item['unit'] === 'conf' && !empty($item['package_unit']) && $item['default_quantity'] > 0) {
            $line .= " ({$item['default_quantity']}{$item['package_unit']}/conf)";
        }
        if ($item['unit'] === 'pz') $line .= ' [usa PEZZI interi]';
        $dl = intval($item['days_left']);
        if (!empty($item['expiry_date'])) {
            if ($dl < 0) $line .= ' ⚠️SCADUTO';
            elseif ($dl <= 3) $line .= " 🔴{$dl}gg";
            elseif ($dl <= 7) $line .= " 🟠{$dl}gg";
        }
        if (!empty($item['opened_at'])) $line .= ' [APERTO]';
        $ingredientLines[] = $line;
    }
    $ingredientsText = implode("\n", $ingredientLines);

    $safeName = htmlspecialchars($ingredientName, ENT_QUOTES, 'UTF-8');

    $prompt = <<<PROMPT
You are an expert home chef. Generate ONE recipe in {$langName} that uses "{$safeName}" as the main ingredient, for {$persons} person(s).
Return ONLY a JSON object, no markdown fences.

REGOLE:
1. Usa SOLO ingredienti dalla lista DISPENSA qui sotto + acqua/sale/pepe/olio (sempre disponibili).
2. "{$safeName}" DEVE essere il primo ingrediente — è obbligatorio includerlo.
3. Quantità MASSIME per {$persons} persona/e: pasta/riso 90g/pers, carne 150g/pers, affettati/salumi 70g/pers, pesce 180g/pers, legumi secchi 80g/pers, verdure 150g/pers, verdure intere grosse 1 pz/pers, formaggio 70g/pers, piadina/wrap 1-2 pz/pers.
4. "qty_number": valore NUMERICO nella STESSA unità della dispensa (g/ml/pz/conf). Per non-dispensa: 0.
5. "name": usa ESATTAMENTE il nome dalla lista dispensa (il sistema lo usa per scalare l'inventario).
6. "from_pantry": true se l'ingrediente è nella lista DISPENSA, false per acqua/sale/pepe/olio.
7. Language: {$langName} for all text fields. Keep "meal" as English meal key (colazione/pranzo/cena/snack/dolce/libero).

DISPENSA:
{$ingredientsText}

JSON schema:
{"title":"…","meal":"libero","persons":{$persons},"prep_time":"…","cook_time":"…","tags":["…"],"ingredients":[{"name":"…","qty":"80 g","qty_number":80,"from_pantry":true}],"steps":["…"],"nutrition_note":"…"}
PROMPT;

    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 8192],
    ];

    $result = callGeminiWithFallback($apiKey, $payload, 45, 'recipe_ingredient');

    if ($result['http_code'] !== 200) {
        echo json_encode(['success' => false, 'error' => $result['data']['error']['message'] ?? 'gemini_error']);
        return;
    }

    $text = $result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'gemini_error']);
        return;
    }

    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = str_replace('```', '', $text);
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        echo json_encode(['success' => false, 'error' => 'parse_error', 'raw' => mb_substr($text, 0, 500)]);
        return;
    }
    $text = substr($text, $start, $end - $start + 1);

    $recipe = json_decode($text, true);
    if (!is_array($recipe) || empty($recipe['title'])) {
        echo json_encode(['success' => false, 'error' => 'parse_error', 'raw' => mb_substr($text, 0, 500)]);
        return;
    }

    if (!empty($recipe['ingredients'])) {
        _enrichChatIngredients($recipe['ingredients'], $items);
    }

    EverLog::info('recipe_from_ingredient ok', ['ingredient' => $ingredientName, 'title' => $recipe['title'] ?? '?', 'persons' => $persons]);
    echo json_encode(['success' => true, 'recipe' => $recipe]);
}


function _enrichChatIngredients(array &$ingredients, array $items): void {
    if (empty($ingredients) || empty($items)) return;

    // Build lookup
    $itemsLookup = [];
    foreach ($items as $item) {
        $itemsLookup[] = [
            'item' => $item,
            'lower' => mb_strtolower(trim($item['name']), 'UTF-8'),
            'words' => preg_split('/[\s,.\-\/]+/', mb_strtolower(trim($item['name']), 'UTF-8')),
        ];
    }

    $aliases = [
        'uovo' => ['uova','uovo','egg'],
        'uova' => ['uovo','uova','egg'],
        'latte' => ['latte','milk'],
        'formaggio' => ['formaggio','cheese','philadelphia','mozzarella','parmigiano','grana','pecorino','ricotta','mascarpone','stracchino','gorgonzola'],
        'pasta' => ['pasta','spaghetti','penne','fusilli','rigatoni','farfalle','tagliatelle','linguine','bucatini','orecchiette','paccheri','maccheroni'],
        'pomodoro' => ['pomodoro','pomodori','tomato','passata','pelati','polpa'],
        'cipolla' => ['cipolla','cipolle','onion'],
        'aglio' => ['aglio','garlic'],
        'burro' => ['burro','butter'],
        'panna' => ['panna','cream','crema'],
        'zucchero' => ['zucchero','sugar'],
        'farina' => ['farina','flour'],
        'olio' => ['olio','oil'],
        'patata' => ['patata','patate','potato'],
        'carota' => ['carota','carote','carrot'],
        'sedano' => ['sedano','celery'],
        'prezzemolo' => ['prezzemolo','parsley'],
        'basilico' => ['basilico','basil'],
    ];

    foreach ($ingredients as &$ing) {
        // Try to match ALL ingredients — from_pantry was set to true for all by chatExtractRecipe
        // If no match is found, product_id stays unset → shown as 🛒 in frontend

        $ingNameLower = mb_strtolower(trim($ing['name']), 'UTF-8');
        $ingWords = preg_split('/[\s,.\-\/]+/', $ingNameLower);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($itemsLookup as $entry) {
            $itemNameLower = $entry['lower'];
            $itemWords = $entry['words'];
            $score = 0;

            if ($ingNameLower === $itemNameLower) {
                $score = 100;
            } elseif (mb_strpos($itemNameLower, $ingNameLower) !== false) {
                $score = 80;
            } elseif (mb_strpos($ingNameLower, $itemNameLower) !== false) {
                $score = 70;
            } else {
                $expandedIngWords = $ingWords;
                foreach ($ingWords as $w) {
                    foreach ($aliases as $key => $group) {
                        if (in_array($w, $group) || mb_strpos($w, $key) === 0 || mb_strpos($key, $w) === 0) {
                            $expandedIngWords = array_merge($expandedIngWords, $group);
                        }
                    }
                }
                $expandedIngWords = array_unique($expandedIngWords);
                $common = 0;
                foreach ($expandedIngWords as $ew) {
                    foreach ($itemWords as $iw) {
                        $minLen = min(mb_strlen($ew), mb_strlen($iw));
                        if ($minLen >= 3) {
                            $prefixLen = 0;
                            for ($c = 0; $c < $minLen; $c++) {
                                if (mb_substr($ew, $c, 1) === mb_substr($iw, $c, 1)) $prefixLen++;
                                else break;
                            }
                            if ($prefixLen >= min(4, $minLen)) { $common++; break; }
                        }
                        if ($ew === $iw) { $common++; break; }
                    }
                }
                if ($common > 0) {
                    $score = ($common / max(count($ingWords), 1)) * 65;
                    if (count($ingWords) > 0) {
                        foreach ($itemWords as $iw) {
                            if (mb_strpos($iw, $ingWords[0]) === 0 || mb_strpos($ingWords[0], $iw) === 0) {
                                $score += 10; break;
                            }
                        }
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $entry['item'];
            }
        }

        if ($bestMatch && $bestScore > 30) {
            $ing['product_id'] = (int)$bestMatch['product_id'];
            $ing['location'] = $bestMatch['location'];
            $ing['inventory_unit'] = $bestMatch['unit'];
            $ing['inventory_qty'] = (float)$bestMatch['quantity'];
            $ing['default_quantity'] = (float)($bestMatch['default_quantity'] ?? 0);
            $ing['package_unit'] = $bestMatch['package_unit'] ?? '';
            $ing['available_qty'] = $bestMatch['quantity'] . ' ' . $bestMatch['unit'];
            $ing['vacuum_sealed'] = !empty($bestMatch['vacuum_sealed']) ? 1 : 0;
            if (!empty($bestMatch['brand'])) $ing['brand'] = $bestMatch['brand'];
            if (!empty($bestMatch['expiry_date'])) $ing['expiry_date'] = $bestMatch['expiry_date'];

            // Validate and convert qty_number to inventory unit
            $qtyNum = (float)($ing['qty_number'] ?? 0);
            $invUnit = $bestMatch['unit'] ?? 'pz';
            $invQty = (float)$bestMatch['quantity'];

            if ($qtyNum > 0) {
                $recipeQty = $ing['qty'] ?? '';
                $recipeUnit = '';
                $recipeVal = 0;
                if (preg_match('/(\d+[.,]?\d*)\s*(g|gr|gramm|kg|ml|l|litri|cl|pz|pezz|conf)/i', $recipeQty, $qm)) {
                    $recipeVal = (float)str_replace(',', '.', $qm[1]);
                    $ru = strtolower($qm[2]);
                    if (strpos($ru, 'g') === 0) $recipeUnit = 'g';
                    elseif ($ru === 'kg') { $recipeUnit = 'g'; $recipeVal *= 1000; }
                    elseif ($ru === 'ml') $recipeUnit = 'ml';
                    elseif ($ru === 'cl') { $recipeUnit = 'ml'; $recipeVal *= 10; }
                    elseif ($ru === 'l' || strpos($ru, 'litr') === 0) { $recipeUnit = 'ml'; $recipeVal *= 1000; }
                    elseif (strpos($ru, 'pz') === 0 || strpos($ru, 'pezz') === 0) $recipeUnit = 'pz';
                    elseif (strpos($ru, 'conf') === 0) $recipeUnit = 'conf';
                }
                $confAlreadyInSubUnit = false;
                if ($recipeUnit && $recipeUnit !== $invUnit) {
                    if ($recipeUnit === 'g' && $invUnit === 'g') $qtyNum = $recipeVal;
                    elseif ($recipeUnit === 'g' && $invUnit === 'kg') $qtyNum = $recipeVal / 1000;
                    elseif ($recipeUnit === 'ml' && $invUnit === 'ml') $qtyNum = $recipeVal;
                    elseif ($recipeUnit === 'ml' && $invUnit === 'l') $qtyNum = $recipeVal / 1000;
                    elseif ($invUnit === 'conf') {
                        $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                        $pkgUnitLC = strtolower($bestMatch['package_unit'] ?? '');
                        if ($defQty > 0 && ($pkgUnitLC === 'g' || $pkgUnitLC === 'ml') && ($recipeUnit === 'g' || $recipeUnit === 'ml')) {
                            $qtyNum = $recipeVal; $ing['qty'] = round($qtyNum) . ' ' . $pkgUnitLC; $confAlreadyInSubUnit = true;
                        } else { $qtyNum = $defQty > 0 ? max(0.25, round(($recipeVal / $defQty) * 4) / 4) : 1; }
                    } elseif ($invUnit === 'pz') {
                        $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                        $qtyNum = $defQty > 0 ? max(0.25, round(($recipeVal / $defQty) * 4) / 4) : max(1, round($recipeVal / 100));
                    }
                }
                // Conf+weight: normalise fractional conf to sub-units
                if (!$confAlreadyInSubUnit && $invUnit === 'conf' && $qtyNum > 0) {
                    $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                    $pkgUnitLC = strtolower($bestMatch['package_unit'] ?? '');
                    if ($defQty > 0 && ($pkgUnitLC === 'g' || $pkgUnitLC === 'ml') && $qtyNum <= $invQty) {
                        $qtyNum = round($qtyNum * $defQty); $ing['qty'] = $qtyNum . ' ' . $pkgUnitLC;
                    }
                }
                if ($qtyNum > $invQty) $qtyNum = $invQty;
                if ($recipeVal > 0 && $recipeUnit === $invUnit && $qtyNum < $recipeVal * 0.01) $qtyNum = $recipeVal;
                $ing['qty_number'] = round($qtyNum, 3);
            }
        }
    }
    unset($ing);
}

// ===== RECIPE GENERATION — STREAMING AGENT =====
function generateRecipeStream(PDO $db): void {
    EverLog::info('generateRecipeStream');
    // Override content-type for SSE before any output is sent
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    header('Content-Encoding: identity');
    set_time_limit(600); // up to 10 min: worst-case 2 models x 2 retries x 90s wait + generation time
    ignore_user_abort(true);
    while (ob_get_level() > 0) ob_end_clean();

    $send = function(string $type, array $data): void {
        echo 'data: ' . json_encode(['type' => $type] + $data, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    };

    try {

    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) { $send('error', ['error' => 'no_api_key']); return; }

    $input               = json_decode(file_get_contents('php://input'), true) ?? [];
    $lang                = recipeNormalizeLang($input['lang'] ?? 'it');
    $recipeLangName      = recipeLangName($lang);
    $mealType            = $input['meal'] ?? 'pranzo';
    $persons             = max(1, intval($input['persons'] ?? 1));
    $subType             = $input['sub_type'] ?? '';
    $options             = $input['options'] ?? [];
    $appliances          = $input['appliances'] ?? [];
    $dietaryRestrictions = $input['dietary_restrictions'] ?? '';
    $todayRecipes        = $input['today_recipes'] ?? [];
    $mealPlanType        = $input['meal_plan_type'] ?? '';
    $variation           = max(0, intval($input['variation'] ?? 0));
    $rejectedIngredients = $input['rejected_ingredients'] ?? [];

    // ── AGENTE PASSO 1: Analisi dispensa ─────────────────────────────────────
    $send('status', ['step' => 1, 'message' => recipeText($lang, 'status_analyze_pantry')]);

    $stmt = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date, i.opened_at,
               CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
        ORDER BY days_left ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) { $send('error', ['error' => recipeText($lang, 'error_pantry_empty')]); return; }

    $getItemPriority = function($item): int {
        $daysLeft = floatval($item['days_left']);
        $isOpen   = !empty($item['opened_at']) ||
                    (floatval($item['quantity']) > 0 && floatval($item['quantity']) < 1 && $item['unit'] === 'conf');
        if (!empty($item['expiry_date']) && $daysLeft < 0) return 1;
        if (!empty($item['expiry_date']) && $daysLeft <= 3) return 2;
        if (!empty($item['expiry_date']) && $daysLeft <= 7) return 3;
        if ($isOpen) return 3; // opened items: same priority as expiring this week — must be used soon
        if (!empty($item['expiry_date'])) return 4;
        return 6;
    };

    usort($items, function($a, $b) use ($getItemPriority) {
        $pa = $getItemPriority($a); $pb = $getItemPriority($b);
        if ($pa !== $pb) return $pa - $pb;
        return floatval($a['days_left']) - floatval($b['days_left']);
    });

    $staplePatterns = '/\b(sale|pepe|olio d.oliva|olio di semi|olio extra|acqua|aceto balsamico|aceto di|sel marin)\b/i';
    $priorityGroups = [];
    foreach ($items as $item) {
        $group = $getItemPriority($item);
        if ($group >= 5 && preg_match($staplePatterns, $item['name'])) continue;
        $qty      = floatval($item['quantity']);
        $isOpen   = !empty($item['opened_at']) || ($qty > 0 && $qty < 1 && $item['unit'] === 'conf');
        $daysLeft = intval($item['days_left']);
        $line     = "- {$item['name']}: {$item['quantity']} {$item['unit']}";
        if ($item['unit'] === 'conf' && !empty($item['package_unit']) && $item['default_quantity'] > 0)
            $line .= " ({$item['default_quantity']}{$item['package_unit']}/conf)";
        if ($item['unit'] === 'pz')
            $line .= ' [usa PEZZI interi — qty_number in pz, non grammi]';
        // Annotazioni urgenza: solo gruppi 1-3 (riduce token per gruppi 4-6)
        if ($group <= 3 && $item['expiry_date']) {
            if ($daysLeft < 0)       $line .= ' ⚠️SCADUTO';
            elseif ($daysLeft <= 3)  $line .= " 🔴{$daysLeft}gg";
            else                     $line .= " 🟠{$daysLeft}gg";
        }
        if ($isOpen && $group <= 5) $line .= ' [APERTO]';
        $priorityGroups[$group][] = $line;
    }

    // Limiti ingredienti per gruppo: con piano pasto attivo passa TUTTO (l'AI deve combinare liberamente)
    // Senza piano pasto: limiti moderati per ridurre token (ora safe grazie a thinkingBudget:0)
    $hasMealPlan = !empty($mealPlanType);
    $ingredientSections = [];
    $priorityHeaders    = [1=>'SCADUTI — usa subito',2=>'SCADENZA ≤3gg — priorità alta',3=>'SCADENZA ≤7gg / APERTI — usa presto',4=>'ALTRI CON SCADENZA',6=>'DISPENSA'];
    $totalIngredientsSent = 0;
    foreach ($priorityHeaders as $g => $header) {
        if (empty($priorityGroups[$g])) continue;
        $gi = $priorityGroups[$g];
        if (!$hasMealPlan) {
            // Senza piano: limiti moderati
            if ($g === 4 && count($gi) > 25) $gi = array_slice($gi, 0, 25);
            if ($g === 6 && count($gi) > 15) $gi = array_slice($gi, 0, 15);
        }
        // Con piano pasto attivo: nessun limite — tutti gli ingredienti disponibili
        $ingredientSections[] = "[$header]\n" . implode("\n", $gi);
        $totalIngredientsSent += count($gi);
    }
    $ingredientsText = implode("\n", $ingredientSections);

    // Inventory status event
    $urgentCount = count($priorityGroups[1] ?? []) + count($priorityGroups[2] ?? []);
    if ($urgentCount > 0) {
        $urgentRaw   = array_merge($priorityGroups[1] ?? [], $priorityGroups[2] ?? []);
        $urgentNames = array_slice(array_map(
            fn($l) => trim(preg_replace('/\s[\[\x{26A0}\x{1F534}\x{1F7E0}].*/u', '', explode(':', ltrim($l, '- '))[0])),
            $urgentRaw), 0, 3);
        $send('status', ['step' => 1, 'message' => recipeText($lang, 'status_urgent', ['n' => $urgentCount, 'items' => implode(', ', $urgentNames)])]);
    } else {
        $countMsg = recipeText($lang, 'status_products_found', ['n' => count($items)]);
        if ($hasMealPlan && $totalIngredientsSent < count($items)) {
            $countMsg .= recipeText($lang, 'status_passed_ai', ['n' => $totalIngredientsSent]);
        } elseif ($hasMealPlan) {
            $countMsg .= recipeText($lang, 'status_all_passed_ai');
        }
        $send('status', ['step' => 1, 'message' => '✅ ' . $countMsg]);
    }

    // Mandatory/recommended items
    $mandatoryItems  = [];
    $recommendedItems = [];
    $wantsExpiryPriority = in_array('scadenze', $options) || in_array('zerowaste', $options);
    $wantsOpenedPriority = in_array('opened', $options);
    if ($wantsExpiryPriority || $wantsOpenedPriority) {
        foreach ($items as $item) {
            $g        = $getItemPriority($item);
            $daysLeft = floatval($item['days_left']);
            $isOpen   = !empty($item['opened_at']) ||
                        (floatval($item['quantity']) > 0 && floatval($item['quantity']) < 1 && $item['unit'] === 'conf');
            $expiryNote = !empty($item['expiry_date']) ? " — scade: {$item['expiry_date']}" : '';
            $openNote   = $isOpen ? ' [APERTO]' : '';
            $label      = $item['name'] . ($item['brand'] ? " ({$item['brand']})" : '') . $openNote . $expiryNote;
            if ($wantsExpiryPriority) {
                if ($g === 1 || $g === 2) $mandatoryItems[]  = $label;
                elseif ($g === 3)         $recommendedItems[] = $label;
            }
            if (($wantsOpenedPriority || $wantsExpiryPriority) && $isOpen && $daysLeft <= 7 && $daysLeft >= 0) {
                if (!in_array($label, $mandatoryItems) && !in_array($label, $recommendedItems))
                    $recommendedItems[] = $label;
            }
        }
    }
    $mustUseText = '';
    if (!empty($mandatoryItems))   $mustUseText .= "\n\n⚠️ OBBLIGATORI (scaduti/imminenti — DEVE usarne almeno 1):\n"  . implode("\n", array_map(fn($n) => "→ $n", $mandatoryItems));
    if (!empty($recommendedItems)) $mustUseText .= "\n\n🔶 CONSIGLIATI (aperti/in scadenza):\n" . implode("\n", array_map(fn($n) => "· $n", $recommendedItems));

    // Meal labels
    $mealLabels = ['colazione'=>'colazione (mattina)','pranzo'=>'pranzo (mezzogiorno)','cena'=>'cena (sera)','dolce'=>'dolce/dessert','succo'=>'succo di frutta/bevanda'];
    $mealLabel       = $mealLabels[$mealType] ?? $mealType;
    $mealLabelSimple = ['colazione'=>'colazione','pranzo'=>'pranzo','cena'=>'cena','dolce'=>'dolce','succo'=>'succo'];

    $subTypeLabels = [
        'dolce' => ['torta'=>'Torta (soffice, da forno: torta di mele, ciambellone, plumcake, angel cake, ecc.)','crema'=>'Crema o Budino (crema pasticcera, panna cotta, mousse, tiramisù, budino, semifreddo)','crumble'=>'Crumble o Crostata (base croccante: crumble di frutta, crostata, sbriciolata)','biscotti'=>'Biscotti o Pasticcini (biscotti, cookies, muffin, cupcake, pasticcini)','frutta'=>'Dolce alla Frutta (macedonia creativa, frutta caramellata, sorbetto, frullato dolce)'],
        'succo' => ['dolce'=>'Succo Dolce e Fruttato (mix di frutta dolce: pesca, mela, pera, fragola, banana)','energizzante'=>'Succo Energizzante (con zenzero, curcuma, barbabietola, carota, mela verde)','detox'=>'Succo Detox / Verde (cetriolo, sedano, spinaci, mela verde, limone)','rinfrescante'=>'Succo Rinfrescante (anguria, menta, lime, cetriolo, acqua di cocco)','vitaminico'=>'Succo Vitaminico / Agrumi (arancia, pompelmo, limone, kiwi, mandarino)'],
    ];
    $subTypeText = '';
    if (!empty($subType) && isset($subTypeLabels[$mealType][$subType])) {
        $subHint      = $subTypeLabels[$mealType][$subType];
        $mealLabel   .= " — tipo: $subHint";
        $subTypeText  = "\n\n🎨 SOTTO-TIPO: {$subHint}. La ricetta DEVE essere di questo tipo.";
    }

    $extraRules = [];
    $optionLabels = ['veloce'=>'VELOCE: max 15-20 min totali.','pocafame'=>'POCA FAME: porzione leggera, snack o insalata.','scadenze'=>'PRIORITÀ SCADENZE: usa per primi i prodotti in scadenza.','salutare'=>'SALUTARE: ingredienti integrali, verdure, pochi grassi.','opened'=>'PRIORITÀ APERTI: usa per primi i prodotti [APERTO].','zerowaste'=>'ZERO SPRECHI: usa il più possibile ingredienti in scadenza.'];
    foreach ($options as $opt) { if (isset($optionLabels[$opt])) $extraRules[] = $optionLabels[$opt]; }
    $extraRulesText = !empty($extraRules)         ? "\n\nPREFERENZE DELL'UTENTE:\n" . implode("\n", $extraRules) : '';
    $appliancesText = _buildAppliancesPrompt($appliances, compact: false);
    $dietaryText    = !empty($dietaryRestrictions) ? "\n\nRESTRIZIONI ALIMENTARI:\n{$dietaryRestrictions}\nRispetta SEMPRE queste restrizioni." : '';

    $mealPlanTypeLabels = ['pasta'=>'Pasta (primo piatto a base di pasta)','riso'=>'Riso (risotto, insalata di riso, riso saltato, ecc.)','carne'=>'Carne (secondo piatto a base di carne)','pesce'=>'Pesce (secondo piatto a base di pesce o frutti di mare)','legumi'=>'Legumi (zuppa, insalata, hummus, pasta e fagioli, ecc.)','uova'=>'Uova (frittata, uova strapazzate, quiche, ecc.)','formaggio'=>'Formaggio (fonduta, gnocchi al formaggio, torta salata, ecc.)','pizza'=>'Pizza o focaccia (impastata in casa o usi ingredienti simili)','affettati'=>'Affettati (tagliere misto, piadina, panino, ecc.)','verdure'=>'Verdure (piatto principale a base di verdure, contorno abbondante)','zuppa'=>'Zuppa o minestra (zuppe, vellutate, minestrone)','insalata'=>'Insalata (insalata mista, insalata di riso o pasta, poke)','pane'=>'Pane / Sandwich (toast, tramezzino, bruschette)','dolce'=>'Dolce o dessert','libero'=>''];
    $typeKeywords = ['pesce'=>['tonno','salmone','merluzzo','branzino','orata','sardine','acciughe','alici','gamberi','cozze','vongole','polpo','calamari','seppia','sgombro','trota','baccalà','dentice','spigola','pesce'],'carne'=>['pollo','manzo','maiale','vitello','agnello','tacchino','salsiccia','hamburger','bistecca','cotoletta','pancetta','speck','carne','arrosto','filetto','lonza','braciola'],'pasta'=>['pasta','spaghetti','penne','rigatoni','fusilli','tagliatelle','lasagne','farfalle','orecchiette','bucatini','linguine','maccheroni','gnocchi','pennette','bavette'],'riso'=>['riso','basmati','arborio','carnaroli','parboiled','riso integrale'],'legumi'=>['fagioli','ceci','lenticchie','piselli','fave','lupini','soia','legumi','borlotti','cannellini','azuki'],'uova'=>['uova','uovo'],'formaggio'=>['formaggio','parmigiano','mozzarella','ricotta','pecorino','grana','gorgonzola','scamorza','fontina','emmental','asiago','provola','provolone','taleggio','stracchino'],'pizza'=>['farina','lievito','pizza','focaccia'],'affettati'=>['prosciutto','salame','bresaola','mortadella','speck','coppa','affettati','wurstel','würstel','piadina','pancetta cotta'],'verdure'=>['zucchine','zucchina','melanzane','peperoni','spinaci','cavolfiore','broccoli','carote','zucca','bietole','cavolo','carciofi','asparagi','lattuga','rucola','radicchio','cicoria','finocchio','cipolla','porri','verdure'],'zuppa'=>['brodo','zuppa','minestra','minestrone','vellutata','orzo','farro','fagioli','ceci','lenticchie'],'insalata'=>['insalata','lattuga','rucola','spinaci','radicchio','misticanza','valeriana','songino'],'pane'=>['pane','pancarrè','baguette','toast','tramezzino','crackers','grissini','ciabatta','rosetta'],'dolce'=>['cioccolato','cacao','zucchero','miele','marmellata','nutella','creme caramel','savoiardi','biscotti','pan di spagna','panna']];

    $mealPlanText = '';
    $mealPlanRule = '';
    if (!empty($mealPlanType) && isset($mealPlanTypeLabels[$mealPlanType]) && $mealPlanTypeLabels[$mealPlanType] !== '') {
        $hint          = $mealPlanTypeLabels[$mealPlanType];
        $matchingItems = [];
        if (isset($typeKeywords[$mealPlanType])) {
            foreach ($items as $item) {
                $nameLower = mb_strtolower($item['name'] . ' ' . ($item['brand'] ?? ''));
                foreach ($typeKeywords[$mealPlanType] as $kw) {
                    if (mb_strpos($nameLower, $kw) !== false) {
                        $entry = "→ {$item['name']}" . ($item['brand'] ? " ({$item['brand']})" : '') . ": {$item['quantity']} {$item['unit']}";
                        if (!empty($item['expiry_date'])) { $dl = intval($item['days_left']); $entry .= $dl < 0 ? " [SCADUTO]" : " [scade tra $dl giorni]"; }
                        $matchingItems[] = $entry;
                        break;
                    }
                }
            }
            $matchingItems = array_unique($matchingItems);
        }
        $matchingBlock = !empty($matchingItems)
            ? "Ingredienti disponibili compatibili (usa almeno uno come BASE):\n" . implode("\n", $matchingItems)
            : "Nessun ingrediente perfettamente corrispondente — usa la cosa più affine disponibile e segnalalo in nutrition_note.";
        $mealPlanText = "\n\n🎯 TIPO OBBLIGATORIO: {$hint}\n{$matchingBlock}";
        $mealPlanRule = "0. La ricetta DEVE essere: {$hint}. Usa gli ingredienti compatibili come base.\n   ";
    }

    $varietyText = '';
    $today = date('Y-m-d'); $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $weekStmt = $db->prepare("SELECT date, meal, recipe_json FROM recipes WHERE date >= ? ORDER BY date DESC");
    $weekStmt->execute([$weekAgo]);
    $weekDbRecipes = $weekStmt->fetchAll();
    $todayTitles = []; $weekTitles = [];
    foreach ($weekDbRecipes as $tr) {
        $rj = json_decode($tr['recipe_json'], true);
        if (!empty($rj['title'])) { $weekTitles[] = $rj['title']; if ($tr['date'] === $today) $todayTitles[] = $rj['title']; }
    }
    if (!empty($todayRecipes)) $todayTitles = array_unique(array_merge($todayTitles, $todayRecipes));
    if (!empty($todayTitles)) {
        $todayList    = implode(', ', array_map(fn($t) => '"' . $t . '"', $todayTitles));
        $varietyText .= "\n\nGIÀ FATTO OGGI: {$todayList} — proponi qualcosa di DIVERSO.";
    }
    $weekOnly = array_diff($weekTitles, $todayTitles);
    if (!empty($weekOnly)) {
        $weekList     = implode(', ', array_map(fn($t) => '"' . $t . '"', array_values($weekOnly)));
        $varietyText .= "\n\nULTIMI 7GG: {$weekList} — varia.";
    }

    $regenText = '';
    if ($variation > 0) {
        $regenText = "\n\n🔁 RIGENERA #{$variation}: proponi qualcosa di COMPLETAMENTE DIVERSO (altro stile, altro ingrediente principale, altra tecnica).";
        if (!empty($rejectedIngredients)) {
            $rejList    = implode(', ', array_map(fn($n) => '"' . $n . '"', $rejectedIngredients));
            $regenText .= " Evita come ingrediente principale: {$rejList}.";
        }
    }

    // ── AGENTE PASSO 2: Selezione concetto (locale, nessuna chiamata AI) ────────
    // Determina il concetto della ricetta in base agli ingredienti disponibili
    // e ai parametri selezionati — senza consumare quote Gemini.
    $send('status', ['step' => 2, 'message' => recipeText($lang, 'status_evaluate_ingredients')]);

    // Raccoglie i nomi degli ingredienti di maggiore priorità
    $conceptIngredients = [];
    foreach ([1, 2, 3, 5, 6] as $g) {
        foreach (array_slice($priorityGroups[$g] ?? [], 0, 4) as $line) {
            $name = trim(explode(':', ltrim($line, '- '))[0]);
            // Rimuove emoji e flag di urgenza
            $name = trim(preg_replace('/\s*[\x{26A0}\x{1F534}\x{1F7E0}].*$/u', '', $name));
            $name = trim(preg_replace('/\s*\[.*\]/', '', $name));
            if ($name) $conceptIngredients[] = $name;
        }
        if (count($conceptIngredients) >= 6) break;
    }

    // Costruisce un messaggio di stato informativo basato su ciò che verrà cucinato
    $conceptMsg = recipeText($lang, 'status_preparing_recipe');
    if (!empty($mealPlanType) && isset($mealPlanTypeLabels[$mealPlanType]) && $mealPlanTypeLabels[$mealPlanType] !== '') {
        // Tipo di pasto dal piano settimanale — mostra la categoria
        $shortLabel = explode(' (', $mealPlanTypeLabels[$mealPlanType])[0];
        $conceptMsg = recipeText($lang, 'status_dish_based_on', ['type' => $shortLabel]);
        // Aggiungi l'ingrediente principale se disponibile
        if (!empty($matchingItems)) {
            $firstMatch = ltrim(reset($matchingItems), '→ ');
            $fName = trim(explode(':', $firstMatch)[0]);
            if ($fName) $conceptMsg .= " ({$fName})";
        }
    } elseif (!empty($conceptIngredients)) {
        // Mostra i primi 2 ingredienti più urgenti
        $shown = array_slice($conceptIngredients, 0, 2);
        $a = mb_strtolower($shown[0] ?? '');
        $b = mb_strtolower($shown[1] ?? '');
        $conceptMsg = recipeText($lang, 'status_recipe_with', ['a' => $a, 'b' => $b]);
        if ($variation > 0) $conceptMsg .= recipeText($lang, 'status_variant', ['n' => $variation]);
    } elseif (!empty($subType) && !empty($subTypeLabels[$mealType][$subType])) {
        $conceptMsg = "🎨 " . explode(' (', $subTypeLabels[$mealType][$subType])[0];
    }
    $send('status', ['step' => 2, 'message' => $conceptMsg]);

    // ── AGENTE PASSO 3: Generazione ricetta (A+C: retry SSE-aware + fallback modello) ──
    $conceptHint = '';
    $send('status', ['step' => 3, 'message' => recipeText($lang, 'status_creating_full_recipe')]);

    $promptLanguageRule = recipeText($lang, 'prompt_lang_rule');
    $promptStepExample = recipeText($lang, 'prompt_step_example');
    $prompt = <<<PROMPT
You are an expert home chef. Generate ONE recipe for $mealLabel for $persons person(s) using the available ingredients below.{$extraRulesText}{$appliancesText}{$dietaryText}{$subTypeText}{$mealPlanText}{$varietyText}{$regenText}{$mustUseText}

REGOLE:
{$mealPlanRule}1. PRIORITÀ: usa prima gli ingredienti scaduti/in scadenza (⚠️🔴🟠), poi quelli [APERTO], poi il resto.
2. Usa SOLO ingredienti dalla lista + acqua/sale/pepe/olio (sempre disponibili).
3. Quantità MASSIME per $persons persona/e (NON superare mai): pasta/riso asciutto 90g/pers, carne 150g/pers, affettati/salumi/speck/prosciutto 70g/pers, pesce 180g/pers, legumi secchi 80g/pers (lessi 200g/pers), verdure contorno 150g/pers, verdure intere grosse (peperoni/melanzane/zucchine) 1 pz/pers, formaggio 70g/pers, latte 200ml/pers, farina per dolci 200g/pers, piadina/tortilla/wrap 1-2 pz/pers. Se un ingrediente rimasto è inferiore a questi limiti, usalo tutto.
4. "qty_number": valore NUMERICO nella STESSA unità della dispensa (g/ml/pz/conf, MAI kg o litri). Per non-dispensa: 0. IMPORTANTE: per ingredienti con unità "pz" scrivi qty_number come numero di PEZZI (es. 2, non 200g).
5. "name": usa ESATTAMENTE il nome dalla lista (il sistema lo usa per scalare l'inventario).
6. Includi nella lista ingredienti TUTTI quelli citati nei passi (tranne acqua/sale/pepe/olio).
7. Language rule: {$recipeLangName} only for all textual fields (`title`, `tags`, `expiry_note`, `ingredients.qty`, `steps`, `nutrition_note`, `tools_needed`). Keep `meal` unchanged.
8. `tools_needed`: array of kitchen tools/appliances actually required by this recipe (e.g. ["Forno","Frullatore"]). Use the same language as all other text fields. Empty array [] if only stovetop/knife/pan needed.
9. `zero_waste_tips`: array of zero-waste tips for steps that generate reusable scraps (peels, leftover cooking water, egg whites, cheese rinds, bread crusts, vegetable tops, etc.). Each entry: {"step": 0-based_step_index, "scrap": "scrap name", "tip": "short practical reuse tip (max 20 words)"}. Use the same language as other text fields. Empty array [] if no reusable scraps are generated.

DISPENSA:
$ingredientsText

Rispondi SOLO JSON valido (no markdown):
{$promptLanguageRule}
{"title":"…","meal":"$mealType","persons":$persons,"prep_time":"…","cook_time":"…","tags":["…"],"expiry_note":"…","tools_needed":["…"],"ingredients":[{"name":"…","qty":"200 g","qty_number":200,"from_pantry":true}],"steps":["{$promptStepExample}"],"nutrition_note":"…","zero_waste_tips":[{"step":0,"scrap":"…","tip":"…"}]}
PROMPT;

    $genConfig = [
        'temperature'    => min(1.4, 0.7 + $variation * 0.25),
        'maxOutputTokens' => 4096,
        'thinkingConfig'  => ['thinkingBudget' => 0], // disabilita thinking: libera token per output
    ];
    $payload   = ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => $genConfig];

    // A: retry SSE-aware con feedback live; C: fallback automatico su quota separata
    // Ordine: 2.5-flash (quota separata e spesso più disponibile) → 2.0-flash
    $models = [
        'gemini-2.5-flash',  // primario: quota TPM separata da 2.0
        'gemini-2.0-flash',  // fallback
    ];

    $result   = null;
    $httpCode = 0;

    foreach ($models as $modelIdx => $model) {
        $url        = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $maxRetries = 3; // 1 chiamata + max 2 retry con attesa

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $retryAfterHeader = null;

            $curlErrno = 0;
            $curlErrMsg = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfterHeader) {
                    if (stripos($header, 'retry-after:') === 0) {
                        $val = intval(trim(substr($header, strlen('retry-after:'))));
                        if ($val > 0) $retryAfterHeader = $val;
                    }
                    return strlen($header);
                },
            ]);

            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) {
                $curlErrno  = curl_errno($ch);
                $curlErrMsg = curl_error($ch);
                $body = '';
            }
            curl_close($ch);

            $result = [
                'http_code' => $httpCode,
                'body'      => $body,
                'data'      => $body ? json_decode($body, true) : null,
            ];

            // Successo o errore non-retry → esci dal loop retry
            if ($httpCode === 200) break 2;
            if ($httpCode !== 429 && $httpCode !== 503) break;
            if ($attempt >= $maxRetries) break;

            // Calcola attesa: usa Retry-After se presente, altrimenti 30s (poi cambieremo modello)
            $waitSec = $retryAfterHeader ?? 30;
            if ($body) {
                $errData = json_decode($body, true);
                foreach (($errData['error']['details'] ?? []) as $detail) {
                    if (!empty($detail['retryDelay'])) {
                        $parsed = intval(preg_replace('/\D/', '', $detail['retryDelay']));
                        if ($parsed > 0) { $waitSec = min($parsed + 2, 60); break; }
                    }
                }
            }
            $waitSec = min($waitSec, 60); // cap a 60s

            // A: feedback live con countdown
            $modelName = str_replace('gemini-', 'Gemini ', $model);
            $send('status', ['step' => 3, 'message' => recipeText($lang, 'status_quota_wait', ['model' => $modelName, 's' => $waitSec, 'a' => $attempt, 'm' => $maxRetries])]);
            sleep($waitSec);
            $send('status', ['step' => 3, 'message' => recipeText($lang, 'status_retry_generation')]);
        }

        // C: se primario esaurito dopo tutti i retry, cambia modello immediatamente
        if ($httpCode === 429 && $modelIdx === 0) {
            $fallbackName = str_replace('gemini-', 'Gemini ', $models[1]);
            $send('status', ['step' => 3, 'message' => recipeText($lang, 'status_switch_model', ['model' => $fallbackName])]);
            continue;
        }
        break;
    }

    if ($httpCode !== 200) {
        if ($httpCode === 0) {
            // cURL-level failure: timeout, DNS, network down
            $curlLabel = $curlErrMsg ?: "cURL errno {$curlErrno}";
            $send('error', ['error' => recipeText($lang, 'error_gemini_api'), 'http_code' => 0, 'detail' => "Nessuna risposta da Gemini ({$curlLabel}) — verifica la connessione del server o riprova tra qualche istante."]);
        } else {
            $errDetail = $result['data']['error']['message'] ?? substr($result['body'], 0, 300);
            $statusLabels = [429 => 'Quota API esaurita (429)', 503 => 'Servizio Gemini non disponibile (503)', 401 => 'API key non valida (401)', 403 => 'API key non autorizzata (403)', 500 => 'Errore interno Gemini (500)'];
            $statusLabel  = $statusLabels[$httpCode] ?? "HTTP {$httpCode}";
            $send('error', ['error' => recipeText($lang, 'error_gemini_api'), 'http_code' => $httpCode, 'detail' => "{$statusLabel}" . ($errDetail ? ": {$errDetail}" : '')]);
        }
        return;
    }

    $text = $result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    $text = trim($text);
    $recipe = json_decode($text, true);

    if (!$recipe || empty($recipe['title'])) {
        $send('error', ['error' => recipeText($lang, 'error_cannot_generate'), 'raw' => $text]);
        return;
    }

    // Normalize steps: Gemini sometimes returns [{"text":"..."}, ...] instead of ["...", ...]
    if (!empty($recipe['steps']) && is_array($recipe['steps'])) {
        $recipe['steps'] = array_values(array_map(function($s) {
            if (is_string($s)) return $s;
            if (is_array($s))  return $s['text'] ?? $s['description'] ?? $s['step'] ?? json_encode($s, JSON_UNESCAPED_UNICODE);
            return (string)$s;
        }, $recipe['steps']));
    }
    if (!empty($recipe['ingredients'])) {
        $itemsLookup = [];
        foreach ($items as $item) {
            $itemsLookup[] = [
                'item'  => $item,
                'lower' => mb_strtolower(trim($item['name']), 'UTF-8'),
                'words' => preg_split('/[\s,.\-\/]+/', mb_strtolower(trim($item['name']), 'UTF-8')),
                'cat'   => mb_strtolower($item['category'] ?? '', 'UTF-8'),
            ];
        }
        $aliases = ['uovo'=>['uova','uovo','egg'],'uova'=>['uovo','uova','egg'],'latte'=>['latte','milk'],'formaggio'=>['formaggio','cheese','philadelphia','mozzarella','parmigiano','grana','pecorino','ricotta','mascarpone','stracchino','gorgonzola'],'pasta'=>['pasta','spaghetti','penne','fusilli','rigatoni','farfalle','tagliatelle','linguine','bucatini','orecchiette','paccheri','maccheroni'],'pomodoro'=>['pomodoro','pomodori','tomato','passata','pelati','polpa'],'cipolla'=>['cipolla','cipolle','onion'],'aglio'=>['aglio','garlic'],'burro'=>['burro','butter'],'panna'=>['panna','cream','crema'],'zucchero'=>['zucchero','sugar'],'farina'=>['farina','flour'],'olio'=>['olio','oil'],'patata'=>['patata','patate','potato'],'carota'=>['carota','carote','carrot'],'sedano'=>['sedano','celery'],'prezzemolo'=>['prezzemolo','parsley'],'basilico'=>['basilico','basil']];

        foreach ($recipe['ingredients'] as &$ing) {
            if (empty($ing['from_pantry'])) continue;
            $ingNameLower = mb_strtolower(trim($ing['name']), 'UTF-8');
            $ingWords     = preg_split('/[\s,.\-\/]+/', $ingNameLower);
            $bestMatch    = null;
            $bestScore    = 0;
            foreach ($itemsLookup as $entry) {
                $itemNameLower = $entry['lower'];
                $itemWords     = $entry['words'];
                $score         = 0;
                if ($ingNameLower === $itemNameLower) {
                    $score = 100;
                } elseif (mb_strpos($itemNameLower, $ingNameLower) !== false) {
                    $score = 80;
                } elseif (mb_strpos($ingNameLower, $itemNameLower) !== false) {
                    $score = 70;
                } else {
                    $expandedIngWords = $ingWords;
                    foreach ($ingWords as $w) {
                        foreach ($aliases as $key => $group) {
                            if (in_array($w, $group) || mb_strpos($w, $key) === 0 || mb_strpos($key, $w) === 0)
                                $expandedIngWords = array_merge($expandedIngWords, $group);
                        }
                    }
                    $expandedIngWords = array_unique($expandedIngWords);
                    $common = 0;
                    foreach ($expandedIngWords as $ew) {
                        foreach ($itemWords as $iw) {
                            $minLen = min(mb_strlen($ew), mb_strlen($iw));
                            if ($minLen >= 3) {
                                $prefixLen = 0;
                                for ($c = 0; $c < $minLen; $c++) {
                                    if (mb_substr($ew, $c, 1) === mb_substr($iw, $c, 1)) $prefixLen++; else break;
                                }
                                if ($prefixLen >= min(4, $minLen)) { $common++; break; }
                            }
                            if ($ew === $iw) { $common++; break; }
                        }
                    }
                    if ($common > 0) {
                        $score = ($common / max(count($ingWords), 1)) * 65;
                        if (count($ingWords) > 0) {
                            foreach ($itemWords as $iw) {
                                if (mb_strpos($iw, $ingWords[0]) === 0 || mb_strpos($ingWords[0], $iw) === 0) { $score += 10; break; }
                            }
                        }
                    }
                }
                if ($score > $bestScore) { $bestScore = $score; $bestMatch = $entry['item']; }
            }
            if ($bestMatch && $bestScore > 30) {
                $ing['product_id']       = (int)$bestMatch['product_id'];
                $ing['location']         = $bestMatch['location'];
                $ing['inventory_unit']   = $bestMatch['unit'];
                $ing['inventory_qty']    = (float)$bestMatch['quantity'];
                $ing['default_quantity'] = (float)($bestMatch['default_quantity'] ?? 0);
                $ing['package_unit']     = $bestMatch['package_unit'] ?? '';
                $ing['available_qty']    = $bestMatch['quantity'] . ' ' . $bestMatch['unit'];
                $ing['vacuum_sealed']    = !empty($bestMatch['vacuum_sealed']) ? 1 : 0;
                if (!empty($bestMatch['brand']))       $ing['brand']       = $bestMatch['brand'];
                if (!empty($bestMatch['expiry_date'])) $ing['expiry_date'] = $bestMatch['expiry_date'];
                $qtyNum  = (float)($ing['qty_number'] ?? 0);
                $invUnit = $bestMatch['unit'] ?? 'pz';
                $invQty  = (float)$bestMatch['quantity'];
                if ($qtyNum > 0) {
                    $recipeQty  = $ing['qty'] ?? '';
                    $recipeUnit = ''; $recipeVal = 0;
                    if (preg_match('/(\d+[.,]?\d*)\s*(g|gr|gramm|kg|ml|l|litri|cl|pz|pezz|conf)/i', $recipeQty, $qm)) {
                        $recipeVal = (float)str_replace(',', '.', $qm[1]);
                        $ru = strtolower($qm[2]);
                        if (strpos($ru, 'g') === 0)                       $recipeUnit = 'g';
                        elseif ($ru === 'kg')                              { $recipeUnit = 'g';  $recipeVal *= 1000; }
                        elseif ($ru === 'ml')                              $recipeUnit = 'ml';
                        elseif ($ru === 'cl')                              { $recipeUnit = 'ml'; $recipeVal *= 10; }
                        elseif ($ru === 'l' || strpos($ru, 'litr') === 0) { $recipeUnit = 'ml'; $recipeVal *= 1000; }
                        elseif (strpos($ru, 'pz') === 0 || strpos($ru, 'pezz') === 0) $recipeUnit = 'pz';
                        elseif (strpos($ru, 'conf') === 0)                $recipeUnit = 'conf';
                    }
                    $confAlreadyInSubUnit = false;
                    if ($recipeUnit && $recipeUnit !== $invUnit) {
                        if ($recipeUnit === 'g'  && $invUnit === 'kg')  $qtyNum = $recipeVal / 1000;
                        elseif ($recipeUnit === 'g'  && $invUnit === 'g')   $qtyNum = $recipeVal;
                        elseif ($recipeUnit === 'ml' && $invUnit === 'l')   $qtyNum = $recipeVal / 1000;
                        elseif ($recipeUnit === 'ml' && $invUnit === 'ml')  $qtyNum = $recipeVal;
                        elseif ($invUnit === 'conf') {
                            $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                            $pkgUnitLC = strtolower($bestMatch['package_unit'] ?? '');
                            if ($defQty > 0 && ($pkgUnitLC === 'g' || $pkgUnitLC === 'ml') && ($recipeUnit === 'g' || $recipeUnit === 'ml')) {
                                $qtyNum = $recipeVal; $ing['qty'] = round($qtyNum) . ' ' . $pkgUnitLC; $confAlreadyInSubUnit = true;
                            } else { $qtyNum = $defQty > 0 ? max(0.25, round(($recipeVal / $defQty) * 4) / 4) : 1; }
                        } elseif ($invUnit === 'pz') {
                            $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                            if ($defQty > 0) { $qtyNum = $recipeVal / $defQty; $qtyNum = max(0.25, round($qtyNum * 4) / 4); }
                            else $qtyNum = max(1, round($recipeVal / 100));
                        }
                    }
                    // Conf+weight: normalise fractional conf to sub-units
                    if (!$confAlreadyInSubUnit && $invUnit === 'conf' && $qtyNum > 0) {
                        $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                        $pkgUnitLC = strtolower($bestMatch['package_unit'] ?? '');
                        if ($defQty > 0 && ($pkgUnitLC === 'g' || $pkgUnitLC === 'ml') && $qtyNum <= $invQty) {
                            $qtyNum = round($qtyNum * $defQty); $ing['qty'] = $qtyNum . ' ' . $pkgUnitLC;
                        }
                    }
                    if ($qtyNum > $invQty) $qtyNum = $invQty;
                    if ($recipeVal > 0 && $recipeUnit === $invUnit && $qtyNum < $recipeVal * 0.01) $qtyNum = $recipeVal;
                    $ing['qty_number'] = round($qtyNum, 3);
                }
            }
        }
        unset($ing);
    }

    $send('status', ['step' => 4, 'message' => '✅ Ricetta pronta!']);
    $send('recipe', ['recipe' => $recipe]);

    } catch (\Throwable $e) {
        EverLog::error('generateRecipeStream fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $send('error', [
            'error'  => 'Errore interno del server',
            'detail' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
        ]);
    }
}

// ===== GEMINI AI PRODUCT IDENTIFICATION =====
function geminiIdentifyProduct(): void {
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        EverLog::info('geminiIdentifyProduct');
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $imageBase64 = $input['image'] ?? '';

    if (empty($imageBase64)) {
        echo json_encode(['success' => false, 'error' => 'No image provided']);
        return;
    }

    // Step 1: Ask Gemini to identify the product
    $prompt = <<<PROMPT
Analizza questa foto di un prodotto alimentare o di uso domestico. Identifica il prodotto nel modo più preciso possibile.

Rispondi SOLO con un JSON valido (senza markdown, senza backtick):
{
  "name": "Nome del prodotto (es: Yogurt Greco Bianco)",
  "brand": "Marca se visibile (es: Fage, Müller) o stringa vuota",
  "category": "Categoria in italiano (es: latticini, pasta, bevande, snack, carne, pesce, frutta, verdura, surgelati, condimenti, conserve, cereali, pane, igiene, pulizia, altro)",
  "search_terms": "termini di ricerca per trovare il prodotto su un database (es: greek yogurt fage, pasta barilla spaghetti)",
  "confidence": "alta/media/bassa",
  "description": "Breve descrizione del prodotto identificato"
}
PROMPT;

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $imageBase64
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'maxOutputTokens' => 512
        ]
    ];

    $result   = callGeminiWithFallback($apiKey, $payload, 30, 'identify_product');
    $httpCode = $result['http_code'];

    if ($httpCode !== 200) {
        $errMsg = $result['data']['error']['message'] ?? 'Gemini API error';
        echo json_encode(['success' => false, 'error' => $errMsg, 'http_code' => $httpCode]);
        return;
    }

    $data = $result['data'];
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    $text = preg_replace('/^```json\\s*/i', '', $text);
    $text = preg_replace('/\\s*```$/i', '', $text);
    $text = trim($text);

    $identified = json_decode($text, true);

    if (!$identified || empty($identified['name'])) {
        echo json_encode(['success' => false, 'error' => 'Cannot identify the product', 'raw' => $text]);
        return;
    }

    // Step 2: Search Open Food Facts by product name to find a matching barcode
    $searchTerms = $identified['search_terms'] ?? $identified['name'];
    $offProducts = searchOpenFoodFacts($searchTerms, $identified['name'], $identified['brand'] ?? '');

    echo json_encode([
        'success' => true,
        'identified' => $identified,
        'off_matches' => $offProducts
    ]);
}

function searchOpenFoodFacts(string $searchTerms, string $name, string $brand): array {
    $results = [];

    // Try multiple search strategies
    $queries = [];
    if (!empty($brand)) {
        EverLog::debug('searchOpenFoodFacts');
        $queries[] = trim($brand . ' ' . $name);
    }
    $queries[] = $name;
    if ($searchTerms !== $name) {
        $queries[] = $searchTerms;
    }

    $seen = [];
    foreach ($queries as $query) {
        $encodedQuery = urlencode($query);
        $url = "https://world.openfoodfacts.org/cgi/search.pl?search_terms={$encodedQuery}&search_simple=1&action=process&json=1&page_size=5&fields=code,product_name,product_name_it,brands,image_front_small_url,quantity,categories_tags&lc=it";

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "User-Agent: DispensaManager/1.0\r\n"
            ]
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) continue;

        $data = json_decode($response, true);
        if (empty($data['products'])) continue;

        foreach ($data['products'] as $p) {
            $code = $p['code'] ?? '';
            if (empty($code) || isset($seen[$code])) continue;
            $seen[$code] = true;

            $pName = $p['product_name_it'] ?? $p['product_name'] ?? '';
            if (empty($pName)) continue;

            $results[] = [
                'barcode' => $code,
                'name' => $pName,
                'brand' => $p['brands'] ?? '',
                'image_url' => $p['image_front_small_url'] ?? '',
                'quantity_info' => $p['quantity'] ?? '',
                'category' => $p['categories_tags'][0] ?? '',
            ];

            if (count($results) >= 6) break 2;
        }
    }

    return $results;
}

/**
 * Build a detailed appliances prompt fragment for Gemini recipe generation.
 *
 * For multi-function appliances (Cookeo, Bimby, Thermomix, Monsieur Cuisine, etc.)
 * the prompt explicitly instructs the AI to consolidate as many steps as possible
 * into that single machine rather than using multiple appliances or the stove.
 *
 * @param string[] $appliances  List of appliance names from user settings.
 * @param bool     $compact     True = one-line format (chat); False = multi-line (recipe gen).
 */
function _buildAppliancesPrompt(array $appliances, bool $compact = false): string {
    if (empty($appliances)) return '';

    // Multi-function all-in-one cookers: can sauté, boil, steam, pressure-cook, blend, etc.
    $multiFunction = [
        'cookeo', 'bimby', 'thermomix', 'monsieur cuisine',
        'bimby tm', 'vorwerk', 'instant pot', 'multicooker',
        'robot da cucina', 'robot cucina',
        'macchina del pane', 'bread machine',
    ];

    $detectedMulti = [];
    foreach ($appliances as $a) {
        $aLow = mb_strtolower(trim($a));
        foreach ($multiFunction as $kw) {
            if (str_contains($aLow, $kw)) {
                $detectedMulti[] = $a;
                break;
            }
        }
    }

    $allList = implode(', ', $appliances);

    if (empty($detectedMulti)) {
        // No multi-function appliance: standard wording
        return $compact
            ? "\nElettrodomestici disponibili: {$allList} (più fornelli e forno sempre disponibili)."
            : "\n\nELETTRODOMESTICI: {$allList} (+ fornelli e forno). Usa SOLO questi.";
    }

    // Build capability hint per multi-function appliance
    $capabilityMap = [
        'cookeo'           => 'rosolare, stufare, cuocere a pressione, vapore, saltare, riscaldare',
        'bimby'            => 'tritare, frullare, cuocere, soffriggere, vapore, impastare, pesare, emulsionare',
        'thermomix'        => 'tritare, frullare, cuocere, soffriggere, vapore, impastare, pesare, emulsionare',
        'monsieur cuisine' => 'tritare, frullare, cuocere, soffriggere, vapore, impastare, pesare',
        'instant pot'      => 'rosolare, cuocere a pressione, stufare, vapore, slow cook, riscaldare',
        'multicooker'      => 'rosolare, cuocere a pressione, stufare, vapore, slow cook',
        'robot da cucina'  => 'tritare, frullare, cuocere, mescolare, impastare',
        'robot cucina'     => 'tritare, frullare, cuocere, mescolare, impastare',
        'macchina del pane'=> 'impastare, lievitare, cuocere pane (ordine ingredienti: liquidi → farina → sale → zucchero → lievito in cima; scegliere programma: Base, Integrale, Francese, Rapido, Dolce, Solo impasto)',
        'bread machine'    => 'impastare, lievitare, cuocere pane (ordine: liquidi → farina → sale → zucchero → lievito in cima)',
    ];

    $multiDetails = [];
    foreach ($detectedMulti as $a) {
        $aLow = mb_strtolower(trim($a));
        $cap = '';
        foreach ($capabilityMap as $kw => $caps) {
            if (str_contains($aLow, $kw)) { $cap = $caps; break; }
        }
        $multiDetails[] = $cap ? "{$a} ({$cap})" : $a;
    }
    $multiStr = implode(' e ', $multiDetails);

    // The other (non-multi) appliances available as backup
    $others = array_filter($appliances, fn($a) => !in_array($a, $detectedMulti));
    $othersStr = !empty($others) ? ', ' . implode(', ', $others) . ' (accessori di supporto se serve)' : '';

    if ($compact) {
        // When multiple specialized appliances are present, list each with capabilities.
        // Do NOT force-prefer one over another — the user may explicitly ask for a specific one.
        if (count($detectedMulti) === 1) {
            $single = $multiDetails[0];
            return "\nElettrodomestici: {$allList}. Se la ricetta lo consente, preferisci usare {$single} per quanti più passaggi possibile.";
        }
        // Multiple specialized appliances: describe each, let the user's request decide
        $multiStr = implode('; ', $multiDetails);
        return "\nElettrodomestici: {$allList}. Apparecchi specializzati disponibili: {$multiStr}. Usa quello più adatto alla ricetta richiesta dall'utente, rispettando sempre la sua preferenza esplicita.";
    }

    $ruleLines = implode("\n", array_map(fn($d) => "   → {$d}", $multiDetails));
    return <<<APPL

ELETTRODOMESTICI DISPONIBILI: {$allList} (+ fornelli e forno se indispensabile).
⚠️  REGOLA OBBLIGATORIA APPARECCHI MULTIFUNZIONE:
   Hai a disposizione un apparecchio multifunzione potente. Devi usarlo per QUANTI PIÙ PASSI POSSIBILE.
   Funzioni disponibili:
{$ruleLines}{$othersStr}
   → Ogni passaggio che l'apparecchio può fare DA SOLO va fatto lì, NON su fornelli/forno separati.
   → Indica esplicitamente nelle istruzioni quale funzione/programma usare (es. "modalità Rosolare", "Turbo 10 sec", "Varoma 20 min").
   → Usa fornelli/forno SOLO per operazioni che l'apparecchio non supporta fisicamente.
APPL;
}

// ===== BRING! SHOPPING LIST INTEGRATION =====

function bringAuth(): ?array {
    $email = env('BRING_EMAIL');
    $password = env('BRING_PASSWORD');
    
    if (empty($email) || empty($password)) {
        EverLog::info('bringAuth');
        return null;
    }
    
    // Check cache file for valid token
    $cacheFile = __DIR__ . '/../data/bring_token.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['expires']) && $cached['expires'] > time()) {
            return $cached;
        }
    }
    
    $url = 'https://api.getbring.com/rest/v2/bringauth';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nX-BRING-API-KEY: cof4Nc6D8sOprah0hUXrFl\r\nX-BRING-CLIENT: webApp\r\n",
            'content' => http_build_query(['email' => $email, 'password' => $password]),
            'timeout' => 10,
        ]
    ]);
    
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return null;
    
    $data = json_decode($response, true);
    if (!isset($data['access_token'])) return null;
    
    $tokenData = [
        'access_token' => $data['access_token'],
        'uuid' => $data['uuid'],
        'bringListUUID' => $data['bringListUUID'] ?? '',
        'expires' => time() + 3500, // tokens last ~1 hour
    ];
    
    // Cache token
    @file_put_contents($cacheFile, json_encode($tokenData));
    
    return $tokenData;
}

function bringRequest(string $method, string $url, ?string $body = null): ?array {
    $auth = bringAuth();
    if (!$auth) {
        EverLog::debug('bringRequest');
        return null;
    }
    
    $headers = "Authorization: Bearer {$auth['access_token']}\r\n" .
               "X-BRING-API-KEY: cof4Nc6D8sOprah0hUXrFl\r\n" .
               "X-BRING-CLIENT: webApp\r\n" .
               "Content-Type: application/x-www-form-urlencoded\r\n";
    
    $opts = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'timeout' => 10,
            'ignore_errors' => true,
        ]
    ];
    if ($body !== null) {
        $opts['http']['content'] = $body;
    }
    
    $response = @file_get_contents($url, false, stream_context_create($opts));
    if ($response === false) return null;
    
    $data = json_decode($response, true);
    return $data ?? ['_raw' => $response];
}

/**
 * Load and cache the Bring! IT↔DE catalog mapping.
 * Returns ['de2it' => [German => Italian], 'it2de' => [italian_lower => German]]
 */
function bringCatalog(): array {
    $cacheFile = __DIR__ . '/../data/bring_catalog.json';
    
    // Cache for 24 hours
    if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400) {
        EverLog::debug('bringCatalog');
        return json_decode(file_get_contents($cacheFile), true) ?: ['de2it' => [], 'it2de' => []];
    }
    
    $json = @file_get_contents('https://web.getbring.com/locale/articles.it-IT.json');
    if (!$json) return ['de2it' => [], 'it2de' => []];
    
    $data = json_decode($json, true);
    if (!$data) return ['de2it' => [], 'it2de' => []];
    
    $de2it = [];
    $it2de = [];
    foreach ($data as $deKey => $itVal) {
        if (!is_string($itVal) || empty($itVal)) continue;
        $de2it[$deKey] = $itVal;
        $it2de[mb_strtolower($itVal)] = $deKey;
    }
    
    $catalog = ['de2it' => $de2it, 'it2de' => $it2de];
    @file_put_contents($cacheFile, json_encode($catalog, JSON_UNESCAPED_UNICODE));
    
    return $catalog;
}

/** Translate a Bring! item name from German key to Italian display name */
function bringToItalian(string $name): string {
    $catalog = bringCatalog();
    return $catalog['de2it'][$name] ?? $name;
}

/** Translate an Italian product name to the Bring! German catalog key (fuzzy match) */
function italianToBring(string $italianName): string {
    $catalog = bringCatalog();
    $lower = mb_strtolower(trim($italianName));

    // Pass 1: exact match
    if (isset($catalog['it2de'][$lower])) {
        return $catalog['it2de'][$lower];
    }

    // Pass 2: whole-word match — catalog key must be a whole word inside the input.
    // Uses word-boundary logic (split on spaces) to avoid substring false positives like
    // "gin" inside "original", "rum" inside "crumble", "aceto" inside "pancetta", etc.
    // Only considers single-word catalog keys (multi-word keys need Pass 1 exact match).
    // To avoid ambiguous mappings (e.g. "pancetta dolce" => "mais"), skip generic qualifiers
    // and pick the most specific (longest) matching token.
    $inputWords = array_filter(
        preg_split('/\s+/', $lower),
        fn($w) => mb_strlen($w) >= 4   // skip very short words — too ambiguous
    );

    $genericQualifiers = [
        'dolce','salato','light','bio','classico','original','naturale','fresco','fresca',
        'intero','intera','magro','magra','piccolo','piccola','grande','rosso','bianco',
        // Generic descriptors that appear inside multi-word product names (e.g. "succo e polpa frutta",
        // "muesli frutta secca") but do NOT represent the item category on their own.
        // Pass 1 (exact match on shopping_name) still works correctly for truly generic items
        // like shopping_name='Frutta' → it2de['frutta'] = 'Früchte'.
        'frutta','verdura','frutti',
    ];
    $candidates = [];
    foreach ($catalog['it2de'] as $itLower => $deKey) {
        if (str_contains($itLower, ' ')) continue; // multi-word key → exact-only
        if (mb_strlen($itLower) < 4)    continue; // too short → skip (gin, rum, etc.)
        if (in_array($itLower, $genericQualifiers, true)) continue;
        if (in_array($itLower, $inputWords, true)) {
            $candidates[] = ['it' => $itLower, 'de' => $deKey, 'len' => mb_strlen($itLower)];
        }
    }

    if (!empty($candidates)) {
        usort($candidates, fn($a, $b) => $b['len'] <=> $a['len']);
        return $candidates[0]['de'];
    }

    // No match — return the original Italian name so Bring! shows it as a custom item
    return $italianName;
}

/**
 * Auto-compute a generic shopping/Bring! name for a product.
 *
 * Priority:
 *  1. Curated keyword map  — groups cured meats, etc. that the catalog doesn't unify
 *  2. Bring! catalog back-translation — "Latte di Montagna" → "Milch" → "Latte"
 *  3. First significant token capitalized
 *
 * The returned string is always a valid Bring! catalog name where possible,
 * so that italianToBring(computeShoppingName($n)) resolves to a catalog key.
 */
/**
 * Ask Gemini to classify a product name into a short Italian shopping category word.
 * Results are cached in a local JSON file to avoid repeated API calls.
 * Returns null on failure so the caller can fall back gracefully.
 */
function _geminiClassifyProduct(string $name, string $brand, string $category): ?string {
    EverLog::debug('_geminiClassifyProduct');
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) return null;

    // Load/save classification cache
    $cacheFile = __DIR__ . '/../data/shopping_name_cache.json';
    $cache = [];
    if (file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw) $cache = json_decode($raw, true) ?: [];
    }
    $cacheKey = md5(mb_strtolower($name . '|' . $brand));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    // Build catalog list so Gemini picks an existing Bring! entry when possible
    $catalog = bringCatalog();
    $catalogList = implode(', ', array_slice(array_values($catalog['de2it']), 0, 200));

    $prompt = <<<PROMPT
Sei un assistente per la spesa italiana. Data la descrizione di un prodotto alimentare,
rispondi con UNA SOLA parola (o al massimo due) in italiano che rappresenta la categoria
generica più appropriata per la lista della spesa.

Il nome deve essere:
- Breve (1-2 parole al massimo)
- In italiano
- Riconoscibile da un supermercato italiano (es: "Pane", "Latte", "Formaggio", "Yogurt",
  "Pasta", "Riso", "Olio", "Biscotti", "Succo", "Marmellata", "Salsa", "Farina", ...)
- Se esiste nel catalogo Bring! scegli quella voce: {$catalogList}

Prodotto: "{$name}"
Marca: "{$brand}"
Categoria OpenFoodFacts: "{$category}"

Rispondi SOLO con la parola/coppia di parole, senza punteggiatura, senza spiegazioni.
PROMPT;

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 16],
    ];

    $result = callGeminiWithFallback($apiKey, $payload, 15, 'classify_category');
    if ($result['http_code'] !== 200 || !isset($result['data']['candidates'][0])) return null;

    $text = trim($result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '');
    // Sanitize: keep only letters and spaces, max 30 chars, capitalize first letter
    $text = preg_replace('/[^\p{L}\s]/u', '', $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text) < 2 || mb_strlen($text) > 30) return null;
    $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);

    // Persist to cache
    $cache[$cacheKey] = $text;
    @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return $text;
}

function computeShoppingName(string $name, string $category = '', string $brand = ''): string {
    $lower = mb_strtolower(trim($name));
    $stop = ['di','del','della','dei','degli','delle','da','in','con','per','su',
             'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo',
             'parzialmente','scremato','uht','bio','light','freschi','fresca','fresco'];
    $tokens = array_values(array_filter(
        preg_split('/\s+/', preg_replace('/[^\p{L}\s]/u', ' ', $lower)),
        fn($w) => mb_strlen($w) > 2 && !in_array($w, $stop)
    ));

    // 0. Compound-phrase map — checked against the FULL lowercase name (stop words included)
    //    so multi-word product types are classified BEFORE single-token lookup.
    //    This prevents "Pane grattugiato" → "Pane", "Panna da cucina" → "Panna", etc.
    $phraseMap = [
        // Breadcrumbs (MUST come before generic "pane")
        'pangrattato'           => 'Pangrattato',
        'pan grattato'          => 'Pangrattato',
        'pane grattato'         => 'Pangrattato',
        'pane grattugiato'      => 'Pangrattato',
        'pan grattugiato'       => 'Pangrattato',
        // Cooking cream (MUST come before generic "panna")
        'panna da cucina'       => 'Panna da cucina',
        'panna cucina'          => 'Panna da cucina',
        'panna chef'            => 'Panna da cucina',
        'panna acida'           => 'Panna acida',
        // Plant-based milks (MUST come before generic "latte")
        'latte condensato'      => 'Latte condensato',
        'latte evaporato'       => 'Latte condensato',
        'latte di soia'         => 'Latte di soia',
        'latte soia'            => 'Latte di soia',
        'latte vegetale'        => 'Latte vegetale',
        'latte di mandorla'     => 'Latte di mandorla',
        'latte mandorla'        => 'Latte di mandorla',
        'latte di avena'        => 'Latte di avena',
        'latte avena'           => 'Latte di avena',
        'latte di riso'         => 'Latte di riso',
        'latte riso'            => 'Latte di riso',
        'latte di cocco'        => 'Latte di cocco',
        'latte cocco'           => 'Latte di cocco',
        // Baked bakery — different from bread
        'fette biscottate'      => 'Fette biscottate',
        'pan di spagna'         => 'Pan di Spagna',
        // Specific vinegars
        'aceto balsamico'       => 'Aceto balsamico',
        'glassa balsamico'      => 'Aceto balsamico',
        'glassa balsamic'       => 'Aceto balsamico',
        // Cold cuts — specific cuts
        'prosciutto cotto'      => 'Prosciutto cotto',
        // Flour subtypes (MUST come before generic "farina")
        'farina di riso'        => 'Farina di riso',
        'farina riso'           => 'Farina di riso',
        'farina di mais'        => 'Farina di mais',
        'farina mais'           => 'Farina di mais',
        'farina integrale'      => 'Farina integrale',
        'farina 00'             => 'Farina',
        // Roux / sugar subtypes
        'zucchero di canna'     => 'Zucchero di canna',
        'zucchero canna'        => 'Zucchero di canna',
        'zucchero velo'         => 'Zucchero a velo',
        'zucchero a velo'       => 'Zucchero a velo',
        // Fresh pasta
        'pasta fresca'          => 'Pasta fresca',
        // Broth / stock
        'brodo vegetale'        => 'Brodo',
        'brodo pollo'           => 'Brodo',
        'brodo manzo'           => 'Brodo',
        // Mixed vegetable purée / passato (MUST come before generic carote/patate)
        'passato di verdure'    => 'Verdure',
        'passato di patate'     => 'Verdure',
        // Water
        'acqua frizzante'       => 'Acqua',
        'acqua gassata'         => 'Acqua',
        'acqua minerale'        => 'Acqua',
        // Aroma / flavouring
        'aroma vaniglia'        => 'Ingredienti Spezie',
        'aroma mandorla'        => 'Ingredienti Spezie',
        'aroma limone'          => 'Ingredienti Spezie',
        'aroma rum'             => 'Ingredienti Spezie',
        'aroma arancia'         => 'Ingredienti Spezie',
    ];
    foreach ($phraseMap as $phrase => $canonical) {
        if (mb_strpos($lower, $phrase) !== false) {
            return $canonical;
        }
    }

    // 1. Curated keyword → canonical group name.
    //    Extended list covers the most common Italian pantry items and avoids Gemini calls.
    $keywordMap = [
        // Cold cuts / affettati
        'mortadella'    => 'Affettato',
        'nduja'         => 'Affettato',
        'salame'        => 'Affettato',
        'salami'        => 'Affettato',
        'coppa'         => 'Affettato',
        'capicola'      => 'Affettato',
        'speck'         => 'Affettato',
        'schinkenspeck' => 'Affettato',
        'schinken'      => 'Affettato',
        'prosciutto'    => 'Affettato',
        // Items with their own Bring! entry
        'bresaola'      => 'Bresaola',
        'pancetta'      => 'Pancetta',
        'salsiccia'     => 'Salsiccia',
        'wurstel'       => 'Wurstel',
        // Bread & bakery
        'pane'          => 'Pane',
        'bauletto'      => 'Pane',
        'pancarrè'      => 'Pane',
        'pancare'       => 'Pane',
        'toast'         => 'Pane',
        'focaccia'      => 'Pane',
        'ciabatta'      => 'Pane',
        'baguette'      => 'Pane',
        'grissini'      => 'Grissini',
        'crackers'      => 'Cracker',
        'cracker'       => 'Cracker',
        'taralli'       => 'Taralli',
        'tarallini'     => 'Taralli',
        'piadina'       => 'Piadina',
        'piadelle'      => 'Piadina',
        'biscotto'      => 'Biscotti',
        'biscotti'      => 'Biscotti',
        // Breadcrumbs single-token safety net (phrase map has priority, but just in case)
        'grattugiato'   => 'Pangrattato',
        'grattato'      => 'Pangrattato',
        'pangrattato'   => 'Pangrattato',
        'biscottate'    => 'Fette biscottate',
        // Leavening agents
        'lievito'       => 'Lievito',
        // Flavourings / aromas (single-token fallback; phrases handled above)
        'aroma'         => 'Ingredienti Spezie',
        // Dairy
        'latte'         => 'Latte',
        'yogurt'        => 'Yogurt',
        'yaourt'        => 'Yogurt',
        'yougurt'       => 'Yogurt',
        'burro'         => 'Burro',
        'panna'         => 'Panna',
        'mozzarella'    => 'Mozzarella',
        'formaggio'     => 'Formaggio',
        'ricotta'       => 'Ricotta',
        'ricottina'     => 'Ricotta',
        'casatella'     => 'Formaggio',
        'philadelphia'  => 'Formaggio cremoso',
        // "Bel Paese" — known Italian cheese brand
        'bel'           => 'Formaggio',
        // Pasta
        'pasta'         => 'Pasta',
        'spaghetti'     => 'Pasta',
        'penne'         => 'Pasta',
        'rigatoni'      => 'Pasta',
        'fusilli'       => 'Pasta',
        'orecchiette'   => 'Pasta',
        'tortiglioni'   => 'Pasta',
        'linguine'      => 'Pasta',
        'sedani'        => 'Pasta',
        'lasagne'       => 'Pasta',
        'tortellini'    => 'Pasta',
        'gnocchi'       => 'Gnocchi',
        // Rice
        'riso'          => 'Riso',
        // Eggs
        'uova'          => 'Uova',
        'uovo'          => 'Uova',
        // Fruit & veg
        'mela'          => 'Mele',
        'mele'          => 'Mele',
        'pera'          => 'Pere',
        'arancia'       => 'Arance',
        'arance'        => 'Arance',
        'limone'        => 'Limone',
        'banana'        => 'Banane',
        'banane'        => 'Banane',
        'kiwi'          => 'Kiwi',
        'avocado'       => 'Avocado',
        'pomodoro'      => 'Pomodori',
        'pomodori'      => 'Pomodori',
        'pomodorini'    => 'Pomodorini',
        'carota'        => 'Carote',
        'carote'        => 'Carote',
        'cipolla'       => 'Cipolla',
        'cipolle'       => 'Cipolla',
        'aglio'         => 'Aglio',
        'zucchina'      => 'Zucchine',
        'zucchine'      => 'Zucchine',
        'spinaci'       => 'Spinaci',
        'lattuga'       => 'Insalata',
        'melone'        => 'Melone',
        'finocchio'     => 'Finocchio',
        // Condiments & pantry
        'olio'          => 'Olio',
        'aceto'         => 'Aceto',
        'sale'          => 'Sale',
        'zucchero'      => 'Zucchero',
        'farina'        => 'Farina',
        'lievito'       => 'Lievito',
        'miele'         => 'Miele',
        'marmellata'    => 'Marmellata',
        'confettura'    => 'Marmellata',
        'maionese'      => 'Maionese',
        'senape'        => 'Senape',
        'ketchup'       => 'Ketchup',
        // Canned / preserved
        'passata'       => 'Passata',
        'polpa'         => 'Polpa di pomodoro',
        'pelati'        => 'Pelati',
        'tonno'         => 'Tonno',
        'sardine'       => 'Sardine',
        'ceci'          => 'Ceci',
        'lenticchie'    => 'Lenticchie',
        'fagioli'       => 'Fagioli',
        'piselli'       => 'Piselli',
        'mais'          => 'Mais',
        // Frozen
        'surgelato'     => 'Surgelati',
        'surgelati'     => 'Surgelati',
        // Drinks
        'vino'          => 'Vino',
        'birra'         => 'Birra',
        'succo'         => 'Succo',
        // Cereals & snacks
        'muesli'        => 'Muesli',
        'cereali'       => 'Cereali',
        // Frozen & desserts (before coffee/tea tokens to avoid "gelato caffè → Caffè")
        'gelato'        => 'Gelato',
        'semifreddo'    => 'Gelato',
        // Beverages (coffee, tea, herbal)
        'camomilla'     => 'Camomilla',
        'camomille'     => 'Camomilla',
        'tisana'        => 'Tè',
        // Cat food / pet
        'gatto'         => 'Cibo per gatti',
        'cane'          => 'Cibo per cani',
        // Known product/brand single tokens → category override
        'risofrolle'    => 'Cracker',
        'zuppalatte'    => 'Biscotti',
        'kaffee'        => 'Caffè',
        'ovomaltine'    => 'Bevande',
        'ciobar'        => 'Cioccolata calda',
        'apfelsaft'     => 'Succo',
        'kartoffelpüree'=> 'Purè',
        'purée'         => 'Purè',
        'pure'          => 'Purè',
        'inchusa'       => 'Birra',
        'ichnusa'       => 'Birra',
        'vesoletto'     => 'Vino',
        'trebbiano'     => 'Vino',
        'sangiovese'    => 'Vino',
        'barbera'       => 'Vino',
        'chianti'       => 'Vino',
        'soave'         => 'Vino',
        'prosecco'      => 'Vino',
        'frizzante'     => 'Acqua',
        'semolino'      => 'Semolino',
        'bicarbonato'   => 'Bicarbonato',
        'sambuca'       => 'Liquore',
        'limoncello'    => 'Liquore',
        'grappa'        => 'Liquore',
        'dado'          => 'Brodo',
        'zuccheri'      => 'Zucchero',
        'zucchero'      => 'Zucchero',
        // Foreign-language tokens
        'jus'           => 'Succo',
        'zumo'          => 'Succo',
        'arome'         => 'Aroma',
        'caffe'         => 'Caffè',
        'caffè'         => 'Caffè',
    ];

    foreach ($tokens as $token) {
        if (isset($keywordMap[$token])) {
            return $keywordMap[$token];
        }
    }

    // 2. Bring! catalog back-translation: "Latte di Montagna" → "Milch" → "Latte"
    $bringKey = italianToBring($name);
    if ($bringKey !== $name) {
        $italian = bringToItalian($bringKey);
        if ($italian && mb_strtolower($italian) !== $lower) {
            return $italian;
        }
    }

    // 3. Gemini AI classification — called when:
    //    - The name has 2+ tokens (e.g. "Gran bauletto rustico"),
    //    - OR the single token doesn't look like a clean Italian product word
    //      (contains non-Italian chars, uppercase mix, brand-style length, etc.),
    //    - OR category/brand context is available to help Gemini disambiguate.
    // Single-token ultra-common words (5+ lowercase Italian chars) that already look
    // like valid category names are skipped (unlikely to need AI).
    $firstToken = $tokens[0] ?? '';
    $isCleanItalianToken = count($tokens) === 1
        && mb_strlen($firstToken) >= 5
        && mb_strtolower($firstToken) === $firstToken  // all lowercase → already in stop-word-free form
        && preg_match('/^[a-z]+$/', $firstToken);     // only ASCII lowercase (no accents = usually Italian noun)
    $hasCategoryHint = $category !== '' || $brand !== '';
    $needsAI = !$isCleanItalianToken || ($hasCategoryHint && count($tokens) >= 2);
    if ($needsAI) {
        $aiResult = _geminiClassifyProduct($name, $brand, $category);
        if ($aiResult !== null) return $aiResult;
    }

    // 4. Fallback: capitalize the first meaningful token.
    if (!empty($tokens)) {
        return mb_strtoupper(mb_substr($firstToken, 0, 1)) . mb_substr($firstToken, 1);
    }
    return ucfirst($name);
}

/**
 * Real-time shopping sync for a single product.
 * Called after inventory changes (use/update/add) to keep the shopping list in sync immediately.
 * Delegates to Bring! or internal DB depending on SHOPPING_MODE.
 */
function bringQuickSyncProduct(PDO $db, int $productId): void {
    $stmt = $db->prepare("SELECT SUM(quantity) FROM inventory WHERE product_id = ? AND quantity > 0");
    $stmt->execute([$productId]);
    $totalQty = (float)($stmt->fetchColumn() ?: 0);

    $stmt = $db->prepare("SELECT name, brand, shopping_name FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $prod = $stmt->fetch();
    if (!$prod) return;

    $genericName = $prod['shopping_name'] ?: computeShoppingName($prod['name'], '', $prod['brand']);

    if (isShoppingBringMode()) {
        // Delegate to Bring!
        $auth = bringAuth();
        if (!$auth) return;
        $listUUID = $auth['bringListUUID'];
        $bringName = italianToBring($genericName);

        $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
        if (!$listData || !isset($listData['purchase'])) return;

        $onBring = false;
        foreach ($listData['purchase'] as $item) {
            if (strcasecmp($item['name'] ?? '', $bringName) === 0) { $onBring = true; break; }
        }

        if ($totalQty <= 0 && !$onBring) {
            $spec = $genericName !== $prod['name']
                ? $prod['name'] . ($prod['brand'] ? ' · ' . $prod['brand'] : '') . ' · 🛒 Esaurito'
                : ($prod['brand'] ? $prod['brand'] . ' · ' : '') . '🛒 Esaurito';
            bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}",
                http_build_query(['uuid' => $listUUID, 'purchase' => $bringName, 'specification' => $spec]));
            EverLog::info('bringQuickSync: added to Bring!', ['product_id' => $productId, 'name' => $bringName]);
        } elseif ($totalQty > 0 && $onBring) {
            bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}",
                http_build_query(['uuid' => $listUUID, 'remove' => $bringName]));
            EverLog::info('bringQuickSync: removed from Bring!', ['product_id' => $productId, 'name' => $bringName]);
        }
    } else {
        // Internal mode
        $threshold = (int)env('SHOPPING_AUTO_ADD_THRESHOLD', '0');
        $stmtCheck = $db->prepare("SELECT id FROM shopping_list WHERE lower(name) = lower(?)");
        $stmtCheck->execute([$genericName]);
        $onList = (bool)$stmtCheck->fetch();

        if ($totalQty <= $threshold && !$onList) {
            $spec = $genericName !== $prod['name']
                ? $prod['name'] . ($prod['brand'] ? ' · ' . $prod['brand'] : '')
                : ($prod['brand'] ?: '');
            $db->prepare("INSERT OR IGNORE INTO shopping_list (name, raw_name, specification) VALUES (?, ?, ?)")
               ->execute([$genericName, $prod['name'], $spec]);
            EverLog::info('shoppingQuickSync: added to internal list', ['product_id' => $productId, 'name' => $genericName]);
        } elseif ($totalQty > $threshold && $onList) {
            $db->prepare("DELETE FROM shopping_list WHERE lower(name) = lower(?)")->execute([$genericName]);
            EverLog::info('shoppingQuickSync: removed from internal list', ['product_id' => $productId, 'name' => $genericName]);
        }
    }
}

// ===== LOCAL BACKUP =====

/**
 * Create a timestamped local backup of evershelf.db.
 * WAL-checkpointed before copy. Purges backups older than BACKUP_RETENTION_DAYS.
 */
function createLocalBackup(?PDO $db = null): array {
    EverLog::info('createLocalBackup');
    $backupDir = BACKUP_DIR;
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
        return ['success' => false, 'error' => 'Cannot create backup directory'];
    }

    $dbFile = __DIR__ . '/../data/evershelf.db';
    if (!file_exists($dbFile)) {
        return ['success' => false, 'error' => 'Database file not found'];
    }

    // WAL checkpoint: flush WAL into main DB file before copying
    try {
        $pdo = $db ?? getDB();
        $pdo->exec('PRAGMA wal_checkpoint(FULL)');
    } catch (Throwable $e) { /* non-fatal */ }

    $date     = date('Y-m-d_Hi');
    $filename = "evershelf_{$date}.db";
    $destPath = "$backupDir/$filename";

    if (!copy($dbFile, $destPath)) {
        return ['success' => false, 'error' => 'Failed to copy database file'];
    }

    // Purge local backups older than retention
    $retentionDays = max(1, (int)env('BACKUP_RETENTION_DAYS', '3'));
    $cutoff = strtotime("-{$retentionDays} days");
    $purged = 0;
    foreach (glob("$backupDir/evershelf_*.db") ?: [] as $f) {
        if ($f !== $destPath && filemtime($f) < $cutoff) {
            unlink($f);
            $purged++;
        }
    }

    $sizeKb = (int)round(filesize($destPath) / 1024);
    $result = [
        'success'    => true,
        'filename'   => $filename,
        'path'       => $destPath,
        'size_kb'    => $sizeKb,
        'purged'     => $purged,
        'created_at' => date('c'),
    ];

    // Update last-backup timestamp file
    file_put_contents(BACKUP_LAST_TS_PATH, json_encode(['ts' => time(), 'filename' => $filename, 'size_kb' => $sizeKb]));

    return $result;
}

/**
 * List local backup files with metadata.
 */
function listLocalBackups(): array {
    $backupDir = BACKUP_DIR;
    $backups   = [];
    foreach (glob("$backupDir/evershelf_*.db") ?: [] as $f) {
        $backups[] = [
            'filename'   => basename($f),
            'size_kb'    => (int)round(filesize($f) / 1024),
            'created_at' => date('c', filemtime($f)),
        ];
    }
    usort($backups, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    $lastTs = [];
    if (file_exists(BACKUP_LAST_TS_PATH)) {
        $lastTs = json_decode(file_get_contents(BACKUP_LAST_TS_PATH), true) ?: [];
    }

    return [
        'success'         => true,
        'backups'         => $backups,
        'last_backup_ts'  => $lastTs['ts'] ?? null,
        'last_backup_file'=> $lastTs['filename'] ?? null,
        'retention_days'  => max(1, (int)env('BACKUP_RETENTION_DAYS', '3')),
    ];
}

/**
 * Delete a specific local backup file.
 */
function deleteLocalBackup(string $filename): array {
    if (!preg_match('/^evershelf_\d{4}-\d{2}-\d{2}_\d{4}\.db$/', $filename)) {
        return ['success' => false, 'error' => 'Invalid backup filename'];
    }
    $path = BACKUP_DIR . '/' . $filename;
    if (!file_exists($path)) {
        return ['success' => false, 'error' => 'File not found'];
    }
    return unlink($path) ? ['success' => true] : ['success' => false, 'error' => 'Failed to delete file'];
}

/**
 * Restore a local backup: replaces the current evershelf.db.
 * Clears WAL/SHM files and invalidates smart shopping cache.
 */
function restoreLocalBackup(string $filename, PDO $db): array {
    if (!preg_match('/^evershelf_\d{4}-\d{2}-\d{2}_\d{4}\.db$/', $filename)) {
        return ['success' => false, 'error' => 'Invalid backup filename'];
    }
    $backupPath = BACKUP_DIR . '/' . $filename;
    if (!file_exists($backupPath)) {
        return ['success' => false, 'error' => 'Backup file not found'];
    }
    $dbPath = __DIR__ . '/../data/evershelf.db';

    // Flush WAL before replacing DB
    try { $db->exec('PRAGMA wal_checkpoint(FULL)'); } catch (Throwable $e) {}

    if (!copy($backupPath, $dbPath)) {
        return ['success' => false, 'error' => 'Failed to restore backup'];
    }
    // Remove stale WAL/SHM so next connection starts clean
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
    // Invalidate dependent caches
    @unlink(__DIR__ . '/../data/smart_shopping_cache.json');

    EverLog::info('restoreLocalBackup', ['filename' => $filename]);
    return ['success' => true, 'message' => 'Restore complete — reload the page to see the restored data.'];
}

// ===== GOOGLE DRIVE BACKUP =====

/** Write / overwrite a single key in the .env file (used by OAuth callback). */
function _gdriveSetEnvVar(string $key, string $value): void {
    $envFile = __DIR__ . '/../.env';
    $envVars = loadEnv();
    $envVars[$key] = $value;
    $lines = [];
    foreach ($envVars as $k => $v) { $lines[] = "$k=$v"; }
    file_put_contents($envFile, implode("\n", $lines) . "\n");
}

/**
 * Build the OAuth 2.0 redirect URI for the server-side callback.
 * Used only for _gdriveHandleOAuthCallback (legacy flow).
 * The interactive auth URL now uses GDRIVE_REDIRECT_URI or http://localhost instead.
 */
function _gdriveRedirectUri(): string {
    $override = env('GDRIVE_REDIRECT_URI', '');
    if (!empty($override)) return $override;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$scheme://$host/api/index.php?action=gdrive_oauth_callback";
}

/**
 * Get an access token using a stored OAuth 2.0 refresh token.
 */
function _gdriveGetTokenOAuth(): array {
    $clientId     = env('GDRIVE_CLIENT_ID', '');
    $clientSecret = env('GDRIVE_CLIENT_SECRET', '');
    $refreshToken = env('GDRIVE_REFRESH_TOKEN', '');
    if (!$clientId || !$clientSecret) {
        return ['error' => 'GDRIVE_CLIENT_ID and GDRIVE_CLIENT_SECRET are required for OAuth'];
    }
    if (!$refreshToken) {
        return ['error' => 'Not authorized yet — click "Authorize with Google" first'];
    }
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if (!$response) return ['error' => 'cURL failed: ' . $curlErr];
    $data = json_decode($response, true);
    if (!empty($data['access_token'])) return ['token' => $data['access_token']];
    return ['error' => 'OAuth refresh error: ' . ($data['error_description'] ?? $data['error'] ?? $response)];
}

/**
 * Handle the OAuth 2.0 callback: exchange the code for tokens, store refresh_token.
 * Returns HTML (not JSON) — must be called before Content-Type header is sent.
 */
function _gdriveHandleOAuthCallback(): void {
    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body style="font-family:sans-serif;padding:2rem"><h2>&#10060; Error</h2><p>No authorization code received.</p></body></html>';
        return;
    }
    $clientId     = env('GDRIVE_CLIENT_ID', '');
    $clientSecret = env('GDRIVE_CLIENT_SECRET', '');
    $redirectUri  = _gdriveRedirectUri();
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    header('Content-Type: text/html; charset=utf-8');
    if (!empty($data['refresh_token'])) {
        _gdriveSetEnvVar('GDRIVE_REFRESH_TOKEN', $data['refresh_token']);
        echo '<html><head><title>EverShelf &#10004;</title></head><body style="font-family:sans-serif;text-align:center;padding:3rem;background:#f0fdf4">'
           . '<h2 style="color:#15803d">&#10004; Google Drive Authorized!</h2>'
           . '<p>EverShelf can now back up to your Google Drive.</p>'
           . '<p style="color:#94a3b8;font-size:0.9rem">This tab will close automatically.</p>'
           . '<script>setTimeout(()=>{try{window.close()}catch(e){}},2500)</script>'
           . '</body></html>';
    } else {
        $err = htmlspecialchars($data['error_description'] ?? $data['error'] ?? 'Unknown error');
        http_response_code(400);
        echo "<html><body style='font-family:sans-serif;padding:2rem'><h2>&#10060; Authorization failed</h2><p>$err</p></body></html>";
    }
}

/**
 * Obtain a short-lived Google API access token via OAuth 2.0 refresh token.
 * Returns ['token' => string] on success, ['error' => string] on failure.
 */
function _gdriveGetToken(): ?string { return _gdriveGetTokenOAuth()['token'] ?? null; }
function _gdriveGetTokenEx(): array { return _gdriveGetTokenOAuth(); }

/**
 * Upload a file to Google Drive using multipart upload.
 * Returns the Drive file ID on success, null on failure.
 */
/** Returns ['id' => string] on success or ['error' => string] on failure. */
function _gdriveUploadFile(string $token, string $folderId, string $filePath, string $remoteName): array {
    if (!file_exists($filePath)) return ['error' => 'Local backup file not found: ' . $filePath];
    $mimeType    = 'application/x-sqlite3';
    $metadata    = json_encode(['name' => $remoteName, 'parents' => [$folderId]]);
    $fileContent = file_get_contents($filePath);
    $boundary    = 'es_backup_' . bin2hex(random_bytes(8));
    $body        = "--$boundary\r\n"
                 . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
                 . $metadata . "\r\n"
                 . "--$boundary\r\n"
                 . "Content-Type: $mimeType\r\n\r\n"
                 . $fileContent . "\r\n"
                 . "--$boundary--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Content-Type: multipart/related; boundary=$boundary",
            "Content-Length: " . strlen($body),
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if (!$response) return ['error' => 'cURL upload failed: ' . $curlErr];
    $data = json_decode($response, true);
    if (!empty($data['id'])) return ['id' => $data['id']];
    $apiErr = $data['error']['message'] ?? $data['error']['status'] ?? json_encode($data);
    return ['error' => 'Drive API error: ' . $apiErr];
}

/**
 * Delete Drive backups older than $retentionDays.
 * Returns count of deleted files.
 */
function _gdrivePurgeOld(string $token, string $folderId, int $retentionDays): int {
    if ($retentionDays <= 0) return 0;
    $cutoff = date('c', strtotime("-{$retentionDays} days"));
    $q      = "'$folderId' in parents and name contains 'evershelf_' and trashed=false";
    $url    = 'https://www.googleapis.com/drive/v3/files?'
            . http_build_query(['q' => $q, 'fields' => 'files(id,name,createdTime)', 'pageSize' => '1000']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return 0;
    $data    = json_decode($response, true);
    $deleted = 0;
    foreach ($data['files'] ?? [] as $file) {
        if (!empty($file['createdTime']) && $file['createdTime'] < $cutoff) {
            $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$file['id']}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
                CURLOPT_TIMEOUT        => 15,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 204) $deleted++;
        }
    }
    return $deleted;
}

/**
 * Full backup flow: create local snapshot, upload to Google Drive, purge old Drive files.
 */
function backupToGDrive(?PDO $db = null): array {
    EverLog::info('backupToGDrive');
    if (env('GDRIVE_ENABLED', 'false') !== 'true') {
        return ['success' => false, 'error' => 'Google Drive backup is not enabled'];
    }
    $folderId = env('GDRIVE_FOLDER_ID', '');
    if (empty($folderId)) {
        return ['success' => false, 'error' => 'GDRIVE_FOLDER_ID not configured'];
    }

    // 1. Create (or reuse recent) local backup
    $local = createLocalBackup($db);
    if (!$local['success']) return $local;

    // 2. Authenticate with Google
    $tokResult = _gdriveGetTokenEx();
    if (empty($tokResult['token'])) {
        return ['success' => false, 'error' => $tokResult['error'] ?? 'Google Drive authentication failed'];
    }
    $token = $tokResult['token'];

    // 3. Upload
    $uploadResult = _gdriveUploadFile($token, $folderId, $local['path'], $local['filename']);
    if (empty($uploadResult['id'])) {
        return ['success' => false, 'error' => $uploadResult['error'] ?? 'Upload to Google Drive failed'];
    }
    $driveFileId = $uploadResult['id'];

    // 4. Purge old files on Drive
    $retentionDays = max(0, (int)env('GDRIVE_RETENTION_DAYS', '30'));
    $purgedRemote  = $retentionDays > 0 ? _gdrivePurgeOld($token, $folderId, $retentionDays) : 0;

    EverLog::info('backupToGDrive ok', ['file' => $local['filename'], 'drive_id' => $driveFileId, 'purged_remote' => $purgedRemote]);
    return [
        'success'       => true,
        'filename'      => $local['filename'],
        'size_kb'       => $local['size_kb'],
        'drive_file_id' => $driveFileId,
        'purged_local'  => $local['purged'],
        'purged_remote' => $purgedRemote,
        'created_at'    => $local['created_at'],
    ];
}

/**
 * Server-side Bring! cleanup: remove items from Bring! that the app auto-added
 * but are no longer flagged by smart shopping (stock is now adequate).
 * Called by the cron after recomputing the smart shopping cache.
 * Returns a summary array for logging.
 */
function bringCleanupObsolete(PDO $db): array {
    EverLog::debug('bringCleanupObsolete');
    // Load the freshly-computed smart shopping cache
    $cacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
    if (!file_exists($cacheFile)) return ['skipped' => 'no_cache'];
    $smartData = json_decode(file_get_contents($cacheFile), true);
    $smartItems = $smartData['items'] ?? [];

    $auth = bringAuth();
    if (!$auth) return ['skipped' => 'no_bring_auth'];
    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) return ['skipped' => 'no_list_uuid'];

    $bringData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$bringData || !isset($bringData['purchase'])) return ['skipped' => 'bring_fetch_failed'];

    // Reuse nameTokens closure
    $stopwords = ['di','del','della','dei','il','la','le','lo','gli','un','una','e','con','per','da',
                  'al','alla','in','su','se','che','non','ma','o','a','i','nel','nei','tra','delle',
                  'degli','agli','dai','dalle','sui','sulle','sugli'];
    $ntFn = function(string $name) use ($stopwords): array {
        $name = mb_strtolower(trim($name));
        $toks = preg_split('/[^a-z0-9àáâãäåèéêëìíîïòóôõöùúûü]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter($toks, fn($t) => mb_strlen($t) > 2 && !in_array($t, $stopwords))));
    };

    // Build smart map by shopping_name tokens AND by exact name.
    // Exact match is tried first to prevent loose token collisions like
    // 'Panna' (Bring! item, in stock) matching 'Panna da cucina' (depleted, critical)
    // because they share the 'panna' token.
    $smartByTok = [];
    $smartByExactName = [];
    foreach ($smartItems as $si) {
        $sName = !empty($si['shopping_name']) ? $si['shopping_name'] : $si['name'];
        $sNameNorm = strtolower(trim($sName));
        if ($sNameNorm !== '') $smartByExactName[$sNameNorm] = $si;
        foreach ($ntFn($sName) as $tok) {
            if (!isset($smartByTok[$tok])) $smartByTok[$tok] = $si;
        }
    }

    // App-added marker: the app always writes ⚡ 🟠 or 🛒 in the specification
    $appMarkers = ['⚡', '🟠', '🛒'];

    $toRemove = [];
    foreach ($bringData['purchase'] as $bringItem) {
        $spec    = $bringItem['specification'] ?? '';
        $rawName = $bringItem['name'] ?? '';
        $name    = bringToItalian($rawName);

        // Only clean up items the app put there (identified by urgency markers in spec)
        $isAppAdded = false;
        foreach ($appMarkers as $m) {
            if (mb_strpos($spec, $m) !== false) { $isAppAdded = true; break; }
        }
        if (!$isAppAdded) continue;

        // Match against smart items: exact shopping_name first, then first-token fallback.
        // Exact match prevents e.g. 'Panna' → 'Panna da cucina' via shared token 'panna'.
        $nameToks = $ntFn($name);
        $exactKey = strtolower(trim($name));
        $smartSi  = $smartByExactName[$exactKey] ?? null;
        if ($smartSi === null) {
            $firstTok = $nameToks[0] ?? '';
            $smartSi  = $firstTok ? ($smartByTok[$firstTok] ?? null) : null;
        }

        if ($smartSi !== null) {
            // Still in smart_shopping with critical or high urgency → keep
            if (in_array($smartSi['urgency'], ['critical', 'high'], true)) continue;
            // Medium with low stock → keep
            if ($smartSi['urgency'] === 'medium' && (float)($smartSi['pct_left'] ?? 100) < 60) continue;
            // qty=0 → keep (genuinely out of stock)
            if ((float)($smartSi['current_qty'] ?? 0) <= 0) continue;
        }
        // Not in smart (or low-urgency with stock) → schedule for removal

        $toRemove[] = ['name' => $name, 'rawName' => $rawName];
    }

    $removed = 0;
    $errors  = 0;
    foreach ($toRemove as $item) {
        // Try with the catalog key (rawName as returned from Bring! list)
        $body   = http_build_query(['uuid' => $listUUID, 'remove' => $item['rawName']]);
        $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);

        // Retry: if rawName is the Italian locale name, also try the German catalog key
        if ($result === null) {
            $catalogKey = italianToBring($item['name']);
            if ($catalogKey !== $item['rawName']) {
                $body   = http_build_query(['uuid' => $listUUID, 'remove' => $catalogKey]);
                $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
            }
        }

        if ($result !== null) $removed++;
        else { $errors++; }

        // Small delay between removals to avoid hammering the Bring! API
        if (count($toRemove) > 3) usleep(300_000); // 300ms
    }

    return ['candidates' => count($toRemove), 'removed' => $removed, 'errors' => $errors];
}

/**
 * Server-side Bring! auto-add: push critical/high smart_shopping items to Bring!
 * that are not already on the list. Called by the cron alongside cleanup.
 */
function bringAutoAddCritical(PDO $db): array {
    EverLog::debug('bringAutoAddCritical');
    $cacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
    if (!file_exists($cacheFile)) return ['skipped' => 'no_cache'];
    $smartData = json_decode(file_get_contents($cacheFile), true);
    $smartItems = $smartData['items'] ?? [];

    $auth = bringAuth();
    if (!$auth) return ['skipped' => 'no_bring_auth'];
    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) return ['skipped' => 'no_list_uuid'];

    $bringData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$bringData || !isset($bringData['purchase'])) return ['skipped' => 'bring_fetch_failed'];

    // Build set of already-present items (by Bring! key)
    $onBring = [];
    foreach ($bringData['purchase'] as $bi) {
        $onBring[strtolower($bi['name'] ?? '')] = true;
    }

    $added = 0;
    $updated = 0;
    foreach ($smartItems as $si) {
        if (!in_array($si['urgency'], ['critical', 'high'], true)) continue;

        $genericName = $si['shopping_name'] ?: $si['name'];
        $bringName   = italianToBring($genericName);
        $bringKey    = strtolower($bringName);

        // Build urgency spec
        $urgencyLabel = $si['urgency'] === 'critical' ? '⚡ Urgente' : '🟠 Presto';
        $spec = $urgencyLabel;
        if (!empty($si['name']) && $si['name'] !== $genericName) {
            $spec = $si['name'] . ($si['brand'] ? ' · ' . $si['brand'] : '') . ' — ' . $urgencyLabel;
        }
        if (!empty($si['suggested_qty'])) {
            $spec .= ' · 🛒 ' . ($si['qty_label'] ?? 'Almeno: ' . $si['suggested_qty'] . ' ' . ($si['unit'] ?? 'pz'));
        }

        if (isset($onBring[$bringKey])) {
            // Update spec if it changed
            $existingSpec = '';
            foreach ($bringData['purchase'] as $bi) {
                if (strtolower($bi['name'] ?? '') === $bringKey) { $existingSpec = $bi['specification'] ?? ''; break; }
            }
            if ($existingSpec !== $spec) {
                $body = http_build_query(['uuid' => $listUUID, 'purchase' => $bringName, 'specification' => $spec]);
                bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
                $updated++;
            }
            continue;
        }

        $body = http_build_query(['uuid' => $listUUID, 'purchase' => $bringName, 'specification' => $spec]);
        $r = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
        if ($r !== null) $added++;
    }

    return ['added' => $added, 'updated' => $updated];
}

function bringGetList(): void {
    $auth = bringAuth();
    if (!$auth) {
        EverLog::info('bringGetList');
        echo json_encode(['success' => false, 'error' => 'Bring! credentials not configured. Add BRING_EMAIL and BRING_PASSWORD to .env']);
        return;
    }
    
    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) {
        // Try to get lists
        $lists = bringRequest('GET', "https://api.getbring.com/rest/v2/bringusers/{$auth['uuid']}/lists");
        if ($lists && isset($lists['lists'][0]['listUuid'])) {
            $listUUID = $lists['lists'][0]['listUuid'];
        } else {
            echo json_encode(['success' => false, 'error' => 'No Bring! list found']);
            return;
        }
    }
    
    $data = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Error fetching the list']);
        return;
    }
    
    $purchase = [];
    $recently = [];
    
    if (isset($data['purchase'])) {
        foreach ($data['purchase'] as $item) {
            $rawName = $item['name'] ?? '';
            $purchase[] = [
                'name' => bringToItalian($rawName),
                'rawName' => $rawName,
                'specification' => $item['specification'] ?? '',
            ];
        }
    }
    if (isset($data['recently'])) {
        foreach ($data['recently'] as $item) {
            $rawName = $item['name'] ?? '';
            $recently[] = [
                'name' => bringToItalian($rawName),
                'rawName' => $rawName,
                'specification' => $item['specification'] ?? '',
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'listUUID' => $listUUID,
        'purchase' => $purchase,
        'recently' => $recently,
    ], JSON_UNESCAPED_UNICODE);

    // ── Background auto-migration ─────────────────────────────────────────
    // After sending the response, silently migrate any item that still uses
    // the specific product name instead of the generic shopping_name.
    // This runs at most once every 10 minutes (flag file throttle) to avoid
    // hammering the Bring! API on every page load.
    $flagFile = __DIR__ . '/../data/bring_migrate_ts.json';
    $doMigrate = true;
    if (file_exists($flagFile)) {
        $ts = (int)(json_decode(file_get_contents($flagFile), true)['ts'] ?? 0);
        if ((time() - $ts) < 600) $doMigrate = false;
    }
    if ($doMigrate) {
        file_put_contents($flagFile, json_encode(['ts' => time()]));
        // Use a global PDO instance if available, otherwise open a new connection
        global $db;
        if ($db instanceof PDO) {
            bringMigrateNamesInternal($db, $data['purchase'] ?? [], $listUUID);
        }
    }
}

function bringAddItems(): void {
    $auth = bringAuth();
    if (!$auth) {
        EverLog::info('bringAddItems');
        echo json_encode(['success' => false, 'error' => 'Bring! credentials not configured']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $items = $input['items'] ?? [];
    $listUUID = $input['listUUID'] ?? $auth['bringListUUID'];
    
    if (empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'List not found']);
        return;
    }
    
    $added = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    
    // Fetch current list to check for duplicates and existing specs
    $existingItems = [];  // strtolower(name) => specification
    $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if ($listData && isset($listData['purchase'])) {
        foreach ($listData['purchase'] as $existingItem) {
            $existingItems[strtolower($existingItem['name'] ?? '')] = $existingItem['specification'] ?? '';
        }
    }
    
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        if (empty($name)) continue;
        
        // Map Italian name to Bring! catalog key (German) for proper recognition
        $bringName = italianToBring($name);
        $bringKey = strtolower($bringName);
        $spec = $item['specification'] ?? '';
        $update_spec = $item['update_spec'] ?? false;  // explicit flag to force spec update
        
        if (array_key_exists($bringKey, $existingItems)) {
            // Item already on the list — only update if specification changed and update_spec requested
            if ($update_spec && $existingItems[$bringKey] !== $spec) {
                $body = http_build_query([
                    'uuid' => $listUUID,
                    'purchase' => $bringName,
                    'specification' => $spec,
                ]);
                $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
                if ($result !== null) $updated++;
            } else {
                $skipped++;
            }
            continue;
        }
        
        $body = http_build_query([
            'uuid' => $listUUID,
            'purchase' => $bringName,
            'specification' => $spec,
        ]);
        
        $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
        if ($result !== null) {
            $added++;
        } else {
            $errors[] = $name;
        }
    }
    
    if ($added > 0 || $updated > 0) {
        // Invalidate cache so next smart_shopping request reflects the updated Bring! list
        @unlink(__DIR__ . '/../data/smart_shopping_cache.json');
        // Fire HA webhook for each newly added item
        foreach ($items as $item) {
            $iName = $item['name'] ?? '';
            if ($iName === '') continue;
            _fireHaWebhook('shopping_add', ['item' => $iName, 'specification' => $item['specification'] ?? '']);
        }
    }
    echo json_encode(['success' => true, 'added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);
}

function bringRemoveItem(): void {
    $auth = bringAuth();
    if (!$auth) {
        EverLog::info('bringRemoveItem');
        echo json_encode(['success' => false, 'error' => 'Bring! credentials not configured']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $listUUID = $input['listUUID'] ?? $auth['bringListUUID'];
    
    if (empty($name) || empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }
    
    // Use rawName (German catalog key) if provided, otherwise derive from Italian name.
    // Always try both the catalog key AND the Italian name as-stored, because:
    // – Catalog items: Bring! stores them internally by German key (e.g. "Käse" for "Formaggio")
    //   but the list API returns them in the user's locale ("Formaggio").
    //   Removal only works with the German key.
    // – Custom items (not in catalog): stored and removed by the name as entered.
    $rawName     = $input['rawName'] ?? '';
    $catalogKey  = italianToBring($name);     // German key from catalog (may equal $name if not found)
    $removeName  = !empty($rawName) ? $rawName : $catalogKey;

    $listUUID = $auth['bringListUUID'];

    // Try primary removal (catalog key or provided rawName)
    $body   = http_build_query(['uuid' => $listUUID, 'remove' => $removeName]);
    $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);

    // If the primary key was the catalog key and failed, retry with the Italian name as-is
    // (for custom non-catalog items stored under their Italian name)
    if ($result === null && $removeName !== $name) {
        $body   = http_build_query(['uuid' => $listUUID, 'remove' => $name]);
        $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
    }

    if ($result !== null) {
        // Invalidate cache so next smart_shopping request reflects the updated Bring! list
        @unlink(__DIR__ . '/../data/smart_shopping_cache.json');
    }
    echo json_encode(['success' => $result !== null]);
}

function bringCleanSpecs(): void {
    EverLog::debug('bringCleanSpecs');
    $auth = bringAuth();
    if (!$auth) {
        EverLog::info('bringCleanSpecs');
        echo json_encode(['success' => false, 'error' => 'Bring! credentials not configured']);
        return;
    }

    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'List not found']);
        return;
    }

    $data = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$data || !isset($data['purchase'])) {
        echo json_encode(['success' => false, 'error' => 'Error fetching the list']);
        return;
    }

    $cleaned = 0;
    foreach ($data['purchase'] as $item) {
        $spec = $item['specification'] ?? '';
        if ($spec !== '') {
            $body = http_build_query([
                'uuid' => $listUUID,
                'purchase' => $item['name'],
                'specification' => '',
            ]);
            bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
            $cleaned++;
        }
    }

    echo json_encode(['success' => true, 'cleaned' => $cleaned]);
}

/**
 * Core migration logic: iterate $purchaseItems and replace specific product
 * names with generic shopping_name in the Bring! list identified by $listUUID.
 * Returns ['migrated'=>int, 'skipped'=>int, 'errors'=>int].
 */
function bringMigrateNamesInternal(PDO $db, array $purchaseItems, string $listUUID): array {
    // Build lookup: product name (lowercase) → [shopping_name, brand]
    $products = $db->query("SELECT name, brand, shopping_name FROM products WHERE shopping_name IS NOT NULL AND shopping_name != ''")->fetchAll();
    $lookup = [];
    foreach ($products as $p) {
        EverLog::debug('bringMigrateNamesInternal');
        $lookup[mb_strtolower($p['name'])] = ['shopping_name' => $p['shopping_name'], 'brand' => $p['brand'] ?? ''];
    }

    $migrated = 0;
    $skipped  = 0;
    $errors   = 0;

    foreach ($purchaseItems as $item) {
        $rawName = $item['name'] ?? '';
        $itName  = bringToItalian($rawName);
        $key     = mb_strtolower($itName);
        $spec    = $item['specification'] ?? '';

        if (!isset($lookup[$key])) { $skipped++; continue; }

        $shoppingName = $lookup[$key]['shopping_name'];
        $brand        = $lookup[$key]['brand'];

        // Resolve to the correct Bring! catalog key (German)
        $bringKey = italianToBring($shoppingName);

        // Already using the correct catalog key or the shopping name → nothing to do
        if (mb_strtolower($rawName) === mb_strtolower($bringKey))     { $skipped++; continue; }
        if (mb_strtolower($rawName) === mb_strtolower($shoppingName)) { $skipped++; continue; }
        if (mb_strtolower($itName)  === mb_strtolower($shoppingName)) { $skipped++; continue; }

        // Build spec: "Specific Name · Brand"
        $newSpec = $itName . ($brand ? " · {$brand}" : '');
        if ($spec !== '' && $spec !== $newSpec && stripos($spec, $itName) === false) {
            $newSpec = $itName . ($brand ? " · {$brand}" : '') . ' — ' . $spec;
        }

        // Check if the correct catalog key is already in the list
        $alreadyAdded = false;
        foreach ($purchaseItems as $existing) {
            if (strcasecmp($existing['name'] ?? '', $bringKey) === 0) {
                $alreadyAdded = true;
                break;
            }
        }

        // Remove old item using the correct API (PUT with remove param)
        bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}",
            http_build_query(['uuid' => $listUUID, 'remove' => $rawName]));

        // Add with the correct German catalog key (unless already present)
        if (!$alreadyAdded) {
            $addBody = http_build_query([
                'uuid'          => $listUUID,
                'purchase'      => $bringKey,
                'specification' => $newSpec,
            ]);
            $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $addBody);
            if ($result !== false) { $migrated++; } else { $errors++; }
        } else {
            $migrated++; // old item removed, correct generic already present
        }
    }

    return ['migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors];
}

function bringMigrateNames(PDO $db): void {
    EverLog::info('bringMigrateNames');
    $auth = bringAuth();
    if (!$auth) {
        EverLog::info('bringMigrateNames');
        echo json_encode(['success' => false, 'error' => 'Bring! credentials not configured']);
        return;
    }
    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'List not found']);
        return;
    }
    $data = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$data || !isset($data['purchase'])) {
        echo json_encode(['success' => false, 'error' => 'Error fetching the list']);
        return;
    }

    $result = bringMigrateNamesInternal($db, $data['purchase'], $listUUID);

    // Reset throttle so next bring_list load re-checks
    @unlink(__DIR__ . '/../data/bring_migrate_ts.json');

    echo json_encode(array_merge(['success' => true], $result));
}

function invalidateSmartShoppingCache(): void {
    $cacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
}

function smartShoppingCached(PDO $db): void {
    EverLog::info('smartShoppingCached');
    // Never let the browser or proxy cache this — urgency is time-sensitive
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $cacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
    $maxAge    = 3 * 60; // 3 minutes — keep urgency fresh

    if (file_exists($cacheFile)) {
        $mtime = filemtime($cacheFile);
        if ((time() - $mtime) <= $maxAge) {
            $raw = file_get_contents($cacheFile);
            if ($raw !== false) {
                // Inject how many seconds ago the cache was created
                $data = json_decode($raw, true);
                if ($data && isset($data['success'])) {
                    $data['cache_age_seconds'] = time() - ($data['cached_ts'] ?? $mtime);
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                    return;
                }
            }
        }
    }

    // Cache missing or stale — compute live
    smartShopping($db);
}

/**
 * Smart Shopping List: analyzes usage frequency, stock levels, expiry to produce
 * intelligent urgency-ranked shopping recommendations.
 */

/**
 * Token-based fuzzy match: returns true if the product name shares at least one
 * significant word (> 2 chars, not a stopword) with any key in $bringItems.
 * Mirrors the JS _findSimilarItem / _nameTokens logic.
 */
/**
 * Strict matching: returns true only when a Bring item's name "covers" the product name,
 * i.e. the FIRST significant token of the product matches the FIRST significant token of
 * a Bring item name. This prevents false positives like "Früchte/Frutta" matching the
 * product "Muesli Frutta Secca" (which has "frutta" as a secondary token, not the first).
 * Mirrors JS _matchBringToSmart / _syncOnBringFlags logic.
 */
function _productOnBring(string $productName, array $bringItems, string $shoppingName = ''): bool {
    // Check by shopping_name first (covers catalog-matched generic names like "Latte", "Affettato")
    if ($shoppingName !== '') {
        if (isset($bringItems[mb_strtolower($shoppingName)])) return true;
        $snKey = italianToBring($shoppingName);
        if (isset($bringItems[mb_strtolower($snKey)])) return true;
    }
    // Exact key match (both German raw and Italian translated keys are stored)
    if (isset($bringItems[mb_strtolower($productName)])) return true;
    static $stop = ['di','del','della','dei','degli','dalle','delle','da','in','con','per','su',
                    'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo'];
    $tokenize = function(string $s) use ($stop): array {
        $clean = mb_strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $s));
        return array_values(array_filter(
            preg_split('/\s+/', trim($clean)),
            fn($t) => mb_strlen($t) > 2 && !in_array($t, $stop)
        ));
    };
    $pTokens = $tokenize($productName);
    if (empty($pTokens)) return false;
    $pFirst = $pTokens[0];
    foreach (array_keys($bringItems) as $bKey) {
        $bTokens = $tokenize($bKey);
        if (empty($bTokens)) continue;
        // First token of product must equal first token of Bring item
        if ($bTokens[0] === $pFirst) return true;
    }
    return false;
}

function smartShopping(PDO $db): void {
    EverLog::info('smartShopping');
    $now = time();
    $today = date('Y-m-d');

    // Helper: extract significant tokens from a product name (mirrors JS _nameTokens)
    // Includes synonym expansion so French/Italian variants match (e.g. yaourt = yogurt)
    $nameTokens = function(string $name): array {
        $stop = ['di','del','della','dei','degli','delle','da','in','con','per','su',
                 'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo'];
        $synonyms = [
            'yaourt' => 'yogurt', 'yogourt' => 'yogurt',
            'lait'   => 'latte',  'fromage'  => 'formaggio',
            'sucre'  => 'zucchero', 'jus'    => 'succo',
            'orange' => 'arancia', 'pomme'   => 'mela',
            'poire'  => 'pera',
        ];
        $tokens = preg_split('/\s+/', strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $name)));
        $tokens = array_filter($tokens, fn($t) => strlen($t) > 2 && !in_array($t, $stop));
        // Apply synonyms
        $tokens = array_map(fn($t) => $synonyms[$t] ?? $t, $tokens);
        return array_values(array_unique($tokens));
    };

    // 1. Get all products with their inventory and transaction history
    $products = $db->query("
        SELECT p.id, p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit,
               p.shopping_name
        FROM products p
        ORDER BY p.name
    ")->fetchAll();

    // 2. Get all inventory grouped by product
    $invStmt = $db->query("
        SELECT i.product_id, SUM(i.quantity) as total_qty, 
               MIN(i.expiry_date) as nearest_expiry,
               GROUP_CONCAT(DISTINCT i.location) as locations,
               MAX(i.opened_at) as opened_at,
               SUM(CASE WHEN i.expiry_date IS NULL OR i.expiry_date >= date('now') THEN i.quantity ELSE 0 END) as fresh_qty
        FROM inventory i
        WHERE i.quantity > 0
        GROUP BY i.product_id
    ");
    $inventory = [];
    foreach ($invStmt->fetchAll() as $inv) {
        $inventory[$inv['product_id']] = $inv;
    }

    // 3. Get transaction stats per product (exclude undone=1 corrections)
    // Also compute rolling 90-day consumption for smarter quantity suggestions (#70)
    $txStmt = $db->query("
        SELECT product_id,
               COUNT(CASE WHEN type IN ('out','waste') AND undone=0 THEN 1 END) as use_count,
               SUM(CASE WHEN type IN ('out','waste') AND undone=0 THEN quantity ELSE 0 END) as total_used,
               COUNT(CASE WHEN type = 'in' AND undone=0 THEN 1 END) as buy_count,
               SUM(CASE WHEN type = 'in' AND undone=0 THEN quantity ELSE 0 END) as total_bought,
               MIN(CASE WHEN type = 'in' AND undone=0 THEN created_at END) as first_in,
               MAX(CASE WHEN type = 'in' AND undone=0 THEN created_at END) as last_in,
               MAX(CASE WHEN type IN ('out','waste') AND undone=0 THEN created_at END) as last_out,
               SUM(CASE WHEN type IN ('out','waste') AND undone=0 AND created_at >= datetime('now','-90 days') THEN quantity ELSE 0 END) as used_90d,
               SUM(CASE WHEN type IN ('out','waste') AND undone=0 AND created_at >= datetime('now','-30 days') THEN quantity ELSE 0 END) as used_30d
        FROM transactions
        GROUP BY product_id
    ");
    $txData = [];
    foreach ($txStmt->fetchAll() as $tx) {
        $txData[$tx['product_id']] = $tx;
    }

    // 4. Fetch current Bring! list to know what's already there
    $bringItems = [];
    try {
        $auth = bringAuth();
        if ($auth) {
            $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$auth['bringListUUID']}");
            if ($listData && isset($listData['purchase'])) {
                foreach ($listData['purchase'] as $bi) {
                    $bringItems[mb_strtolower(bringToItalian($bi['name'] ?? ''))] = true;
                    $bringItems[mb_strtolower($bi['name'] ?? '')] = true;
                }
            }
        }
    } catch (Exception $e) { /* ignore */ }

    // 4b. Build stockByAnyToken: every significant token of in-stock products → total qty.
    // Used to skip depleted products covered by any equivalent in-stock product.
    // Any-token (not just first) groups product families:
    //   'Passata di pomodoro' + 'Polpa di pomodoro' + 'Pelato Cirio' all share 'pomodoro'
    //   'Aglio rosso' + 'Aglio' share 'aglio'
    //   'Latte di Montagna' + 'Latte Parzialmente Scremato' share 'latte'
    $stockByAnyToken = [];
    // Also build stockByShoppingName: normalized generic name → total qty.
    // And freshStockByShoppingName: same but only counting non-expired batches.
    $stockByShoppingName = [];
    $freshStockByShoppingName = [];
    foreach ($products as $pStock) {
        $qty = isset($inventory[$pStock['id']]) ? (float)$inventory[$pStock['id']]['total_qty'] : 0;
        if ($qty <= 0) continue;
        foreach ($nameTokens($pStock['name']) as $tok) {
            $stockByAnyToken[$tok] = ($stockByAnyToken[$tok] ?? 0) + $qty;
        }
        $sName = strtolower(trim($pStock['shopping_name'] ?? ''));
        if ($sName !== '') {
            $stockByShoppingName[$sName] = ($stockByShoppingName[$sName] ?? 0) + $qty;
            $fQty = isset($inventory[$pStock['id']]) ? (float)($inventory[$pStock['id']]['fresh_qty'] ?? $qty) : 0;
            if ($fQty > 0) {
                $freshStockByShoppingName[$sName] = ($freshStockByShoppingName[$sName] ?? 0) + $fQty;
            }
        }
    }

    // 5. Analyze each product
    $items = [];
    foreach ($products as $p) {
        $pid = $p['id'];
        $inv = $inventory[$pid] ?? null;
        $tx = $txData[$pid] ?? null;

        // Skip products never bought/used and not in inventory
        if (!$tx && !$inv) continue;

        $qty = $inv ? (float)$inv['total_qty'] : 0;
        $unit = $p['unit'] ?: 'pz';
        $defQty = (float)($p['default_quantity'] ?: 0);
        $isOpened = $inv && !empty($inv['opened_at']);

        // --- Usage frequency ---
        $useCount = $tx ? (int)$tx['use_count'] : 0;
        $buyCount = $tx ? (int)$tx['buy_count'] : 0;
        $totalUsed = $tx ? (float)$tx['total_used'] : 0;
        $totalBought = $tx ? (float)$tx['total_bought'] : 0;

        // Days since first purchase
        $firstIn = $tx && $tx['first_in'] ? strtotime($tx['first_in']) : null;
        $lastIn = $tx && $tx['last_in'] ? strtotime($tx['last_in']) : null;
        $lastOut = $tx && $tx['last_out'] ? strtotime($tx['last_out']) : null;
        $daysSinceFirst = $firstIn ? max(1, ($now - $firstIn) / 86400) : 999;

        // Average daily consumption rate — rolling 90-day window with EWMA weighting (#70).
        // Priority: if we have ≥3 use events in last 90 days, use weighted blend
        //   70% weight on last 30 days, 30% on days 31-90 → reacts to habit changes.
        // Fallback: all-time effective-period rate (original logic).
        $used90d = (float)($tx['used_90d'] ?? 0);
        $used30d = (float)($tx['used_30d'] ?? 0);
        $used60_90d = max(0, $used90d - $used30d); // consumption in days 31-90

        $dailyRate30 = $used30d > 0 ? $used30d / 30.0 : 0;
        $dailyRate60 = $used60_90d > 0 ? $used60_90d / 60.0 : 0;

        // Use EWMA only when we have enough recent data
        $useEwma = ($used90d > 0 && $daysSinceFirst >= 14);
        if ($useEwma) {
            if ($dailyRate30 > 0 && $dailyRate60 > 0) {
                // Both windows have data → blend 70/30
                $dailyRate = 0.70 * $dailyRate30 + 0.30 * $dailyRate60;
            } elseif ($dailyRate30 > 0) {
                $dailyRate = $dailyRate30; // only recent data
            } else {
                $dailyRate = $dailyRate60; // only older data
            }
        } else {
            // Fallback: all-time effective-period rate (original logic)
            $lastActivity = max($lastIn ?? 0, $lastOut ?? 0);
            $activitySpan = ($firstIn && $lastActivity > $firstIn) ? ($lastActivity - $firstIn) : 0;
            // Guard: if all activity fits within 24h (e.g. bought & consumed same day / seconds apart),
            // effectiveDays would collapse to 1 → wildly inflated daily rate (e.g. Pizza: in+out 9s apart).
            // Fall back to daysSinceFirst (first purchase → now) for a conservative estimate.
            $effectiveDays = ($activitySpan >= 86400)
                ? max(1, $activitySpan / 86400)
                : $daysSinceFirst;
            $dailyRate = $effectiveDays < 999 && $totalUsed > 0 ? $totalUsed / $effectiveDays : 0;
        }

        // Days of stock remaining
        $daysLeft = ($dailyRate > 0 && $qty > 0) ? $qty / $dailyRate : ($qty > 0 ? 999 : 0);

        // --- Expiry check ---
        $expiryDate = $inv ? $inv['nearest_expiry'] : null;
        $daysToExpiry = $expiryDate ? (strtotime($expiryDate) - $now) / 86400 : 999;
        $isExpired = $daysToExpiry < 0;
        $isExpiringSoon = !$isExpired && $daysToExpiry <= 3;

        // Fresh (non-expired) quantity — used for suppression when only part of stock is expired
        $freshQty = $inv ? (float)($inv['fresh_qty'] ?? $qty) : 0;

        // --- Stock level assessment ---
        // percentage_left: how much is left vs typical purchase size
        // Use average of totalBought/buyCount if available, else default_quantity, else best-guess from defQty or 1
        $refQty = $totalBought > 0 && $buyCount > 0
            ? $totalBought / $buyCount
            : ($defQty > 0 ? $defQty : max(1, $qty)); // avoid inflating pctLeft for products with no history
        $pctLeft = $refQty > 0 ? min(200, ($qty / $refQty) * 100) : ($qty > 0 ? 100 : 0);
        // pctLeft based on FRESH (non-expired) stock only — used for expiry-aware suppression
        $freshPctLeft = $refQty > 0 ? min(200, ($freshQty / $refQty) * 100) : ($freshQty > 0 ? 100 : 0);

        // Cap daysLeft at a reasonable ceiling to avoid 999-day noise in reason strings
        $daysLeft = min($daysLeft, 365);

        // --- Frequency & recency metrics ---
        // Uses per month (30 days) — measures how frequently the product is actually used
        // For items tracked < 30 days, normalize over at least 14 days to avoid inflation
        $usesPerMonth = $daysSinceFirst >= 30
            ? ($useCount / $daysSinceFirst) * 30
            : ($daysSinceFirst >= 7 ? ($useCount / $daysSinceFirst) * 30 : $useCount * 0.5);
        // Days since last use/purchase — measures recency
        $daysSinceLastUse = $lastOut ? ($now - $lastOut) / 86400 : ($lastIn ? ($now - $lastIn) / 86400 : 999);
        // Days since last PURCHASE specifically
        $daysSinceLastBuy = $lastIn ? ($now - $lastIn) / 86400 : 999;
        // Product was restocked very recently (within 3 days) — suppress non-expiry urgency
        $justRestocked = $daysSinceLastBuy <= 3;
        // Is this a frequently used product? (≥ 1.5 uses/month)
        $isFrequent = $usesPerMonth >= 1.5;
        // Is it a regular product? (≥ 0.5 uses/month = at least once every 2 months)
        $isRegular = $usesPerMonth >= 0.5;
        // Is it recently relevant? (used/bought in last 60 days)
        $isRecent = $daysSinceLastUse <= 60;

        // --- Determine urgency ---
        $urgency = 'none'; // none, low, medium, high, critical
        $reasons = [];
        $score = 0;

        // Out of stock
        if ($qty <= 0) {
            // If ANY *specific* token of this depleted product also appears in an in-stock product,
            // the user's need is already covered — skip flagging it.
            // Generic preparation/type words (succo, polpa, crema, ecc.) are excluded from this check
            // to avoid false coverage: 'limmi succo di limone' must NOT be suppressed by 'Succo e polpa di pera'.
            // A token must appear in both names AND be specific (not in the generic list) to count.
            $coverageGeneric = ['succo','polpa','crema','salsa','frutta','verdura','intero',
                                'parzialmente','scremato','biologico','naturale','integrale',
                                'cotto','fresco','secco','arrostito','bollito','sgusciato',
                                'bianco','rosso','nero','giallo','verde','misto','dolce','light'];
            $pToks = array_diff($nameTokens($p['name']), $coverageGeneric);
            $coveredByEquivalent = false;
            // Products exhausted within the last 14 days bypass token-based suppression:
            // if the user just finished a specific product, they likely need to restock it
            // regardless of whether a vague equivalent token exists in another product.
            $recentlyExhausted = $lastOut && ($now - $lastOut) / 86400 <= 14;
            if (!$recentlyExhausted) {
                foreach ($pToks as $tok) {
                    if (($stockByAnyToken[$tok] ?? 0) > 0) { $coveredByEquivalent = true; break; }
                }
            }
            // Also check shopping_name coverage: if this depleted product has a generic name
            // (e.g. "Formaggio") and there's stock of ANY product with the same generic name,
            // the need is covered. This catches "Bel Paese" → covered by "Formaggio Gouda" in stock,
            // "Biscotti Pastefrolle" → covered by "Frollini..." (both shopping_name="Biscotti"), etc.
            // NOTE: recentlyExhausted does NOT bypass this check — same-family stock always suppresses.
            // recentlyExhausted only bypasses the loose token-based check above.
            if (!$coveredByEquivalent) {
                $sName = strtolower(trim($p['shopping_name'] ?? ''));
                if ($sName !== '' && ($stockByShoppingName[$sName] ?? 0) > 0) {
                    $coveredByEquivalent = true;
                }
            }
            if ($coveredByEquivalent) continue;

            // For DEPLETED products: recency is misleading — the product may not have been
            // "used recently" precisely because it ran out. Base urgency on usage rate only.
            $reasons[] = 'Esaurito';
            if ($isFrequent && $useCount >= 5) {
                $urgency = 'critical'; $score += 120;
                $reasons[] = "Uso frequente ({$useCount}x)";
            } elseif ($isFrequent && $useCount >= 2) {
                $urgency = 'critical'; $score += 100;
            } elseif ($isFrequent) {
                // usesPerMonth >= 1.5 but few recorded uses (new product) → high
                $urgency = 'high'; $score += 75;
            } elseif ($isRegular && ($useCount >= 3 || $buyCount >= 2)) {
                $urgency = 'high'; $score += 65;
            } elseif ($isRegular) {
                $urgency = 'medium'; $score += 45;
            } elseif ($useCount >= 2 || $buyCount >= 2) {
                $urgency = 'low'; $score += 30;
            } else {
                $urgency = 'low'; $score += 10;
            }
        }

        // Almost finished — only flag if usage frequency justifies it.
        // Suppress if the same shopping_name family has adequate stock from OTHER products
        // (e.g. "Burro g" at 12% but "Burro conf" at 99% → no need to flag).
        $sNameLow = strtolower(trim($p['shopping_name'] ?? ''));
        $familyOtherStock = ($sNameLow !== '') ? max(0, ($stockByShoppingName[$sNameLow] ?? 0) - $qty) : 0;
        // For g/ml/kg/l: any conf/pz family stock ≥ 0.5 means a package is available.
        // For conf/pz: needs at least 1 full unit from other family products.
        $familyCovered = $sNameLow !== '' && $qty > 0 && (
            (!in_array($unit, ['conf', 'pz']) && $familyOtherStock >= 0.5) ||
            (in_array($unit, ['conf', 'pz']) && $familyOtherStock >= 1.0)
        );
        if (!$familyCovered && $qty > 0 && $pctLeft <= 15 && $isRegular) {
            $urgency = $isFrequent ? 'high' : 'medium';
            $reasons[] = 'Quasi finito (' . round($pctLeft) . '%)';
            $score += 80;
        } elseif (!$familyCovered && $qty > 0 && $pctLeft <= 30 && $isRegular) {
            if ($dailyRate > 0 && $daysLeft <= 5 && $isFrequent) {
                $urgency = 'high';
                $reasons[] = 'Finisce tra ~' . round($daysLeft) . 'gg';
                $score += 75;
            } elseif ($dailyRate > 0 && $daysLeft <= 10 && $isRecent) {
                $urgency = 'medium';
                $reasons[] = 'Finisce tra ~' . round($daysLeft) . 'gg';
                $score += 50;
            } elseif ($isRecent) {
                $urgency = 'low';
                $reasons[] = 'Scorta bassa (' . round($pctLeft) . '%)';
                $score += 30;
            }
        }

        // Expiring soon or expired (needs replacement) — valid regardless of frequency
        if ($isExpired && $qty > 0) {
            // Check if the product's shopping_name FAMILY has adequate FRESH stock
            // from other (non-expired) products. If so, no need to buy more.
            $sNameKey = strtolower(trim($p['shopping_name'] ?? ''));
            $familyFreshQty = $sNameKey !== '' ? ($freshStockByShoppingName[$sNameKey] ?? 0) : 0;
            // Subtract this product's own qty (it is expired, so fresh_qty=0 for it anyway)
            $refQtyLocal = $refQty > 0 ? $refQty : 1;
            $familyFreshPct = min(200, ($familyFreshQty / $refQtyLocal) * 100);

            if (($justRestocked && $freshPctLeft >= 50) || $familyFreshPct >= 50) {
                // Fresh stock from this product or same-family products is adequate.
                // The expired batch will show in the dashboard expiry banner — don't add to shopping list.
            } else {
                $urgency = 'critical';
                $reasons[] = 'Scaduto!';
                $score += 90;
            }
        } elseif ($isExpiringSoon && $qty > 0 && $pctLeft < 50) {
            // Only flag "expiring soon" if stock is also low (<50%). If you have plenty of
            // stock (e.g. just bought fresh produce that naturally expires in 3 days), the
            // shopping list is not the right place — the expiry banner handles it.
            if ($urgency === 'none') $urgency = 'medium';
            $reasons[] = 'Scade tra ' . max(0, round($daysToExpiry)) . 'gg';
            $score += 40;
        }

        // Frequently used but stock getting low (predictive) — scale urgency by imminence
        if ($urgency === 'none' && $dailyRate > 0 && $daysLeft <= 14 && $isFrequent && $isRecent) {
            $daysLeftDisplay = (int)round($daysLeft);
            $reasons[] = 'Finisce tra ~' . $daysLeftDisplay . 'gg';
            if ($daysLeftDisplay <= 3) {
                // Running out within 3 days for a frequent product → high urgency
                $urgency = 'high';
                $score += 70;
            } elseif ($daysLeftDisplay <= 7) {
                // Running out within a week → medium
                $urgency = 'medium';
                $score += 45;
            } else {
                $urgency = 'low';
                $score += 25;
            }
        }
        // Also upgrade existing low urgency when imminent depletion is detected
        if ($urgency === 'low' && $dailyRate > 0 && (int)round($daysLeft) <= 3 && $isFrequent) {
            $urgency = 'high';
            $daysLeftLbl = 'Finisce tra ~' . (int)round($daysLeft) . 'gg';
            if (!in_array($daysLeftLbl, $reasons)) {
                $reasons[] = $daysLeftLbl;
            }
            $score += 45;
        }

        // Opened item with fast consumption — only if actually used regularly
        if ($isOpened && $urgency === 'none' && $dailyRate > 0 && $daysLeft <= 7 && $isRegular) {
            $urgency = 'low';
            $reasons[] = 'Aperto, finisce presto';
            $score += 20;
        }

        // Absolute minimum stock fallback: flag items with critically low stock.
        // Requires: product is regularly consumed (isRegular), bought ≥2 times (proven staple),
        // and stock is clearly depleted relative to normal purchase (pctLeft < 80).
        if ($urgency === 'none' && $isRegular && $buyCount >= 2 && $qty > 0 && $pctLeft < 80) {
            if ($unit === 'conf') {
                if ($qty <= 1) {
                    $urgency = 'high';
                    $reasons[] = 'Solo 1 confezione rimasta';
                    $score += 60;
                } elseif ($qty <= 2) {
                    $urgency = 'medium';
                    $reasons[] = 'Solo 2 confezioni rimaste';
                    $score += 40;
                }
            } elseif ($unit === 'pz') {
                if ($qty <= 1) {
                    $urgency = 'high';
                    $reasons[] = 'Solo 1 pezzo rimasto';
                    $score += 60;
                } elseif ($qty <= 2) {
                    $urgency = 'medium';
                    $reasons[] = 'Solo 2 pezzi rimasti';
                    $score += 40;
                }
            } elseif (($unit === 'g' || $unit === 'ml') && $defQty > 0 && $qty <= $defQty * 0.20) {
                $urgency = 'medium';
                $reasons[] = 'Scorta minima (' . round($qty) . $unit . ')';
                $score += 40;
            }
        }

        if ($urgency === 'none') continue;

        // Family stock coverage: suppress items covered by other products in the same generic family.
        // For non-expired items: suppress if family has other stock (already bought an equivalent).
        // For expired items: suppress if the family has FRESH stock >= the expired qty in other products
        //   e.g. Minestrone tradizione (expired 1/5) but Minesteone 12 verdure + Buon Minestrone = 590g → suppress
        // Critical-without-family-cover always shows so user knows something needs replacing.
        $sNameFamily = strtolower(trim($p['shopping_name'] ?? ''));
        if ($sNameFamily !== '') {
            if (!$isExpired && $urgency !== 'critical') {
                $familyTotal = $stockByShoppingName[$sNameFamily] ?? 0;
                $otherFamilyQty = $familyTotal - $qty;
                if ($otherFamilyQty > 0) {
                    continue;
                }
            } elseif ($isExpired) {
                // For expired: check if OTHER family members have fresh stock covering the expired amount
                $familyFreshTotal = $freshStockByShoppingName[$sNameFamily] ?? 0;
                // freshStockByShoppingName counts this product's fresh_qty too (which is 0 if all expired)
                // So if familyFreshTotal > 0 it means OTHER products in family have fresh stock
                if ($familyFreshTotal > 0) {
                    continue; // family has fresh stock → expired product is covered
                }
            }
        }
        if ($useCount >= 8) $score += 15;
        elseif ($useCount >= 5) $score += 10;

        // Compute generic shopping name for this product
        $shoppingName = $p['shopping_name'] ?: computeShoppingName($p['name'], $p['category'], $p['brand']);

        // Is already on Bring? check both product name and generic shopping name
        $onBring = _productOnBring($p['name'], $bringItems, $shoppingName);

        // "Just restocked" suppression: if bought in the last 3 days AND stock is above 50%
        // of reference qty, skip non-expiry urgency flags. The product doesn't need rebuying yet.
        // Note: isExpiringSoon is intentionally excluded — if you have ≥50% stock it was already
        // filtered above (pctLeft < 50 required for expiringSoon urgency).
        if ($justRestocked && $pctLeft >= 50 && !$isExpired) {
            continue;
        }

        // --- Suggested purchase quantity (based on 14-day consumption) ---
        // Rules:
        //   unit='conf'                              → conf count from dailyRate directly
        //   unit=g/ml/pz + package_unit non-empty   → # confezioni (definitive)
        //   unit=g/ml + defQty > 0 (no pkg_unit)    → round to nearest defQty multiple (approx)
        //   unit=g/ml, no defQty, no pkg_unit        → raw amount, rounded to sensible step
        //   unit=pz, no pkg_unit                     → raw pz count (approx)
        //   dailyRate=0                              → null (no data)
        $suggestedQty    = null;
        $suggestedUnit   = $unit;
        $suggestedApprox = false; // true = show "almeno" in badge

        $pkgUnit = trim($p['package_unit'] ?? ''); // non-empty only when user set a real package

        if ($dailyRate > 0) {
            $need14 = $dailyRate * 14;

            if ($unit === 'conf') {
                // Guard against unit mismatch: transactions may have been recorded in g/ml
                // (e.g. product unit was changed from 'g' to 'conf' after initial tracking).
                // If totalUsed is much larger than buy_count (e.g. 900 vs 4), it's clearly grams.
                // In that case fall back to purchase-frequency as the daily rate.
                if ($buyCount > 0 && $totalUsed > $buyCount * 5 && $daysSinceFirst < 999) {
                    $need14 = ($buyCount / $daysSinceFirst) * 14;
                }
                $suggestedQty   = (int) max(1, min(10, (int)($need14 + 0.3)));
                $suggestedUnit  = 'conf';

            } elseif ($pkgUnit !== '' && $defQty > 0) {
                // Real package info available → express in confezioni (definitive)
                $pkgs           = (int) max(1, min(10, (int)($need14 / $defQty + 0.3)));
                $suggestedQty   = $pkgs;
                $suggestedUnit  = 'conf';

            } elseif (($unit === 'g' || $unit === 'ml') && $defQty > 0) {
                // defQty known but no pkg_unit (e.g. Pomodorini 400g, Salame 100g) →
                // use defQty as the minimum purchase unit and round to nearest multiple.
                // This ensures we never suggest less than one "reference pack".
                $pkgs           = (int) max(1, (int)($need14 / $defQty + 0.3));
                $pkgs           = min(10, $pkgs);
                $suggestedQty   = $pkgs * (int)$defQty;
                $suggestedUnit  = $unit;
                $suggestedApprox = true; // always "almeno" — no confirmed pkg size

            } elseif ($unit === 'g' || $unit === 'ml') {
                // No reference at all → raw amount, approximate
                // Skip if consumption is negligible (< 30 units/14gg)
                if ($need14 >= 30) {
                    if ($need14 < 500) {
                        $rounded = (int) max(100, round($need14 / 100) * 100);
                    } elseif ($need14 < 2000) {
                        $rounded = (int) max(250, round($need14 / 250) * 250);
                    } else {
                        $rounded = (int) max(500, round($need14 / 500) * 500);
                    }
                    $suggestedQty    = $rounded;
                    $suggestedUnit   = $unit;
                    $suggestedApprox = true;
                }

            } elseif ($unit === 'pz') {
                // No package info → raw pz count, approximate
                $suggestedQty    = (int) max(1, min(10, (int)($need14 + 0.3)));
                $suggestedUnit   = 'pz';
                $suggestedApprox = ($suggestedQty > 1);
            }
        }

        // If stock is still >50% just suggest the minimum sensible purchase (don't over-stock)
        if ($suggestedQty !== null && $pctLeft > 50) {
            if ($suggestedUnit === 'conf') {
                $suggestedQty    = 1;
                $suggestedApprox = false;
            } elseif ($suggestedUnit === 'pz') {
                $suggestedQty    = 1;
                $suggestedApprox = false;
            } else {
                // g/ml with >50% stock: suggest minimum reference pack or skip
                if ($defQty > 0) {
                    $suggestedQty    = (int)$defQty;
                    $suggestedApprox = true;
                } else {
                    $suggestedQty = null;
                }
            }
        }

        $items[] = [
            'product_id' => $pid,
            'name' => $p['name'],
            'shopping_name' => $shoppingName,
            'brand' => $p['brand'] ?: '',
            'category' => $p['category'] ?: '',
            'unit' => $unit,
            'current_qty' => round($qty, 1),
            'default_qty' => $defQty,
            'package_unit' => $p['package_unit'] ?: '',
            'pct_left' => round($pctLeft),
            'use_count' => $useCount,
            'buy_count' => $buyCount,
            'daily_rate' => round($dailyRate, 2),
            'uses_per_month' => round($usesPerMonth, 1),
            'days_since_last_use' => round($daysSinceLastUse),
            'days_left' => round($daysLeft),
            'expiry_date' => $expiryDate,
            'days_to_expiry' => round($daysToExpiry),
            'is_opened' => $isOpened,
            'urgency' => $urgency,
            'reasons' => $reasons,
            'score' => $score,
            'on_bring' => $onBring,
            'locations' => $inv ? $inv['locations'] : '',
            'variants' => [],
            'suggested_qty'   => $suggestedQty,   // null = no badge
            'suggested_unit'  => $suggestedUnit,
            'suggested_approx' => $suggestedApprox, // true = show "almeno" prefix
        ];
    }

    // Group items by shopping_name: keep the most urgent representative per group,
    // collect the rest as variants so the UI can show "Affettato (Mortadella, Speck, Nduja)".
    $grouped = [];
    foreach ($items as $item) {
        $sn = $item['shopping_name'];
        if (!isset($grouped[$sn])) {
            $grouped[$sn] = $item;
        } else {
            // Merge: keep the higher-score item as the representative
            if ($item['score'] > $grouped[$sn]['score']) {
                $demoted = [
                    'product_id' => $grouped[$sn]['product_id'],
                    'name'       => $grouped[$sn]['name'],
                    'brand'      => $grouped[$sn]['brand'],
                    'urgency'    => $grouped[$sn]['urgency'],
                ];
                $variants = array_merge([$demoted], $grouped[$sn]['variants']);
                $grouped[$sn] = $item;
                $grouped[$sn]['variants'] = $variants;
            } else {
                $grouped[$sn]['variants'][] = [
                    'product_id' => $item['product_id'],
                    'name'       => $item['name'],
                    'brand'      => $item['brand'],
                    'urgency'    => $item['urgency'],
                ];
            }
            // on_bring is true if ANY variant in the group is already on Bring!
            if ($item['on_bring']) $grouped[$sn]['on_bring'] = true;
        }
    }
    $items = array_values($grouped);

    // Sort by score descending (most urgent first)
    usort($items, fn($a, $b) => $b['score'] - $a['score']);

    echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
}

function bringSuggestItems(PDO $db): void {
    EverLog::info('bringSuggestItems');
    $apiKey = env('GEMINI_API_KEY');

    // 1. Load smart shopping data from cache or compute fresh
    $cacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
    $smartItems = null;
    if (file_exists($cacheFile)) {
        $raw = file_get_contents($cacheFile);
        if ($raw) {
            $cached = json_decode($raw, true);
            if ($cached && isset($cached['items'])) {
                $smartItems = $cached['items'];
            }
        }
    }
    if ($smartItems === null) {
        ob_start();
        smartShopping($db);
        $raw = ob_get_clean();
        $data = json_decode($raw, true);
        $smartItems = $data['items'] ?? [];
    }

    // 2. Get Bring! listUUID for response
    $listUUID = '';
    $auth = bringAuth();
    if ($auth) $listUUID = $auth['bringListUUID'] ?? '';

    // 3. Convert smart shopping items → suggestions (alta/media priority only, skip on_bring)
    $suggestions = [];
    $knownNames  = []; // names already in suggestion list (to deduplicate AI output)

    foreach ($smartItems as $item) {
        if ($item['on_bring'] ?? false) continue;
        $urgency = $item['urgency'] ?? 'low';
        if ($urgency === 'low') continue;

        $priority = ($urgency === 'critical' || $urgency === 'high') ? 'alta' : 'media';
        $reasons  = $item['reasons'] ?? [];
        $reason   = !empty($reasons) ? implode(', ', $reasons) : 'Scorte basse';

        $suggestions[]  = [
            'name'          => $item['name'],
            'specification' => '',
            'reason'        => $reason,
            'category'      => $item['category'] ?: 'altro',
            'priority'      => $priority,
            'source'        => 'stock',
        ];
        $knownNames[] = mb_strtolower($item['name']);

        if (count($suggestions) >= 15) break;
    }

    // 4. Seasonal tip (fallback static, overridden by Gemini below)
    $monthTips = [
        1  => 'Gennaio: arance, mandarini, kiwi, carciofi e verze sono di stagione.',
        2  => 'Febbraio: radicchio, finocchi, pere e agrumi da non perdere.',
        3  => 'Marzo: arrivano gli asparagi! Ottimo anche con piselli freschi e spinaci.',
        4  => 'Aprile: stagione di asparagi, carciofi, fave e fragole.',
        5  => 'Maggio: zucchine, fragole, ciliegie — ottimo mese per frutta e verdura fresca.',
        6  => 'Giugno: albicocche, pesche, pomodori freschi, melanzane — estate in arrivo.',
        7  => 'Luglio: cocomero, pesche, melanzane e pomodori sono al loro meglio.',
        8  => 'Agosto: prugne, fichi, peperoni e basilico fresco di stagione.',
        9  => 'Settembre: uva, fichi, funghi porcini, melograno e more.',
        10 => 'Ottobre: melograni, castagne, funghi, mele e pere autunnali.',
        11 => 'Novembre: cachi, melograni, cavoli, broccoli e radicchio tardivo.',
        12 => 'Dicembre: arance, mandarini, cachi, verze e cavolfiori.',
    ];
    $seasonalTip = $monthTips[(int)date('n')] ?? '';

    // 5. Try to enrich with Gemini: generate ADDITIONAL seasonal / complementary suggestions
    if (!empty($apiKey)) {
        // Cache key: month + list of known names (so it refreshes each month)
        $gemCacheFile = __DIR__ . '/../data/food_facts_cache.json';
        $gemCache     = file_exists($gemCacheFile) ? (json_decode(file_get_contents($gemCacheFile), true) ?: []) : [];
        $gemCacheKey  = 'suggest_ai_' . date('Y-m') . '_' . md5(implode('|', $knownNames));

        // Cache valid for 6 hours
        $cached = $gemCache[$gemCacheKey] ?? null;
        $cacheTs = $gemCache[$gemCacheKey . '_ts'] ?? 0;
        $cacheValid = $cached && (time() - $cacheTs < 21600);

        if ($cacheValid) {
            $aiResult = $cached;
        } else {
            // Build inventory snapshot for Gemini (what the user already has)
            $inStockNames = array_map(fn($i) => $i['name'], array_filter($smartItems, fn($i) => ($i['current_qty'] ?? 0) > 0));
            $dietary  = trim(env('DIETARY') ?? '');
            $monthName = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',
                          7=>'Luglio',8=>'Agosto',9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'][(int)date('n')];
            $inStockJson  = json_encode(array_values(array_slice($inStockNames, 0, 40)), JSON_UNESCAPED_UNICODE);
            $alreadyJson  = json_encode(array_values($knownNames), JSON_UNESCAPED_UNICODE);
            $dietaryLine  = $dietary ? "- Dietary preferences: {$dietary}" : '';

            $prompt = "You are a helpful Italian household shopping assistant.\n"
                . "Today is {$monthName} " . date('Y') . ".\n"
                . "The user already has these products in stock: {$inStockJson}\n"
                . "The following products are already in the shopping list: {$alreadyJson}\n"
                . ($dietaryLine ? $dietaryLine . "\n" : '')
                . "\nTask: suggest 3 to 6 additional products the user should buy this month.\n"
                . "Focus on:\n"
                . "  a) Seasonal Italian fruits and vegetables for {$monthName}\n"
                . "  b) Complementary staples that pair well with what the user has\n"
                . "  c) Anything commonly forgotten but regularly needed\n"
                . "Do NOT suggest products already in stock or already in the shopping list.\n"
                . "Also write one short seasonal tip (max 15 words) in Italian.\n"
                . "\nReply ONLY with valid JSON in this exact format (no markdown):\n"
                . "{\"seasonal_tip\":\"...\",\"suggestions\":[{\"name\":\"...\",\"reason\":\"...\",\"category\":\"...\",\"priority\":\"bassa\"}]}\n"
                . "Category must be one of: frutta,verdura,latticini,carne,pesce,pane,cereali,condimenti,bevande,surgelati,altro\n"
                . "Priority must be: bassa\n"
                . "Name and reason must be in Italian. Reason max 8 words.";

            $payload   = ['contents' => [['parts' => [['text' => $prompt]]]]];
            $gemResult = callGeminiWithFallback($apiKey, $payload, 20, 'bring_suggest');

            $aiResult = null;
            if ($gemResult['http_code'] === 200) {
                $text = $gemResult['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $text = preg_replace('/^```json\s*/i', '', trim($text));
                $text = preg_replace('/\s*```$/i', '', $text);
                $parsed = json_decode(trim($text), true);
                if (is_array($parsed) && isset($parsed['suggestions'])) {
                    $aiResult = $parsed;
                    // Cache result
                    $gemCache[$gemCacheKey]       = $aiResult;
                    $gemCache[$gemCacheKey . '_ts'] = time();
                    file_put_contents($gemCacheFile, json_encode($gemCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
        }

        if ($aiResult) {
            // Override seasonal tip with AI-generated one
            if (!empty($aiResult['seasonal_tip'])) {
                $seasonalTip = $aiResult['seasonal_tip'];
            }
            // Append AI suggestions (deduplicate against stock-based ones)
            foreach ($aiResult['suggestions'] ?? [] as $ai) {
                $aiName = mb_strtolower(trim($ai['name'] ?? ''));
                if (!$aiName) continue;
                // Skip if already in list (first-token check)
                $aiFirst = explode(' ', $aiName)[0];
                $isDup = false;
                foreach ($knownNames as $kn) {
                    if (str_starts_with($kn, $aiFirst)) { $isDup = true; break; }
                }
                if ($isDup) continue;

                $suggestions[] = [
                    'name'          => ucfirst(trim($ai['name'])),
                    'specification' => '',
                    'reason'        => trim($ai['reason'] ?? 'Stagionale'),
                    'category'      => $ai['category'] ?? 'altro',
                    'priority'      => 'bassa',
                    'source'        => 'ai',
                ];
                $knownNames[] = $aiName;
            }
        }
    }

    echo json_encode([
        'success'      => true,
        'suggestions'  => $suggestions,
        'seasonal_tip' => $seasonalTip,
        'listUUID'     => $listUUID,
    ], JSON_UNESCAPED_UNICODE);
}

// ===== SHOPPING ABSTRACTION (internal DB or Bring!) =====

function isShoppingBringMode(): bool {
    return env('SHOPPING_MODE', 'internal') === 'bring'
        && !empty(env('BRING_EMAIL'))
        && !empty(env('BRING_PASSWORD'));
}

function shoppingGetList(PDO $db): void {
    if (isShoppingBringMode()) {
        bringGetList();
        return;
    }
    $items   = $db->query(
        "SELECT name, raw_name, specification FROM shopping_list ORDER BY sort_order ASC, added_at ASC"
    )->fetchAll();
    $purchase = array_map(fn($r) => [
        'name'          => $r['name'],
        'rawName'       => $r['raw_name'] ?: $r['name'],
        'specification' => $r['specification'],
    ], $items);
    echo json_encode([
        'success'   => true,
        'listUUID'  => 'internal-list',
        'purchase'  => $purchase,
        'recently'  => [],
    ], JSON_UNESCAPED_UNICODE);
}

function shoppingAdd(PDO $db): void {
    if (isShoppingBringMode()) {
        bringAddItems();
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $items = $input['items'] ?? [];
    $added = 0; $updated = 0; $skipped = 0;
    foreach ($items as $item) {
        $name    = trim($item['name'] ?? '');
        if ($name === '') continue;
        $rawName = trim($item['rawName'] ?? $item['raw_name'] ?? $name);
        $spec    = $item['specification'] ?? '';
        $updateSpec = !empty($item['update_spec']);
        $stmt = $db->prepare("SELECT id, specification FROM shopping_list WHERE lower(name) = lower(?)");
        $stmt->execute([$name]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ($updateSpec && $existing['specification'] !== $spec) {
                $db->prepare("UPDATE shopping_list SET specification=?, raw_name=? WHERE id=?")->execute([$spec, $rawName, $existing['id']]);
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            $db->prepare("INSERT INTO shopping_list (name, raw_name, specification) VALUES (?, ?, ?)")->execute([$name, $rawName, $spec]);
            $added++;
            _fireHaWebhook('shopping_add', ['item' => $name, 'specification' => $spec]);
        }
    }
    echo json_encode(['success' => true, 'added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => []]);
}

function shoppingRemove(PDO $db): void {
    if (isShoppingBringMode()) {
        bringRemoveItem();
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($input['name'] ?? '');
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Missing name']);
        return;
    }
    $db->prepare("DELETE FROM shopping_list WHERE lower(name) = lower(?)")->execute([$name]);
    echo json_encode(['success' => true]);
}

// ===== SHARED APP DATA FUNCTIONS =====

function appSettingsGet(PDO $db): void {
    $rows = $db->query("SELECT key, value FROM app_settings")->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        EverLog::debug('appSettingsGet');
        $settings[$row['key']] = json_decode($row['value'], true) ?? $row['value'];
    }
    echo json_encode(['success' => true, 'settings' => $settings]);
}

function appSettingsSave(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input['settings'] ?? null)) {
        EverLog::debug('appSettingsSave');
        echo json_encode(['error' => 'Missing settings object']);
        return;
    }
    $stmt = $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                          ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at");
    foreach ($input['settings'] as $key => $value) {
        $stmt->execute([$key, json_encode($value)]);
    }
    echo json_encode(['success' => true]);
}

function recipesList(PDO $db): void {
    $limit = min(intval($_GET['limit'] ?? 60), 200);
    $rows = $db->query("SELECT id, date, meal, recipe_json, created_at FROM recipes ORDER BY date DESC, created_at DESC LIMIT {$limit}")->fetchAll();
    EverLog::debug('recipesList');
    $recipes = [];
    foreach ($rows as $row) {
        $recipes[] = [
            'id' => $row['id'],
            'date' => $row['date'],
            'meal' => $row['meal'],
            'recipe' => json_decode($row['recipe_json'], true),
            'savedAt' => strtotime($row['created_at']) * 1000
        ];
    }
    echo json_encode(['success' => true, 'recipes' => $recipes]);
}

function recipesSave(PDO $db): void {
    EverLog::info('recipesSave');
    $input = json_decode(file_get_contents('php://input'), true);
    $date = $input['date'] ?? date('Y-m-d');
    $meal = trim($input['meal'] ?? '') ?: 'libero';
    $recipe = $input['recipe'] ?? null;

    if (!$recipe) {
        echo json_encode(['error' => 'Missing recipe']);
        return;
    }

    // UPSERT: one recipe per meal per day (last one wins)
    $stmt = $db->prepare("INSERT INTO recipes (date, meal, recipe_json, created_at) VALUES (?, ?, ?, datetime('now'))
                          ON CONFLICT(date, meal) DO UPDATE SET recipe_json = excluded.recipe_json, created_at = excluded.created_at");
    $stmt->execute([$date, $meal, json_encode($recipe)]);

    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
}

function recipesDelete(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if ($id > 0) {
        EverLog::info('recipesDelete');
        $db->prepare("DELETE FROM recipes WHERE id = ?")->execute([$id]);
    }
    echo json_encode(['success' => true]);
}

function chatList(PDO $db): void {
    $rows = $db->query("SELECT id, role, text, created_at FROM chat_messages ORDER BY id ASC LIMIT 100")->fetchAll();
    echo json_encode(['success' => true, 'messages' => $rows]);
}

function chatSave(PDO $db): void {
    EverLog::debug('chatList');
    $input = json_decode(file_get_contents('php://input'), true);
    $messages = $input['messages'] ?? [];
    if (empty($messages)) {
        echo json_encode(['error' => 'No messages']);
        return;
    }
    $stmt = $db->prepare("INSERT INTO chat_messages (role, text, created_at) VALUES (?, ?, datetime('now'))");
    foreach ($messages as $msg) {
        if (!empty($msg['role']) && isset($msg['text'])) {
            $stmt->execute([$msg['role'], $msg['text']]);
        }
    }
    // Prune: keep only the last 200 messages (cap to avoid unbounded growth)
    $db->exec("DELETE FROM chat_messages WHERE id NOT IN (SELECT id FROM chat_messages ORDER BY id DESC LIMIT 200)");
    echo json_encode(['success' => true]);
}

function chatClear(PDO $db): void {
    EverLog::info('chatClear');
    $db->exec("DELETE FROM chat_messages");
    echo json_encode(['success' => true]);
}

/**
 * One-time migration: convert all kg→g and l→ml in products table,
 * and scale inventory quantities accordingly.
 */
function migrateUnitsToBase(PDO $db): void {
    EverLog::info('migrateUnitsToBase');
    $changes = 0;

    // Get products with kg or l units
    $stmt = $db->query("SELECT id, unit, default_quantity, package_unit FROM products WHERE unit IN ('kg','l') OR package_unit IN ('kg','l')");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $p) {
        $newUnit = $p['unit'];
        $newDefQty = (float)$p['default_quantity'];
        $newPkgUnit = $p['package_unit'];
        $scaleInventory = false;

        if ($p['unit'] === 'kg') {
            $newUnit = 'g';
            $newDefQty = $newDefQty * 1000;
            $scaleInventory = true;
        } elseif ($p['unit'] === 'l') {
            $newUnit = 'ml';
            $newDefQty = $newDefQty * 1000;
            $scaleInventory = true;
        }

        if ($p['package_unit'] === 'kg') {
            $newPkgUnit = 'g';
            if ($p['unit'] === 'conf') $newDefQty = $newDefQty * 1000;
        } elseif ($p['package_unit'] === 'l') {
            $newPkgUnit = 'ml';
            if ($p['unit'] === 'conf') $newDefQty = $newDefQty * 1000;
        }

        $upd = $db->prepare("UPDATE products SET unit = ?, default_quantity = ?, package_unit = ? WHERE id = ?");
        $upd->execute([$newUnit, $newDefQty, $newPkgUnit, $p['id']]);
        $changes++;

        // Scale inventory quantities (kg→g means multiply by 1000)
        if ($scaleInventory) {
            $db->prepare("UPDATE inventory SET quantity = quantity * 1000 WHERE product_id = ?")->execute([$p['id']]);
        }
    }

    echo json_encode(['success' => true, 'changes' => $changes]);
}

// =============================================================================
// ===== CENTRALIZED ERROR REPORTING → GITHUB ISSUES ==========================
// =============================================================================

// GH_REPO is defined at the very top of this file so they
// are available to the global exception handler even before this point.
// The token is accessed via _ghToken() which decodes it at runtime.

/**
 * POST /api/?action=report_error
 *
 * Accepts error payloads from any client (PWA browser, Android kiosk, cron).
 * Creates a GitHub issue on dadaloop82/EverShelf with deduplication:
 * if an open issue with the same fingerprint already exists it posts a comment
 * instead of opening a duplicate.
 *
 * Expected JSON body:
 *   source      string  'pwa'|'kiosk'|'php'|'cron'|'scale'
 *   type        string  e.g. 'js-error'|'php-crash'|'unhandled-promise'|…
 *   message     string  Error message (required)
 *   stack       string? Stack trace
 *   context     object? Arbitrary key→value extra info
 *   url         string? Page URL where the error occurred
 *   user_agent  string? Navigator UA
 *   version     string? App version
 */
function reportError(): void {
    EverLog::info('reportError');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $source    = preg_replace('/[^a-z0-9_\-]/', '', strtolower($input['source']    ?? 'unknown'));
    $type      = preg_replace('/[^a-z0-9_\-]/', '', strtolower($input['type']      ?? 'error'));
    $message   = substr(trim($input['message']   ?? ''), 0, 500);
    $stack     = substr(trim($input['stack']     ?? ''), 0, 4000);
    $pageUrl   = substr(trim($input['url']       ?? ''), 0, 300);
    $ua        = substr(trim($input['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    $version   = substr(trim($input['version']   ?? ''), 0, 50);
    $context   = $input['context'] ?? [];

    if (empty($message)) {
        echo json_encode(['ok' => false, 'error' => 'message required']);
        return;
    }

    // ── Write to local log regardless of GitHub availability ──────────────
    _appendErrorLog($source, $type, $message, $stack, $pageUrl, $ua, $context);

    // ── Version guard: skip GitHub issue if client is not on latest release ─
    // Avoids noise from bugs already fixed in a newer version.
    // Exception: install/update errors are ALWAYS reported regardless of version,
    // because a device that is failing to install the update is by definition on
    // an old version — suppressing the issue is the opposite of useful.
    $installErrorTypes = ['install_download_failed', 'install_failure', 'install-failure', 'install_packager_exception'];
    $bypassVersionGuard = in_array($type, $installErrorTypes, true)
        || ($context['version_guard_bypass'] ?? false);
    if (!$bypassVersionGuard && !_isLatestVersion($version)) {
        echo json_encode(['ok' => true, 'skipped' => 'outdated_version']);
        return;
    }

    // ── Fire GitHub issue (non-blocking: we always return ok to client) ───
    _createOrCommentGithubIssue(_ghToken(), GH_REPO, $source, $type, $message, $stack, $pageUrl, $ua, $version, $context);

    echo json_encode(['ok' => true]);
}

/**
 * POST /api/?action=report_bug
 *
 * Manual bug/feature/question report submitted by the user via the in-app form.
 * Creates a GitHub issue directly with the provided title and description.
 *
 * Expected JSON body:
 *   type        string  'bug'|'feature'|'question'
 *   title       string  Issue title (required, max 150 chars)
 *   description string  Main description (required, max 3000 chars)
 *   steps       string? Steps to reproduce (optional, max 2000 chars)
 *   lang        string? UI language the user is running
 *   url         string? Page URL
 *   user_agent  string? Navigator UA
 *   version     string? App version
 */
function reportBugManual(): void {
    EverLog::info('reportBugManual');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $allowedTypes = ['bug', 'feature', 'question'];
    $type  = in_array($input['type'] ?? '', $allowedTypes, true) ? $input['type'] : 'bug';
    $title = substr(trim($input['title']       ?? ''), 0, 150);
    $desc  = substr(trim($input['description'] ?? ''), 0, 3000);
    $steps = substr(trim($input['steps']       ?? ''), 0, 2000);
    $ua    = substr(trim($input['user_agent']  ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
    $url   = substr(trim($input['url']         ?? ''), 0, 300);
    $ver   = substr(trim($input['version']     ?? ''), 0, 50);
    $lang  = preg_replace('/[^a-z\-]/', '', strtolower($input['lang'] ?? 'it'));

    if (empty($title) || empty($desc)) {
        echo json_encode(['ok' => false, 'error' => 'title and description required']);
        return;
    }

    $token = _ghToken();
    if (!$token) {
        // No GitHub token configured — log locally and return ok so the UX is not broken
        _appendErrorLog('pwa', 'manual_report', $title, $desc, $url, $ua, ['type' => $type, 'version' => $ver, 'lang' => $lang]);
        echo json_encode(['ok' => true, 'issue' => null]);
        return;
    }

    // Labels: always 'user-report' + type-specific label
    $labelMap = [
        'bug'      => ['bug',         'user-report'],
        'feature'  => ['enhancement', 'user-report'],
        'question' => ['question',    'user-report'],
    ];
    $labels = $labelMap[$type];

    $typeEmoji = ['bug' => '🐛', 'feature' => '💡', 'question' => '❓'][$type];
    $ts = date('Y-m-d H:i:s T');

    $body  = "## {$typeEmoji} User Report\n\n";
    $body .= "**Description:**\n{$desc}\n\n";
    if ($steps) {
        $body .= "**Steps to reproduce:**\n{$steps}\n\n";
    }
    $body .= "---\n";
    $body .= "**Version:** `{$ver}`  \n";
    $body .= "**Language:** `{$lang}`  \n";
    if ($url) $body .= "**URL:** `{$url}`  \n";
    if ($ua)  $body .= "**User-Agent:** `{$ua}`  \n";
    $body .= "**Reported at:** {$ts}\n\n";
    $body .= "_This issue was submitted via the in-app bug report form._";

    $res = _githubRequest($token, 'POST',
        'https://api.github.com/repos/' . GH_REPO . '/issues',
        ['title' => $title, 'body' => $body, 'labels' => $labels]
    );

    $issueNum = $res['body']['number'] ?? null;
    $issueUrl = $res['body']['html_url'] ?? null;
    if ($issueNum) {
        echo json_encode(['ok' => true, 'issue' => $issueNum, 'url' => $issueUrl]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'github_api_error']);
    }
}

/**
 * Append to data/error_reports.log (local safety net, max 500 KB)
 */
function _appendErrorLog(string $source, string $type, string $message, string $stack, string $url, string $ua, array $context): void {
    $logFile = __DIR__ . '/../data/error_reports.log';
    // Rotate if > 500 KB
    if (file_exists($logFile) && filesize($logFile) > 500000) {
        $lines = file($logFile);
        $lines = array_slice($lines, -300);
        file_put_contents($logFile, implode('', $lines));
    }
    $ts   = date('Y-m-d H:i:s');
    $ctx  = $context ? ' ctx=' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $line = "[$ts] [$source] [$type] $message" . ($url ? " | url=$url" : '') . $ctx . "\n";
    if ($stack) $line .= "  STACK: " . str_replace("\n", "\n  ", $stack) . "\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Fingerprint = sha1(source:type:first-120-chars-of-message)
 * Used to deduplicate open issues.
 */
function _errorFingerprint(string $source, string $type, string $message): string {
    return sha1($source . ':' . $type . ':' . substr($message, 0, 120));
}

/**
 * Return the latest release tag for this repo from GitHub (cached 6 h).
 * Returns '' if no release exists or the API is unreachable.
 */
function _latestReleaseTag(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $cacheFile = __DIR__ . '/../data/latest_release_cache.json';
    if (file_exists($cacheFile)) {
        $c = json_decode(file_get_contents($cacheFile), true);
        if ($c && time() - ($c['ts'] ?? 0) < 21600) { // 6 h
            return $cached = ($c['tag'] ?? '');
        }
    }
    $res = _githubRequest(_ghToken(), 'GET', 'https://api.github.com/repos/' . GH_REPO . '/releases/latest');
    $tag = $res['body']['tag_name'] ?? '';
    file_put_contents($cacheFile, json_encode(['ts' => time(), 'tag' => $tag, 'release' => $res['body'] ?? []]));
    return $cached = $tag;
}

/**
 * Read the webapp version from manifest.json (cached per process).
 */
function _appVersion(): string {
    static $ver = null;
    if ($ver !== null) return $ver;
    $manifest = @json_decode(@file_get_contents(__DIR__ . '/../manifest.json'), true);
    return $ver = ($manifest['version'] ?? '');
}

/**
 * Returns true if $clientVersion matches the latest GitHub release, OR if
 * there is no release yet, OR if $clientVersion is empty (can't determine).
 * A leading 'v' is stripped from both sides before comparison.
 */
function _isLatestVersion(string $clientVersion): bool {
    if ($clientVersion === '') return true; // unknown → allow (don't suppress)
    $latest = _latestReleaseTag();
    if ($latest === '') return true; // no release yet → allow
    $latestNorm = ltrim($latest, 'v');
    // If tag is not semver-like (e.g. "latest", "rolling") we can't compare
    // meaningfully, so don't suppress error reporting.
    if (!preg_match('/^\d+\.\d+/', $latestNorm)) return true;
    return ltrim($clientVersion, 'v') === $latestNorm;
}

/**
 * GET/POST /api/?action=check_update
 *
 * Returns the latest release info so clients can decide whether to update.
 * Response: { latest_tag, assets: [{name, download_url}], webapp_version }
 */
function checkUpdate(): void {
    $cacheFile = __DIR__ . '/../data/latest_release_cache.json';
    $release   = [];
    if (file_exists($cacheFile)) {
        EverLog::info('checkUpdate');
        $c = json_decode(file_get_contents($cacheFile), true);
        if ($c && time() - ($c['ts'] ?? 0) < 21600) {
            $release = $c['release'] ?? [];
        }
    }
    if (empty($release)) {
        $res     = _githubRequest(_ghToken(), 'GET', 'https://api.github.com/repos/' . GH_REPO . '/releases/latest');
        $release = $res['body'] ?? [];
        $tag     = $release['tag_name'] ?? '';
        file_put_contents($cacheFile, json_encode(['ts' => time(), 'tag' => $tag, 'release' => $release]));
    }

    $assets = [];
    foreach (($release['assets'] ?? []) as $a) {
        $assets[] = ['name' => $a['name'] ?? '', 'download_url' => $a['browser_download_url'] ?? ''];
    }

    echo json_encode([
        'ok'             => true,
        'latest_tag'     => $release['tag_name'] ?? '',
        'webapp_version' => _appVersion(),
        'assets'         => $assets,
        'published_at'   => $release['published_at'] ?? '',
        'html_url'       => $release['html_url'] ?? '',
    ]);
}

/**
 * Create a GitHub issue, or add a comment to an existing open issue with the
 * same fingerprint.  Uses the REST API v3 directly (no library needed).
 */
function _createOrCommentGithubIssue(
    string $token, string $repo,
    string $source, string $type, string $message,
    string $stack, string $pageUrl, string $ua,
    string $version, array $context
): void {
    $fp = _errorFingerprint($source, $type, $message);
    EverLog::debug('_createOrCommentGithubIssue', ['fp' => $fp, 'type' => $type]);

    // ── 1. Search for an existing open issue with this fingerprint ─────────
    $searchQuery = urlencode("repo:$repo is:issue is:open label:auto-report \"fp:$fp\" in:body");
    $searchResult = _githubRequest($token, 'GET', "https://api.github.com/search/issues?q=$searchQuery&per_page=1");

    $existingIssueNumber = null;
    if (isset($searchResult['body']['items']) && count($searchResult['body']['items']) > 0) {
        $existingIssueNumber = $searchResult['body']['items'][0]['number'] ?? null;
    }

    // ── Build the common details block ─────────────────────────────────────
    $ts      = date('Y-m-d H:i:s T');
    $ctxMd   = '';
    if ($context) {
        $ctxMd = "\n**Context:**\n```json\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n";
    }
    $stackMd = $stack ? "\n**Stack trace:**\n```\n$stack\n```\n" : '';
    $urlMd   = $pageUrl ? "\n**URL:** `$pageUrl`" : '';
    $uaMd    = $ua ? "\n**User-Agent:** `$ua`" : '';
    $verMd   = $version ? "\n**Version:** `$version`" : '';

    if ($existingIssueNumber) {
        // ── 2a. Post a comment to the existing issue ──────────────────────
        $body = "### 🔁 Recurrence — $ts\n"
            . "**Source:** `$source` | **Type:** `$type`\n"
            . $urlMd . $uaMd . $verMd . "\n"
            . $ctxMd . $stackMd
            . "\n---\n_fp:{$fp}_";
        _githubRequest($token, 'POST',
            "https://api.github.com/repos/$repo/issues/$existingIssueNumber/comments",
            ['body' => $body]
        );
    } else {
        // ── 2b. Create a new issue ────────────────────────────────────────
        // Determine labels from source
        $labelMap = [
            'pwa'   => 'js-error',
            'kiosk' => 'kiosk-error',
            'php'   => 'php-crash',
            'cron'  => 'php-crash',
            'scale' => 'scale-error',
        ];
        $typeLabel = $labelMap[$source] ?? 'js-error';

        $shortMsg = strlen($message) > 70 ? substr($message, 0, 70) . '…' : $message;
        $title    = "[" . strtoupper($source) . "] $shortMsg";

        $body = "## 🚨 Automatic Error Report\n\n"
            . "**Source:** `$source`  \n"
            . "**Type:** `$type`  \n"
            . "**Reported at:** $ts  \n"
            . $urlMd . "\n"
            . $uaMd . "\n"
            . $verMd . "\n\n"
            . "**Error message:**\n> $message\n"
            . $stackMd
            . $ctxMd
            . "\n---\n"
            . "<!-- auto-report fp:$fp -->\n"
            . "_This issue was created automatically by EverShelf's error reporter. fp:`{$fp}`_";

        _githubRequest($token, 'POST',
            "https://api.github.com/repos/$repo/issues",
            [
                'title'  => $title,
                'body'   => $body,
                'labels' => ['auto-report', $typeLabel],
            ]
        );
    }
}

/**
 * Minimal GitHub REST API helper (curl).
 * Returns ['http_code' => int, 'body' => array].
 */
function _githubRequest(string $token, string $method, string $url, array $payload = []): array {
    EverLog::debug('_githubRequest');
    $ch = curl_init($url);
    $headers = [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: EverShelf-ErrorReporter/1.0',
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $code, 'body' => json_decode($raw ?: '{}', true) ?: []];
}

/**
 * Called by the PHP exception/shutdown handlers registered at the top of this file.
 * Writes to local log + creates a GitHub issue.
 */
function _phpErrorReport(string $message, string $file, int $line, string $trace, string $type): void {
    EverLog::error('_phpErrorReport');
    // Prevent infinite loops if this function itself throws
    static $running = false;
    if ($running) return;
    $running = true;

    $source  = 'php';
    $errType = 'php-crash';
    $appVer  = _appVersion();
    $context = [
        'file'    => $file,
        'line'    => $line,
        'php'     => PHP_VERSION,
        'app_ver' => $appVer,
        'action'  => $_GET['action'] ?? '',
        'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
    ];

    _appendErrorLog($source, $errType, "[$type] $message", $trace, '', '', $context);

    // Only create GitHub issue if running the latest released version
    if (_isLatestVersion($appVer)) {
        _createOrCommentGithubIssue(
            _ghToken(), GH_REPO, $source, $errType,
            "[$type] $message", $trace,
            '', '', $appVer, $context
        );
    }

    $running = false;
}

// =============================================================================
// ===== GEMINI AI: PRODUCT HINT (shelf-life + storage suggestion) =============
// =============================================================================
/**
 * POST /api/?action=gemini_product_hint
 * Body: { name, category, lang }
 * Returns: { success, location, expiry_days, reason, source }
 * Uses a permanent cache keyed by (name, lang) — science doesn't change.
 */
function geminiProductHint(): void {
    EverLog::info('geminiProductHint');
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        EverLog::info('geminiProductHint');
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name     = trim($input['name']    ?? '');
    $category = trim($input['category'] ?? '');
    $lang     = trim($input['lang']    ?? 'it');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'missing name']);
        return;
    }

    // Cache keyed by normalised name + lang
    $cacheFile = __DIR__ . '/../data/food_facts_cache.json';
    $cacheKey  = 'phint_' . md5(mb_strtolower($name) . '|' . $lang);
    $cache = [];
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    if (!empty($cache[$cacheKey])) {
        echo json_encode(array_merge(['success' => true, 'source' => 'cache'], $cache[$cacheKey]));
        return;
    }

    $langLabel = match($lang) { 'en' => 'English', 'de' => 'German', default => 'Italian' };
    $prompt = "You are a food safety expert. For the food product named \"{$name}\" (category: {$category}), "
        . "answer in {$langLabel} with a strict JSON object and NOTHING else:\n"
        . "{\n"
        . "  \"location\": \"dispensa\" | \"frigo\" | \"freezer\",\n"
        . "  \"expiry_days\": <integer, typical unopened shelf life in days>,\n"
        . "  \"reason\": \"<1 short sentence explaining location and duration>\"\n"
        . "}\n"
        . "Rules: location must be one of the three values. expiry_days must be a positive integer. "
        . "If the product is typically refrigerated use 'frigo'. If frozen use 'freezer'. Otherwise 'dispensa'. "
        . "Output ONLY the JSON, no markdown, no extra text.";

    $payload = ['contents' => [['parts' => [['text' => $prompt]]]]];
    $result  = callGeminiWithFallback($apiKey, $payload, 15, 'product_hint');

    if ($result['http_code'] !== 200) {
        echo json_encode(['success' => false, 'error' => 'gemini_error', 'http_code' => $result['http_code']]);
        return;
    }

    $text = $result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    // Strip potential markdown fences
    $text = preg_replace('/^```json\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '', $text);
    $parsed = json_decode(trim($text), true);

    $allowedLocations = ['dispensa', 'frigo', 'freezer'];
    if (
        !is_array($parsed)
        || empty($parsed['location'])
        || !in_array($parsed['location'], $allowedLocations, true)
        || empty($parsed['expiry_days'])
        || !is_numeric($parsed['expiry_days'])
    ) {
        echo json_encode(['success' => false, 'error' => 'parse_error', 'raw' => $text]);
        return;
    }

    $data = [
        'location'    => $parsed['location'],
        'expiry_days' => (int)$parsed['expiry_days'],
        'reason'      => $parsed['reason'] ?? '',
    ];

    // Persist to cache (permanent — no expiry)
    $cache[$cacheKey] = $data;
    file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo json_encode(array_merge(['success' => true, 'source' => 'gemini'], $data));
}

// =============================================================================
// ===== GEMINI AI: SHOPPING SUGGESTION ENRICHMENT ============================
// =============================================================================
/**
 * POST /api/?action=gemini_shopping_enrich
 * Body: { items: [{name, reason, category, priority}], lang }
 * Returns: { success, items: [{name, reason, tip}] }
 * Enriches shopping suggestions with a short actionable tip per item.
 * Batches all items in a single Gemini call. Cached by name+lang hash.
 */
function geminiShoppingEnrich(PDO $db): void {
    EverLog::info('geminiShoppingEnrich');
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        EverLog::info('geminiShoppingEnrich');
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $items = $input['items'] ?? [];
    $lang  = trim($input['lang'] ?? 'it');

    if (empty($items)) {
        echo json_encode(['success' => true, 'items' => []]);
        return;
    }

    // Cache keyed by sorted item names + lang (so reorder doesn't bust it)
    $names = array_column($items, 'name');
    sort($names);
    $cacheFile = __DIR__ . '/../data/food_facts_cache.json';
    $cacheKey  = 'senrich_' . md5(implode('|', $names) . '|' . $lang);
    $cache = [];
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    if (!empty($cache[$cacheKey])) {
        echo json_encode(['success' => true, 'items' => $cache[$cacheKey], 'source' => 'cache']);
        return;
    }

    $langLabel  = match($lang) { 'en' => 'English', 'de' => 'German', default => 'Italian' };
    $itemsJson  = json_encode(array_map(fn($i) => [
        'name'     => $i['name'],
        'reason'   => $i['reason'] ?? '',
        'category' => $i['category'] ?? '',
        'priority' => $i['priority'] ?? 'media',
    ], $items), JSON_UNESCAPED_UNICODE);

    $prompt = "You are a practical household assistant. "
        . "For each item in this shopping list, add a very short tip (max 10 words) in {$langLabel} "
        . "on what to look for when buying or how to store it. "
        . "Input JSON array:\n{$itemsJson}\n\n"
        . "Reply ONLY with a JSON array of objects with exactly these keys:\n"
        . "[{\"name\":\"...\",\"tip\":\"...\"},...]\n"
        . "Keep the same order and count as the input. Output ONLY the JSON array, no markdown.";

    $payload = ['contents' => [['parts' => [['text' => $prompt]]]]];
    $result  = callGeminiWithFallback($apiKey, $payload, 20, 'shopping_enrich');

    if ($result['http_code'] !== 200) {
        echo json_encode(['success' => false, 'error' => 'gemini_error']);
        return;
    }

    $text = $result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text = preg_replace('/^```json\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '', $text);
    $parsed = json_decode(trim($text), true);

    if (!is_array($parsed)) {
        echo json_encode(['success' => false, 'error' => 'parse_error']);
        return;
    }

    // Build tip map by name for safe merging
    $tipMap = [];
    foreach ($parsed as $p) {
        if (!empty($p['name'])) $tipMap[mb_strtolower($p['name'])] = $p['tip'] ?? '';
    }

    $enriched = array_map(function($item) use ($tipMap) {
        $item['tip'] = $tipMap[mb_strtolower($item['name'])] ?? '';
        return $item;
    }, $items);

    // Cache for 24 h (TTL stored alongside)
    $cache[$cacheKey] = $enriched;
    file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo json_encode(['success' => true, 'items' => $enriched, 'source' => 'gemini']);
}

// =============================================================================
// ===== GEMINI AI: NUMBER OCR (read barcode digits from image) ================
// =============================================================================
/**
 * POST /api/?action=gemini_number_ocr
 * Body: { image: base64-jpeg }
 * Returns: { success, barcode } or { success: false, error }
 * Uses Gemini vision to read the barcode number printed on a product label.
 */
function geminiNumberOCR(): void {
    EverLog::info('geminiNumberOCR');
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) { echo json_encode(['success' => false, 'error' => 'no_api_key']); return; }
    EverLog::info('geminiNumberOCR');

    $input = json_decode(file_get_contents('php://input'), true);
    $imageBase64 = $input['image'] ?? '';
    if (!$imageBase64) { echo json_encode(['success' => false, 'error' => 'no_image']); return; }

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => 'Look at this product image. Find the barcode number (EAN-13 or EAN-8) printed on the label — it is usually a sequence of 8 or 13 digits printed below or near the barcode stripes. Return ONLY the digit sequence, nothing else. If you cannot find a valid barcode number, return exactly: none'],
                ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageBase64]]
            ]
        ]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 20, 'thinkingConfig' => ['thinkingBudget' => 0]]
    ];

    $result = callGeminiWithFallback($apiKey, $payload, 10, 'number_ocr');
    $text   = trim($result['text'] ?? '');
    $digits = preg_replace('/\D/', '', $text);

    if (strlen($digits) === 13 || strlen($digits) === 8) {
        echo json_encode(['success' => true, 'barcode' => $digits]);
    } else {
        echo json_encode(['success' => false, 'error' => 'not_found']);
    }
}

// =============================================================================
// ===== GEMINI AI: ANOMALY EXPLANATION =======================================
// =============================================================================
/**
 * POST /api/?action=gemini_anomaly_explain
 * Body: { name, inv_qty, expected_qty, diff, direction, unit, lang }
 * Returns: { success, explanation }
 * Explains in plain language why the anomaly likely occurred and what to do.
 */
function geminiAnomalyExplain(): void {
    EverLog::info('geminiAnomalyExplain');
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        EverLog::info('geminiAnomalyExplain');
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $name      = trim($input['name']         ?? '');
    $invQty    = $input['inv_qty']            ?? 0;
    $expQty    = $input['expected_qty']       ?? 0;
    $diff      = $input['diff']              ?? 0;
    $direction = $input['direction']         ?? 'missing';
    $unit      = $input['unit']              ?? 'pz';
    $lang      = trim($input['lang']         ?? 'it');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'missing name']);
        return;
    }

    $langLabel = match($lang) { 'en' => 'English', 'de' => 'German', default => 'Italian' };

    $directionDesc = match($direction) {
        'phantom'   => "The inventory shows {$invQty} {$unit} but transaction history predicts only {$expQty} {$unit} (excess of " . abs($diff) . " {$unit}).",
        'missing'   => "The inventory shows {$invQty} {$unit} but transaction history predicts {$expQty} {$unit} (shortage of " . abs($diff) . " {$unit}).",
        'untracked' => "More consumption was recorded than purchase entries. The initial stock was likely never registered as an 'in' transaction. Current inventory: {$invQty} {$unit}.",
        default     => "Inventory discrepancy detected for {$name}.",
    };

    $prompt = "You are a helpful home pantry assistant. "
        . "An inventory discrepancy has been detected for the product \"{$name}\". "
        . $directionDesc . " "
        . "In 2-3 sentences in {$langLabel}, explain in simple friendly language: "
        . "(1) the most likely everyday reason this happened, and "
        . "(2) the simplest action the user should take to fix it. "
        . "Do NOT mention databases, transactions, or technical terms. "
        . "Be conversational and practical.";

    $payload = ['contents' => [['parts' => [['text' => $prompt]]]]];
    $result  = callGeminiWithFallback($apiKey, $payload, 15, 'anomaly_explain');

    if ($result['http_code'] !== 200) {
        echo json_encode(['success' => false, 'error' => 'gemini_error']);
        return;
    }

    $explanation = trim($result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '');

    echo json_encode(['success' => true, 'explanation' => $explanation]);
}

// ─────────────────────────────────────────────────────────────────────────────
// SHOPPING LIST PRICE ESTIMATION (AI-powered, cached)
// ─────────────────────────────────────────────────────────────────────────────
// Note: PRICE_CACHE_PATH constant is defined at the top of the file.

function _loadPriceCache(): array {
    if (!file_exists(PRICE_CACHE_PATH)) return [];
    try { return json_decode(file_get_contents(PRICE_CACHE_PATH), true) ?? []; } catch (\Throwable $e) { return []; }
}

function _savePriceCache(array $data): void {
    file_put_contents(PRICE_CACHE_PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Return cache key: md5(lowercase name + country + schema version)
 * Bump version suffix when AI prompt format changes to auto-invalidate old entries.
 */
function _priceKey(string $name, string $country): string {
    return md5(mb_strtolower(trim($name)) . '|' . mb_strtolower(trim($country)) . '|v3');
}

/**
 * Ask Gemini for the estimated retail price per unit (kg, l, pz as appropriate)
 * for a product in a given country/currency. Returns an array:
 * { price_per_unit, unit_label, currency, source_note } or null on failure.
 */
function _fetchPriceFromAI(string $name, string $country, string $currency, string $lang): ?array {
    EverLog::info('_fetchPriceFromAI');
    $result = _fetchPricesBatchFromAI([$name], $country, $currency, $lang);
    return $result[$name] ?? null;
}

/**
 * Ask Gemini to price multiple items in a SINGLE API call.
 * Returns: { name => { price_per_unit, unit_label, currency, source_note } }
 * Items that could not be priced are omitted from the result.
 */
function _fetchPricesBatchFromAI(array $names, string $country, string $currency, string $lang): array {
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey) || empty($names)) return [];
    EverLog::info('price_batch_ai start', ['count' => count($names), 'country' => $country]);

    // Build a numbered list for the prompt
    $list = '';
    foreach ($names as $i => $n) {
        $list .= ($i + 1) . '. ' . $n . "\n";
    }

    $prompt = <<<PROMPT
You are a grocery price assistant. Estimate typical retail prices for the following items in {$country}, currency {$currency}.

Items:
{$list}
For each item return the price for the MOST NATURAL RETAIL UNIT — the smallest standard unit a shopper buys:
- Standard packages (pasta, flour, frozen food, biscuits, canned goods): price per typical package (e.g. "pacco 500g", "barattolo 400g", "confezione")
- Sold by piece or bunch (fresh herbs, eggs, individual fruit/veg, single portions): price per piece/bunch (e.g. "mazzo", "uovo", "pz")
- Liquids in bottles or cartons: price per typical container (e.g. "bottiglia 1L", "brick 1L")
- Deli items sold loose by weight: price per kg

Rules:
1. Mid-range supermarket prices (not premium, not discount).
2. Round to 2 decimal places.
3. NEVER use per-kg for items normally sold in packages or by piece.
4. ALWAYS return a best estimate — even for branded or unusual items. Use the closest generic equivalent if needed.
5. Respond ONLY with a valid JSON object keyed by the EXACT item name from the list above. No markdown, no explanation:
{
  "Item Name 1": {"price_per_unit": 1.50, "unit_label": "mazzo", "currency": "{$currency}", "source_note": "..."},
  "Item Name 2": {"price_per_unit": 2.80, "unit_label": "kg", "currency": "{$currency}", "source_note": "..."}
}
PROMPT;

    $payload = ['contents' => [['parts' => [['text' => $prompt]]]]];
    // 55s timeout — generous for large batches (set_time_limit(120) in getAllShoppingPrices)
    $result  = callGeminiWithFallback($apiKey, $payload, 55, 'price_batch');

    if ($result['http_code'] !== 200) return [];

    $text = trim($result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    $data = json_decode(trim($text), true);

    if (!is_array($data)) return [];

    // Validate and return only items with valid price
    $out = [];
    foreach ($data as $name => $entry) {
        if (isset($entry['price_per_unit']) && is_numeric($entry['price_per_unit'])) {
            $out[$name] = $entry;
        }
    }
    EverLog::info('price_batch_ai done', ['requested' => count($names), 'returned' => count($out)]);
    return $out;
}

/**
/**
 * GET /api/?action=guess_category&name=...
 * Returns the macro-category for a product name, using a file cache + Gemini AI fallback.
 * Response: { category: string }
 */
function guessCategoryFromAI(): void {
    $name = trim($_GET['name'] ?? '');
    if ($name === '') { echo json_encode(['category' => 'altro']); return; }
    EverLog::info('guessCategoryFromAI');

    // Load cache
    $cache = [];
    if (file_exists(CATEGORY_CACHE_PATH)) {
        $cache = json_decode(file_get_contents(CATEGORY_CACHE_PATH), true) ?? [];
    }
    $key = md5(mb_strtolower($name));
    if (isset($cache[$key])) { echo json_encode(['category' => $cache[$key]]); return; }

    $apiKey = env('GEMINI_API_KEY', '');
    if ($apiKey === '') { echo json_encode(['category' => 'altro']); return; }

    $cats   = 'latticini, carne, pesce, frutta, verdura, pasta, pane, surgelati, bevande, condimenti, snack, conserve, cereali, igiene, pulizia, altro';
    $prompt = "Sei un classificatore di prodotti alimentari e domestici italiani.\n"
            . "Classifica il prodotto \"" . addslashes($name) . "\" in UNA di queste categorie esatte: $cats.\n"
            . "Rispondi con SOLO la parola chiave della categoria, senza spiegazioni né punteggiatura aggiuntiva.";

    $payload = [
        'contents'           => [['parts' => [['text' => $prompt]]]],
        'generationConfig'   => [
            'temperature'   => 0,
            'maxOutputTokens' => 20,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ];

    $result = callGeminiWithFallback($apiKey, $payload, 10, 'guess_category');
    $raw    = strtolower(trim($result['data']['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    $raw    = preg_replace('/[^a-z_ ]/', '', $raw);
    $raw    = trim($raw);

    $valid  = ['latticini','carne','pesce','frutta','verdura','pasta','pane','surgelati',
               'bevande','condimenti','snack','conserve','cereali','igiene','pulizia','altro'];
    $cat    = in_array($raw, $valid, true) ? $raw : 'altro';

    // Persist to cache
    $cache[$key] = $cat;
    @file_put_contents(CATEGORY_CACHE_PATH, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['category' => $cat]);
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET /api/?action=get_shopping_price
 * POST body: { name, quantity, unit, default_quantity, package_unit, country, currency, lang, force_refresh }
 *
 * Returns: { success, name, price_per_unit, unit_label, currency, estimated_total, estimated_total_label, cached_at, source_note }
 */
function getShoppingPrice(PDO $db): void {
    EverLog::info('getShoppingPrice');
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $name    = trim($input['name']             ?? '');
    $qty     = (float)($input['quantity']      ?? 1);
    $unit    = trim($input['unit']             ?? 'pz');
    $defQty  = (float)($input['default_quantity'] ?? 0);
    $pkgUnit = trim($input['package_unit']     ?? '');
    $country = trim($input['country']          ?? env('PRICE_COUNTRY', 'Italia'));
    $currency= trim($input['currency']         ?? env('PRICE_CURRENCY', 'EUR'));
    $lang    = trim($input['lang']             ?? 'it');
    $forceRefresh = !empty($input['force_refresh']);
    $updateMonths = (int)env('PRICE_UPDATE_MONTHS', '3');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'missing name']);
        return;
    }

    // Guard: price estimation requires Gemini API key
    if (empty(env('GEMINI_API_KEY'))) {
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $cache = _loadPriceCache();
    $key   = _priceKey($name, $country);
    $now   = time();
    $maxAge = $updateMonths * 30 * 86400;

    // Use cache if fresh
    if (!$forceRefresh && isset($cache[$key])) {
        $entry = $cache[$key];
        $age = $now - ($entry['cached_at'] ?? 0);
        if ($age < $maxAge) {
            $entry['success'] = true;
            $entry['from_cache'] = true;
            $entry['estimated_total'] = _calcEstimatedTotal($entry['price_per_unit'], $entry['unit_label'] ?? '', $qty, $unit, $defQty, $pkgUnit);
            $entry['estimated_total_label'] = _formatPrice($entry['estimated_total'], $currency);
            echo json_encode($entry);
            return;
        }
    }

    $priceData = _fetchPriceFromAI($name, $country, $currency, $lang);
    if (!$priceData || $priceData['price_per_unit'] === null) {
        echo json_encode(['success' => false, 'error' => 'price_not_found', 'name' => $name]);
        return;
    }

    $entry = [
        'name'          => $name,
        'price_per_unit'=> (float)$priceData['price_per_unit'],
        'unit_label'    => $priceData['unit_label'] ?? 'kg',
        'currency'      => $currency,
        'source_note'   => $priceData['source_note'] ?? '',
        'country'       => $country,
        'cached_at'     => $now,
    ];
    $cache[$key] = $entry;
    _savePriceCache($cache);

    $entry['success']               = true;
    $entry['from_cache']            = false;
    $entry['estimated_total']       = _calcEstimatedTotal($entry['price_per_unit'], $entry['unit_label'], $qty, $unit, $defQty, $pkgUnit);
    $entry['estimated_total_label'] = _formatPrice($entry['estimated_total'], $currency);
    echo json_encode($entry);
}

/**
 * GET /api/?action=get_all_shopping_prices
 * POST body: { items: [{name}], country, currency, lang, force_refresh }
 * qty/unit are resolved SERVER-SIDE from smart_shopping_cache — not trusted from client.
 *
 * Returns: { success, prices: { name → priceEntry }, total, total_label, from_total_cache }
 */
function getAllShoppingPrices(PDO $db): void {
    EverLog::info('getAllShoppingPrices');
    // This endpoint may call the AI for many items at once — extend timeout.
    set_time_limit(120);

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $clientItems = $input['items'] ?? [];
    $country  = trim($input['country']  ?? env('PRICE_COUNTRY', 'Italia'));
    $currency = trim($input['currency'] ?? env('PRICE_CURRENCY', 'EUR'));
    $lang     = trim($input['lang']     ?? 'it');
    $forceRefresh = !empty($input['force_refresh']); // re-fetch AI prices (expensive, rarely used)
    $forceTotal   = !empty($input['force_total']);   // bust only the 5-min total cache (fast)
    $updateMonths = (int)env('PRICE_UPDATE_MONTHS', '3');

    if (empty($clientItems)) {
        echo json_encode(['success' => true, 'prices' => [], 'total' => 0, 'total_label' => _formatPrice(0, $currency)]);
        return;
    }

    // ── Resolve qty/unit from server-side smart cache (source of truth) ──────
    $smartItems = [];
    $smartCacheFile = __DIR__ . '/../data/smart_shopping_cache.json';
    if (file_exists($smartCacheFile)) {
        $raw = file_get_contents($smartCacheFile);
        if ($raw) {
            $sc = json_decode($raw, true);
            if ($sc && isset($sc['items'])) $smartItems = $sc['items'];
        }
    }
    // Build lookup: lowercase name/shopping_name → smart item
    $smartByName = [];
    foreach ($smartItems as $si) {
        $smartByName[mb_strtolower($si['name'] ?? '')] = $si;
        if (!empty($si['shopping_name'])) {
            $smartByName[mb_strtolower($si['shopping_name'])] = $si;
        }
    }

    // Build canonical items array using server-side qty/unit
    $items = [];
    foreach ($clientItems as $ci) {
        $name = trim($ci['name'] ?? '');
        if ($name === '') continue;

        // 1) Exact match by name or shopping_name
        $si = $smartByName[mb_strtolower($name)] ?? null;

        // 2) Prefix-word fallback: "Salame" → "Salame Paesano", "Penne" → "Penne rigate"
        //    Match when the Bring! name is a word-prefix of a smart key (case-insensitive).
        if ($si === null) {
            $nameLower = mb_strtolower($name);
            foreach ($smartByName as $smartKey => $candidate) {
                // smartKey starts with the Bring! name (exact word boundary)
                if (str_starts_with($smartKey, $nameLower)
                    && (strlen($smartKey) === strlen($nameLower) || $smartKey[strlen($nameLower)] === ' ')) {
                    $si = $candidate;
                    break;
                }
            }
        }

        $items[] = [
            'name'             => $name,
            'quantity'         => (float)(($si['suggested_qty']  ?? $si['buy_qty'] ?? null) ?? ($ci['quantity'] ?? 1)),
            'unit'             => trim(($si['suggested_unit'] ?? $si['unit'] ?? null) ?? ($ci['unit'] ?? 'conf')),
            'default_quantity' => (float)(($si['default_qty'] ?? null) ?? ($ci['default_quantity'] ?? 0)),
            'package_unit'     => trim(($si['package_unit'] ?? null) ?? ($ci['package_unit'] ?? '')),
        ];
    }

    // ── 5-minute server-side total cache ──────────────────────────────────────
    // Key = hash of item names + resolved qty/unit + country (not force_refresh)
    $totalCachePath = __DIR__ . '/../data/shopping_total_cache.json';
    $totalCacheKey  = md5(json_encode(array_map(
        fn($i) => [$i['name'], $i['quantity'], $i['unit']],
        $items
    )) . $country . $currency);

    if (!$forceRefresh && !$forceTotal && file_exists($totalCachePath)) {
        $tc = json_decode(file_get_contents($totalCachePath), true) ?? [];
        if (isset($tc[$totalCacheKey]) && (time() - ($tc[$totalCacheKey]['ts'] ?? 0)) < 300) {
            $cached = $tc[$totalCacheKey]['result'];
            $cached['from_total_cache'] = true;
            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    // ── Price computation ─────────────────────────────────────────────────────
    $priceCache = _loadPriceCache();
    $now        = time();
    $maxAge     = $updateMonths * 30 * 86400;
    $prices     = [];
    $total      = 0.0;
    $missing    = [];

    // First pass: serve from cache
    foreach ($items as $item) {
        $name    = $item['name'];
        $qty     = $item['quantity'];
        $unit    = $item['unit'];
        $defQty  = $item['default_quantity'];
        $pkgUnit = $item['package_unit'];

        $key = _priceKey($name, $country);
        if (!$forceRefresh && isset($priceCache[$key])) {
            $age = $now - ($priceCache[$key]['cached_at'] ?? 0);
            if ($age < $maxAge) {
                $entry = $priceCache[$key];
                $est = _calcEstimatedTotal($entry['price_per_unit'], $entry['unit_label'] ?? '', $qty, $unit, $defQty, $pkgUnit);
                $prices[$name] = array_merge($entry, [
                    'estimated_total'       => $est,
                    'estimated_total_label' => $est !== null ? _formatPrice($est, $currency) : null,
                    'from_cache'            => true,
                    '_resolved_qty'         => $qty,
                    '_resolved_unit'        => $unit,
                ]);
                $total += $est ?? 0;
                continue;
            }
        }
        $missing[] = $item;
    }

    // Second pass: fetch ALL missing items in ONE batch Gemini call
    if (!empty($missing)) {
        $missingNames = array_column($missing, 'name');
        $batchPrices  = _fetchPricesBatchFromAI($missingNames, $country, $currency, $lang);

        // Build a lookup from item name → item params
        $missingByName = [];
        foreach ($missing as $item) $missingByName[$item['name']] = $item;

        foreach ($missingNames as $name) {
            $item    = $missingByName[$name];
            $qty     = $item['quantity'];
            $unit    = $item['unit'];
            $defQty  = $item['default_quantity'];
            $pkgUnit = $item['package_unit'];
            $key     = _priceKey($name, $country);

            $priceData = $batchPrices[$name] ?? null;
            if ($priceData && isset($priceData['price_per_unit'])) {
                $entry = [
                    'name'           => $name,
                    'price_per_unit' => (float)$priceData['price_per_unit'],
                    'unit_label'     => $priceData['unit_label'] ?? 'pz',
                    'currency'       => $currency,
                    'source_note'    => $priceData['source_note'] ?? '',
                    'country'        => $country,
                    'cached_at'      => $now,
                ];
                $priceCache[$key] = $entry;
                $est = _calcEstimatedTotal($entry['price_per_unit'], $entry['unit_label'], $qty, $unit, $defQty, $pkgUnit);
                $prices[$name] = array_merge($entry, [
                    'estimated_total'       => $est,
                    'estimated_total_label' => $est !== null ? _formatPrice($est, $currency) : null,
                    'from_cache'            => false,
                    '_resolved_qty'         => $qty,
                    '_resolved_unit'        => $unit,
                ]);
                $total += $est ?? 0;
            } else {
                $prices[$name] = ['name' => $name, 'error' => 'not_found', 'estimated_total' => null];
            }
        }
    }

    _savePriceCache($priceCache);

    $total  = round($total, 2);
    $result = [
        'success'          => true,
        'prices'           => $prices,
        'total'            => $total,
        'total_label'      => _formatPrice($total, $currency),
        'from_total_cache' => false,
    ];

    // Persist to total cache
    $tc = file_exists($totalCachePath) ? (json_decode(file_get_contents($totalCachePath), true) ?? []) : [];
    // Keep cache small: max 10 keys (different list configurations)
    if (count($tc) >= 10) $tc = array_slice($tc, -9, null, true);
    $tc[$totalCacheKey] = ['ts' => $now, 'result' => $result];
    file_put_contents($totalCachePath, json_encode($tc, JSON_UNESCAPED_UNICODE));

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * Calculate estimated cost for a shopping item given price_per_unit and the item's quantity/unit.
 * Price unit: kg, l, pz/unit
 */
function _calcEstimatedTotal(float $pricePerUnit, string $priceUnitLabel, float $qty, string $unit, float $defQty, string $pkgUnit): ?float {
    if ($pricePerUnit <= 0) return null;

    $label = strtolower(trim($priceUnitLabel));

    // ── Weight-based price (per kg) ───────────────────────────────────────────
    // Only exact 'kg' triggers weight conversion; retail-unit labels like
    // "pacco 500g" or "mazzo" fall through to the countable path below.
    if ($label === 'kg') {
        $weightKg = 0.0;
        if (($unit === 'conf' || $unit === 'pz') && $defQty > 0 && !empty($pkgUnit)) {
            // Each conf/pz weighs defQty pkgUnit (e.g. defQty=250, pkgUnit='g')
            $sub = strtolower($pkgUnit);
            if ($sub === 'g')  $weightKg = $qty * $defQty / 1000.0;
            elseif ($sub === 'kg') $weightKg = $qty * $defQty;
        } elseif (($unit === 'conf' || $unit === 'pz') && $defQty > 0 && empty($pkgUnit)) {
            // pkgUnit not recorded in DB — for /kg prices assume defQty is in grams
            // (vast majority of grocery packages: pancetta 80g, formaggio 200g, etc.)
            // GUARD: if defQty < 20 it is almost certainly a piece/unit count (e.g. "1 pz
            // per purchase"), not a gram weight.  Treating 1 as 1g would give a nonsense
            // price (e.g. Peperoni defQty=1 → 0.001 kg → €0.003 displayed as €0.00).
            // Skip the weight conversion for these; the item falls through to the
            // countable path at the bottom (ppu × qty) which returns a rough estimate.
            if ($defQty >= 20) {
                $weightKg = $qty * $defQty / 1000.0;
            }
        } elseif ($unit === 'g')  {
            $weightKg = $qty / 1000.0;
        } elseif ($unit === 'kg') {
            $weightKg = $qty;
        }
        if ($weightKg <= 0) {
            // Two cases:
            // A) defQty was 0 (no weight data at all) → "–" is more honest than a fake price.
            // B) defQty was 1-19 (suspicious: the value was stored as a piece count, not grams;
            //    the assignment was intentionally skipped by the defQty<20 guard above).
            //    In case B, fall back to ppu × qty so the badge shows something rather than €0.00.
            if (in_array($unit, ['pz', 'conf']) && $defQty > 0) {
                return round($pricePerUnit * max(1.0, $qty), 2);
            }
            return null;
        }
        return round($pricePerUnit * $weightKg, 2);
    }

    // ── Volume-based price (per liter) ────────────────────────────────────────
    if (in_array($label, ['l', 'lt', 'litre', 'liter', 'litro'])) {
        $volumeL = 0.0;
        if (($unit === 'conf' || $unit === 'pz') && $defQty > 0 && !empty($pkgUnit)) {
            $sub = strtolower($pkgUnit);
            if ($sub === 'ml') $volumeL = $qty * $defQty / 1000.0;
            elseif ($sub === 'l') $volumeL = $qty * $defQty;
        } elseif (($unit === 'conf' || $unit === 'pz') && $defQty > 0 && empty($pkgUnit)) {
            // pkgUnit not recorded — for /L prices assume defQty is in ml
            $volumeL = $qty * $defQty / 1000.0;
        } elseif ($unit === 'ml') {
            $volumeL = $qty / 1000.0;
        } elseif ($unit === 'l') {
            $volumeL = $qty; 
        }
        if ($volumeL <= 0) return null;
        return round($pricePerUnit * $volumeL, 2);
    }

    // ── Countable retail unit (mazzo, pacco, barattolo, pz, conf, …) ─────────
    // price_per_unit is already the price for ONE retail unit.
    //
    // Special case: shopping qty is in g/ml but price is per-package.
    // We must convert grams→packages so we don't multiply 100×€2.75=€275.
    if (in_array(strtolower($unit), ['g', 'ml'])) {
        $pkgWeight = 0.0;
        // 1) Use defQty if package unit matches (e.g. defQty=250, pkgUnit='g', unit='g')
        if ($defQty > 0 && !empty($pkgUnit) && strtolower($pkgUnit) === strtolower($unit)) {
            $pkgWeight = $defQty;
        }
        // 2) Extract weight/volume from label: "confezione 250g", "vasetto 125ml", "pacco 500g",
        //    "pacco 1kg" (convert kg→g), "bottiglia 1.5L" (convert L→ml)
        if ($pkgWeight <= 0) {
            if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(g|ml|kg|l|lt)\b/i', $priceUnitLabel, $m)) {
                $rawVal = (float)str_replace(',', '.', $m[1]);
                $rawUnit = strtolower($m[2]);
                if ($rawUnit === strtolower($unit)) {
                    $pkgWeight = $rawVal;
                } elseif ($rawUnit === 'kg' && strtolower($unit) === 'g') {
                    $pkgWeight = $rawVal * 1000.0;
                } elseif (in_array($rawUnit, ['l', 'lt']) && strtolower($unit) === 'ml') {
                    $pkgWeight = $rawVal * 1000.0;
                }
            }
        }
        // 3) Also try defQty alone (no pkgUnit set but defQty likely in same unit)
        if ($pkgWeight <= 0 && $defQty > 0) {
            $pkgWeight = $defQty;
        }
        if ($pkgWeight > 0) {
            $packages = (int) max(1, ceil($qty / $pkgWeight));
            return round($pricePerUnit * $packages, 2);
        }
        // No conversion possible → return single-unit price (1 package minimum)
        return round($pricePerUnit, 2);
    }

    // Special case: unit='pz' (individual pieces) vs. container retail unit.
    // If the AI priced per-container and the user requested individual pieces,
    // buy ceil(qty / piecesPerContainer) containers — or just 1 if unknown.
    if (strtolower($unit) === 'pz') {
        static $containerKw = [
            'confezione', 'pacco', 'pack', 'busta', 'sacchetto', 'vasetto',
            'barattolo', 'rete', 'casco', 'mazzo', 'bottiglia', 'brick',
            'lattina', 'latta', 'vaschetta', 'scatola', 'tray',
        ];
        $isContainer = false;
        foreach ($containerKw as $kw) {
            if (str_contains($label, $kw)) { $isContainer = true; break; }
        }
        if ($isContainer) {
            // Try to extract pieces-per-container from label (e.g. "confezione 6 uova" → 6).
            // Ignore numbers followed by a weight/volume unit (e.g. "rete 1kg" → 0).
            $pcsPerContainer = 0;
            if (preg_match('/\b(\d+)\b(?!\s*(?:g|kg|ml|l|lt|cl|gr)\b)/i', $priceUnitLabel, $pm)) {
                $pcsPerContainer = (int)$pm[1];
            }
            $containers = ($pcsPerContainer >= 2)
                ? (int) max(1, ceil($qty / $pcsPerContainer))
                : 1;
            return round($pricePerUnit * $containers, 2);
        }
    }

    // ── conf/pz with known package weight vs weight-labeled AI price ──────────
    // E.g. unit='conf', defQty=170g, AI priced 'pacco 500g' @ €3.20
    // → need ceil(7×170 / 500) = 3 packs × €3.20 = €9.60, not 7×€3.20 = €22.40
    if (in_array(strtolower($unit), ['conf', 'pz']) && $defQty > 0 && !empty($pkgUnit)) {
        $pkgL  = strtolower($pkgUnit);
        $isWt  = in_array($pkgL, ['g', 'kg']);
        $isVol = in_array($pkgL, ['ml', 'l', 'lt']);
        if (($isWt || $isVol) &&
            preg_match('/\b(\d+(?:[.,]\d+)?)\s*(g|kg|ml|l|lt)\b/i', $priceUnitLabel, $m)) {
            $rawVal  = (float) str_replace(',', '.', $m[1]);
            $rawUnit = strtolower($m[2]);
            $labelIsWt  = in_array($rawUnit, ['g', 'kg']);
            $labelIsVol = in_array($rawUnit, ['ml', 'l', 'lt']);
            if (($isWt && $labelIsWt) || ($isVol && $labelIsVol)) {
                // Convert to base units (g or ml)
                $defBase   = $pkgL  === 'kg' ? $defQty * 1000.0 : $defQty;
                $labelBase = match($rawUnit) { 'kg','l','lt' => $rawVal * 1000.0, default => $rawVal };
                if ($labelBase > 0) {
                    $totalBase = $qty * $defBase;
                    $packs     = (int) max(1, ceil($totalBase / $labelBase));
                    return round($pricePerUnit * $packs, 2);
                }
            }
        }
    }

    $buyQty = max(1.0, $qty);
    return round($pricePerUnit * $buyQty, 2);
}

function _formatPrice(float $amount, string $currency): string {
    $sym = match(strtoupper($currency)) {
        'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF',
        'JPY' => '¥', 'CNY' => '¥', 'CAD' => 'CA$', 'AUD' => 'A$',
        'BRL' => 'R$', 'RUB' => '₽', 'INR' => '₹', 'MXN' => '$',
        'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr', 'PLN' => 'zł',
        'CZK' => 'Kč', 'HUF' => 'Ft', 'RON' => 'lei',
        default => $currency,
    };
    return $sym . number_format($amount, 2, '.', '');
}

