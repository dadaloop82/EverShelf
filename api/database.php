<?php
/**
 * EverShelf - Database initialization, schema, and migrations.
 * Uses SQLite with WAL journal mode for concurrent read/write performance.
 *
 * @author Stimpfl Daniel <evershelfproject@gmail.com>
 * @license MIT
 */

define('DB_PATH', __DIR__ . '/../data/evershelf.db');

function getDB(): PDO {
    $isNew = !file_exists(DB_PATH);
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");
    
    if ($isNew) {
        initializeDB($db);
    }
    
    // Run migrations
    migrateDB($db);
    
    return $db;
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);
        CREATE INDEX IF NOT EXISTS idx_inventory_product ON inventory(product_id);
        CREATE INDEX IF NOT EXISTS idx_inventory_location ON inventory(location);
        CREATE INDEX IF NOT EXISTS idx_transactions_product ON transactions(product_id);
        CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(created_at);
    ");
}

function migrateDB(PDO $db): void {
    // Add package_unit column if missing
    $cols = $db->query("PRAGMA table_info(products)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('package_unit', $colNames)) {
        $db->exec("ALTER TABLE products ADD COLUMN package_unit TEXT DEFAULT ''");
    }

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
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            )
        ");
        $db->exec("INSERT INTO transactions SELECT * FROM transactions_old");
        $db->exec("DROP TABLE transactions_old");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_product ON transactions(product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(created_at)");
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
        // Backfill: detect already-opened items and set opened_at + recalculate expiry
        backfillOpenedItems($db);
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
}

/**
 * Backfill opened_at for existing inventory items that appear to be opened.
 * An item is considered opened if:
 *  - conf unit with fractional quantity
 *  - weight/volume unit (g,kg,ml,l) with quantity < default_quantity
 * Uses updated_at as the approximate opened_at date.
 * Recalculates expiry_date based on opened shelf life from opened_at.
 */
