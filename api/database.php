<?php
/**
 * EverShelf - Database initialization, schema, and migrations.
 * Uses SQLite with WAL journal mode for concurrent read/write performance.
 *
 * @author Stimpfl Daniel <evershelfproject@gmail.com>
 * @license MIT
 */

require_once __DIR__ . '/lib/demo.php';

/**
 * The SQLite file this request answers from.
 *
 * Demo mode never opens the household's pantry: it answers from its own file
 * (`api/lib/demo.php` → `data/demo/evershelf-demo-<lang>.db`), seeded with demo
 * products. Everything downstream (`getDB()`, backups, health) reads this constant,
 * so there is exactly ONE place that decides which database is "the database".
 */
function evershelfDbFilePath(): string
{
    if (function_exists('evershelfDemoRequested') && evershelfDemoRequested()) {
        return evershelfDemoDbPath();
    }
    return __DIR__ . '/../data/evershelf.db';
}

define('DB_PATH', evershelfDbFilePath());

/**
 * Ensure the data directory exists and is writable by the web-server user.
 * This is needed when a Docker volume is first mounted: the image's chown
 * step is applied to the image layer, but a fresh named volume starts empty
 * (owned by root), making SQLite's PDO::__construct fail with HY000[14].
 */
function _ensureDataDir(): void {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create data directory: $dir");
        }
    }
    if (!is_writable($dir)) {
        // Try to fix permissions (only works when running as root, e.g. first boot)
        @chmod($dir, 0775);
        if (!is_writable($dir)) {
            throw new \RuntimeException(
                "Data directory is not writable: $dir — run: chown -R www-data:www-data $dir"
            );
        }
    }
    // Ensure backups sub-directory exists too
    $backups = $dir . '/backups';
    if (!is_dir($backups)) {
        @mkdir($backups, 0775, true);
    }
}

/** Ensure the SQLite DB and WAL sidecar files are writable (Docker volume first-boot). */
function _ensureDbWritable(): void {
    if (!file_exists(DB_PATH)) {
        return;
    }
    if (!is_writable(DB_PATH)) {
        @chmod(DB_PATH, 0664);
    }
    foreach ([DB_PATH . '-wal', DB_PATH . '-shm'] as $sidecar) {
        if (file_exists($sidecar) && !is_writable($sidecar)) {
            @chmod($sidecar, 0664);
        }
    }
}

function getDB(): PDO {
    _ensureDataDir();
    _ensureDbWritable();
    // logger.php is required by index.php before getDB() is called.
    // In cron context it may not be loaded yet — guard with class_exists.
    $useLogging = class_exists('LoggingPDO', false);
    $isNew = !file_exists(DB_PATH);
    $db = $useLogging
        ? new LoggingPDO('sqlite:' . DB_PATH)
        : new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set a busy timeout to prevent "database is locked" errors under high concurrency.
    // This gives SQLite up to 5 seconds to acquire a lock before throwing an exception.
    $db->setAttribute(PDO::ATTR_TIMEOUT, 5); // PDO::ATTR_TIMEOUT is in seconds for MySQL, but not directly for SQLite.
                                             // For SQLite, we use PRAGMA busy_timeout.
    $db->exec('PRAGMA journal_mode = WAL;');
    $db->exec('PRAGMA busy_timeout = 20000;'); // 20 s — cron + PWA writes can contend under WAL

    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");
    $db->exec("PRAGMA synchronous=NORMAL");    // faster writes, still safe with WAL
    $db->exec("PRAGMA cache_size=-8000");      // ~8 MB page cache (was 2 MB)
    $db->exec("PRAGMA temp_store=MEMORY");     // temp tables in RAM
    
    if ($isNew) {
        initializeDB($db);
    }
    
    // Run migrations
    migrateDB($db);

    // Demo mode answers from its own file (see evershelfDbFilePath()): make sure it
    // holds today's seed. No-op on a household install.
    if (function_exists('evershelfDemoSeedIfStale')) {
        evershelfDemoSeedIfStale($db);
    }

    
    return $db;
}

/**
 * Retry a DB write when SQLite returns "database is locked" (concurrent cron + API).
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function dbWithRetry(callable $fn, int $maxAttempts = 6): mixed {
    $attempt = 0;
    while (true) {
        try {
            return $fn();
        } catch (\PDOException $e) {
            $attempt++;
            $locked = str_contains($e->getMessage(), 'database is locked');
            if (!$locked || $attempt >= $maxAttempts) {
                throw $e;
            }
            usleep(150000 * $attempt); // 150 ms, 300 ms, 450 ms …
        }
    }
}

/**
 * Start a SQLite IMMEDIATE write transaction.
 * IMPORTANT: use dbCommit()/dbRollback() — NOT PDO::commit()/rollBack().
 * PDO::beginTransaction() uses DEFERRED BEGIN; exec('BEGIN IMMEDIATE') is not
 * tracked by PDO::inTransaction(), so PDO::commit() throws "no active transaction"
 * and the write is rolled back on disconnect.
 */
function dbBeginImmediate(PDO $db): void {
    if ($db->inTransaction()) {
        return;
    }
    $db->exec('BEGIN IMMEDIATE');
}

function dbCommit(PDO $db): void {
    if ($db->inTransaction()) {
        $db->commit();
        return;
    }
    $db->exec('COMMIT');
}

function dbRollback(PDO $db): void {
    if ($db->inTransaction()) {
        $db->rollBack();
        return;
    }
    try {
        $db->exec('ROLLBACK');
    } catch (Throwable $e) {
        // already closed / no txn
    }
}

function initializeDB(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            barcode TEXT UNIQUE,
            name TEXT NOT NULL,
            brand TEXT DEFAULT '',
            category TEXT DEFAULT '',
            image_url TEXT DEFAULT '',
            unit TEXT DEFAULT 'pz',
            default_quantity REAL DEFAULT 1,
            package_unit TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            shopping_name TEXT DEFAULT '',
            parent_id INTEGER DEFAULT NULL,
            nutriments_json TEXT DEFAULT NULL,
            name_user_set INTEGER DEFAULT 0,
            is_favorite INTEGER DEFAULT 0,
            kind TEXT DEFAULT '',
            favorite_user_override INTEGER DEFAULT 0,
            is_medical INTEGER NOT NULL DEFAULT 0,
            med_atc TEXT DEFAULT '',
            med_ingredient TEXT DEFAULT '',
            med_tags TEXT DEFAULT '',
            med_pao_days INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            location TEXT NOT NULL DEFAULT 'dispensa',
            quantity REAL NOT NULL DEFAULT 1,
            expiry_date DATE,
            vacuum_sealed INTEGER DEFAULT 0,
            opened_at DATETIME DEFAULT NULL,
            frozen_at DATETIME DEFAULT NULL,
            pre_frozen_expiry DATE DEFAULT NULL,
            expiry_user_set INTEGER DEFAULT 0,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('in', 'out', 'waste')),
            quantity REAL NOT NULL,
            location TEXT NOT NULL DEFAULT 'dispensa',
            notes TEXT DEFAULT '',
            undone INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);
        CREATE INDEX IF NOT EXISTS idx_inventory_product ON inventory(product_id);
        CREATE INDEX IF NOT EXISTS idx_inventory_location ON inventory(location);
        -- Dashboard/expiry views: WHERE expiry_date <= ... / ORDER BY expiry_date
        CREATE INDEX IF NOT EXISTS idx_inventory_expiry ON inventory(expiry_date);
        CREATE INDEX IF NOT EXISTS idx_transactions_product ON transactions(product_id);
        CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(created_at);
        -- Composite indexes for hot queries
        -- getStats(): WHERE type IN (...) AND created_at >= ...
        CREATE INDEX IF NOT EXISTS idx_transactions_type_date ON transactions(type, created_at);
        -- smartShopping(): GROUP BY product_id filtering on type+undone
        CREATE INDEX IF NOT EXISTS idx_transactions_pid_type_undone ON transactions(product_id, type, undone);
        -- Hot read paths (audit D1/G2): FIFO pick by added_at, product name and the
        -- shopping family/buy key. The expression indexes must stay equivalent to the
        -- WHERE clauses they serve (EXPLAIN QUERY PLAN was run on each of them).
        CREATE INDEX IF NOT EXISTS idx_inventory_added ON inventory(added_at);
        CREATE INDEX IF NOT EXISTS idx_products_name_lower ON products(lower(name));
        CREATE INDEX IF NOT EXISTS idx_products_shopping_name_lower ON products(lower(trim(shopping_name)));
        CREATE INDEX IF NOT EXISTS idx_products_family_key_lower ON products(lower(trim(coalesce(shopping_name,''))));
        CREATE INDEX IF NOT EXISTS idx_products_buy_key_lower ON products(lower(trim(coalesce(nullif(shopping_name,''), name))));
    ");
}

/**
 * The one place `app_settings` is created. Every migration reads it, so it has to exist
 * before the first of them runs — on a brand-new install nothing else creates it.
 */
