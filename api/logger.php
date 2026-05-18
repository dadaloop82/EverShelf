<?php
/**
 * EverShelf Logger — rotating file logger with 4 configurable levels.
 *
 * Levels (in order of verbosity):
 *   DEBUG(0) — ogni minima operazione: query, cache, AI payload, function entry/exit
 *   INFO (1) — azioni completate, AI result summary, sync status             [default]
 *   WARN (2) — rate limit, cache miss, AI fallback, token renewal, slow op
 *   ERROR(3) — DB failure, AI API error, file write error, exception
 *
 * Config via .env (all optional):
 *   LOG_LEVEL        = INFO    (DEBUG|INFO|WARN|ERROR)
 *   LOG_ROTATE_HOURS = 24      (new file every N hours; 1–168; default 24)
 *   LOG_MAX_FILES    = 14      (max rotated files to keep; default 14)
 *
 * Log files: logs/evershelf_YYYY-MM-DD_HH.log
 * Each line:  [2026-05-18 14:23:11] [INFO ] [rid=a1b2c3d4] [action] Message {ctx}
 */
class EverLog {

    // ── Level constants ────────────────────────────────────────────────────
    const DEBUG = 0;
    const INFO  = 1;
    const WARN  = 2;
    const ERROR = 3;

    private static bool   $initialized  = false;
    private static int    $level        = self::INFO;
    private static string $logFile      = '';
    private static string $logDir       = '';
    private static int    $rotateHours  = 24;
    private static int    $maxFiles     = 14;
    private static string $requestId    = '';
    private static string $currentAction = '-';

    // ── Init (called lazily on first write) ────────────────────────────────
    private static function init(): void {
        if (self::$initialized) return;
        self::$initialized = true;

        // Read .env values via getenv() (populated by Apache SetEnv or putenv() in index.php)
        $envLevel    = strtoupper((string)(getenv('LOG_LEVEL')        ?: 'INFO'));
        $rotateHours = max(1, min(168, (int)(getenv('LOG_ROTATE_HOURS') ?: 24)));
        $maxFiles    = max(1, min(365, (int)(getenv('LOG_MAX_FILES')    ?: 14)));

        self::$level       = match($envLevel) {
            'DEBUG' => self::DEBUG,
            'WARN'  => self::WARN,
            'ERROR' => self::ERROR,
            default => self::INFO,
        };
        self::$rotateHours = $rotateHours;
        self::$maxFiles    = $maxFiles;
        self::$requestId   = substr(bin2hex(random_bytes(4)), 0, 8);

        // Ensure log directory exists
        $base         = dirname(__DIR__) . '/logs';
        self::$logDir = $base;
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }

        // Compute current log file path (slot by rotate-hours bucket)
        $slotTs        = (int)(floor(time() / ($rotateHours * 3600)) * ($rotateHours * 3600));
        $slotLabel     = gmdate('Y-m-d_H', $slotTs);
        self::$logFile = "$base/evershelf_{$slotLabel}.log";

