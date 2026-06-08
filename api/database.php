<?php
/**
 * EverShelf - Database initialization, schema, and migrations.
 * Uses SQLite with WAL journal mode for concurrent read/write performance.
 *
 * @author Stimpfl Daniel <evershelfproject@gmail.com>
 * @license MIT
 */

define('DB_PATH', __DIR__ . '/../data/evershelf.db');

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
    $db->exec('PRAGMA busy_timeout = 10000;'); // 10 s — cron + PWA writes can contend under WAL

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
    
    return $db;
}

/**
 * Retry a DB write when SQLite returns "database is locked" (concurrent cron + API).
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function dbWithRetry(callable $fn, int $maxAttempts = 4): mixed {
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
            notes TEXT DEFAULT '',
            shopping_name TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            location TEXT NOT NULL DEFAULT 'dispensa',
            quantity REAL NOT NULL DEFAULT 1,
            expiry_date DATE,
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
        CREATE INDEX IF NOT EXISTS idx_transactions_product ON transactions(product_id);
        CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(created_at);
        -- Composite indexes for hot queries
        -- getStats(): WHERE type IN (...) AND created_at >= ...
        CREATE INDEX IF NOT EXISTS idx_transactions_type_date ON transactions(type, created_at);
        -- smartShopping(): GROUP BY product_id filtering on type+undone
        CREATE INDEX IF NOT EXISTS idx_transactions_pid_type_undone ON transactions(product_id, type, undone);
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

    // Empty barcode strings break UNIQUE (only one '' allowed); normalize to NULL.
    $db->exec("UPDATE products SET barcode = NULL WHERE barcode IS NOT NULL AND TRIM(barcode) = ''");

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
    // app_settings: key-value store shared across all devices
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='app_settings'")->fetchAll();
    if (empty($tables)) {
        $db->exec("
            CREATE TABLE app_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    // recipes: one per meal per day (last wins)
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='recipes'")->fetchAll();
    if (empty($tables)) {
        $db->exec("
            CREATE TABLE recipes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL,
                meal TEXT NOT NULL,
                recipe_json TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, meal)
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

    // Add is_favorite column to recipes if missing (#124)
    $recCols = array_column($db->query("PRAGMA table_info(recipes)")->fetchAll(), 'name');
    if (!in_array('is_favorite', $recCols)) {
        try { $db->exec("ALTER TABLE recipes ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
    }

    // Add nutriments_json column to products if missing (#118)
    $prodCols2 = array_column($db->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('nutriments_json', $prodCols2)) {
        try { $db->exec("ALTER TABLE products ADD COLUMN nutriments_json TEXT DEFAULT NULL"); }
        catch (PDOException $e) { if (strpos($e->getMessage(), 'duplicate column') === false) throw $e; }
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
 * Estimate sealed shelf life in days, with fridge/freezer extensions.
 * Mirrors the JS estimateExpiryDays() function.
 */
function estimateSealedExpiryDaysPHP(string $name, string $category, string $location): int {
    $n = mb_strtolower($name);
    $cat = mb_strtolower($category);
    $loc = mb_strtolower($location);

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
    elseif (preg_match('/carota|carote|zucchina|zucchine|peperoni|melanzane/', $n)) $days = 7;
    elseif (preg_match('/broccoli|cavolfiore|cavolo|spinaci|bietola/', $n)) $days = 5;
    elseif (preg_match('/cipolla|cipolle/', $n)) $days = 10;
    elseif (preg_match('/patata|patate/', $n)) $days = 30; // whole tubers in a bag, pantry: 3-5 weeks
    elseif (preg_match('/biscott|cracker|grissini|fette\s+biscott/', $n)) $days = 180;
    elseif (preg_match('/nutella|marmellata|miele/', $n)) $days = 365;
    elseif (preg_match('/passata|pelati|pomodor/', $n)) $days = 730;
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
        $newExpiry = date('Y-m-d', strtotime($row['added_at'] . " +{$days} days"));
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