function evershelfEnsureAppSettingsTable(PDO $db): void
{
    $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='app_settings'")->fetchColumn();
    if ($exists) {
        return;
    }
    $db->exec("
        CREATE TABLE app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
}

function migrateDB(PDO $db): void {
    // Guard: if core tables don't exist yet (e.g. DB file present but empty / partial init),
    // run initializeDB first so all tables are created, then return — no ALTER TABLE needed.
    $productsExists = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='products'"
    )->fetchColumn();
    if (!$productsExists) {
        initializeDB($db);
        return;
    }
    // A fresh install reaches the migrations with no app_settings row store: create it before
    // anything reads a migration marker (a brand-new instance used to answer HTTP 500 here).
    evershelfEnsureAppSettingsTable($db);

    // Add package_unit column if missing
    $cols = $db->query("PRAGMA table_info(products)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('package_unit', $colNames)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN package_unit TEXT DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    if (!in_array('shopping_name', $colNames)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN shopping_name TEXT DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    // Parent / child products (#284): a child keeps its own brand, barcode and
    // stock and rolls up to the umbrella product for the family totals.
    if (!in_array('parent_id', $colNames)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN parent_id INTEGER DEFAULT NULL"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_parent ON products(parent_id)");

    // Empty barcode strings break UNIQUE (only one '' allowed); normalize to NULL.
    $db->exec("UPDATE products SET barcode = NULL WHERE barcode IS NOT NULL AND TRIM(barcode) = ''");

    // Migration: titles stored with HTML entities ("Marmellata di &quot;Limone…&quot;").
    // A catalogue escaped the name once, an old import escaped it twice, and the literal
    // entity then showed in the pantry, in the history log and in every toast. Every new
    // write is decoded at the single title choke point (mergeIncomingProductFields →
    // productTitleDecodeEntities); this pass repairs the rows already in the database, so
    // the household does not have to rename a product to clean it.
    $titlesDecoded = $db->query("SELECT value FROM app_settings WHERE key = 'migration_decode_title_entities_v1'")->fetchColumn();
    if (!$titlesDecoded) {
        require_once __DIR__ . '/lib/product_kind.php';
        $rows = $db->query("SELECT id, name, brand FROM products WHERE name LIKE '%&%' OR brand LIKE '%&%'")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $updProduct = $db->prepare('UPDATE products SET name = ?, brand = ? WHERE id = ?');
        $fixedProducts = 0;
        foreach ($rows as $row) {
            $name = productTitleDecodeEntities((string)$row['name']);
            $brand = productTitleDecodeEntities((string)$row['brand']);
            if ($name !== (string)$row['name'] || $brand !== (string)$row['brand']) {
                $updProduct->execute([$name, $brand, (int)$row['id']]);
                $fixedProducts++;
            }
        }
        // The history joins products for the name, but its own notes carry recipe titles.
        $txRows = $db->query("SELECT id, notes FROM transactions WHERE notes LIKE '%&%'")
                     ->fetchAll(PDO::FETCH_ASSOC);
        $updNotes = $db->prepare('UPDATE transactions SET notes = ? WHERE id = ?');
        $fixedNotes = 0;
        foreach ($txRows as $row) {
            $notes = productTitleDecodeEntities((string)$row['notes']);
            if ($notes !== (string)$row['notes']) {
                $updNotes->execute([$notes, (int)$row['id']]);
                $fixedNotes++;
            }
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_decode_title_entities_v1', '1')");
        if (($fixedProducts + $fixedNotes) > 0 && class_exists('EverLog')) {
            EverLog::info('Decoded HTML entities stored in titles', [
                'event' => 'titles_entities_decoded',
                'products' => $fixedProducts,
                'transactions' => $fixedNotes,
            ]);
        }
    }

    // The medical columns must exist BEFORE the migrations below read them.
    // The expiry pass (a few lines down) filters on `COALESCE(p.is_medical, 0) = 0`, and its
    // ALTER used to live further down in this same function: an installation arriving from a
    // version without the medical module (no flag `migration_infer_missing_expiry_v1`, no
    // column) reached that SELECT with the column still missing and died with
    // "no such column: p.is_medical" — a boot that fails on every request, not a data problem
    // but indistinguishable from one for the household. Cheap and idempotent, so it runs here.
    $prodColsMedical = array_column($db->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    foreach ([
        'is_medical'     => 'INTEGER NOT NULL DEFAULT 0',
        'med_atc'        => "TEXT DEFAULT ''",
        'med_ingredient' => "TEXT DEFAULT ''",
        'med_pao_days'   => 'INTEGER DEFAULT NULL',
        'med_tags'       => "TEXT DEFAULT ''",
    ] as $medCol => $medType) {
        if (in_array($medCol, $prodColsMedical, true)) {
            continue;
        }
        try {
            $db->exec("ALTER TABLE products ADD COLUMN {$medCol} {$medType}");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column') === false) {
                throw $e;
            }
        }
    }

    // Migration: a GENRE is never a CLASS. The AI once answered «Integratore alimentare» as the
    // genre for a supplement, the kind pipeline took it and prefixed the title — so a box of
    // glycerol read «Integratore alimentare glicerolo CARLO ERBA» and stored the class in
    // `products.kind`, which is a filter in no list. The class is now in `umbrella_labels`
    // (vocab DATA), so the pipeline refuses it and strips it; this pass heals the titles it
    // already wrote, with the same two functions the medical branch runs (`migrateDB` cannot
    // wait for somebody to press "apply auto rules"). A title that does not start with an
    // umbrella label is untouched.
    //
    // `kind` must exist BEFORE the pass reads it: `initializeDB()` now creates it, and this
    // guard keeps a database written by an older schema safe. Without it a brand-new
    // installation (and every demo database, created from scratch) died here on
    // «no such column: kind» before the ALTER that lives further down could run.
    $prodColsKind = array_column($db->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    if (!in_array('kind', $prodColsKind, true)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN kind TEXT DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    $kindClassHealed = $db->query("SELECT value FROM app_settings WHERE key = 'migration_strip_kind_class_prefix_v1'")->fetchColumn();
    if (!$kindClassHealed) {
        require_once __DIR__ . '/lib/product_kind.php';
        $classRows = $db->query("SELECT id, name, COALESCE(kind, '') AS kind FROM products")->fetchAll(PDO::FETCH_ASSOC);
        $classHealed = 0;
        $updKind = $db->prepare('UPDATE products SET name = ?, kind = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        foreach ($classRows as $classRow) {
            $oldTitle = (string)($classRow['name'] ?? '');
            $oldGenre = (string)($classRow['kind'] ?? '');
            $strippedTitle = productKindStripUmbrellaCategoryPrefix($oldTitle);
            $newGenre = ($oldGenre !== '' && !productKindIsPlausibleGenre($oldGenre)) ? '' : $oldGenre;
            if ($strippedTitle === $oldTitle && $newGenre === $oldGenre) {
                continue;
            }
            $newTitle = $strippedTitle !== '' ? productTitleCapitalize($strippedTitle) : $oldTitle;
            $updKind->execute([$newTitle, $newGenre, (int)$classRow['id']]);
            $classHealed++;
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_strip_kind_class_prefix_v1', '1')");
        if ($classHealed > 0 && class_exists('EverLog')) {
            EverLog::info('Titles healed from a class prefix', [
                'event' => 'kind_class_prefix_stripped',
                'rows'  => $classHealed,
            ]);
        }
    }

    // Migration: rows that arrived with NO best-before date at all ("senza scadenza").
    // A row without a date never enters "in scadenza" and the pantry can never warn about
    // it, so a whole shopping trip could sit on the shelf unmonitored. Every new write is
    // dated at the choke point (addToInventory → inventoryInferExpiryDate, stored as an
    // ESTIMATE with expiry_user_set = 0); this pass dates the rows already stored, with the
    // same rule (`estimateSealedExpiryDaysPHP` on the KIND of place they sit in). A medicine
    // is skipped: its box prints its own date and inventing one would be a safety claim.
    $expiryInferred = $db->query("SELECT value FROM app_settings WHERE key = 'migration_infer_missing_expiry_v1'")->fetchColumn();
    if (!$expiryInferred) {
        $rows = $db->query(
            "SELECT i.id, i.location, i.added_at, p.name, p.category
               FROM inventory i
               JOIN products p ON p.id = i.product_id
              WHERE i.expiry_date IS NULL
                AND COALESCE(p.is_medical, 0) = 0"
        )->fetchAll(PDO::FETCH_ASSOC);
        $updExpiry = $db->prepare('UPDATE inventory SET expiry_date = ?, expiry_user_set = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $dated = 0;
        foreach ($rows as $row) {
            $days = max(1, estimateSealedExpiryDaysPHP(
                (string)($row['name'] ?? ''),
                (string)($row['category'] ?? ''),
                (string)($row['location'] ?? '')
            ));
            // ANCHORED ON THE DAY THE ROW ENTERED THE PANTRY, and a non-perishable stays without a
            // date. `today + days` dated four rows of salt added in March as "expires 2027-11-07"
            // (the day this pass ran plus a year), which the household rightly read as "the dates
            // changed by themselves"; and the 9999 sentinel became the year 2054.
            $expiry = expiryDateFromDays($days, (string)($row['added_at'] ?? ''));
            if ($expiry === null) continue;
            $updExpiry->execute([$expiry, (int)$row['id']]);
            $dated++;
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_infer_missing_expiry_v1', '1')");
        if ($dated > 0 && class_exists('EverLog')) {
            EverLog::info('Dated the inventory rows stored without an expiry', [
                'event' => 'expiry_inferred_backfill',
                'rows'  => $dated,
            ]);
        }
    }

    // Migration: a SINGLE-DOSE container raises no clock, and a box of them raises even less.
    // The rule in `data/medical_pao.json` said «1 giorno» for fiala/monodose/flaconcino, so
    // `med_classify` stored 1 on every such product — and a 6-container box of glycerol
    // (notes: «6 CONTENITORI MONODOSE», 4 pz left, printed date 2028) then read «Aperto da 15 gg
    // · da usare entro 1 gg dall'apertura»: a sealed box declared expired two weeks ago. The
    // data now answers 0; here the STORED value that is exactly the old derived one is
    // re-derived through the same data file (a number the household typed is never touched:
    // only the single value the old rule could produce, and only when the form now says 0).
    $medPaoSingleDose = $db->query("SELECT value FROM app_settings WHERE key = 'migration_med_pao_single_dose_v1'")->fetchColumn();
    if (!$medPaoSingleDose) {
        require_once __DIR__ . '/lib/medical.php';
        $singleDoseRows = $db->query(
            "SELECT id, name, COALESCE(notes, '') AS notes
               FROM products
              WHERE COALESCE(is_medical, 0) = 1
                AND COALESCE(med_pao_days, 0) = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        $singleDoseReset = 0;
        $updMedPao = $db->prepare('UPDATE products SET med_pao_days = 0 WHERE id = ?');
        foreach ($singleDoseRows as $singleDoseRow) {
            if (medicalPaoDaysForName((string)$singleDoseRow['name'], (string)$singleDoseRow['notes']) === 0) {
                $updMedPao->execute([(int)$singleDoseRow['id']]);
                $singleDoseReset++;
            }
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_med_pao_single_dose_v1', '1')");
        if ($singleDoseReset > 0 && class_exists('EverLog')) {
            EverLog::info('Medicine PAO: single-dose containers no longer raise a clock', [
                'event' => 'med_pao_single_dose_reset',
                'rows'  => $singleDoseReset,
            ]);
        }
    }

    // Migration: a shopping generic that is only a CONTAINER ("Frutta", "Verdura",
    // "Latticini"). The title choke point makes every product lead with its genre, and
    // the shopping-name extractor took the first word — so the list was titled with the
    // shelf, not with the thing to buy. New writes are refined in computeShoppingName();
    // this pass repairs the rows already stored. Products whose name gives no better
    // word are left alone: a brand is not a generic.
    $genericsFixed = $db->query("SELECT value FROM app_settings WHERE key = 'migration_shopping_generic_v1'")->fetchColumn();
    if (!$genericsFixed) {
        require_once __DIR__ . '/lib/product_kind.php';
        $heads = productKindContainerHeads();
        $renamed = 0;
        if ($heads !== []) {
            $rows = $db->query("SELECT id, name, shopping_name FROM products
                                WHERE shopping_name IS NOT NULL AND TRIM(shopping_name) != ''")
                       ->fetchAll(PDO::FETCH_ASSOC);
            $updGeneric = $db->prepare('UPDATE products SET shopping_name = ? WHERE id = ?');
            foreach ($rows as $row) {
                if (!productKindIsContainerHead((string)$row['shopping_name'])) {
                    continue;
                }
                $better = productKindShoppingGeneric((string)$row['name']);
                if ($better === '' || mb_strtolower($better) === mb_strtolower((string)$row['shopping_name'])) {
                    continue;
                }
                $updGeneric->execute([$better, (int)$row['id']]);
                $renamed++;
            }
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_shopping_generic_v1', '1')");
        if ($renamed > 0 && class_exists('EverLog')) {
            EverLog::info('Repaired shopping generics that were only a container word', [
                'event' => 'shopping_generic_refined',
                'products' => $renamed,
            ]);
        }
    }

    // v2 of the same repair: the second pass also handles a generic that is only a
    // container word but whose title gives food words instead of a dictionary genre
    // ("Cereali muesli croccante…" → "Muesli croccante"), and it reaches the rows
    // already sitting in the shopping list, which kept the old title.
    $genericsV2 = $db->query("SELECT value FROM app_settings WHERE key = 'migration_shopping_generic_v2'")->fetchColumn();
    if (!$genericsV2) {
        require_once __DIR__ . '/lib/product_kind.php';
        $renamedV2 = 0;
        if (productKindContainerHeads() !== []) {
            $rows = $db->query("SELECT id, name, brand, shopping_name FROM products
                                WHERE shopping_name IS NOT NULL AND TRIM(shopping_name) != ''")
                       ->fetchAll(PDO::FETCH_ASSOC);
            $upd = $db->prepare('UPDATE products SET shopping_name = ? WHERE id = ?');
            foreach ($rows as $row) {
                if (!productKindIsContainerHead((string)$row['shopping_name'])) {
                    continue;
                }
                $better = productKindShoppingGeneric((string)$row['name'], (string)($row['brand'] ?? ''));
                if ($better === '' || mb_strtolower($better) === mb_strtolower((string)$row['shopping_name'])) {
                    continue;
                }
                $upd->execute([$better, (int)$row['id']]);
                $renamedV2++;
            }
            try {
                $listRows = $db->query("SELECT id, name, raw_name FROM shopping_list")->fetchAll(PDO::FETCH_ASSOC);
                $updList = $db->prepare('UPDATE shopping_list SET name = ? WHERE id = ?');
                foreach ($listRows as $row) {
                    if (!productKindIsContainerHead((string)$row['name'])) {
                        continue;
                    }
                    $raw = trim((string)($row['raw_name'] ?? ''));
                    if ($raw === '') {
                        continue;
                    }
                    $better = productKindShoppingGeneric($raw);
                    if ($better === '' || mb_strtolower($better) === mb_strtolower((string)$row['name'])) {
                        continue;
                    }
                    // The list is keyed by name: a better title may already be there
                    // ("Peperone" next to "Verdura peperone"). When it is the SAME product
                    // (same raw_name) the container row is a leftover of the old naming and
                    // is dropped; otherwise the row stays as it is.
                    try {
                        $updList->execute([$better, (int)$row['id']]);
                        $renamedV2++;
                    } catch (Throwable $e) {
                        try {
                            $dupStmt = $db->prepare('SELECT id, raw_name FROM shopping_list WHERE name = ? LIMIT 1');
                            $dupStmt->execute([$better]);
                            $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
                            if ($dup && mb_strtolower(trim((string)($dup['raw_name'] ?? ''))) === mb_strtolower($raw)) {
                                $db->prepare('DELETE FROM shopping_list WHERE id = ?')->execute([(int)$row['id']]);
                                $renamedV2++;
                            }
                        } catch (Throwable $inner) {
                            // Leave the row alone: a half-repaired list is worse than an old title.
                        }
                        continue;
                    }
                }
            } catch (Throwable $e) {
                // A shopping_list that does not exist yet is not a reason to fail the boot.
            }
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_shopping_generic_v2', '1')");
        if ($renamedV2 > 0 && class_exists('EverLog')) {
            EverLog::info('Second pass on container-word shopping generics', [
                'event' => 'shopping_generic_refined_v2',
                'rows' => $renamedV2,
            ]);
        }
    }

    // v3: a ONE-WORD generic that truncates the product ("Petto" for "Petto di pollo a
    // fette"). The complement after the connector is part of what the household buys.
    $genericsV3 = $db->query("SELECT value FROM app_settings WHERE key = 'migration_shopping_generic_v3'")->fetchColumn();
    if (!$genericsV3) {
        require_once __DIR__ . '/lib/product_kind.php';
        $renamedV3 = 0;
        $rows = $db->query("SELECT id, name, shopping_name FROM products
                            WHERE shopping_name IS NOT NULL AND TRIM(shopping_name) != ''
                              AND TRIM(shopping_name) NOT LIKE '% %'")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $upd3 = $db->prepare('UPDATE products SET shopping_name = ? WHERE id = ?');
        foreach ($rows as $row) {
            $better = productKindShoppingPhrase((string)$row['name']);
            if ($better === '' || mb_strtolower($better) === mb_strtolower((string)$row['shopping_name'])) {
                continue;
            }
            $upd3->execute([$better, (int)$row['id']]);
            $renamedV3++;
        }
        try {
            $listRows = $db->query("SELECT id, name, raw_name FROM shopping_list
                                    WHERE TRIM(name) NOT LIKE '% %'")->fetchAll(PDO::FETCH_ASSOC);
            $updList3 = $db->prepare('UPDATE shopping_list SET name = ? WHERE id = ?');
            foreach ($listRows as $row) {
                $raw = trim((string)($row['raw_name'] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $better = productKindShoppingPhrase($raw);
                if ($better === '' || mb_strtolower($better) === mb_strtolower((string)$row['name'])) {
                    continue;
                }
                try {
                    $updList3->execute([$better, (int)$row['id']]);
                    $renamedV3++;
                } catch (Throwable $e) {
                    // Same product already listed under the longer title: drop the stump.
                    try {
                        $dupStmt = $db->prepare('SELECT id, raw_name FROM shopping_list WHERE name = ? LIMIT 1');
                        $dupStmt->execute([$better]);
                        $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
                        if ($dup && mb_strtolower(trim((string)($dup['raw_name'] ?? ''))) === mb_strtolower($raw)) {
                            $db->prepare('DELETE FROM shopping_list WHERE id = ?')->execute([(int)$row['id']]);
                            $renamedV3++;
                        }
                    } catch (Throwable $inner) {
                        // leave it alone
                    }
                }
            }
        } catch (Throwable $e) {
            // no shopping_list yet
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_shopping_generic_v3', '1')");
        if ($renamedV3 > 0 && class_exists('EverLog')) {
            EverLog::info('Completed truncated one-word shopping generics', [
                'event' => 'shopping_generic_refined_v3',
                'rows' => $renamedV3,
            ]);
        }
    }

    // v4: one number per buyable generic. The list carried "Peperone" AND "Peperoni",
    // "Patata" AND "Patate", "Gnocco" AND "Gnocchi" — two rows of the same vegetable,
    // because the pantry title is singular and the shopping word was copied from it.
    // Every shopping generic now goes to the PLURAL the language declares (a mass noun
    // is untouched), and the rows that differ only by number are merged.
    $genericsV4 = $db->query("SELECT value FROM app_settings WHERE key = 'migration_shopping_generic_v4'")->fetchColumn();
    if (!$genericsV4) {
        require_once __DIR__ . '/lib/product_kind.php';
        $renamedV4 = 0;
        $mergedV4 = 0;
        $rows = $db->query("SELECT id, shopping_name FROM products
                            WHERE shopping_name IS NOT NULL AND TRIM(shopping_name) != ''")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $upd4 = $db->prepare('UPDATE products SET shopping_name = ? WHERE id = ?');
        foreach ($rows as $row) {
            $better = productKindShoppingPlural((string)$row['shopping_name']);
            if ($better === (string)$row['shopping_name']) {
                continue;
            }
            $upd4->execute([$better, (int)$row['id']]);
            $renamedV4++;
        }
        try {
            $listRows = $db->query("SELECT id, name, need_qty, need_unit FROM shopping_list")
                           ->fetchAll(PDO::FETCH_ASSOC);
            $updList4 = $db->prepare('UPDATE shopping_list SET name = ? WHERE id = ?');
            $findDup  = $db->prepare('SELECT id, need_qty, need_unit FROM shopping_list WHERE name = ? LIMIT 1');
            $delRow   = $db->prepare('DELETE FROM shopping_list WHERE id = ?');
            $bumpDup  = $db->prepare('UPDATE shopping_list SET need_qty = ?, need_unit = ? WHERE id = ?');
            foreach ($listRows as $row) {
                $better = productKindShoppingPlural((string)$row['name']);
                if ($better === (string)$row['name']) {
                    continue;
                }
                try {
                    $updList4->execute([$better, (int)$row['id']]);
                    $renamedV4++;
                } catch (Throwable $e) {
                    // The plural row is already there: merge the numbers when they speak
                    // the same unit, then drop the duplicate. Same word = same family.
                    $findDup->execute([$better]);
                    $dup = $findDup->fetch(PDO::FETCH_ASSOC);
                    if (!$dup) {
                        continue;
                    }
                    $q1 = (float)($row['need_qty'] ?? 0);
                    $u1 = trim((string)($row['need_unit'] ?? ''));
                    $q2 = (float)($dup['need_qty'] ?? 0);
                    $u2 = trim((string)($dup['need_unit'] ?? ''));
                    if ($q1 > 0 && $q2 > 0 && ($u1 === $u2 || $u1 === '' || $u2 === '')) {
                        $bumpDup->execute([$q1 + $q2, $u1 !== '' ? $u1 : $u2, (int)$dup['id']]);
                    }
                    $delRow->execute([(int)$row['id']]);
                    $mergedV4++;
                }
            }
        } catch (Throwable $e) {
            // no shopping_list yet
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_shopping_generic_v4', '1')");
        if (($renamedV4 + $mergedV4) > 0 && class_exists('EverLog')) {
            EverLog::info('Shopping generics moved to plural, singular duplicates merged', [
                'event' => 'shopping_generic_plural_v4',
                'renamed' => $renamedV4,
                'merged' => $mergedV4,
            ]);
        }
    }

    // v5: recompute EVERY stored generic with the current pipeline.
    //
    // The previous passes patched names one rule at a time, and each rule could leave an
    // artifact behind: the phrase rule turned "Latte di Montagna" into "Latte di montagna",
    // which split milk into one family per brand — so the list asked for milk the pantry
    // already had. Here the canonical generic is rebuilt from the product name
    // (dictionary → specific generic → phrase → plural) and the list rows are merged onto it.
    $genericsV5 = $db->query("SELECT value FROM app_settings WHERE key = 'migration_shopping_generic_v5'")->fetchColumn();
    if (!$genericsV5) {
        require_once __DIR__ . '/lib/product_kind.php';
        $renamedV5 = 0;
        $mergedV5 = 0;
        $rows = $db->query("SELECT id, name, brand, shopping_name FROM products")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $upd5 = $db->prepare('UPDATE products SET shopping_name = ? WHERE id = ?');
        $alias = [];   // old generic (lowercased) → new one, to repair the list rows too
        foreach ($rows as $row) {
            $name = trim((string)$row['name']);
            if ($name === '') {
                continue;
            }
            $canonical = computeShoppingNameCanonical($name, (string)($row['brand'] ?? ''));
            $current = trim((string)($row['shopping_name'] ?? ''));
            if ($canonical === '') {
                continue;
            }
            if ($current !== '' && mb_strtolower($current) !== mb_strtolower($canonical)) {
                $alias[mb_strtolower($current)] = $canonical;
            }
            if ($current === $canonical) {
                continue;
            }
            $upd5->execute([$canonical, (int)$row['id']]);
            $renamedV5++;
        }
        try {
            $listRows = $db->query("SELECT id, name, raw_name, need_qty, need_unit FROM shopping_list")
                           ->fetchAll(PDO::FETCH_ASSOC);
            $updList5 = $db->prepare('UPDATE shopping_list SET name = ? WHERE id = ?');
            $findDup5 = $db->prepare('SELECT id, need_qty, need_unit FROM shopping_list WHERE lower(name) = lower(?) LIMIT 1');
            $bumpDup5 = $db->prepare('UPDATE shopping_list SET need_qty = ?, need_unit = ? WHERE id = ?');
            $delRow5  = $db->prepare('DELETE FROM shopping_list WHERE id = ?');
            foreach ($listRows as $row) {
                $current = trim((string)$row['name']);
                if ($current === '') {
                    continue;
                }
                $target = $alias[mb_strtolower($current)] ?? '';
                if ($target === '' || mb_strtolower($target) === mb_strtolower($current)) {
                    continue;
                }
                $findDup5->execute([$target]);
                $dup = $findDup5->fetch(PDO::FETCH_ASSOC);
                if (!$dup) {
                    $updList5->execute([$target, (int)$row['id']]);
                    $renamedV5++;
                    continue;
                }
                $q1 = (float)($row['need_qty'] ?? 0);
                $u1 = trim((string)($row['need_unit'] ?? ''));
                $q2 = (float)($dup['need_qty'] ?? 0);
                $u2 = trim((string)($dup['need_unit'] ?? ''));
                if ($q1 > 0 && $q2 > 0 && ($u1 === $u2 || $u1 === '' || $u2 === '')) {
                    $bumpDup5->execute([$q1 + $q2, $u1 !== '' ? $u1 : $u2, (int)$dup['id']]);
                }
                $delRow5->execute([(int)$row['id']]);
                $mergedV5++;
            }
        } catch (Throwable $e) {
            // no shopping_list yet
        }
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_shopping_generic_v5', '1')");
        if (($renamedV5 + $mergedV5) > 0 && class_exists('EverLog')) {
            EverLog::info('Shopping generics recomputed with the full pipeline', [
                'event' => 'shopping_generic_recompute_v5',
                'renamed' => $renamedV5,
                'merged' => $mergedV5,
            ]);
        }
    }

    $invCols = $db->query("PRAGMA table_info(inventory)")->fetchAll();
    $invColNames = array_column($invCols, 'name');
    if (!in_array('expiry_user_set', $invColNames)) {
        try { $db->exec("ALTER TABLE inventory ADD COLUMN expiry_user_set INTEGER DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS barcode_cache (
        barcode TEXT PRIMARY KEY,
        found INTEGER NOT NULL DEFAULT 0,
        source TEXT,
        payload TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    require_once __DIR__ . '/lib/barcode_catalog.php';
    barcodeCatalogEnsureTable($db);

    // The local medicine catalogue (AIFA open data). Created empty on every boot: the rows are
    // put there by `scripts/import-medicines.php --country=IT`, never by a network call from the app.
    require_once __DIR__ . '/lib/medicines.php';
    medicinesEnsureTable($db);

    $db->exec("CREATE TABLE IF NOT EXISTS shopping_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        items_json TEXT NOT NULL DEFAULT '[]',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Browser Web Push subscriptions (empty-payload wake + inbox fetch).
    require_once __DIR__ . '/lib/webpush.php';
    evershelfWebPushEnsureTable($db);

    // Custom product tags (#97): free-form labels on the catalogue. Relational
    // tables, not a comma-separated column, so filtering by tag is an index
    // lookup and renaming a tag is one row.
    require_once __DIR__ . '/lib/tags.php';
    tagsEnsureTables($db);

    // The household's own PAO (period after opening), edited from the pantry edit sheet and
    // averaged per family + place. Empty on a fresh install: the data table keeps answering.
    require_once __DIR__ . '/lib/pao_overrides.php';
    paoEnsureTable($db);

    // Migrate transactions CHECK constraint to allow 'waste' type
    $sql = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='transactions'")->fetchColumn();
    if ($sql && strpos($sql, "'waste'") === false) {
        $db->exec("ALTER TABLE transactions RENAME TO transactions_old");
        $db->exec("
            CREATE TABLE transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('in', 'out', 'waste')),
                quantity REAL NOT NULL,
                location TEXT NOT NULL DEFAULT 'dispensa',
                notes TEXT DEFAULT '',
                undone INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            )
        ");
        // Insert with explicit columns: transactions_old may lack 'undone' (pre-v1.7.x DB)
        $db->exec("INSERT INTO transactions (id, product_id, type, quantity, location, notes, created_at)
                   SELECT id, product_id, type, quantity, location, notes, created_at FROM transactions_old");
        $db->exec("DROP TABLE transactions_old");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_product ON transactions(product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_type_date ON transactions(type, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_pid_type_undone ON transactions(product_id, type, undone)");
    }

    // --- New shared tables ---
    // app_settings: key-value store shared across all devices.
    // FIRST, before any migration reads it: on a brand-new install nothing has created this
    // table yet, and the title/marker migrations below query it — a fresh instance failed with
    // "no such table: app_settings" and served HTTP 500 on every request.
    evershelfEnsureAppSettingsTable($db);

    // recipes archive: multiple uncategorized (libero) per day; one row per scheduled meal slot per day
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='recipes'")->fetchAll();
    if (empty($tables)) {
        $db->exec("
            CREATE TABLE recipes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL,
                meal TEXT NOT NULL,
                recipe_json TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_favorite INTEGER NOT NULL DEFAULT 0,
                rating INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX idx_recipes_date ON recipes(date);
        ");
    }

    // chat_messages: shared chat history
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='chat_messages'")->fetchAll();
    if (empty($tables)) {
        $db->exec("
            CREATE TABLE chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role TEXT NOT NULL,
                text TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    // Add vacuum_sealed column to inventory if missing
    $invCols = $db->query("PRAGMA table_info(inventory)")->fetchAll();
    $invColNames = array_column($invCols, 'name');
    if (!in_array('vacuum_sealed', $invColNames)) {
        $db->exec("ALTER TABLE inventory ADD COLUMN vacuum_sealed INTEGER DEFAULT 0");
    }

    // Add opened_at column to inventory if missing
    if (!in_array('opened_at', $invColNames)) {
        $db->exec("ALTER TABLE inventory ADD COLUMN opened_at DATETIME DEFAULT NULL");
        // Backfill: detect already-opened fridge items and set opened_at.
        // Only frigo items — pantry/freezer fractional quantities don't imply opened.
        backfillOpenedItems($db);
    }

    // Freezer lifecycle (#286): the ISO timestamp of the freeze and the best-before date
    // the pack had BEFORE it. The frozen state owns `expiry_date` (today + the category's
    // freezer days), so every reader — dashboard, ICS feed, cron, PAO — keeps reading ONE
    // column and cannot disagree; thawing gives the original date back, limited by the
    // 48 h window. Both nullable: a row that was never frozen carries no state.
    if (!in_array('frozen_at', $invColNames)) {
        $db->exec("ALTER TABLE inventory ADD COLUMN frozen_at DATETIME DEFAULT NULL");
    }
    if (!in_array('pre_frozen_expiry', $invColNames)) {
        $db->exec("ALTER TABLE inventory ADD COLUMN pre_frozen_expiry DATE DEFAULT NULL");
    }

    // Migration: undo incorrect backfill for non-frigo items.
    // The original backfill also tagged dispensa/freezer items as opened, which overwrote
    // their manufacturer expiry_date with a short estimated value.  Clear opened_at so they
    // return to the sealed section; clear expiry_date so users can re-enter the real date.
    $migDone = $db->query("SELECT value FROM app_settings WHERE key = 'migration_fix_nonfrigo_opened_v1'")->fetchColumn();
    if (!$migDone) {
        $db->exec("UPDATE inventory SET opened_at = NULL, expiry_date = NULL
                   WHERE location NOT IN ('frigo') AND opened_at IS NOT NULL");
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value)
                   VALUES ('migration_fix_nonfrigo_opened_v1', '1')");
    }

    // Migration v2: recalculate sealed fridge item expiry (fridge extends shelf life)
    $migrated = $db->query("SELECT value FROM app_settings WHERE key = 'migration_fridge_expiry_v1'")->fetchColumn();
    if (!$migrated) {
        recalcSealedFridgeExpiry($db);
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('migration_fridge_expiry_v1', '1')");
    }

    // Add undone column to transactions if missing
    $txCols = $db->query("PRAGMA table_info(transactions)")->fetchAll();
    $txColNames = array_column($txCols, 'name');
    if (!in_array('undone', $txColNames)) {
        $db->exec("ALTER TABLE transactions ADD COLUMN undone INTEGER DEFAULT 0");
    }

    // Ensure composite indexes exist (added in v1.7.5 for performance)
    $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_type_date ON transactions(type, created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_pid_type_undone ON transactions(product_id, type, undone)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_inventory_expiry ON inventory(expiry_date)");

    // Internal shopping list table (v1.8.0) — used when SHOPPING_MODE=internal
    $shopTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='shopping_list'")->fetchAll();
    if (empty($shopTables)) {
        $db->exec("
            CREATE TABLE shopping_list (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT NOT NULL,
                raw_name      TEXT NOT NULL DEFAULT '',
                specification TEXT NOT NULL DEFAULT '',
                added_at      INTEGER DEFAULT (strftime('%s','now')),
                sort_order    INTEGER DEFAULT 0
            )
        ");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_shopping_list_name ON shopping_list(lower(name))");
    }
    $shopCols = array_column($db->query("PRAGMA table_info(shopping_list)")->fetchAll(), 'name');
    foreach ([
        'need_qty'   => 'REAL',
        'need_unit'  => 'TEXT NOT NULL DEFAULT \'\'',
        'is_checked' => 'INTEGER NOT NULL DEFAULT 0',
    ] as $col => $type) {
        if (!in_array($col, $shopCols, true)) {
            try {
                $db->exec("ALTER TABLE shopping_list ADD COLUMN {$col} {$type}");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'duplicate column') === false) {
                    throw $e;
                }
            }
        }
    }

    // Add is_favorite column to recipes if missing (#124)
    $recCols = array_column($db->query("PRAGMA table_info(recipes)")->fetchAll(), 'name');
    if (!in_array('is_favorite', $recCols)) {
        try { $db->exec("ALTER TABLE recipes ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // Add rating column to recipes if missing: the household's own 0–5 vote (0 = not rated).
    // A separate scale from the favourite star on purpose — "I like this" and "this was good"
    // are two different statements, and a rating is a number the book can be filtered by.
    $recRatingCols = array_column($db->query("PRAGMA table_info(recipes)")->fetchAll(), 'name');
    if (!in_array('rating', $recRatingCols)) {
        try { $db->exec("ALTER TABLE recipes ADD COLUMN rating INTEGER NOT NULL DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // Allow many chat/archive imports per day (meal=libero); keep one row per scheduled slot (pranzo/cena/…)
    $recSql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='recipes'")->fetchColumn();
    if ($recSql !== '' && str_contains($recSql, 'UNIQUE(date, meal)')) {
        // SQLite commits DDL implicitly — do not wrap in an explicit transaction.
        $db->exec("
            CREATE TABLE recipes_archive_mig (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL,
                meal TEXT NOT NULL,
                recipe_json TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_favorite INTEGER NOT NULL DEFAULT 0
            )
        ");
        $db->exec("
            INSERT INTO recipes_archive_mig (id, date, meal, recipe_json, created_at, is_favorite)
            SELECT id, date, meal, recipe_json, created_at, COALESCE(is_favorite, 0) FROM recipes
        ");
        $db->exec('DROP TABLE recipes');
        $db->exec('ALTER TABLE recipes_archive_mig RENAME TO recipes');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_recipes_date ON recipes(date)');
    }
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_recipes_date_meal_slot ON recipes(date, meal) WHERE meal IN ('colazione', 'pranzo', 'merenda', 'cena', 'dolce', 'succo')");

    // Content hash: one archived recipe body = one row (chat re-saves / ingredient "used" flags must not clone)
    $recColsHash = array_column($db->query("PRAGMA table_info(recipes)")->fetchAll(), 'name');
    if (!in_array('content_hash', $recColsHash, true)) {
        try { $db->exec("ALTER TABLE recipes ADD COLUMN content_hash TEXT NOT NULL DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    recipesBackfillAndDedupByHash($db);
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_recipes_content_hash ON recipes(content_hash) WHERE content_hash != ''");

    // Pin favourite products at top of inventory (#98)
    $prodFavCols = array_column($db->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('is_favorite', $prodFavCols)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // Add nutriments_json column to products if missing (#118)
    $prodCols2 = array_column($db->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('nutriments_json', $prodCols2)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN nutriments_json TEXT DEFAULT NULL"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // EverShelf Med (opt-in): a product can be a medicine. The flag keeps food and drugs in
    // separate domains even when the module is switched on later — a row already in the
    // "Armadietto Farmaci" location is recognised from the location too.
    if (!in_array('is_medical', $prodCols2)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN is_medical INTEGER NOT NULL DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    // ATC group + active ingredient: typed by the household (the AIFA catalogue is a later
    // import), so "what do I have for a headache?" can be answered without any guessing.
    if (!in_array('med_atc', $prodCols2)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN med_atc TEXT DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    if (!in_array('med_ingredient', $prodCols2)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN med_ingredient TEXT DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    // Phase 1 taxonomy: a CLOSED set of household categories (comma separated slugs from
    // data/medical_categories.json) — never free text, or the offline search would rot.
    if (!in_array('med_tags', $prodCols2)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN med_tags TEXT DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    // "Once opened it expires after N days" (syrups, eye drops, creams): the operational
    // expiry replays from this number, no external API and no guessed shelf life.
    if (!in_array('med_pao_days', $prodCols2)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN med_pao_days INTEGER DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // User-locked product title/brand — rescans must not overwrite with Open Food Facts names
    $prodCols3 = array_column($db->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('name_user_set', $prodCols3)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN name_user_set INTEGER DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // Product genre (genere) resolved on save — leads the article title and lets the
    // maintenance action re-apply rules without another AI round-trip (lib/product_kind.php).
    $prodCols4 = array_column($db->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('kind', $prodCols4)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN kind TEXT DEFAULT ''"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }
    // Set when the user unstars a favourite by hand: the automatic promotion
    // (lib/auto_favorite.php) must never re-add what they removed on purpose.
    if (!in_array('favorite_user_override', $prodCols4)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN favorite_user_override INTEGER DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // Fuel Mode / Health Bridge snapshots (#fuel)
    require_once __DIR__ . '/lib/health.php';
    healthEnsureTables($db);
    // Repair: undo used to mark original undone=1 AND insert a compensating [Undone]
    // tx, which double-counted in ledger balance (ghost fractions like 0.233 conf).
    $undoRepair = $db->query("SELECT value FROM app_settings WHERE key = 'migration_undone_double_count_v1'")->fetchColumn();
    if (!$undoRepair) {
        $db->exec("
            UPDATE transactions
            SET undone = 1
            WHERE undone = 0
              AND notes = '[Undone]'
              AND EXISTS (
                SELECT 1 FROM transactions o
                WHERE o.product_id = transactions.product_id
                  AND o.undone = 1
                  AND o.id < transactions.id
                  AND abs(o.quantity - transactions.quantity) < 0.0001
                  AND (
                    (o.type IN ('out','waste') AND transactions.type = 'in')
                    OR (o.type = 'in' AND transactions.type IN ('out','waste'))
                  )
                  AND (julianday(transactions.created_at) - julianday(o.created_at)) * 86400.0 BETWEEN -60 AND 3600
              )
        ");
        $db->exec("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('migration_undone_double_count_v1', '1')");
    }
}

/**
 * Backfill opened_at for frigo items that appear to be opened.
 * An item is considered opened if:
 *  - conf unit with fractional quantity
 *  - weight/volume unit (g,kg,ml,l) with quantity < default_quantity
 * Uses updated_at as the approximate opened_at date.
 * Does NOT overwrite expiry_date — the manufacturer date is preserved;
 * getStats computes opened expiry on-the-fly from opened_at.
 *
 * Only frigo items: pantry/freezer fractional quantities are normal
 * (e.g. 3 of 6 UHT milks) and do not indicate a food-safety expiry change.
 */
function backfillOpenedItems(PDO $db): void {
    $stmt = $db->query("
        SELECT i.id, i.quantity, i.location, i.updated_at, i.expiry_date, i.vacuum_sealed,
               p.name, p.category, p.unit, p.default_quantity
        FROM inventory i
        JOIN products p ON i.product_id = p.id
        WHERE i.quantity > 0 AND i.location = 'frigo'
    ");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $isOpened = false;
        $unit = $row['unit'] ?: 'pz';
        $qty = (float)$row['quantity'];
        $defQty = (float)($row['default_quantity'] ?: 0);

        if ($unit === 'conf') {
            $frac = $qty - floor($qty + 0.001);
            if ($frac > 0.001) $isOpened = true;
        } elseif (in_array($unit, ['g','kg','ml','l']) && $defQty > 0 && $qty < $defQty - 0.001) {
            $isOpened = true;
        }

        if (!$isOpened) continue;

        // Only set opened_at — do NOT touch expiry_date (manufacturer date is preserved)
        $upd = $db->prepare("UPDATE inventory SET opened_at = ? WHERE id = ? AND opened_at IS NULL");
        $upd->execute([$row['updated_at'], $row['id']]);
    }
}

/**
 * Estimate shelf life in days for an opened product.
 * Much shorter than sealed shelf life.
 */
function estimateOpenedExpiryDaysPHP(string $name, string $category, string $location): int {
    $n = mb_strtolower($name);
    $cat = mb_strtolower($category);
    $loc = mb_strtolower($location);

    // ── A: Non-perishables — check BEFORE location so dispensa doesn't swallow them ──
    if (preg_match('/\bsale\b|\bsel\s+mar|\bsalt\b/', $n) && !preg_match('/\b(salmone|salame|salsa)\b/', $n)) return 9999;
    if (preg_match('/\bzucchero\b|\bsugar\b/', $n)) return 9999;
    if (preg_match('/\bmiele\b/', $n)) return 9999;
    if (preg_match('/\baceto\b/', $n)) return 9999; // all vinegars
    if (preg_match('/\bbicarbonato\b|\blievito\s+chimico\b/', $n)) return 9999;

    // ── B: High-ABV spirits ──────────────────────────────────────────────
    if (preg_match('/\b(sambuca|rum\b|brandy|whiskey|whisky|vodka|gin\b|grappa|amaro|aperol|campari|limoncello|cognac|porto|marsala|baileys|amaretto|vermouth)\b/', $n)) return 730;

    // ── C: Long-life regardless of location ─────────────────────────────
    if (preg_match('/\b(aroma|estratto|essenza|vanilli|colorante)\b/', $n)) return 730;
    if (preg_match('/\b(t[eè]\b|tea\b|tisana|camomilla|verbena|infuso|rooibos)\b/', $n)) return 730;
    if (preg_match('/\b(caff[eè]|coffee|nespresso)\b/', $n)) return 365;
    if (preg_match('/\bolio\b/', $n)) return 365;
    if (preg_match('/salsa\s+di\s+soia|soy\s*sauce/', $n)) return 90; // soy sauce fine opened anywhere
    // Dry bread products (grated, breadcrumbs) — not fresh bread
    if (preg_match('/grattugiat|pangratt|panko|bread\s*crumb|briciole\s+di\s+pane/', $n)) return 90;
    // Dry goods only outside fridge (uncooked)
    if ($loc !== 'frigo') {
        if (preg_match('/\b(pasta|spaghetti|penne|rigatoni|fusilli|farfalle|tagliatelle|linguine|bucatini|lasagn|tortiglioni)\b/', $n)) return 365;
        if (preg_match('/\b(riso|risotto|orzo|farro|quinoa|couscous)\b/', $n) && !preg_match('/\b(pronto|cotto)\b/', $n)) return 365;
        if (preg_match('/\b(polenta|semola|maizena|amido|farina)\b/', $n)) return 180;
        if (preg_match('/\b(lenticchie|ceci|fagioli|piselli)\b/', $n) && !preg_match('/\b(cotto|vapore|scatola)\b/', $n)) return 365;
    }

    // ── D: Freezer — per-product estimates (USDA/EFSA guidelines) ───────
    if ($loc === 'freezer') {
        // Bread, pastry, dough
        if (preg_match('/\b(pane|bread|toast|brioche|ciabatta|baguette|focaccia|pizza\s*base|impasto)\b/', $n)) return 90;
        if (preg_match('/\b(pasta\s+fresca|gnocchi|ravioli|tortellini|lasagna\s+fresca)\b/', $n)) return 60;
        if (preg_match('/\b(croissant|cornetto|pasticceria|dolce|torta|plumcake|muffin|biscotti)\b/', $n)) return 90;
        // Ice cream / sorbet
        if (preg_match('/\b(gelato|sorbetto|ice\s*cream|ghiacciolo)\b/', $n)) return 365;
        // Fish & seafood — shorter (3–6 months)
        if (preg_match('/\b(salmone|trota|spigola|orata|tonno|merluzzo|baccalà|nasello|sgombro|pesce|calamaro|gambero|gamberetti|polpo|seppia|cozza|vongola|frutti\s+di\s+mare|seafood)\b/', $n)) return 120;
        // Poultry — 9 months
        if (preg_match('/\b(pollo|tacchino|anatra|faraona|petto\s+di\s+pollo|coscia|fesa)\b/', $n)) return 270;
        // Red meat whole cuts — 12 months
        if (preg_match('/\b(manzo|vitello|agnello|maiale|lonza|costata|arrosto|fesa|fettina|bistecca)\b/', $n)) return 365;
        // Ground meat / mince — 3–4 months
        if (preg_match('/\b(macinato|macinata|hamburger|polpette|ragù)\b/', $n)) return 120;
        // Sausage / cured meat frozen
        if (preg_match('/\b(salsiccia|würstel|wurstel|salame|pancetta|speck|prosciutto)\b/', $n)) return 60;
        // Dairy
        if (preg_match('/\b(burro)\b/', $n)) return 270;
        if (preg_match('/\b(panna)\b/', $n)) return 90;
        if (preg_match('/\b(formaggio|mozzarella|ricotta)\b/', $n)) return 90;
        // Vegetables (blanched/processed for freezer)
        if (preg_match('/\b(piselli|fagioli|fagiolini|spinaci|broccoli|cavolfiore|carote|mais|edamame|verdure\s+miste|minestrone)\b/', $n)) return 270;
        // Fruits
        if (preg_match('/\b(fragole|lamponi|mirtilli|more|ciliegia|frutta\s+mista|frutta)\b/', $n)) return 270;
        // Stocks, soups, sauces (already cooked)
        if (preg_match('/\b(brodo|zuppa|minestra|sugo|salsa|passata)\b/', $n)) return 180;
        // Generic freezer fallback
        return 180;
    }

    // ── E: Pantry/dispensa — specific products then generic fallback ─────
    if ($loc !== 'frigo') {
        if (preg_match('/\b(biscott[io]|cookies|wafer|tarall[io]|crackers?)\b/', $n)) return 60;
        if (preg_match('/\b(muesli|cereali|corn\s*flakes|granola|fiocchi)\b/', $n)) return 60;
        if (preg_match('/\b(confettura|marmellata)\b/', $n)) return 90;
        if (preg_match('/\b(nutella|cioccolat)\b/', $n)) return 90;
        if (preg_match('/\bpane\b/', $n)) return 4;
        // Specific jarred tomato sauce in pantry (opened, not refrigerated)
        if (preg_match('/salsa\s+di\s+(pomodoro|pronta)/', $n)) return 5;
        // Dairy opened outside fridge: bad very quickly at room temperature
        if (preg_match('/\bpanna\b/', $n)) return 3;
        if (preg_match('/\b(yogurt|yaourt|yoghurt)\b/', $n)) return 2;
        if (preg_match('/\blatte\b/', $n)) return 1;
        if (preg_match('/\bformaggio\b/', $n)) return 2;
        // Root vegetables / tubers in pantry: sfusi in un sacchetto, durano 3-5 settimane
        if (preg_match('/\b(patata|patate|tubero)\b/', $n)) return 30;
        if (preg_match('/\b(cipolla|cipolle|aglio|scalogno|porro)\b/', $n)) return 30;
        if (preg_match('/\b(carota|carote)\b/', $n)) return 14;
        return 60; // generic pantry fallback
    }

    // ── F: Fridge — short-life perishables ──────────────────────────────
    if (preg_match('/latte\s+(fresco|intero|parzial|scremato)/', $n)) return 3;
    if (preg_match('/latte\s+(uht|a\s+lunga)/', $n)) return 7;
    // Long-life mountain/brand milks stored in pantry before use (UHT)
    if (preg_match('/latte.*(montagna|alta\s+qual|parmalat|granarolo|esselunga|conservaz|microfiltrat)/i', $n)) return 7;
    if (preg_match('/\blatte\b/', $n)) return 7; // generic: default to UHT (most common in IT households)
    if (preg_match('/\b(yogurt|yaourt|yoghurt)\b/', $n)) return 5;
    if (preg_match('/mozzarella|burrata|stracciatella/', $n)) return 3;
    if (preg_match('/philadelphia|spalmabile/', $n)) return 7;
    // Specific hard cheeses that contain 'fresco' in their commercial name (e.g. Asiago fresco)
    // must be matched BEFORE the generic 'formaggio fresco' catch-all
    if (preg_match('/parmigiano|grana|pecorino|provolone|asiago|fontina|emmental|gruyere|scamorza|groviera/', $n)) return 28;
    if (preg_match('/formaggio.*(fresco|ricotta|mascarpone|stracchino|crescenza)/', $n)) return 5;
    if (preg_match('/formaggio/', $n)) return 10;
    if (preg_match('/\bburro\b/', $n)) return 30;
    if (preg_match('/\bpanna\b/', $n)) return 4;
    if (preg_match('/prosciutto\s+cotto|mortadella|wurstel/', $n)) return 5;
    if (preg_match('/prosciutto\s+crudo|salame|bresaola|speck|pancetta|nduja/', $n)) return 7;
    if (preg_match('/\b(pollo|tacchino|maiale|manzo|vitello|agnello)\b/', $n)) return 2;
    if (preg_match('/salmone|tonno\s+fresco|pesce(?!\s+in)/', $n)) return 2;
    if (preg_match('/\b(passata|pelati|polpa|sugo|salsa\s+di\s+pomodoro)\b/', $n)) return 5;
    if (preg_match('/insalata\s+di\s+(riso|pasta|farro|orzo|couscous)/', $n)) return 7;
    if (preg_match('/insalata|rucola|spinaci|lattuga|crescione|germogli/', $n)) return 4;
    if (preg_match('/\b(succo|spremuta)\b/', $n)) return 3;
    if (preg_match('/\b(birra|beer)\b/', $n)) return 3;
    if (preg_match('/\bvino\b/', $n)) return 5;
    if (preg_match('/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/', $n)) return 4;
    // Fruit in fridge (opened pack, not necessarily cut)
    if (preg_match('/\bavocado\b/', $n)) return 3;
    if (preg_match('/\b(fragola|fragole|lampone|lamponi|mirtillo|mirtilli|mora|more)\b/', $n)) return 4;
    if (preg_match('/\b(banana|banane|pesca|pesche|albicocca|albicocche|ciliegia|ciliegie|mango|papaya)\b/', $n)) return 4;
    if (preg_match('/\b(mela|mele|pera|pere|nettarina|prugna|kiwi|ananas|uva|melone|anguria)\b/', $n)) return 5;
    if (preg_match('/\b(arancia|arance|mandarino|mandarini|pompelmo|clementina|limone|limoni)\b/', $n)) return 7;
    // Vegetables in fridge (opened pack)
    if (preg_match('/\b(zucchina|zucchine|melanzana|melanzane|pomodor)\b/', $n)) return 5;
    if (preg_match('/\b(peperone|peperoni)\b/', $n)) return 5;
    if (preg_match('/\b(broccolo|broccoli|cavolfiore|cavolo)\b/', $n)) return 4;
    if (preg_match('/\bsedano\b|\bfinocchio\b/', $n)) return 5;
    if (preg_match('/\b(cipolla|cipolle|cipollotto|scalogno|porro)\b/', $n)) return 6;
    if (preg_match('/\b(carota|carote)\b/', $n)) return 7;
    if (preg_match('/\b(patata|patate|tubero)\b/', $n)) return 4;
    if (preg_match('/\baglio\b/', $n)) return 14;

    // ── F.extra: Bread in fridge (opened) ──────────────────────────────────
    // Thin flatbreads (piadina, crescia, tigella) get mold very quickly
    if (preg_match('/\b(piadina|piadelle?|crescia|tigella)\b/', $n)) return 2;
    // Packaged sliced bread — preservatives help a bit
    if (preg_match('/\b(bauletto|pancarrè|pan\s+carr|tramezzin)\b/', $n)) return 4;
    // Generic bread / sandwich bread in fridge
    if (preg_match('/\bpane\b/', $cat)) return 3;

    // ── G: Fridge condiments — medium shelf-life ─────────────────────────
    if (preg_match('/maionese|mayo|mayon/', $n)) return 90;
    if (preg_match('/\bketchup\b/', $n)) return 90;
    if (preg_match('/\b(senape|mustard)\b/', $n)) return 90;
    if (preg_match('/salsa\s+di\s+soia|soy\s*sauce/', $n)) return 90;
    if (preg_match('/\b(tabasco|worcestershire|sriracha)\b/', $n)) return 180;
    if (preg_match('/confettura|marmellata/', $n)) return 180;
    if (preg_match('/nutella|cioccolat/', $n)) return 60;

    // ── H: Category fallbacks ────────────────────────────────────────────
    if (preg_match('/dairy|latticin/', $cat)) return 5;
    if (preg_match('/meat|carne/', $cat)) return 3;
    if (preg_match('/fish|pesce/', $cat)) return 2;
    if (preg_match('/fruit|frutta/', $cat)) return 7;
    if (preg_match('/verdur|vegetable/', $cat)) return 5;
    if (preg_match('/conserve/', $cat)) return 7;
    if (preg_match('/condimenti|sauce/', $cat)) return 30;
    if (preg_match('/bevand|beverage/', $cat)) return 5;

    return 5; // safe default for fridge
}

/**
 * The STORAGE KIND of a place — pantry, fridge, freezer — from its key or the words in its name.
 *
 * Two ports, one rule: the browser does the same in `storageKindOfLocation()` (app.js), and both
 * read the same short list of words the CSV import already recognises
 * (`inventoryImportNormalizeLocation()`). Shelf life depends on the KIND, never on the name: two
 * pantries are the same pantry, and a place called "Frigo cantina" is a fridge.
 */
function storageKindOfLocationPHP(string $location): string {
    $v = mb_strtolower(trim($location));
    if ($v === '') {
        return 'dispensa';
    }
    if ($v === 'freezer' || preg_match('/freez|congelatore|frost/', $v)) {
        return 'freezer';
    }
    if ($v === 'frigo' || preg_match('/frigo|fridge|refrigerator|frigorifero/', $v)) {
        return 'frigo';
    }
    return 'dispensa';
}

/**
 * Estimate sealed shelf life in days, with fridge/freezer extensions.
 * Mirrors the JS estimateExpiryDays() function.
 */
function estimateSealedExpiryDaysPHP(string $name, string $category, string $location): int {
    $n = mb_strtolower($name);
    $cat = mb_strtolower($category);
    $loc = storageKindOfLocationPHP($location);

    $days = null;

    // Specific product overrides
    if (preg_match('/latte\s+(fresco|intero|parzial|scremato)/', $n)) $days = 7;
    elseif (preg_match('/latte\s+uht|latte\s+a\s+lunga/', $n)) $days = 90;
    elseif (preg_match('/yogurt/', $n)) $days = 21;
    elseif (preg_match('/mozzarella|burrata|stracciatella/', $n)) $days = 5;
    elseif (preg_match('/formaggio\s+(fresco|ricotta|mascarpone|stracchino|crescenza)/', $n)) $days = 10;
    elseif (preg_match('/parmigiano|grana|pecorino|provolone|asiago|fontina|emmental|gruyere|scamorza|groviera/', $n)) $days = 60;
    elseif (preg_match('/burro/', $n)) $days = 60;
    elseif (preg_match('/panna/', $n)) $days = 14;
    elseif (preg_match('/prosciutto\s+cotto|mortadella|wurstel/', $n)) $days = 7;
    elseif (preg_match('/prosciutto\s+crudo|salame|bresaola|speck/', $n)) $days = 30;
    elseif (preg_match('/nduja/', $n)) $days = 90;
    elseif (preg_match('/uova/', $n)) $days = 28;
    elseif (preg_match('/pane\s+fresco|pane\s+in\s+cassetta/', $n)) $days = 5;
    elseif (preg_match('/pane\s+confezionato|pan\s+carr|pancarrè/', $n)) $days = 14;
    elseif (preg_match('/insalata\s+di\s+(riso|pasta|farro|orzo|couscous)/', $n)) $days = 7;
    elseif (preg_match('/insalata|rucola|spinaci\s+freschi/', $n)) $days = 5;
    elseif (preg_match('/pollo|tacchino|maiale|manzo|vitello|sovracosci|cosci/', $n)) $days = 3;
    elseif (preg_match('/salmone|tonno\s+fresco|pesce/', $n) && !preg_match('/tonno\s+in\s+scatola|tonno\s+rio/', $n)) $days = 2;
    elseif (preg_match('/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/', $n)) $days = 1095;
    elseif (preg_match('/surgelat|frozen|findus|4\s*salti/', $n)) $days = 180;
    elseif (preg_match('/gelato/', $n)) $days = 365;
    elseif (preg_match('/succo|spremuta/', $n)) $days = 7;
    elseif (preg_match('/birra|vino/', $n)) $days = 365;
    elseif (preg_match('/acqua/', $n)) $days = 365;
    elseif (preg_match('/mela|mele\b/', $n)) $days = 7;
    elseif (preg_match('/arancia|arance|mandarini|agrumi/', $n)) $days = 7;
    elseif (preg_match('/banana|banane/', $n)) $days = 5;
    elseif (preg_match('/pera|pere\b|fragola|fragole|uva|kiwi/', $n)) $days = 5;
    // Fresh tomatoes MUST be before passata/pelati (those also contain "pomodor")
    elseif (preg_match('/\bpomodor[oi]\b/', $n) && !preg_match('/passata|pelati|polpa|concentrat|scatol|lattina|salsa|sugo/', $n)) $days = 7;
    elseif (preg_match('/carota|carote|zucchina|zucchine|peperon|melanzan/', $n)) $days = 7;
    elseif (preg_match('/broccoli|cavolfiore|cavolo|spinaci|bietola/', $n)) $days = 5;
    elseif (preg_match('/cipolla|cipolle/', $n)) $days = 30; // dry bulbs in a basket: weeks, not days (10 made the list suggest 3 onions for a month)
    elseif (preg_match('/patata|patate/', $n)) $days = 30; // whole tubers in a bag, pantry: 3-5 weeks
    elseif (preg_match('/biscott|cracker|grissini|fette\s+biscott/', $n)) $days = 180;
    elseif (preg_match('/nutella|marmellata|miele/', $n)) $days = 365;
    elseif (preg_match('/passata|pelati|pomodor[oi]?\s*(in\s*)?(scatola|lattina)?|polpa\s+di\s+pomodor|concentrato\s+di\s+pomodor/', $n)) $days = 730;
    elseif (preg_match('/olio|aceto/', $n)) $days = 548;

    if ($days === null) {
        // Category fallbacks
        $catMap = [
            'latticini' => 7, 'carne' => 4, 'pesce' => 3, 'frutta' => 7, 'verdura' => 7,
            'pasta' => 730, 'pane' => 4, 'surgelati' => 180, 'bevande' => 365, 'condimenti' => 365,
            'snack' => 180, 'conserve' => 730, 'cereali' => 365, 'igiene' => 1095, 'pulizia' => 1095,
        ];
        $days = 180;
        foreach ($catMap as $key => $d) {
            if (strpos($cat, $key) !== false) { $days = $d; break; }
        }
    }

    // Fridge extends shelf life for produce and short-lived items
    if ($loc === 'frigo') {
        if (preg_match('/mela|mele/', $n)) $days = max($days, 28);
        elseif (preg_match('/arancia|arance|agrumi|mandarini|limone|limoni/', $n)) $days = max($days, 21);
        elseif (preg_match('/carota|carote/', $n)) $days = max($days, 21);
        elseif (preg_match('/cipolla/', $n)) $days = max($days, 14);
        elseif (preg_match('/patata|patate/', $n)) $days = max($days, 21);
        elseif (preg_match('/pera|pere/', $n)) $days = max($days, 21);
        elseif (preg_match('/kiwi/', $n)) $days = max($days, 28);
        elseif (preg_match('/uva/', $n)) $days = max($days, 14);
        elseif (preg_match('/fragola|fragole/', $n)) $days = max($days, 7);
        elseif (preg_match('/peperoni/', $n)) $days = max($days, 14);
        elseif (preg_match('/zucchina|zucchine/', $n)) $days = max($days, 14);
        elseif (preg_match('/melanzane/', $n)) $days = max($days, 14);
        elseif (preg_match('/broccoli|cavolfiore|cavolo/', $n)) $days = max($days, 10);
        elseif ($days <= 7 && preg_match('/frutta|fruit|verdur|vegetable|plant-based/', $cat)) {
            $days = (int)round($days * 2);
        }
    }

    // Freezer extends shelf life significantly
    if ($loc === 'freezer' && $days < 180) {
        if ($days <= 4) $days = 120;
        elseif ($days <= 14) $days = 75;
        elseif ($days <= 30) $days = 120;
        else $days = max($days, 180);
    }

    return $days;
}

/**
 * Turn "days of shelf life" into a best-before DATE — or into nothing at all.
 *
 * Two things this function exists to stop:
 *
 * 1. **The 9999 sentinel is not a number of days.** `estimateSealedExpiryDaysPHP()` and
 *    `estimateOpenedExpiryDaysPHP()` answer **9999** for a non-perishable (salt, sugar, honey,
 *    vinegar, bicarbonate) and both call sites used to turn that into a date: `today + 9999 days`
 *    is **2054-03-13**, and the pantry really did contain two rows like that. A pantry may say
 *    "no expiry"; it may not invent the year 2054.
 * 2. **Shelf life is counted from the day the product ENTERED the pantry, not from today.** The
 *    backfill of rows stored without a date used `today + days`, so four rows of salt added on
 *    2026-03-10 were dated `2027-11-07` — the day the backfill ran plus a year. When the anchor is
 *    known, pass it: every estimate is then reproducible, and re-running a pass cannot drift a date.
 *
 * @param int|null    $days       shelf life in days (9999+ means "non-perishable")
 * @param string|null $anchorDate the day the row entered the pantry (YYYY-MM-DD or a full timestamp);
 *                                null = today, which is correct only for a brand-new purchase.
 * @return string|null YYYY-MM-DD, or null when the honest answer is "no expiry".
 */
function expiryDateFromDays(?int $days, ?string $anchorDate = null): ?string {
    if ($days === null || $days >= 9999) return null;   // sentinel: non-perishable, no date
    $days = max(1, $days);
    $anchor = ($anchorDate !== null && trim($anchorDate) !== '') ? strtotime($anchorDate) : time();
    if ($anchor === false) $anchor = time();
    return date('Y-m-d', strtotime('+' . $days . ' days', $anchor));
}

/**
 * Recalculate expiry for sealed (non-opened) fridge items with new fridge-aware logic.
 */
function recalcSealedFridgeExpiry(PDO $db): void {
    $stmt = $db->query("
        SELECT i.id, i.added_at, i.vacuum_sealed, i.opened_at, i.expiry_date,
               p.name, p.category
        FROM inventory i
        JOIN products p ON i.product_id = p.id
        WHERE i.location = 'frigo' AND i.opened_at IS NULL AND i.quantity > 0
    ");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $days = estimateSealedExpiryDaysPHP($row['name'], $row['category'], 'frigo');
        if ($row['vacuum_sealed']) $days = getVacuumExpiryDaysPHP($days);
        // Anchored on the day the row entered the fridge, and never a date for the 9999 sentinel.
        $newExpiry = expiryDateFromDays($days, $row['added_at']);
        if ($newExpiry === null) continue;
        // Only extend expiry, never shorten it
        if ($row['expiry_date'] && $newExpiry <= $row['expiry_date']) continue;
        $upd = $db->prepare("UPDATE inventory SET expiry_date = ? WHERE id = ?");
        $upd->execute([$newExpiry, $row['id']]);
    }
}

function getVacuumExpiryDaysPHP(int $baseDays): int {
    if ($baseDays <= 7) return (int)round($baseDays * 3);
    if ($baseDays <= 14) return (int)round($baseDays * 3);
    if ($baseDays <= 30) return (int)round($baseDays * 2.5);
    if ($baseDays <= 90) return (int)round($baseDays * 2.5);
    return (int)round($baseDays * 1.5);
}

/**
 * Fresh produce sold by piece (avocado, banana, tomato…) — not by weight.
 * Weight on the label (e.g. "300 g") is informational only.
 *
 * The words live in api/vocab/<lang>.json (`piece_produce` / `produce_preparations`):
 * this function is only the question. Tomatoes count by piece — reading that "2" as
 * grams is how a pantry row of 2 g was born, invisible the moment it was written.
 */
function isSoldByPieceProduct(string $name, string $category = ''): bool {
    return vocabIsPieceProduce($name);
}

/** Force pz/1 for piece produce; optionally flag inventory conversion from g/ml.
 *  Never overrides an explicit g/ml/conf unit — users often weigh salad, melon, etc.
 */
function normalizePieceProductFields(array $fields, ?array $existing = null): array {
    if (!isSoldByPieceProduct((string)($fields['name'] ?? ''), (string)($fields['category'] ?? ''))) {
        return $fields;
    }
    $incoming = strtolower(trim((string)($fields['unit'] ?? '')));
    $prev = strtolower(trim((string)($existing['unit'] ?? '')));
    // Keep the unit the user (or an earlier save) already chose.
    if (in_array($incoming, ['g', 'ml', 'conf'], true) || in_array($prev, ['g', 'ml', 'conf'], true)) {
        unset($fields['_inventory_convert']);
        if (in_array($incoming, ['g', 'ml', 'conf'], true)) {
            return $fields;
        }
        // Incoming still says pz but product was g/ml/conf → restore previous unit
        $fields['unit'] = $prev;
        if (isset($existing['default_quantity'])) {
            $fields['default_quantity'] = $existing['default_quantity'];
        }
        if (array_key_exists('package_unit', $existing)) {
            $fields['package_unit'] = $existing['package_unit'];
        }
        return $fields;
    }
    // Brand-new / still unset: default piece produce to pz
    $fields['unit'] = 'pz';
    $fields['default_quantity'] = 1;
    $fields['package_unit'] = '';
    return $fields;
}

/** Convert inventory rows from grams/ml to piece count when product unit is corrected. */
function repairPieceProductInventory(PDO $db, int $productId, float $gramsPerPiece): void {
    if ($gramsPerPiece <= 0) {
        $gramsPerPiece = 200;
    }
    $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE product_id = ? AND quantity > 0');
    $stmt->execute([$productId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raw = (float)$row['quantity'];
        $pz = round($raw / $gramsPerPiece * 4) / 4;
        if ($pz < 0.25 && $raw > 0) {
            $pz = 0.25;
        }
        $db->prepare('UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$pz, $row['id']]);
    }
}


/**
 * Stable fingerprint of a recipe body for archive deduplication.
 * Title + ingredient names + steps — ignores quantities, used flags, persons (those change on scale/use).
 */
function recipeArchiveContentHash(array $recipe): string {
    $ings = [];
    foreach ($recipe['ingredients'] ?? [] as $ing) {
        if (!is_array($ing)) {
            continue;
        }
        $n = mb_strtolower(trim((string)($ing['name'] ?? '')), 'UTF-8');
        if ($n !== '') {
            $ings[] = $n;
        }
    }
    sort($ings, SORT_STRING);
    $steps = [];
    foreach ($recipe['steps'] ?? [] as $step) {
        $s = mb_strtolower(trim((string)$step), 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        if ($s !== '') {
            $steps[] = $s;
        }
    }
    $canon = [
        't' => mb_strtolower(trim((string)($recipe['title'] ?? '')), 'UTF-8'),
        'i' => $ings,
        's' => $steps,
    ];
    return hash('sha256', json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Soft key for near-duplicates that share a title (chat clones with rewritten steps). */
function recipeArchiveTitleKey(array $recipe): string {
    return mb_strtolower(trim((string)($recipe['title'] ?? '')), 'UTF-8');
}

/**
 * Backfill content_hash and delete duplicate archive rows (keep favourite, else newest).
 * Also collapses same-title libero clones left over from chat re-saves.
 */
function recipesBackfillAndDedupByHash(PDO $db): void {
    $flagKey = 'migration_recipes_content_hash_v2';
    $flag = $db->query("SELECT value FROM app_settings WHERE key = '{$flagKey}'")->fetchColumn();

    // Drop unique index while rewriting hashes (collisions exist until duplicates are removed).
    $db->exec('DROP INDEX IF EXISTS idx_recipes_content_hash');

    $rows = $db->query('SELECT id, meal, recipe_json, is_favorite, created_at FROM recipes')->fetchAll(PDO::FETCH_ASSOC);
    $upd = $db->prepare('UPDATE recipes SET content_hash = ? WHERE id = ?');
    $byHash = [];
    foreach ($rows as $row) {
        $recipe = json_decode((string)$row['recipe_json'], true);
        if (!is_array($recipe)) {
            continue;
        }
        $hash = recipeArchiveContentHash($recipe);
        $upd->execute([$hash, (int)$row['id']]);
        $row['_hash'] = $hash;
        $byHash[$hash][] = $row;
    }

    $del = $db->prepare('DELETE FROM recipes WHERE id = ?');
    $removed = 0;

    $collapse = static function (array $groups) use (&$removed, $del): void {
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, static function ($a, $b) {
                $fa = (int)($a['is_favorite'] ?? 0);
                $fb = (int)($b['is_favorite'] ?? 0);
                if ($fa !== $fb) {
                    return $fb <=> $fa;
                }
                return ((int)$b['id']) <=> ((int)$a['id']);
            });
            array_shift($group);
            foreach ($group as $dup) {
                $del->execute([(int)$dup['id']]);
                $removed++;
            }
        }
    };

    if (!$flag) {
        $collapse($byHash);

        // Same-title libero leftovers (steps reworded → different hash) → keep one
        $rowsLeft = $db->query("SELECT id, meal, recipe_json, is_favorite FROM recipes WHERE meal = 'libero'")->fetchAll(PDO::FETCH_ASSOC);
        $byTitle = [];
        foreach ($rowsLeft as $row) {
            $recipe = json_decode((string)$row['recipe_json'], true);
            if (!is_array($recipe)) {
                continue;
            }
            $tk = recipeArchiveTitleKey($recipe);
            if ($tk === '') {
                continue;
            }
            $byTitle[$tk][] = $row;
        }
        $collapse($byTitle);

        $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
            ->execute([$flagKey, (string)$removed]);
    }

    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_recipes_content_hash ON recipes(content_hash) WHERE content_hash != ''");

    // --- "Altro" is gone (1.16.19) --------------------------------------------
    // A generic "Altro" bucket collected whatever did not fit a real place, so stock
    // hid there instead of living where it belongs. The location is removed from the
    // app; any row still pointing at it moves to the first location (dispensa), and
    // the number is recorded so the client can say so once — nothing disappears
    // silently, and the migration itself is idempotent.
    $altroFlag = 'migration_location_altro_v1';
    $altroDone = $db->query("SELECT value FROM app_settings WHERE key = '{$altroFlag}'")->fetchColumn();
    if ($altroDone === false) {
        $altroMoved = 0;
        try {
            $altroMoved = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE location = 'altro'")->fetchColumn();
            if ($altroMoved > 0) {
                $db->exec("UPDATE inventory SET location = 'dispensa' WHERE location = 'altro'");
            }
        } catch (Throwable $e) {
            $altroMoved = 0;
        }
        $altroNotice = json_encode(['from' => 'altro', 'to' => 'dispensa', 'moved' => $altroMoved, 'ts' => time()]);
        $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
           ->execute([$altroFlag, $altroNotice]);
        if ($altroMoved > 0 && class_exists('EverLog')) {
            EverLog::info('The Altro location was removed; its stock moved to dispensa', [
                'event' => 'location_altro_migrated',
                'moved' => $altroMoved,
                'to'    => 'dispensa',
            ]);
        }
    }

    // --- A medicine with no PAO cannot answer "and now how long does it keep?" ---------------
    // `med_open` marks a row open; without a PAO it must leave the date alone, which leaves the
    // household with a box that never expires. The FORM the name declares suggests the number
    // (data/medical_pao.json: a collirio keeps 28 days, a syrup 30, tablets nothing), and this
    // pass fills the cabinet that is already in the database. It only fills EMPTY values — a number
    // the household typed is never overwritten — and it runs once per database.
    $paoFlag = 'migration_med_pao_default_v1';
    $paoDone = $db->query("SELECT value FROM app_settings WHERE key = '{$paoFlag}'")->fetchColumn();
    if ($paoDone === false) {
        $paoFilled = 0;
        try {
            if (!function_exists('medicalPaoDaysForName')) {
                require_once __DIR__ . '/lib/medical.php';
            }
            $paoRows = $db->query("SELECT id, name, notes FROM products
                                    WHERE is_medical = 1
                                      AND (med_pao_days IS NULL OR med_pao_days = 0)")->fetchAll(PDO::FETCH_ASSOC);
            $paoUpd = $db->prepare('UPDATE products SET med_pao_days = ? WHERE id = ?');
            foreach ($paoRows as $paoRow) {
                // The form is in the pack notes as often as in the title ("Fastum" + "gel 2,5%…").
                $paoDays = medicalPaoDaysForName((string)($paoRow['name'] ?? ''), (string)($paoRow['notes'] ?? ''));
                if ($paoDays > 0) {
                    $paoUpd->execute([$paoDays, (int)$paoRow['id']]);
                    $paoFilled++;
                }
            }
        } catch (Throwable $e) {
            $paoFilled = 0;
        }
        $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
           ->execute([$paoFlag, '1']);
        if ($paoFilled > 0 && class_exists('EverLog')) {
            EverLog::info('Suggested PAO applied to medicines without one', [
                'event'    => 'med_pao_backfill',
                'products' => $paoFilled,
                'rule'     => 'data/medical_pao.json',
            ]);
        }
    }

    // --- Hot-path indexes (audit D1/G2) ---------------------------------------
    // These statements are hit on every dashboard rebuild and by the 5-minute
    // shopping cron, on columns that had no index. Created here as well as in
    // initializeDB() so an existing database picks them up on the next boot.
    // SQLite compares the *parsed* expression, so an expression index must stay
    // equivalent to the WHERE clause it serves: EXPLAIN QUERY PLAN was run on the
    // real statements when the index was added.
    $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_added ON inventory(added_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_products_name_lower ON products(lower(name))');
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_shopping_name_lower ON products(lower(trim(shopping_name)))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_family_key_lower ON products(lower(trim(coalesce(shopping_name,''))))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_buy_key_lower ON products(lower(trim(coalesce(nullif(shopping_name,''), name))))");
}
