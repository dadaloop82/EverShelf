#!/usr/bin/env php
<?php
/**
 * Regression tests: a branch of api/index.php may only call a function that is
 * already defined when that branch runs.
 *
 * Run: php scripts/test-api-hoisting.php
 *
 * Issue #246 was 61 automatic reports of
 * `[Error] Call to undefined function checkRateLimit()` coming from the `ping`
 * and `health_check` branches of api/index.php. Those branches answer before the
 * main dispatcher, so they can only use functions PHP has already hoisted: the
 * ones declared at the top level of the file, or in a lib bootstrap.php loads
 * first. A function declared *inside a block* (`if`, `foreach`, …) only exists
 * once that block has run, so calling it earlier is a fatal error that takes the
 * whole API down — every action, not just the one being edited.
 *
 * The scanner is self-tested at the bottom, so this file can never pass just
 * because it stopped looking.
 */
$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

/**
 * Named function declarations and call sites of a PHP source file.
 *
 * @return array{decls: array<string, array{line: int, depth: int}>, calls: array<int, array{name: string, line: int}>}
 */
function scan_functions(string $src): array
{
    $tokens = token_get_all($src);
    $count  = count($tokens);
    $decls  = [];
    $calls  = [];
    $depth  = 0;
    $prev   = '';      // last significant token
    $expect = false;   // the next T_STRING is the name of the function being declared

    $next = static function (int $i) use ($tokens, $count): ?array {
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($t) ? [$t[0], $t[1]] : [null, $t];
        }
        return null;
    };

    foreach ($tokens as $i => $token) {
        if (!is_array($token)) {                       // single characters: braces, (), ->, …
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
            if (trim($token) !== '') {
                $prev   = $token;
                $expect = false;
            }
            continue;
        }

        [$id, $text, $line] = $token;
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($id === T_FUNCTION) {
            $expect = true;
            $prev   = 'function';
            continue;
        }
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            // "{$var}" / "${var}" inside a string or heredoc: the opening brace is
            // its own token, the closing one is a plain '}', so count it here or
            // the depth drifts negative for the rest of the file.
            $depth++;
            $expect = false;
            $prev   = '{';
            continue;
        }
        if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
            if ($expect && $id === T_STRING && $prev !== 'use') {
                $decls[$text] = ['line' => $line, 'depth' => $depth];
                $expect       = false;
                $prev         = 'decl';
                continue;
            }
            $expect = false;
            $after  = $next($i);
            $isCall = $after !== null && $after[1] === '('
                && !in_array($prev, ['->', '?->', '::', 'new', 'function', 'namespace', '\\'], true);
            if ($isCall) {
                $calls[] = ['name' => $text, 'line' => $line];
            }
            $prev = $text;
            continue;
        }
        $expect = false;
        $prev   = $text;
    }

    return ['decls' => $decls, 'calls' => $calls];
}

/** Functions declared in the files bootstrap.php loads before index.php routes anything. */
function bootstrap_functions(): array
{
    $api   = dirname(__DIR__) . '/api';
    $files = [
        $api . '/bootstrap.php',
        $api . '/logger.php',
        $api . '/database.php',
    ];
    foreach (glob($api . '/lib/*.php') ?: [] as $file) {
        $files[] = $file;
    }

    $names = [];
    foreach (array_unique($files) as $file) {
        $src = (string)file_get_contents($file);
        if (preg_match_all('/^\s*function\s+([A-Za-z_]\w*)\s*\(/m', $src, $m)) {
            foreach ($m[1] as $fn) {
                $names[$fn] = true;
            }
        }
    }
    return $names;
}

$indexFile = dirname(__DIR__) . '/api/index.php';
$index     = (string)file_get_contents($indexFile);
$scan      = scan_functions($index);
assert_true(count($scan['decls']) > 300, 'the scanner sees the functions of api/index.php');
assert_true(count($scan['calls']) > 1000, 'the scanner sees the call sites of api/index.php');

// ── No function is declared inside a block (the issue #246 shape) ──────────
$nested = array_filter($scan['decls'], static fn(array $d): bool => $d['depth'] > 0);
foreach ($nested as $name => $d) {
    assert_true(false, "{$name}() is declared inside a block at line {$d['line']}: PHP will not hoist it");
}
assert_true($nested === [], 'no function of api/index.php is declared inside a block');