        // Rotate (delete oldest files beyond max)
        self::rotate();
    }

    // ── Rotate old log files ───────────────────────────────────────────────
    private static function rotate(): void {
        $files = glob(self::$logDir . '/evershelf_*.log') ?: [];
        if (count($files) <= self::$maxFiles) return;
        sort($files); // oldest first (filenames are lexicographically sortable by date)
        $toDelete = array_slice($files, 0, count($files) - self::$maxFiles);
        foreach ($toDelete as $f) {
            @unlink($f);
        }
    }

    // ── Core write ────────────────────────────────────────────────────────
    private static function write(int $lvl, string $msg, array $ctx, string $action): void {
        self::init();
        if ($lvl < self::$level) return;

        $labels = ['DEBUG', 'INFO ', 'WARN ', 'ERROR'];
        $ts     = gmdate('Y-m-d H:i:s');
        $act    = $action !== '-' ? $action : self::$currentAction;
        $ctxStr = empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line   = "[{$ts}] [{$labels[$lvl]}] [rid=" . self::$requestId . "] [{$act}] {$msg}{$ctxStr}\n";

        @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }

    // ── Public API ────────────────────────────────────────────────────────

    /** Set the current action name (shown in every subsequent log line for this request). */
    public static function setAction(string $action): void {
        self::$currentAction = $action;
    }

    /** Log at DEBUG level — every minor operation, query, cache hit/miss, AI payload. */
    public static function debug(string $msg, array $ctx = [], string $action = '-'): void {
        self::write(self::DEBUG, $msg, $ctx, $action);
    }

    /** Log at INFO level — action completed, recipe generated, sync done. */
    public static function info(string $msg, array $ctx = [], string $action = '-'): void {
        self::write(self::INFO, $msg, $ctx, $action);
    }

    /** Log at WARN level — rate limit, AI fallback, slow op, token renewal. */
    public static function warn(string $msg, array $ctx = [], string $action = '-'): void {
        self::write(self::WARN, $msg, $ctx, $action);
    }

    /** Log at ERROR level — DB failure, AI API error, file write error, exception. */
    public static function error(string $msg, array $ctx = [], string $action = '-'): void {
        self::write(self::ERROR, $msg, $ctx, $action);
    }

    /** Convenience: log a Throwable at ERROR level with class + location. */
    public static function exception(\Throwable $e, string $action = '-', array $extra = []): void {
        self::write(self::ERROR, $e->getMessage(), array_merge([
            'class' => get_class($e),
            'at'    => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => substr($e->getTraceAsString(), 0, 800),
        ], $extra), $action);
    }

    /**
     * Log the start of an action request (INFO).
     * Automatically sets the current action name so subsequent lines inherit it.
     */
    public static function request(string $action, string $method, array $params = []): void {
        self::setAction($action);
        // At DEBUG: include all params; at INFO just the action+method
        if (self::$level <= self::DEBUG) {
            self::write(self::DEBUG, "→ {$method} /{$action}", $params, $action);
        } else {
            self::write(self::INFO, "→ {$method} /{$action}", [], $action);
        }
    }

    /**
     * Log a DB query at DEBUG level.
     * @param string $sql      Truncated SQL or a descriptive label
     * @param mixed  $result   Number of rows affected/returned (optional)
     * @param float  $elapsed  Execution time in seconds (optional)
     */
    public static function query(string $sql, $result = null, float $elapsed = 0.0): void {
        if (self::$level > self::DEBUG) return; // skip entirely unless DEBUG
        $ctx = [];
        if ($result !== null) $ctx['rows'] = $result;
        if ($elapsed > 0)     $ctx['ms']   = round($elapsed * 1000, 1);
        if ($elapsed > 1.0)   $ctx['SLOW'] = true; // highlight slow queries even in context
        self::write(self::DEBUG, 'DB: ' . substr($sql, 0, 200), $ctx, self::$currentAction);
    }

    /**
     * Log a slow operation as WARN regardless of configured level.
     * Call this after any operation that took more than $thresholdSec.
     */
    public static function slowOp(string $label, float $elapsed, float $thresholdSec = 2.0): void {
        if ($elapsed < $thresholdSec) return;
        self::write(self::WARN, "SLOW_OP: {$label}", ['elapsed_s' => round($elapsed, 2)], self::$currentAction);
    }

    /**
     * Log an AI call at INFO level (or DEBUG for full payload).
     * @param string $model      Model name (e.g. 'gemini-2.5-flash')
     * @param int    $promptLen  Character length of the prompt
     * @param bool   $isFallback Whether this is the fallback model
     */
    public static function aiCall(string $model, int $promptLen, bool $isFallback = false): void {
        $ctx = ['model' => $model, 'prompt_chars' => $promptLen];
        if ($isFallback) $ctx['fallback'] = true;
        $level = $isFallback ? self::WARN : self::INFO;
        self::write($level, 'AI call', $ctx, self::$currentAction);
    }

    /**
     * Log an AI response at INFO level.
     * @param string $model       Model that responded
     * @param int    $outputLen   Character length of output
     * @param float  $elapsed     Call duration in seconds
     * @param bool   $ok          Whether the call succeeded
     * @param string $errorMsg    Error message if not ok
     */
    public static function aiResponse(string $model, int $outputLen, float $elapsed, bool $ok = true, string $errorMsg = ''): void {
        $ctx = ['model' => $model, 'output_chars' => $outputLen, 'elapsed_s' => round($elapsed, 2)];
        if (!$ok) {
            $ctx['error'] = substr($errorMsg, 0, 200);
            self::write(self::ERROR, 'AI error', $ctx, self::$currentAction);
        } else {
            self::write(self::INFO, 'AI ok', $ctx, self::$currentAction);
        }
        // Warn if over 10s
        if ($ok && $elapsed > 10.0) {
            self::write(self::WARN, 'AI response slow', ['elapsed_s' => round($elapsed, 2)], self::$currentAction);
        }
    }

    /**
     * Log a cache event at DEBUG level.
     * @param string $cacheKey  The cache key (or a label)
     * @param bool   $hit       true = cache hit, false = cache miss
     * @param string $cacheType 'file', 'session', 'memory'
     */
    public static function cache(string $cacheKey, bool $hit, string $cacheType = 'file'): void {
        if (self::$level > self::DEBUG) return;
        self::write(self::DEBUG,
            ($hit ? 'CACHE HIT' : 'CACHE MISS') . " [{$cacheType}]",
            ['key' => substr($cacheKey, 0, 64)],
            self::$currentAction
        );
    }

    /**
     * Return the last $lines log lines from all available log files, newest last.
     * Used by the get_logs API endpoint.
     */
    public static function tail(int $lines = 500): array {
        self::init();
        $files = glob(self::$logDir . '/evershelf_*.log') ?: [];
        if (empty($files)) return [];
        rsort($files); // newest file first

        $collected = [];
        foreach ($files as $f) {
            if (count($collected) >= $lines) break;
            $content = @file_get_contents($f);
            if ($content === false) continue;
            $fLines = array_filter(explode("\n", $content));
            // Prepend so we read newest-first → older lines at front
            $collected = array_merge(array_values($fLines), $collected);
        }
        // Return last $lines, newest at end (chronological order)
        return array_values(array_slice($collected, -$lines));
    }

    /** List available log files with their sizes and date ranges. */
    public static function listFiles(): array {
        self::init();
        $files = glob(self::$logDir . '/evershelf_*.log') ?: [];
        rsort($files);
        return array_map(fn($f) => [
            'file'    => basename($f),
            'size_kb' => round(filesize($f) / 1024, 1),
            'mtime'   => date('Y-m-d H:i:s', filemtime($f)),
        ], $files);
    }

    /** Current effective level name. */
    public static function levelName(): string {
        self::init();
        return ['DEBUG', 'INFO', 'WARN', 'ERROR'][self::$level];
    }

    /** Current log file path. */
    public static function currentFile(): string {
        self::init();
        return self::$logFile;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// LoggingPDOStatement — wraps PDOStatement to time and log every execute()
// ═══════════════════════════════════════════════════════════════════════════
class LoggingPDOStatement {
    private \PDOStatement $stmt;
    private string        $sql;

    public function __construct(\PDOStatement $stmt, string $sql) {
        $this->stmt = $stmt;
        $this->sql  = $sql;
    }

    public function execute(?array $params = null): bool {
        $t0  = microtime(true);
        $ok  = $this->stmt->execute($params);
        $ms  = round((microtime(true) - $t0) * 1000, 2);
        $ctx = ['ms' => $ms, 'rows' => $this->stmt->rowCount()];
        if ($ms > 500) $ctx['SLOW'] = true;
        EverLog::query($this->sql, $this->stmt->rowCount(), (microtime(true) - $t0));
        return $ok;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, ...$args): mixed {
        return $this->stmt->fetch($mode, ...$args);
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array {
        return $this->stmt->fetchAll($mode ?: \PDO::FETCH_ASSOC);
    }

    public function fetchColumn(int $col = 0): mixed {
        return $this->stmt->fetchColumn($col);
    }

    public function rowCount(): int {
        return $this->stmt->rowCount();
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool {
        return $this->stmt->bindValue($param, $value, $type);
    }

    public function bindParam(int|string $param, mixed &$var, int $type = \PDO::PARAM_STR, int $maxLength = 0): bool {
        return $this->stmt->bindParam($param, $var, $type, $maxLength);
    }

    public function closeCursor(): bool {
        return $this->stmt->closeCursor();
    }

    public function setFetchMode(int $mode, mixed ...$args): bool {
        return $this->stmt->setFetchMode($mode, ...$args);
    }

    public function __get(string $name): mixed {
        return $this->stmt->$name;
    }

    public function __call(string $name, array $args): mixed {
        return $this->stmt->$name(...$args);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// LoggingPDO — wraps PDO to auto-log all prepare(), query(), exec()
// Drop-in replacement: return LoggingPDO from getDB() instead of PDO.
// Type hint: use PDO in all functions (LoggingPDO extends PDO).
// ═══════════════════════════════════════════════════════════════════════════
class LoggingPDO extends \PDO {
    public function prepare(string $query, array $options = []): LoggingPDOStatement|false {
        $stmt = parent::prepare($query, $options);
        if ($stmt === false) {
            EverLog::error('PDO::prepare failed', ['sql' => substr($query, 0, 200)]);
            return false;
        }
        return new LoggingPDOStatement($stmt, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
        $t0   = microtime(true);
        $stmt = $fetchMode !== null
            ? parent::query($query, $fetchMode, ...$fetchModeArgs)
            : parent::query($query);
        $elapsed = microtime(true) - $t0;
        if ($stmt !== false) {
            EverLog::query($query, $stmt->rowCount(), $elapsed);
        } else {
            EverLog::error('PDO::query failed', ['sql' => substr($query, 0, 200)]);
        }
        return $stmt;
    }

    public function exec(string $statement): int|false {
        // Skip WAL/PRAGMA logging below DEBUG (too noisy at startup)
        $isPragma = stripos(ltrim($statement), 'PRAGMA') === 0;
        $t0       = microtime(true);
        $result   = parent::exec($statement);
        $elapsed  = microtime(true) - $t0;
        if (!$isPragma) {
            EverLog::query($statement, $result === false ? 0 : $result, $elapsed);
        } elseif (EverLog::DEBUG >= 0) {
            // Log PRAGMAs only at DEBUG level
            EverLog::query($statement, is_int($result) ? $result : 0, $elapsed);
        }
        return $result;
    }
}