function backfillOpenedItems(PDO $db): void {
    $stmt = $db->query("
        SELECT i.id, i.quantity, i.location, i.updated_at, i.expiry_date, i.vacuum_sealed,
               p.name, p.category, p.unit, p.default_quantity
        FROM inventory i
        JOIN products p ON i.product_id = p.id
        WHERE i.quantity > 0
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

        $openedAt = $row['updated_at'];
        $openedDays = estimateOpenedExpiryDaysPHP($row['name'], $row['category'], $row['location']);
        if ($row['vacuum_sealed']) $openedDays = (int)round($openedDays * 1.5);

        // Calculate new expiry from opened_at
        $newExpiry = date('Y-m-d', strtotime($openedAt . " +{$openedDays} days"));

        $upd = $db->prepare("UPDATE inventory SET opened_at = ?, expiry_date = ? WHERE id = ?");
        $upd->execute([$openedAt, $newExpiry, $row['id']]);
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

    // ── D: Freezer ───────────────────────────────────────────────────────
    if ($loc === 'freezer') return 90;

    // ── E: Pantry/dispensa — specific products then generic fallback ─────
    if ($loc !== 'frigo') {
        if (preg_match('/\b(biscott[io]|cookies|wafer|tarall[io]|crackers?)\b/', $n)) return 60;
        if (preg_match('/\b(muesli|cereali|corn\s*flakes|granola|fiocchi)\b/', $n)) return 60;
        if (preg_match('/\b(confettura|marmellata)\b/', $n)) return 90;
        if (preg_match('/\b(nutella|cioccolat)\b/', $n)) return 90;
        if (preg_match('/\bpane\b/', $n)) return 4;
        // Specific jarred tomato sauce in pantry (opened, not refrigerated)
        if (preg_match('/salsa\s+di\s+(pomodoro|pronta)/', $n)) return 5;
        return 60; // generic pantry fallback (was 30, doubled)
    }

    // ── F: Fridge — short-life perishables ──────────────────────────────
    if (preg_match('/latte\s+(fresco|intero|parzial|scremato)/', $n)) return 3;
    if (preg_match('/latte\s+(uht|a\s+lunga)/', $n)) return 7;
    // Long-life mountain/brand milks stored in pantry before use (UHT)
    if (preg_match('/latte.*(montagna|alta\s+qual|parmalat|granarolo|esselunga|conservaz|microfiltrat)/i', $n)) return 7;
    if (preg_match('/\blatte\b/', $n)) return 4;
    if (preg_match('/\byogurt\b/', $n)) return 5;
    if (preg_match('/mozzarella|burrata|stracciatella/', $n)) return 3;
    if (preg_match('/philadelphia|spalmabile/', $n)) return 7;
    if (preg_match('/formaggio.*(fresco|ricotta|mascarpone|stracchino|crescenza)/', $n)) return 5;
    if (preg_match('/parmigiano|grana|pecorino|provolone/', $n)) return 21;
    if (preg_match('/formaggio/', $n)) return 10;
    if (preg_match('/\bburro\b/', $n)) return 30;
    if (preg_match('/\bpanna\b/', $n)) return 4;
    if (preg_match('/prosciutto\s+cotto|mortadella|wurstel/', $n)) return 5;
    if (preg_match('/prosciutto\s+crudo|salame|bresaola|speck|pancetta|nduja/', $n)) return 7;
    if (preg_match('/\b(pollo|tacchino|maiale|manzo|vitello|agnello)\b/', $n)) return 2;
    if (preg_match('/salmone|tonno\s+fresco|pesce(?!\s+in)/', $n)) return 2;
    if (preg_match('/\b(passata|pelati|polpa|sugo|salsa\s+di\s+pomodoro)\b/', $n)) return 5;
    if (preg_match('/insalata|rucola|spinaci|lattuga|crescione|germogli/', $n)) return 2;
    if (preg_match('/\b(succo|spremuta)\b/', $n)) return 3;
    if (preg_match('/\b(birra|beer)\b/', $n)) return 3;
    if (preg_match('/\bvino\b/', $n)) return 5;
    if (preg_match('/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/', $n)) return 4;
    // Fruit opened/cut in fridge — much shorter than sealed
    if (preg_match('/\bavocado\b/', $n)) return 2;
    if (preg_match('/\b(banana|banane|fragola|lampone|pesca|albicocca|ciliegia|mango|papaya)\b/', $n)) return 2;
    if (preg_match('/\b(mela|pera|nettarina|prugna|kiwi|ananas|uva|melone|anguria)\b/', $n)) return 3;
    if (preg_match('/\b(arancia|mandarino|pompelmo|clementina|limone)\b/', $n)) return 3; // cut citrus
    // Vegetables opened/cut in fridge
    if (preg_match('/\b(zucchina|zucchine|melanzana|pomodor)\b/', $n)) return 3;
    if (preg_match('/\b(peperone|peperoni)\b/', $n)) return 3;
    if (preg_match('/\b(broccolo|broccoli|cavolfiore|cavolo)\b/', $n)) return 3;
    if (preg_match('/\bsedano\b|\bfinocchio\b/', $n)) return 3;
    if (preg_match('/\b(cipolla|cipolle|cipollotto|scalogno|porro)\b/', $n)) return 4;
    if (preg_match('/\b(carota|carote)\b/', $n)) return 5;
    if (preg_match('/\b(patata|patate|tubero)\b/', $n)) return 3; // cooked/cut potato
    if (preg_match('/\baglio\b/', $n)) return 10;

    // ── G: Fridge condiments — medium shelf-life ─────────────────────────
    if (preg_match('/maionese|mayo|mayon/', $n)) return 90;
    if (preg_match('/\bketchup\b/', $n)) return 90;
    if (preg_match('/\b(senape|mustard)\b/', $n)) return 90;
    if (preg_match('/salsa\s+di\s+soia|soy\s*sauce/', $n)) return 90;
    if (preg_match('/\b(tabasco|worcestershire|sriracha)\b/', $n)) return 180;
    if (preg_match('/confettura|marmellata/', $n)) return 60;
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
    elseif (preg_match('/parmigiano|grana|pecorino|provolone/', $n)) $days = 60;
    elseif (preg_match('/burro/', $n)) $days = 60;
    elseif (preg_match('/panna/', $n)) $days = 14;
    elseif (preg_match('/prosciutto\s+cotto|mortadella|wurstel/', $n)) $days = 7;
    elseif (preg_match('/prosciutto\s+crudo|salame|bresaola|speck/', $n)) $days = 30;
    elseif (preg_match('/nduja/', $n)) $days = 90;
    elseif (preg_match('/uova/', $n)) $days = 28;
    elseif (preg_match('/pane\s+fresco|pane\s+in\s+cassetta/', $n)) $days = 5;
    elseif (preg_match('/pane\s+confezionato|pan\s+carr|pancarrè/', $n)) $days = 14;
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
    elseif (preg_match('/patata|patate/', $n)) $days = 14;
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
