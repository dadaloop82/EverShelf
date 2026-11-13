#!/usr/bin/env php
<?php
/**
 * EverShelf — upgrade/migration check: prove that an installation coming from an OLDER
 * version keeps every byte of its data when the NEW version boots on it.
 *
 * Why it exists: a release is one merge away from a household's pantry, and the things that
 * break an upgrade are invisible in a code review — a migration that reads a column it adds
 * three hundred lines further down, a NOT NULL column added without a default, a data repair
 * that eats a value. This script builds a database with the OLD code, fills it with realistic
 * content (products, stock with hand-typed dates, transactions, shopping list, recipes,
 * settings, tags, health rows…), opens that same file with the NEW code (which runs
 * initializeDB()/migrateDB() exactly like the first request after the update) and compares
 * everything, row by row.
 *
 *   php scripts/check-upgrade-migration.php                      # origin/main → the current tree
 *   php scripts/check-upgrade-migration.php --from=v1.31.0 --to=HEAD
 *   php scripts/check-upgrade-migration.php --json               # machine-readable summary
 *   php scripts/check-upgrade-migration.php --keep               # leave the worktrees/temp DB
 *
 * Exit code: 0 = nothing lost, 1 = a check failed (a merge blocker), 2 = bad usage.
 *
 * What a failure means:
 *   - "table X: all N legacy columns still there" → a column the old version wrote disappears
 *   - "X: row count A → B"                        → rows vanished during the migration
 *   - "X: every legacy value preserved"           → a value was rewritten and is not one of
 *                                                   the documented repairs below
 *   - "setting 'k' kept its value"                → a preference would silently change
 * Documented repairs (counted, never silent, never empty):
 *   - HTML entities stored escaped in a title/note are decoded (migration_decode_title_entities_v1)
 *   - a row with NO best-before date gets an estimate (migration_infer_missing_expiry_v1)
 *   - stock in the retired "altro" place moves to "dispensa" (migration_location_altro_v1)
 *   - DERIVED columns are recomputed: products.shopping_name (the buyable word of the list),
 *     recipes.content_hash (md5 → sha256)
 */

declare(strict_types=1);

const CHECK_SEED_TABLES = [
    'products', 'inventory', 'transactions', 'recipes', 'shopping_list', 'chat_messages',
    'app_settings', 'barcode_cache', 'barcode_catalog', 'shopping_templates', 'product_tags',
    'product_tag_links', 'health_daily', 'health_meals', 'health_profile', 'health_tokens',
    'webpush_subscriptions', 'recipes_archive',
];

$args  = array_slice($argv, 1);
$from  = 'origin/main';
$to    = 'HEAD';
$json  = false;
$keep  = false;
$phase = '';
$wtIn  = '';
$state = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--from='))   { $from = substr($a, 7); }
    elseif (str_starts_with($a, '--to=')) { $to = substr($a, 5); }
    elseif ($a === '--json')              { $json = true; }
    elseif ($a === '--keep')              { $keep = true; }
    elseif (str_starts_with($a, '--phase=')) { $phase = substr($a, 8); }
    elseif (str_starts_with($a, '--wt='))    { $wtIn = substr($a, 5); }
    elseif (str_starts_with($a, '--state=')) { $state = substr($a, 8); }
    elseif ($a === '--help' || $a === '-h') {
        fwrite(STDOUT, "usage: php scripts/check-upgrade-migration.php [--from=<ref>] [--to=<ref>] [--json] [--keep]\n");
        exit(0);
    } else {
        fwrite(STDERR, "unknown argument: {$a}\n");
        exit(2);
    }
}

/** Paths of the three libraries api/database.php reaches at runtime. */
function checkLoadVersion(string $wt): void
{
    foreach (['lib/env.php', 'logger.php', 'lib/vocabulary.php', 'lib/product_kind.php'] as $lib) {
        if (is_file($wt . '/api/' . $lib)) {
            require_once $wt . '/api/' . $lib;
        }
    }
    require_once $wt . '/api/database.php';   // getDB() → initializeDB() + migrateDB() of THAT ref
}

function checkColumns(PDO $db, string $table): array
{
    try {
        return array_column($db->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    } catch (Throwable $e) {
        return [];
    }
}

/** Insert only the columns this schema actually has (the seed runs against the OLD schema). */
function checkInsertRow(PDO $db, string $table, array $data): void
{
    $have = checkColumns($db, $table);
    $data = array_intersect_key($data, array_flip($have));
    if ($data === []) { return; }
    $cols = array_keys($data);
    $db->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($data));
}

function checkDumpTable(PDO $db, string $table): array
{
    $cols = checkColumns($db, $table);
    if ($cols === []) { return ['cols' => [], 'rows' => []]; }
    return [
        'cols' => $cols,
        'rows' => $db->query('SELECT ' . implode(',', $cols) . " FROM {$table} ORDER BY rowid")->fetchAll(),
    ];
}

