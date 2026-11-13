<?php
/**
 * EverShelf — demo mode: the flag, the demo database and its seed.
 *
 * A public demo must be able to answer three questions the household's install
 * answers differently, and it must do so without ever touching the household's
 * pantry:
 *
 *   1. *Is this a demo right now?* — `DEMO_MODE=true` in `.env` (a demo deployment),
 *      `?demo=1` on any URL (one visitor, one tab — the flag lives in the URL and the
 *      client repeats it on every API call it makes, so nothing sticks to the browser),
 *      or simply living under `DEMO_BASE_PATH` (default `/demo`, the address the
 *      marketing site embeds). The same flag has always blocked the writes; it now also
 *      picks the DATABASE.
 *   2. *Which database?* — never `data/evershelf.db`. The demo answers from
 *      `data/demo/evershelf-demo-<lang>.db`, seeded from `api/lib/demo_seed.json`
 *      and rebuilt once a day so "expires in 3 days" is still true next week. A demo
 *      install therefore opens an EMPTY household database: the real pantry is not
 *      read, not written and not shown.
 *   3. *In which language?* — the language of the visitor (`?lang=`), English by
 *      default: the names of the seed are DATA, one entry per shipped language
 *      (`api/vocab` owns the vocabulary; this is demo content).
 *
 * Why a database and not mocked JSON: every screen (Home, pantry, shopping, history,
 * stats, stocktake) reads REAL queries. A demo answering with hand-written payloads
 * drifts from the app the moment a query changes; one seeded through `initializeDB()`
 * and `migrateDB()` cannot.
 */

require_once __DIR__ . '/env.php';

/** The languages the demo seed ships with (the same set as `api/vocab/*.json`). */
function evershelfDemoLangs(): array
{
    return ['it', 'en', 'de', 'fr', 'es', 'zh'];
}

/** `1`, `true`, `yes`, `on`, `demo` — the values a `.env` or a URL may use. */
function evershelfDemoFlagOn(mixed $raw): bool
{
    $value = strtolower(trim((string)$raw));
    return in_array($value, ['1', 'true', 'yes', 'on', 'demo'], true);
}

/**
 * The URL prefix a demo deployment lives under (`DEMO_BASE_PATH`, default `/demo`).
 * Empty (or `/`) switches the path rule off — what an install that only wants
 * `?demo=1` would set.
 */
function evershelfDemoBasePath(): string
{
    $base = trim((string)env('DEMO_BASE_PATH', '/demo'));
    if ($base === '' || $base === '/') {
        return '';
    }
    return '/' . trim($base, '/');
}

/** Does this request path belong to the demo mount (`/demo`, `/demo/…`)? */
function evershelfDemoPathMatches(string $requestUri): bool
{
    $base = evershelfDemoBasePath();
    if ($base === '') {
        return false;
    }
    $path = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '');
    return $path === $base || str_starts_with($path, $base . '/');
}

/**
 * Is demo mode in force for THIS request?
 *
 * Cached per request: the answer picks the database file, the auth bypass, the
 * write guard and the framing header, so it must be decided once and never change
 * halfway through a response.
 */
function evershelfDemoRequested(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    if (evershelfDemoFlagOn(env('DEMO_MODE', ''))) {
        return $on = true;
    }
    if (PHP_SAPI === 'cli') {
        return $on = false;
    }
    if (evershelfDemoFlagOn($_GET['demo'] ?? null)) {
        return $on = true;
    }
    // No cookie on purpose: a `path=/` demo cookie made the demo stick to the whole origin
    // for the rest of the browser session, so `/dispensa/` kept answering demo after the
    // visitor left it. The flag is the URL (or the mount), and the client repeats it on
    // every API call it makes.
    return $on = evershelfDemoPathMatches((string)($_SERVER['REQUEST_URI'] ?? ''));
}

/**
 * The demo language: `?lang=de` — the deep link the marketing site uses, repeated by the
 * client on every API call — and English when the request says nothing.
 *
 * English is the default on purpose: a public demo is not the visitor's own pantry, so
 * the browser's `Accept-Language` must not decide what a showcase looks like.
 */
function evershelfDemoLang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $code = strtolower(trim((string)preg_replace('/[-_].*$/', '', trim((string)($_GET['lang'] ?? '')))));
    if (in_array($code, evershelfDemoLangs(), true)) {
        return $lang = $code;
    }
    return $lang = 'en';
}

