<?php
/**
 * Database initialization and connection for Dispensa Manager
 */

define('DB_PATH', __DIR__ . '/../data/dispensa.db');

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
}