// ── The rate limiter specifically: top level, before its first call ────────
assert_true(isset($scan['decls']['checkRateLimit']), 'checkRateLimit() is declared in api/index.php');
assert_true(($scan['decls']['checkRateLimit']['depth'] ?? 1) === 0, 'checkRateLimit() is declared at the top level');
$firstCall = PHP_INT_MAX;
foreach ($scan['calls'] as $call) {
    if ($call['name'] === 'checkRateLimit') {
        $firstCall = min($firstCall, $call['line']);
    }
}
assert_true($firstCall < PHP_INT_MAX, 'checkRateLimit() is called (guard against the check going stale)');
assert_true($scan['decls']['checkRateLimit']['line'] < $firstCall, 'checkRateLimit() is declared before its first call');

// ── Every call that runs before the dispatcher must resolve ────────────────
// The early-exit branches (ping, app_bootstrap, health_check, …) answer before
// the switch, so a typo or a function declared further down inside a block would
// be a fatal on the very first request.
$dispatcherLine = null;
if (preg_match('/^\s*switch\s*\(\$action\)/m', $index, $m, PREG_OFFSET_CAPTURE)) {
    $dispatcherLine = substr_count(substr($index, 0, $m[0][1]), "\n") + 1;
}
assert_true($dispatcherLine !== null, 'the dispatcher switch ($action) was found');

$bootstrap = bootstrap_functions();
$early     = array_filter($scan['calls'], static fn(array $c): bool => $c['line'] < $dispatcherLine);
assert_true(count($early) > 50, 'the early-exit branches do call functions');

$unresolved = [];
foreach ($early as $call) {
    $name = $call['name'];
    if (function_exists($name)) {
        continue;                                    // PHP built-in / loaded extension
    }
    if (isset($bootstrap[$name])) {
        continue;                                    // lib loaded by bootstrap.php
    }
    if (isset($scan['decls'][$name])) {
        if ($scan['decls'][$name]['depth'] === 0) {
            continue;                                // hoisted, so defined before the branch
        }
        $unresolved[] = "{$name}() at line {$call['line']} (declared inside a block)";
        continue;
    }
    $unresolved[] = "{$name}() at line {$call['line']} (never declared)";
}
foreach ($unresolved as $msg) {
    assert_true(false, "called before the dispatcher but not resolvable: {$msg}");
}
assert_true($unresolved === [], 'every function called before the dispatcher is resolvable');

// ── Self-test: the scanner must catch the bug it exists for ────────────────
$broken = "<?php\n"
    . "if ((\$_GET['action'] ?? '') === 'ping') {\n"
    . "    checkRateLimit('ping');\n"
    . "    exit;\n"
    . "}\n"
    . "if ((\$_GET['action'] ?? '') === 'health_check') {\n"
    . "    function checkRateLimit(string \$a): void {}\n"
    . "}\n";
$brokenScan = scan_functions($broken);
assert_true(($brokenScan['decls']['checkRateLimit']['depth'] ?? 0) === 1, 'self-test: a conditional declaration is seen as nested');
assert_true(
    $brokenScan['decls']['checkRateLimit']['line'] > $brokenScan['calls'][0]['line'],
    'self-test: the call is seen before the conditional declaration'
);

$hoisted = "<?php\n"
    . "checkRateLimit('ping');\n"
    . "function checkRateLimit(string \$a): void {}\n";
$hoistedScan = scan_functions($hoisted);
assert_true(($hoistedScan['decls']['checkRateLimit']['depth'] ?? 1) === 0, 'self-test: a top-level declaration is not flagged');
assert_true($hoistedScan['calls'][0]['name'] === 'checkRateLimit', 'self-test: the call site is detected');
assert_true(!isset($hoistedScan['decls']['class']), 'self-test: language constructs are not mistaken for functions');

$objects = "<?php\n\$db->query('x');\nFoo::bar();\nnew PDO('sqlite::memory:');\n";
assert_true(scan_functions($objects)['calls'] === [], 'self-test: method, static and constructor calls are not function calls');

if ($fail > 0) {
    echo "\n{$fail} test(s) failed\n";
    exit(1);
}
echo "\nAll API hoisting tests passed\n";