/**
 * Which origins may embed the demo in a frame (`DEMO_FRAME_ANCESTORS`, default `*`).
 * One value, read by the PHP header and documented in `.env.example`: the static
 * `index.html` is served by Apache, so `.htaccess` repeats the same policy.
 */
function evershelfDemoFrameAncestors(): string
{
    $value = trim((string)env('DEMO_FRAME_ANCESTORS', '*'));
    return $value !== '' ? $value : '*';
}

/**
 * The SQLite file of the demo, one per language: `data/demo/evershelf-demo-<lang>.db`.
 *
 * The language is part of the file name because the product names of the seed are
 * stored in it — answering a German visitor from an English demo would print a
 * German interface over "Whole milk". The folder is created on demand and, when the
 * server cannot write it, the file falls back to `data/` (the folder the app already
 * writes), so a demo never dies for want of a sub-directory.
 */
function evershelfDemoDbPath(string $lang = ''): string
{
    $lang = $lang !== '' ? $lang : evershelfDemoLang();
    if (!in_array($lang, evershelfDemoLangs(), true)) {
        $lang = 'en';
    }
    $dataDir = dirname(__DIR__, 2) . '/data';
    $dir     = $dataDir . '/demo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = $dataDir;
    }
    return $dir . '/evershelf-demo-' . $lang . '.db';
}

/** The seed, decoded once per request. */
function evershelfDemoSeedData(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $file = __DIR__ . '/demo_seed.json';
    $raw  = is_file($file) ? (string)@file_get_contents($file) : '';
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return $data = ['version' => 0, 'products' => [], 'history' => [], 'shopping' => [], 'app_settings' => []];
    }
    return $data = $parsed;
}

/**
 * One localized field of the seed: `{"en": "Milk", "it": "Latte"}` → the visitor's
 * word, English next, any other value last. A plain string (a brand) is itself.
 */
function evershelfDemoLocalized(mixed $value, string $lang, string $fallback = ''): string
{
    if (is_array($value)) {
        foreach ([$lang, 'en'] as $key) {
            if (isset($value[$key]) && trim((string)$value[$key]) !== '') {
                return (string)$value[$key];
            }
        }
        foreach ($value as $candidate) {
            if (trim((string)$candidate) !== '') {
                return (string)$candidate;
            }
        }
        return $fallback;
    }
    $plain = trim((string)$value);
    return $plain !== '' ? $plain : $fallback;
}



/**
 * Make sure the demo database holds today's seed.
 *
 * One indexed read on `app_settings` per request; a rebuild only when the day, the
 * language or the seed version changed. The expiry dates of the seed are relative to
 * `now`, so a demo left alone for a month must be re-based — otherwise "expires in
 * 3 days" becomes "expired 27 days ago" and the showcase turns into a warning.
 */
function evershelfDemoSeedIfStale(PDO $db): void
{
    if (!evershelfDemoRequested()) {
        return;
    }
    $data    = evershelfDemoSeedData();
    $version = (int)($data['version'] ?? 0);
    if ($version <= 0) {
        return; // no seed shipped: an empty demo, never a broken one
    }
    $lang  = evershelfDemoLang();
    $stamp = date('Y-m-d') . '|' . $lang . '|' . $version;

    $current = '';
    try {
        $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = 'demo_seed'");
        $stmt->execute();
        $current = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $current = ''; // table not created yet → rebuild
    }
    if ($current === $stamp) {
        return;
    }
    try {
        evershelfDemoRebuild($db, $data, $lang, $stamp);
        if (class_exists('EverLog', false)) {
            EverLog::info('demo seed rebuilt', [
                'event' => 'demo_seed_rebuilt',
                'lang'  => $lang,
                'day'   => date('Y-m-d'),
            ]);
        }
    } catch (Throwable $e) {
        if (class_exists('EverLog', false)) {
            EverLog::exception($e, 'demo_seed_failed', ['lang' => $lang]);
        }
    }
}

/**
 * Replace the content of the DEMO database with the seed (never called outside it).
 *
 * `DELETE FROM products` here is safe by construction: the only database this runs
 * against is `evershelfDemoDbPath()`, which is not the household's file.
 *
 * @param array<string,mixed> $data decoded `demo_seed.json`
 */