function checkSnapshot(PDO $db): array
{
    $snap = [];
    foreach (CHECK_SEED_TABLES as $t) {
        $snap[$t] = checkDumpTable($db, $t);
        $snap[$t]['count'] = count($snap[$t]['rows']);
    }
    $snap['_schema'] = [];
    foreach (CHECK_SEED_TABLES as $t) { $snap['_schema'][$t] = checkColumns($db, $t); }
    return $snap;
}

/** Decode stored entities repeatedly: HEAD repairs them, so both sides are compared decoded. */
function checkNormText(?string $s): ?string
{
    if ($s === null) { return null; }
    for ($i = 0; $i < 5 && str_contains($s, '&'); $i++) {
        $next = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($next === $s) { break; }
        $s = $next;
    }
    return $s;
}


// ─────────────────────────────────────────────────────────────────────────────
// Child phases (the seeding must run with the OLD code loaded, the migration with the NEW)
// ─────────────────────────────────────────────────────────────────────────────
if ($phase === 'seed' || $phase === 'verify' || $phase === 'idem') {
    if ($wtIn === '' || $state === '') { fwrite(STDERR, "phase needs --wt and --state\n"); exit(2); }
    $snapshotFile = $state . '/legacy.json';
    checkLoadVersion($wtIn);

    $ok = 0;
    $fail = 0;
    $chk = static function (string $label, bool $pass, string $detail = '') use (&$ok, &$fail): void {
        if ($pass) { $ok++; fwrite(STDOUT, "  ok   {$label}\n"); }
        else { $fail++; fwrite(STDOUT, "  FAIL {$label}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n"); }
    };

    if ($phase === 'seed') {
        @unlink($wtIn . '/data/evershelf.db');
        $db = getDB();
        require __DIR__ . '/check-upgrade-migration.seed.php';
        checkSeedLegacy($db);
        file_put_contents($snapshotFile, json_encode(checkSnapshot($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $rows = 0;
        foreach (CHECK_SEED_TABLES as $t) { $rows += count(checkDumpTable($db, $t)['rows']); }
        fwrite(STDOUT, "seeded {$rows} legacy rows\n");
        exit(0);
    }

    if ($phase === 'idem') {
        // A merge is not one boot: migrateDB() runs on every request. The second run must find
        // nothing left to do, otherwise the data drifts a little on each start.
        $db = getDB();
        $dump = static function (PDO $db): string {
            $out = [];
            foreach (CHECK_SEED_TABLES as $t) {
                $d = checkDumpTable($db, $t);
                if ($t === 'app_settings') {
                    $d['rows'] = array_map(static fn($r) => [$r['key'], $r['value']], $d['rows']);
                }
                $out[$t] = $d;
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE);
        };
        $before = $dump($db);
        migrateDB($db);
        $after = $dump($db);
        $chk('a second boot changes nothing', $before === $after);
        migrateDB($db);
        $chk('and a third does not either', $after === $dump($db));
        fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
        exit($fail === 0 ? 0 : 1);
    }

    // ── verify: the NEW code has just opened (and migrated) the OLD database ───────────
    $legacy = json_decode((string)file_get_contents($snapshotFile), true);
    $db = getDB();

    foreach ($legacy['_schema'] as $table => $oldCols) {
        $newCols = checkColumns($db, $table);
        if ($oldCols === [] && $newCols === []) { continue; }
        $missing = array_values(array_diff($oldCols, $newCols));
        $chk("{$table}: all " . count($oldCols) . ' legacy columns still there',
            $newCols !== [] && $missing === [], implode(',', $missing));
    }

    // Columns and tables a merge would otherwise add for fresh installs only.
    $expectNew = [
        'products' => ['is_medical', 'med_atc', 'med_ingredient', 'med_pao_days', 'med_tags'],
        'recipes'  => ['rating'],
    ];
    foreach ($expectNew as $table => $cols) {
        $newCols = checkColumns($db, $table);
        foreach ($cols as $c) {
            $chk("{$table}.{$c} added to the existing database", in_array($c, $newCols, true));
        }
    }
    $chk('the medicines table exists', checkColumns($db, 'medicines') !== []);

    $derived = ['products' => ['shopping_name'], 'recipes' => ['content_hash']];
    $repairs = ['entities' => 0, 'expiry_inferred' => 0, 'altro_moved' => 0, 'recomputed' => 0, 'emptied' => 0];
    foreach (array_values(array_diff(array_keys($legacy), ['_schema'])) as $table) {
        $oldRows = $legacy[$table]['rows'];
        $oldCols = $legacy[$table]['cols'];
        if ($oldCols === []) { continue; }
        $usable = array_values(array_intersect($oldCols, checkColumns($db, $table)));
        $rows   = $db->query('SELECT ' . implode(',', $usable) . " FROM {$table} ORDER BY rowid")->fetchAll();
        $chk("{$table}: row count " . count($oldRows) . ' → ' . count($rows),
            count($rows) === count($oldRows) || ($table === 'app_settings' && count($rows) > count($oldRows)));

        $diff = [];
        foreach ($oldRows as $i => $old) {
            $new = $rows[$i] ?? null;
            if ($new === null) { $diff[] = "#{$i} disappeared"; continue; }
            foreach ($usable as $c) {
                $a = $old[$c];
                $b = $new[$c];
                if ($table === 'app_settings' && $c === 'updated_at') { continue; }
                if (in_array($c, $derived[$table] ?? [], true)) {
                    if ((string)$a !== (string)$b) {
                        $repairs['recomputed']++;
                        if (trim((string)$b) === '') { $repairs['emptied']++; }
                    }
                    continue;
                }
                if ($table === 'inventory' && $old['expiry_date'] === null
                    && in_array($c, ['expiry_date', 'expiry_user_set', 'updated_at'], true)) {
                    if ($c === 'expiry_date' && $b !== null) { $repairs['expiry_inferred']++; }
                    continue;
                }
                if ($table === 'inventory' && $c === 'location' && $a === 'altro' && $b === 'dispensa') {
                    $repairs['altro_moved']++;
                    continue;
                }
                if (in_array($c, ['name', 'notes', 'text', 'brand'], true)) {
                    if (checkNormText((string)$a) === checkNormText((string)$b)) {
                        if ((string)$a !== (string)$b) { $repairs['entities']++; }
                        continue;
                    }
                    $diff[] = "{$table}#{$i}.{$c}: '{$a}' → '{$b}'";
                    continue;
                }
                if ((string)$a !== (string)$b) {
                    $diff[] = "{$table}#{$i}.{$c}: '{$a}' → '{$b}'";
                }
            }
        }
        if ($diff !== []) {
            $chk("{$table}: every legacy value preserved", false, implode(' | ', array_slice($diff, 0, 4)));
        }
    }

    $lost = [];
    foreach ($legacy['app_settings']['rows'] as $row) {
        $k   = (string)$row['key'];
        $now = $db->query('SELECT value FROM app_settings WHERE key = ' . $db->quote($k))->fetchColumn();
        if ($now === false) { $lost[] = "{$k} disappeared"; continue; }
        if ($k === 'env_overrides') {
            $gone = array_diff_key(json_decode((string)$row['value'], true) ?: [], json_decode((string)$now, true) ?: []);
            if ($gone !== []) { $lost[] = 'env_overrides lost ' . implode(',', array_keys($gone)); }
            continue;
        }
        if ((string)$now !== (string)$row['value']) { $lost[] = "{$k}: '{$row['value']}' → '{$now}'"; }
    }
    $chk('every legacy setting kept its value', $lost === [], implode(' | ', array_slice($lost, 0, 4)));

    $integrity = (string)$db->query('PRAGMA integrity_check')->fetchColumn();
    $chk('integrity_check', $integrity === 'ok', $integrity);
    $fk = $db->query('PRAGMA foreign_key_check')->fetchAll();
    $chk('foreign_key_check is empty', $fk === [], json_encode(array_slice($fk, 0, 3)));
    $idx = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%'")->fetchColumn();
    $chk("hot-path indexes present ({$idx})", $idx >= 10);
    $chk('no derived value was wiped to an empty string', $repairs['emptied'] === 0, (string)$repairs['emptied']);

    fwrite(STDOUT, "\nrepairs the new version applied on purpose:\n");
    fwrite(STDOUT, "  titles with escaped entities decoded : {$repairs['entities']}\n");
    fwrite(STDOUT, "  missing expiry dates inferred        : {$repairs['expiry_inferred']}\n");
    fwrite(STDOUT, "  stock moved out of 'altro'           : {$repairs['altro_moved']}\n");
    fwrite(STDOUT, "  derived columns recomputed           : {$repairs['recomputed']} (left empty: {$repairs['emptied']})\n");
    fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
    exit($fail === 0 ? 0 : 1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Driver: two worktrees, one database, three child phases
// ─────────────────────────────────────────────────────────────────────────────
$root = dirname(__DIR__);
$say  = static function (string $line) use ($json): void {
    if (!$json) { fwrite(STDOUT, $line . "\n"); }
};

/** Run a command, return [exitCode, output]. */
$run = static function (string $cmd): array {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

$resolve = static function (string $ref) use ($root, $run): string {
    [$code, $out] = $run('git -C ' . escapeshellarg($root) . ' rev-parse --verify ' . escapeshellarg($ref . '^{commit}'));
    if ($code !== 0) {
        fwrite(STDERR, "cannot resolve ref '{$ref}': {$out}\n");
        exit(2);
    }
    return trim($out);
};

$fromSha = $resolve($from);
$toSha   = $resolve($to);
$tmp     = rtrim(sys_get_temp_dir(), '/') . '/evershelf-upgrade-check-' . getmypid();
$oldWt   = $tmp . '/old';
$newWt   = $tmp . '/new';

$say('EverShelf upgrade check');
$say("  from : {$from} ({$fromSha})");
$say("  to   : {$to} ({$toSha})");
$say('  work : ' . $tmp);

if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "cannot create {$tmp}\n");
    exit(2);
}

$cleanup = static function () use ($root, $oldWt, $newWt, $tmp, $keep, $say): void {
    if ($keep) {
        $say("\nkept for inspection: {$tmp}");
        return;
    }
    foreach ([$oldWt, $newWt] as $wt) {
        exec('git -C ' . escapeshellarg($root) . ' worktree remove --force ' . escapeshellarg($wt) . ' 2>&1');
    }
    exec('git -C ' . escapeshellarg($root) . ' worktree prune 2>&1');
    exec('rm -rf ' . escapeshellarg($tmp));
};

foreach (['old' => $fromSha, 'new' => $toSha] as $label => $sha) {
    $dir = $tmp . '/' . $label;
    [$code, $out] = $run('git -C ' . escapeshellarg($root) . ' worktree add --detach '
        . escapeshellarg($dir) . ' ' . escapeshellarg($sha));
    if ($code !== 0) {
        fwrite(STDERR, "cannot create the {$label} worktree: {$out}\n");
        $cleanup();
        exit(2);
    }
}

$self = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);

// 1) a legacy database, built and filled by the OLD code
[$seedCode, $seedOut] = $run($self . ' --phase=seed --wt=' . escapeshellarg($oldWt) . ' --state=' . escapeshellarg($tmp));
$say("\n[1/3] building a legacy database with {$from}");
$say($seedOut);
if ($seedCode !== 0) {
    fwrite(STDERR, "seeding failed — cannot continue\n");
    $cleanup();
    exit(1);
}

// The old side must be closed before the file is copied (WAL sidecar).
try {
    $sideDb = new PDO('sqlite:' . $oldWt . '/data/evershelf.db');
    $sideDb->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $sideDb = null;
} catch (Throwable $e) {
    fwrite(STDERR, 'checkpoint failed: ' . $e->getMessage() . "\n");
}
@mkdir($newWt . '/data', 0775, true);
foreach (['', '-wal', '-shm'] as $suffix) { @unlink($newWt . '/data/evershelf.db' . $suffix); }
if (!copy($oldWt . '/data/evershelf.db', $newWt . '/data/evershelf.db')) {
    fwrite(STDERR, "cannot copy the legacy database\n");
    $cleanup();
    exit(1);
}

// 2) the SAME file, opened by the NEW code (initializeDB + migrateDB, like the first request)
[$verifyCode, $verifyOut] = $run($self . ' --phase=verify --wt=' . escapeshellarg($newWt) . ' --state=' . escapeshellarg($tmp));
$say("\n[2/3] first boot of {$to} on that database");
$say($verifyOut);

// 3) a second and third boot must change nothing (migrateDB runs on every request)
[$idemCode, $idemOut] = $run($self . ' --phase=idem --wt=' . escapeshellarg($newWt) . ' --state=' . escapeshellarg($tmp));
$say("\n[3/3] the boots after the first");
$say($idemOut);

$cleanup();

$failed = substr_count($verifyOut . $idemOut, 'FAIL');
$passed = substr_count($verifyOut . $idemOut, '  ok   ');
$exit   = ($verifyCode === 0 && $idemCode === 0) ? 0 : 1;
// A phase that dies without printing a single check did not fail a comparison: the new code
// crashed while opening the old database, which after a merge means "the app will not boot".
$crashed = $exit !== 0 && $failed === 0;

if ($json) {
    fwrite(STDOUT, json_encode([
        'from'       => ['ref' => $from, 'sha' => $fromSha],
        'to'         => ['ref' => $to, 'sha' => $toSha],
        'passed'     => $passed,
        'failed'     => $failed,
        'crashed'    => $crashed,
        'ok'         => $exit === 0,
        'verify'     => $verifyOut,
        'idempotent' => $idemOut,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} elseif ($exit === 0) {
    fwrite(STDOUT, "\nRESULT: nothing is lost — the upgrade is safe ({$passed} checks passed).\n");
} elseif ($crashed) {
    fwrite(STDOUT, "\nRESULT: the new version CRASHED while opening the old database — it would not boot.\n"
        . "        This is a merge blocker: see the error above.\n");
} else {
    fwrite(STDOUT, "\nRESULT: {$failed} check(s) FAILED — do not ship this merge yet.\n");
}

exit($exit);