function evershelfDemoRebuild(PDO $db, array $data, string $lang, string $stamp): void
{
    $now  = time();
    $when = static fn(int $daysAgo): string => date('Y-m-d H:i:s', $now - $daysAgo * 86400);
    $date = static fn(int $daysAhead): string => date('Y-m-d', $now + $daysAhead * 86400);

    dbBeginImmediate($db);
    try {
        $db->exec('DELETE FROM transactions');
        $db->exec('DELETE FROM inventory');
        $db->exec('DELETE FROM products');
        $db->exec('DELETE FROM shopping_list');

        $insertProduct = $db->prepare(
            "INSERT INTO products (barcode, name, brand, category, unit, default_quantity, notes,
                                   shopping_name, created_at, updated_at, name_user_set, kind)
             VALUES (NULL, ?, ?, ?, ?, 1, '', ?, ?, ?, 1, '')"
        );
        $insertStock = $db->prepare(
            "INSERT INTO inventory (product_id, location, quantity, expiry_date, added_at, updated_at,
                                    vacuum_sealed, opened_at, expiry_user_set)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0)"
        );

        $ids = [];
        foreach (($data['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $key       = (string)($product['key'] ?? '');
            $name      = evershelfDemoLocalized($product['name'] ?? '', $lang);
            $shopping  = evershelfDemoLocalized($product['shopping_name'] ?? '', $lang, $name);
            $addedDays = max(0, (int)($product['added_days_ago'] ?? 0));
            $addedAt   = $when($addedDays);
            $insertProduct->execute([
                $name,
                evershelfDemoLocalized($product['brand'] ?? '', $lang),
                (string)($product['category'] ?? 'altro'),
                (string)($product['unit'] ?? 'pz'),
                $shopping,
                $addedAt,
                $addedAt,
            ]);
            $id = (int)$db->lastInsertId();
            if ($key !== '') {
                $ids[$key] = $id;
            }
            $expiryDays = $product['expiry_days'] ?? null;
            $openedDays = $product['opened_days_ago'] ?? null;
            $insertStock->execute([
                $id,
                (string)($product['location'] ?? 'dispensa'),
                (float)($product['quantity'] ?? 1),
                $expiryDays === null ? null : $date((int)$expiryDays),
                $addedAt,
                $addedAt,
                $openedDays === null ? null : $when((int)$openedDays),
            ]);
        }

        $insertTx = $db->prepare(
            "INSERT INTO transactions (product_id, type, quantity, location, notes, undone, created_at)
             VALUES (?, ?, ?, ?, '', 0, ?)"
        );
        foreach (($data['history'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = $ids[(string)($entry['product'] ?? '')] ?? 0;
            if ($id <= 0) {
                continue;
            }
            $type = (string)($entry['type'] ?? 'out');
            if (!in_array($type, ['in', 'out', 'waste'], true)) {
                continue;
            }
            $insertTx->execute([
                $id,
                $type,
                (float)($entry['quantity'] ?? 1),
                (string)($entry['location'] ?? 'dispensa'),
                $when((int)($entry['days_ago'] ?? 0)),
            ]);
        }

        $insertShop = $db->prepare(
            "INSERT INTO shopping_list (name, raw_name, specification, added_at, sort_order, need_qty, need_unit, is_checked)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)"
        );
        $order = 0;
        foreach (($data['shopping'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = evershelfDemoLocalized($row['name'] ?? '', $lang);
            if ($name === '') {
                continue;
            }
            $insertShop->execute([
                $name,
                $name,
                evershelfDemoLocalized($row['spec'] ?? '', $lang),
                $now - $order * 60,
                $order,
                (float)($row['qty'] ?? 1),
                (string)($row['unit'] ?? 'pz'),
            ]);
            $order++;
        }

        $insertSetting = $db->prepare(
            "INSERT OR REPLACE INTO app_settings (key, value) VALUES (?, ?)"
        );
        foreach (($data['app_settings'] ?? []) as $key => $value) {
            $insertSetting->execute([(string)$key, (string)$value]);
        }
        $insertSetting->execute(['demo_seed', $stamp]);

        dbCommit($db);
    } catch (Throwable $e) {
        dbRollback($db);
        throw $e;
    }
}
