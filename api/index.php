<?php
/**
 * EverShelf - Main API Router
 * Handles all CRUD operations for products, inventory, shopping lists,
 * AI-powered features (Gemini), and third-party integrations (Bring!, DupliClick).
 *
 * @author Stimpfl Daniel <evershelfproject@gmail.com>
 * @license MIT
 */

// database.php must always be loaded (used both by HTTP router and cron)
require_once __DIR__ . '/database.php';

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
    $aiActions = ['gemini_readExpiry', 'gemini_chat', 'gemini_identify', 'gemini_suggest_shopping'];
    $loginActions = ['dupliclick_login'];
    $recipeActions = ['generate_recipe', 'generate_recipe_stream'];

    if (in_array($action, $aiActions)) {
        $limit = 15;
        $window = 60;
        $bucket = 'ai';
    } elseif (in_array($action, $recipeActions)) {
        $limit = 5;
        $window = 60;
        $bucket = 'recipe';
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

try {
    $db = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

} // end !CRON_MODE block for router bootstrap

if (!defined('CRON_MODE')):
try {
    switch ($action) {
        // ===== PRODUCTS =====
        case 'search_barcode':
            searchBarcode($db);
            break;
        case 'lookup_barcode':
            lookupBarcode();
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

        // ===== SPESA ONLINE =====
        case 'dupliclick_login':
            dupliclickLogin();
            break;

        case 'dupliclick_search':
            dupliclickSearch();
            break;

        case 'dupliclick_status':
            $tokenFile = __DIR__ . '/../data/dupliclick_token.json';
            if (file_exists($tokenFile)) {
                $td = json_decode(file_get_contents($tokenFile), true);
                echo json_encode(['logged_in' => !empty($td['token']), 'email' => $td['email'] ?? '']);
            } else {
                echo json_encode(['logged_in' => false]);
            }
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

        case 'expiry_history':
            getExpiryHistory($db);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
endif; // end !CRON_MODE

// ===== TTS PROXY =====
function ttsProxy() {
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
        http_response_code(502);
        echo json_encode(['error' => 'cURL error: ' . $curlErr]);
        return;
    }

    http_response_code($httpCode ?: 200);
    echo json_encode(['status' => $httpCode, 'body' => $response]);
}

// ===== CLIENT LOG =====

// ===== EXPIRY HISTORY =====
function getExpiryHistory($db): void {
    $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
    if (!$productId) {
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

function lookupBarcode(): void {
    $barcode = $_GET['barcode'] ?? '';
    if (empty($barcode)) {
        echo json_encode(['found' => false, 'error' => 'No barcode provided']);
        return;
    }
    
    // Try Open Food Facts API (Italian version first for better localized data)
    $url = "https://world.openfoodfacts.org/api/v2/product/{$barcode}.json?fields=product_name,product_name_it,generic_name,generic_name_it,brands,categories_tags,categories_hierarchy,categories,image_front_small_url,image_url,quantity,nutriscore_grade,ingredients_text_it,ingredients_text,allergens_tags,conservation_conditions_it,conservation_conditions,origins_it,origins,manufacturing_places,nova_group,ecoscore_grade,labels,stores&lc=it";
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: DispensaManager/1.0\r\n"
        ]
    ]);
    
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        echo json_encode(['found' => false, 'source' => 'openfoodfacts', 'error' => 'API request failed']);
        return;
    }
    
    $data = json_decode($response, true);
    if (isset($data['status']) && $data['status'] === 1 && !empty($data['product'])) {
        $p = $data['product'];
        
        // Prefer Italian name, fall back to generic
        // Also request localized name via abbreviated_product_name
        $name = '';
        if (!empty($p['product_name_it'])) {
            $name = $p['product_name_it'];
        } elseif (!empty($p['generic_name_it'])) {
            $name = $p['generic_name_it'];
        } elseif (!empty($p['product_name'])) {
            $name = $p['product_name'];
        } elseif (!empty($p['generic_name'])) {
            $name = $p['generic_name'];
        }
        
        // If the name looks like it's in a non-Latin script (Arabic, Chinese, Thai, etc.)
        // try to use a fallback from brands + generic category
        if (!empty($name) && preg_match('/[\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0400}-\x{04FF}]/u', $name)) {
            // Try other name fields that might be in Latin script
            $latinName = '';
            foreach (['generic_name_it', 'generic_name', 'product_name_it', 'product_name'] as $field) {
                if (!empty($p[$field]) && !preg_match('/[\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0400}-\x{04FF}]/u', $p[$field])) {
                    $latinName = $p[$field];
                    break;
                }
            }
            // If still no Latin name, construct from brand + category
            if (empty($latinName)) {
                $brand = $p['brands'] ?? '';
                $latinName = !empty($brand) ? $brand : 'Prodotto sconosciuto';
            }
            $name = $latinName;
        }
        
        // Get Italian ingredients, fall back to generic
        $ingredients = '';
        if (!empty($p['ingredients_text_it'])) {
            $ingredients = $p['ingredients_text_it'];
        } elseif (!empty($p['ingredients_text'])) {
            $ingredients = $p['ingredients_text'];
        }
        
        // Category: prefer Italian categories_tags, fallback
        $category = '';
        if (!empty($p['categories_tags'])) {
            // Try to find an Italian-friendly category
            $category = $p['categories_tags'][0] ?? '';
        } elseif (!empty($p['categories_hierarchy'])) {
            $category = end($p['categories_hierarchy']);
        } elseif (!empty($p['categories'])) {
            $category = $p['categories'];
        }
        
        // Allergens
        $allergens = '';
        if (!empty($p['allergens_tags'])) {
            $allergens = implode(', ', array_map(function($a) {
                return str_replace('en:', '', $a);
            }, $p['allergens_tags']));
        }
        
        // Conservation / storage
        $conservation = $p['conservation_conditions_it'] ?? $p['conservation_conditions'] ?? '';
        
        // Origin
        $origin = $p['origins_it'] ?? $p['origins'] ?? $p['manufacturing_places'] ?? '';
        
        $result = [
            'found' => true,
            'source' => 'openfoodfacts',
            'product' => [
                'name' => $name,
                'brand' => $p['brands'] ?? '',
                'category' => $category,
                'image_url' => $p['image_front_small_url'] ?? $p['image_url'] ?? '',
                'quantity_info' => $p['quantity'] ?? '',
                'nutriscore' => $p['nutriscore_grade'] ?? '',
                'ingredients' => $ingredients,
                'allergens' => $allergens,
                'conservation' => $conservation,
                'origin' => $origin,
                'nova_group' => $p['nova_group'] ?? '',
                'ecoscore' => $p['ecoscore_grade'] ?? '',
                'labels' => $p['labels'] ?? '',
                'stores' => $p['stores'] ?? '',
            ]
        ];
        echo json_encode($result);
    } else {
        // Try UPC Item DB as fallback
        $url2 = "https://api.upcitemdb.com/prod/trial/lookup?upc={$barcode}";
        $ctx2 = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: DispensaManager/1.0\r\n"
            ]
        ]);
        $response2 = @file_get_contents($url2, false, $ctx2);
        if ($response2 !== false) {
            $data2 = json_decode($response2, true);
            if (!empty($data2['items'][0])) {
                $item = $data2['items'][0];
                echo json_encode([
                    'found' => true,
                    'source' => 'upcitemdb',
                    'product' => [
                        'name' => $item['title'] ?? '',
                        'brand' => $item['brand'] ?? '',
                        'category' => $item['category'] ?? '',
                        'image_url' => $item['images'][0] ?? '',
                    ]
                ]);
                return;
            }
        }
        echo json_encode(['found' => false, 'source' => 'openfoodfacts']);
    }
}

function saveProduct(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['name'])) {
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
        echo json_encode(['success' => true, 'product' => $product]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
    }
}

function deleteProduct(PDO $db): void {
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
    $q = $_GET['q'] ?? '';
    $stmt = $db->prepare("SELECT * FROM products WHERE name LIKE ? OR brand LIKE ? OR barcode LIKE ? ORDER BY name ASC LIMIT 20");
    $like = "%{$q}%";
    $stmt->execute([$like, $like, $like]);
    echo json_encode(['products' => $stmt->fetchAll()]);
}

// ===== INVENTORY FUNCTIONS =====

function listInventory(PDO $db): void {
    $location = $_GET['location'] ?? '';
    $query = "
        SELECT i.*, p.name, p.brand, p.category, p.image_url, p.unit, p.barcode, p.default_quantity, p.package_unit,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed, i.opened_at
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
    echo json_encode(['inventory' => $stmt->fetchAll()]);
}

function addToInventory(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    $quantity = (float)($input['quantity'] ?? 1);
    $location = $input['location'] ?? 'dispensa';
    $expiry = $input['expiry_date'] ?? null;
    $unit = $input['unit'] ?? null;
    
    if (!$productId) {
        http_response_code(400);
        echo json_encode(['error' => 'Product ID required']);
        return;
    }

    // Validate quantity bounds
    if ($quantity <= 0 || $quantity > 100000) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid quantity']);
        return;
    }

    // Validate location
    $validLocations = ['dispensa', 'frigo', 'freezer', 'altro'];
    if (!in_array($location, $validLocations)) {
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
    // Inventory changed — force smart-shopping recompute on next request
    invalidateSmartShoppingCache();
}

function useFromInventory(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = $input['product_id'] ?? 0;
    $quantity = $input['quantity'] ?? 0;
    $useAll = $input['use_all'] ?? false;
    $location = $input['location'] ?? 'dispensa';
    $notes = $input['notes'] ?? '';
    
    if (!$productId) {
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
            $stmt3 = $db->prepare("UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
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
        // Check if item is now opened (first use reduces quantity)
        $wasOpened = !empty($existing['opened_at']);
        $isNowOpened = false;
        $unit = $prodInfo['unit'] ?? 'pz';
        $defQty = (float)($prodInfo['default_quantity'] ?? 0);
        if ($unit === 'conf') {
            $w = floor($newQty + 0.001);
            $f = round($newQty - $w, 6);
            if ($f > 0.001) $isNowOpened = true;
        } elseif (in_array($unit, ['g','kg','ml','l']) && $defQty > 0 && $newQty < $defQty - 0.001) {
            $isNowOpened = true;
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
            $stmt = $db->prepare("UPDATE inventory SET quantity = ?, opened_at = CURRENT_TIMESTAMP, expiry_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newQty, $openedExpiry, $existing['id']]);
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
    
    // Check if opened part remains (for non-split path)
    if ($remaining > 0 && $prodInfo && $prodInfo['unit'] === 'conf') {
        $w = floor($remaining + 0.001);
        $f = round($remaining - $w, 6);
        if ($f > 0.001) {
            $openedId = (int)$existing['id'];
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
                        $spec = $genericName !== $product['name']
                            ? $product['name'] . ($product['brand'] ? ' · ' . $product['brand'] : '')
                            : ($product['brand'] ?: $product['name']);
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
    if ($openedId) $response['opened_id'] = $openedId;
    echo json_encode($response);
    // Inventory changed — force smart-shopping recompute on next request
    invalidateSmartShoppingCache();
}

function updateInventory(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;
    
    $fields = [];
    $params = [];
    if (isset($input['quantity'])) { $fields[] = "quantity = ?"; $params[] = $input['quantity']; }
    if (isset($input['location'])) { $fields[] = "location = ?"; $params[] = $input['location']; }
    if (isset($input['expiry_date'])) { $fields[] = "expiry_date = ?"; $params[] = $input['expiry_date']; }
    if (isset($input['vacuum_sealed'])) { $fields[] = "vacuum_sealed = ?"; $params[] = (int)$input['vacuum_sealed']; }
    $fields[] = "updated_at = CURRENT_TIMESTAMP";
    $params[] = $id;
    
    $stmt = $db->prepare("UPDATE inventory SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
    
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
    
    echo json_encode(['success' => true]);
}

function deleteInventory(PDO $db): void {
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
        http_response_code(400);
        echo json_encode(['error' => 'product_id required']);
        return;
    }
    $db->prepare("DELETE FROM inventory WHERE product_id = ? AND quantity = 0")->execute([$productId]);
    echo json_encode(['success' => true]);
}

function inventorySummary(PDO $db): void {
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
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    $productId = $_GET['product_id'] ?? '';
    
    $query = "
        SELECT t.*, p.name, p.brand, p.unit
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
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID required']);
        return;
    }

    // Fetch original transaction
    $stmt = $db->prepare("SELECT t.*, p.name FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.id = ?");
    $stmt->execute([$txId]);
    $tx = $stmt->fetch();
    if (!$tx) {
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
            $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, 'out', ?, ?, '[Annullato]')")->execute([$productId, $quantity, $location]);

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
            $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, 'in', ?, ?, '[Annullato]')")->execute([$productId, $quantity, $location]);
        }

        // Mark original as undone
        $db->prepare("UPDATE transactions SET undone = 1 WHERE id = ?")->execute([$txId]);
        $db->commit();
        echo json_encode(['success' => true, 'name' => $tx['name']]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
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
    $rows = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.unit,
               p.default_quantity, p.package_unit,
               i.id AS inventory_id, i.quantity AS inv_qty, i.location,
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

        // Dismiss key: product_id + rounded expected (so re-adding stock resets the alert)
        $key = 'a_' . $r['product_id'] . '_' . round($expected);
        if (!empty($dismissed[$key])) continue;

        $direction = $diff > 0 ? 'phantom' : 'missing';
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
    $totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $totalItems = $db->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory")->fetchColumn();
    $locations = $db->query("SELECT COUNT(DISTINCT location) FROM inventory")->fetchColumn();
    $recentIn = $db->query("SELECT COUNT(*) FROM transactions WHERE type='in' AND created_at >= datetime('now', '-7 days')")->fetchColumn();
    $recentOut = $db->query("SELECT COUNT(*) FROM transactions WHERE type='out' AND created_at >= datetime('now', '-7 days')")->fetchColumn();
    
    // Expiring soonest (next 4 items to expire)
    $expiring = $db->query("
        SELECT i.*, p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.expiry_date IS NOT NULL AND i.expiry_date >= date('now') AND i.quantity > 0
        ORDER BY i.expiry_date ASC
        LIMIT 4
    ")->fetchAll();
    
    // Expired
    $expired = $db->query("
        SELECT i.*, p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.expiry_date IS NOT NULL AND i.expiry_date < date('now')
        ORDER BY i.expiry_date ASC
    ")->fetchAll();
    
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
        $originalExpiry = !empty($item['expiry_date']) ? strtotime($item['expiry_date']) : null;

        if (!empty($item['opened_at'])) {
            // Compute the opened shelf-life from the moment it was opened
            $openedDays = estimateOpenedExpiryDaysPHP($item['name'], $item['category'], $item['location']);
            if ($vacuum) $openedDays = (int)round($openedDays * 1.5);
            $computedExpiry = strtotime($item['opened_at']) + $openedDays * 86400;
            // Use the computed opened expiry only — stored expiry_date may have been set by
            // an older (inaccurate) estimation and would give wrong results if mixed in.
            $finalExpiry = $computedExpiry;
            $item['opened_expiry'] = date('Y-m-d', $finalExpiry);
            $item['days_to_expiry'] = (int)round(($finalExpiry - $today) / 86400);
        } else {
            // Legacy: no opened_at, use stored expiry_date as-is
            $item['opened_expiry'] = $item['expiry_date'] ?? null;
            $item['days_to_expiry'] = $originalExpiry !== null
                ? (int)round(($originalExpiry - $today) / 86400)
                : null;
        }
        $item['is_edible'] = $item['days_to_expiry'] === null || $item['days_to_expiry'] >= 0;
        $item['has_opened_at'] = !empty($item['opened_at']);
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

        if (count($rows) < 3) continue; // Need at least 3 data points

        // Calculate average daily consumption
        $totalUsed = 0;
        foreach ($rows as $r) $totalUsed += abs(floatval($r['quantity']));

        $firstDate = strtotime($rows[0]['created_at']);
        $lastDate  = strtotime($rows[count($rows) - 1]['created_at']);
        $daySpan   = max(1, ($lastDate - $firstDate) / 86400);
        $dailyRate = $totalUsed / $daySpan;

        if ($dailyRate < 0.01) continue; // negligible consumption

        // Get the most recent restock (last 'in' transaction)
        $lastIn = $db->prepare("
            SELECT quantity, created_at
            FROM transactions
            WHERE product_id = ? AND location = ? AND type = 'in'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $lastIn->execute([$pid, $loc]);
        $restock = $lastIn->fetch(PDO::FETCH_ASSOC);

        if (!$restock) continue;

        $restockDate = strtotime($restock['created_at']);
        $restockQty  = floatval($restock['quantity']);

        // If inventory was manually edited (updated_at > last restock), use the
        // manual update as baseline instead — otherwise the prediction is comparing
        // against a stale restock quantity that no longer reflects reality.
        $lastManualUpdate = strtotime($item['updated_at']);
        if ($lastManualUpdate > $restockDate) {
            // Inventory was manually corrected after last restock → use current qty
            // as a fresh baseline from that point; only consider OUT transactions
            // that happened AFTER the manual update.
            $txnsSinceUpdate = $db->prepare("
                SELECT SUM(quantity) as total
                FROM transactions
                WHERE product_id = ? AND location = ? AND type = 'out'
                  AND created_at > ?
            ");
            $txnsSinceUpdate->execute([$pid, $loc, $item['updated_at']]);
            $usedSinceUpdate = floatval($txnsSinceUpdate->fetchColumn() ?: 0);
            $daysSinceBaseline = max(1, (time() - $lastManualUpdate) / 86400);
            // The effective "restock" qty is what inventory had at manual edit time
            // which is current qty + what was consumed since then
            $restockQty = floatval($item['quantity']) + $usedSinceUpdate;
            $restockDate = $lastManualUpdate;
        }

        $daysSinceRestock = max(1, (time() - $restockDate) / 86400);

        // Predicted remaining qty = restock qty - (daily rate * days since restock)
        $expectedQty = max(0, $restockQty - ($dailyRate * $daysSinceRestock));
        $actualQty   = floatval($item['quantity']);

        // Flag if deviation > 30% and absolute diff > meaningful threshold
        $deviation = abs($actualQty - $expectedQty);
        $threshold = max($dailyRate * 3, 0.5); // at least 3 days worth or 0.5 units
        $pctDev = $expectedQty > 0 ? ($deviation / $expectedQty) : ($actualQty > 0 ? 1 : 0);

        if ($pctDev > 0.30 && $deviation > $threshold) {
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
                'direction'          => $actualQty > $expectedQty ? 'more' : 'less',
                'tx_count'           => count($rows),
            ];
        }
    }

    echo json_encode(['success' => true, 'predictions' => $predictions]);
}

// ===== SETTINGS =====

function getServerSettings(): void {
    $geminiKey = env('GEMINI_API_KEY');
    $bringEmail = env('BRING_EMAIL');
    
    echo json_encode([
        'gemini_key' => $geminiKey,
        'gemini_key_set' => !empty($geminiKey),
        'bring_email' => $bringEmail,
        'bring_password_set' => !empty(env('BRING_PASSWORD')),
        'tts_url' => env('TTS_URL'),
        'tts_token' => env('TTS_TOKEN'),
        'tts_method' => env('TTS_METHOD', 'POST'),
        'tts_auth_type' => env('TTS_AUTH_TYPE', 'bearer'),
        'tts_content_type' => env('TTS_CONTENT_TYPE', 'application/json'),
        'tts_payload_key' => env('TTS_PAYLOAD_KEY', 'message'),
        'tts_enabled' => env('TTS_ENABLED', 'false') === 'true',
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
        'spesa_provider' => env('SPESA_PROVIDER', 'bring'),
        'spesa_ai_prompt' => env('SPESA_AI_PROMPT', ''),
        'meal_plan_enabled' => env('MEAL_PLAN_ENABLED', 'false') === 'true',
    ]);
}

function saveSettings(): void {
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
        'camera_facing'   => 'CAMERA_FACING',
        'dietary'         => 'DIETARY',
        'scale_gateway_url' => 'SCALE_GATEWAY_URL',
        'spesa_provider'  => 'SPESA_PROVIDER',
        'spesa_ai_prompt' => 'SPESA_AI_PROMPT',
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
    ];
    // Integer keys
    $intMap = [
        'default_persons' => 'DEFAULT_PERSONS',
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

        sleep($waitSec);
    }

    return [
        'http_code' => $lastCode,
        'body'      => $lastBody,
        'data'      => $lastBody ? json_decode($lastBody, true) : null,
    ];
}

/**
 * Like callGemini() but tries gemini-2.5-flash first, falls back to gemini-2.0-flash
 * on quota/rate-limit errors (429/503). Builds the URL from model name + API key.
 */
function callGeminiWithFallback(string $apiKey, array $payload, int $timeout = 30): array {
    $models = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $last = ['http_code' => 0, 'body' => '', 'data' => null];
    foreach ($models as $model) {
        $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $last = callGemini($url, $payload, $timeout);
        if ($last['http_code'] === 200) return $last;
        if ($last['http_code'] !== 429 && $last['http_code'] !== 503) return $last; // non-retryable
        // 429/503 on this model → try next model
    }
    return $last;
}

function geminiReadExpiry(): void {
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $imageBase64 = $input['image'] ?? '';
    
    if (empty($imageBase64)) {
        echo json_encode(['success' => false, 'error' => 'No image provided']);
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
    
    $result   = callGeminiWithFallback($apiKey, $payload, 30);
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
            echo json_encode(['success' => true, 'expiry_date' => $date, 'raw_text' => $parsed['raw_text'] ?? '']);
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
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $message = $input['message'] ?? '';
    $history = $input['history'] ?? [];
    $appliances = $input['appliances'] ?? [];
    $dietaryRestrictions = $input['dietary_restrictions'] ?? '';

    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Messaggio vuoto']);
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

    $appliancesText = '';
    if (!empty($appliances)) {
        $appliancesText = "\nElettodomestici disponibili: " . implode(', ', $appliances) . " (più fornelli e forno sempre disponibili).";
    }

    $dietaryText = '';
    if (!empty($dietaryRestrictions)) {
        $dietaryText = "\nRestrizioni alimentari dell'utente: {$dietaryRestrictions}. Rispetta SEMPRE queste restrizioni.";
    }

    $systemPrompt = <<<PROMPT
Sei un assistente cucina italiano esperto, amichevole e conciso. L'utente ha una dispensa e ti chiede consigli su cosa preparare.

CONTESTO - INGREDIENTI DISPONIBILI IN DISPENSA:
{$ingredientsText}
{$appliancesText}{$dietaryText}

REGOLE:
1. Rispondi SEMPRE in italiano, in modo colloquiale e amichevole
2. Usa SOLO gli ingredienti dalla dispensa dell'utente (più acqua, sale, pepe, olio che si presumono sempre disponibili)
3. Dai priorità agli ingredienti in scadenza
4. Sii conciso: non fare liste chilometriche, vai al sodo
5. Se l'utente chiede una ricetta o preparazione, dai istruzioni chiare con quantità
6. Se non ci sono ingredienti adatti per la richiesta, dillo onestamente e suggerisci alternative
7. Puoi suggerire combinazioni creative
8. Quando menzioni quantità, usa le stesse unità di misura della dispensa
9. Ricorda il contesto della conversazione precedente
PROMPT;

    // Build conversation for Gemini
    $contents = [];

    // System instruction as first user+model turn
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $systemPrompt]]
    ];
    $contents[] = [
        'role' => 'model',
        'parts' => [['text' => 'Ciao! Sono il tuo assistente cucina. Conosco tutto quello che hai in dispensa e sono pronto ad aiutarti. Cosa ti va di preparare? 😊']]
    ];

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
        'generationConfig' => [
            'temperature' => 0.8,
            'maxOutputTokens' => 1500
        ]
    ];

    $result   = callGeminiWithFallback($apiKey, $payload, 60);
    $httpCode = $result['http_code'];

    if ($httpCode !== 200) {
        $errMsg = $result['data']['error']['message'] ?? 'Errore API Gemini';
        echo json_encode(['success' => false, 'error' => $errMsg, 'http_code' => $httpCode]);
        return;
    }

    $reply = $result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($reply)) {
        echo json_encode(['success' => false, 'error' => 'Risposta vuota da Gemini']);
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
        if (!empty($item['expiry_date'])) return 4;
        if ($isOpen) return 5;
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
        3 => 'SCADENZA ≤7gg',
        4 => 'ALTRI CON SCADENZA',
        5 => 'APERTI',
        6 => 'DISPENSA',
    ];
    // Limit groups to keep prompt compact:
    //  1-3 (urgent): all items; 4 (has expiry): max 40; 5 (opened): all; 6 (pantry): max 20
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
        $extraRulesText = "\n\nPREFERENZE DELL'UTENTE:\n" . implode("\n", $extraRules);
    }
    
    // Appliances
    $appliancesText = '';
    if (!empty($appliances)) {
        $appliancesText = "\n\nELETTRODOMESTICI: " . implode(', ', $appliances) . " (+ fornelli e forno). Usa SOLO questi.";
    }
    
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
3. Quantità per $persons persona/e. Se un ingrediente ha poca quantità, usalo TUTTO.
4. "qty_number": valore NUMERICO nella STESSA unità della dispensa (g/ml/pz/conf, MAI kg o litri). Per non-dispensa: 0.
5. "name": usa ESATTAMENTE il nome dalla lista (il sistema lo usa per scalare l'inventario).
6. Includi nella lista ingredienti TUTTI quelli citati nei passi (tranne acqua/sale/pepe/olio).
7. Language rule: {$recipeLangName} only for all textual fields (`title`, `tags`, `expiry_note`, `ingredients.qty`, `steps`, `nutrition_note`). Keep `meal` unchanged.

DISPENSA:
$ingredientsText

Rispondi SOLO JSON valido (no markdown):
{$promptLanguageRule}
{"title":"…","meal":"$mealType","persons":$persons,"prep_time":"…","cook_time":"…","tags":["…"],"expiry_note":"…","ingredients":[{"name":"…","qty":"200 g","qty_number":200,"from_pantry":true}],"steps":["{$promptStepExample}"],"nutrition_note":"…"}
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

    $result   = callGeminiWithFallback($apiKey, $payload, 60);
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
                                // g/ml → pz (approximate to nearest piece)
                                } elseif ($invUnit === 'pz' || $invUnit === 'conf') {
                                    $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                                    if ($defQty > 0) {
                                        // Convert recipe grams/ml to pieces using default_quantity
                                        $qtyNum = $recipeVal / $defQty;
                                        $qtyNum = max(0.25, round($qtyNum * 4) / 4); // round to nearest quarter
                                    } else {
                                        $qtyNum = max(1, round($recipeVal / 100)); // fallback heuristic
                                    }
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
        
        echo json_encode(['success' => true, 'recipe' => $recipe]);
    } else {
        echo json_encode(['success' => false, 'error' => recipeText($lang, 'error_cannot_generate'), 'raw' => $text]);
    }
}

// ===== RECIPE GENERATION — STREAMING AGENT =====
function generateRecipeStream(PDO $db): void {
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
        if (!empty($item['expiry_date'])) return 4;
        if ($isOpen) return 5;
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
    $priorityHeaders    = [1=>'SCADUTI — usa subito',2=>'SCADENZA ≤3gg — priorità alta',3=>'SCADENZA ≤7gg',4=>'ALTRI CON SCADENZA',5=>'APERTI',6=>'DISPENSA'];
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
    $appliancesText = !empty($appliances)          ? "\n\nELETTRODOMESTICI: " . implode(', ', $appliances) . " (+ fornelli e forno). Usa SOLO questi." : '';
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
3. Quantità per $persons persona/e. Se un ingrediente ha poca quantità, usalo TUTTO.
4. "qty_number": valore NUMERICO nella STESSA unità della dispensa (g/ml/pz/conf, MAI kg o litri). Per non-dispensa: 0.
5. "name": usa ESATTAMENTE il nome dalla lista (il sistema lo usa per scalare l'inventario).
6. Includi nella lista ingredienti TUTTI quelli citati nei passi (tranne acqua/sale/pepe/olio).
7. Language rule: {$recipeLangName} only for all textual fields (`title`, `tags`, `expiry_note`, `ingredients.qty`, `steps`, `nutrition_note`). Keep `meal` unchanged.

DISPENSA:
$ingredientsText

Rispondi SOLO JSON valido (no markdown):
{$promptLanguageRule}
{"title":"…","meal":"$mealType","persons":$persons,"prep_time":"…","cook_time":"…","tags":["…"],"expiry_note":"…","ingredients":[{"name":"…","qty":"200 g","qty_number":200,"from_pantry":true}],"steps":["{$promptStepExample}"],"nutrition_note":"…"}
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

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
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
            curl_close($ch);
            if ($body === false) $body = '';

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
        $errDetail = $result['data']['error']['message'] ?? substr($result['body'], 0, 300);
        $send('error', ['error' => recipeText($lang, 'error_gemini_api'), 'http_code' => $httpCode, 'detail' => $errDetail]);
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

    // ── Post-process: fuzzy-match ingredients → inventory (same as generateRecipe) ──
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
                    if ($recipeUnit && $recipeUnit !== $invUnit) {
                        if ($recipeUnit === 'g'  && $invUnit === 'kg')  $qtyNum = $recipeVal / 1000;
                        elseif ($recipeUnit === 'g'  && $invUnit === 'g')   $qtyNum = $recipeVal;
                        elseif ($recipeUnit === 'ml' && $invUnit === 'l')   $qtyNum = $recipeVal / 1000;
                        elseif ($recipeUnit === 'ml' && $invUnit === 'ml')  $qtyNum = $recipeVal;
                        elseif ($invUnit === 'pz' || $invUnit === 'conf') {
                            $defQty = (float)($bestMatch['default_quantity'] ?? 0);
                            if ($defQty > 0) { $qtyNum = $recipeVal / $defQty; $qtyNum = max(0.25, round($qtyNum * 4) / 4); }
                            else $qtyNum = max(1, round($recipeVal / 100));
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
}

// ===== GEMINI AI PRODUCT IDENTIFICATION =====
function geminiIdentifyProduct(): void {
    $apiKey = env('GEMINI_API_KEY');
    if (empty($apiKey)) {
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

    $result   = callGeminiWithFallback($apiKey, $payload, 30);
    $httpCode = $result['http_code'];

    if ($httpCode !== 200) {
        $errMsg = $result['data']['error']['message'] ?? 'Errore API Gemini';
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
        echo json_encode(['success' => false, 'error' => 'Impossibile identificare il prodotto', 'raw' => $text]);
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

// ===== BRING! SHOPPING LIST INTEGRATION =====

function bringAuth(): ?array {
    $email = env('BRING_EMAIL');
    $password = env('BRING_PASSWORD');
    
    if (empty($email) || empty($password)) {
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
        'intero','intera','magro','magra','piccolo','piccola','grande','rosso','bianco'
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

    $result = callGeminiWithFallback($apiKey, $payload, 15);
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

function bringGetList(): void {
    $auth = bringAuth();
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Credenziali Bring! non configurate. Aggiungi BRING_EMAIL e BRING_PASSWORD al file .env']);
        return;
    }
    
    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) {
        // Try to get lists
        $lists = bringRequest('GET', "https://api.getbring.com/rest/v2/bringusers/{$auth['uuid']}/lists");
        if ($lists && isset($lists['lists'][0]['listUuid'])) {
            $listUUID = $lists['lists'][0]['listUuid'];
        } else {
            echo json_encode(['success' => false, 'error' => 'Nessuna lista Bring! trovata']);
            return;
        }
    }
    
    $data = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Errore nel recupero della lista']);
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
    $listUUID = $input['listUUID'] ?? $auth['bringListUUID'];
    
    if (empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'Lista non trovata']);
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
    }
    echo json_encode(['success' => true, 'added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);
}

function bringRemoveItem(): void {
    $auth = bringAuth();
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Credenziali Bring! non configurate']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $listUUID = $input['listUUID'] ?? $auth['bringListUUID'];
    
    if (empty($name) || empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
        return;
    }
    
    // Use rawName (German key) if provided, otherwise try to map
    $rawName = $input['rawName'] ?? '';
    $removeName = !empty($rawName) ? $rawName : italianToBring($name);
    
    $body = http_build_query([
        'uuid' => $listUUID,
        'remove' => $removeName,
    ]);
    
    $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
    if ($result !== null) {
        // Invalidate cache so next smart_shopping request reflects the updated Bring! list
        @unlink(__DIR__ . '/../data/smart_shopping_cache.json');
    }
    echo json_encode(['success' => $result !== null]);
}

function bringCleanSpecs(): void {
    $auth = bringAuth();
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Credenziali Bring! non configurate']);
        return;
    }

    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'Lista non trovata']);
        return;
    }

    $data = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$data || !isset($data['purchase'])) {
        echo json_encode(['success' => false, 'error' => 'Errore nel recupero della lista']);
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
    $auth = bringAuth();
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Credenziali Bring! non configurate']);
        return;
    }
    $listUUID = $auth['bringListUUID'];
    if (empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'Lista non trovata']);
        return;
    }
    $data = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if (!$data || !isset($data['purchase'])) {
        echo json_encode(['success' => false, 'error' => 'Errore nel recupero della lista']);
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
    $now = time();
    $today = date('Y-m-d');

    // Helper: extract significant tokens from a product name (mirrors JS _nameTokens)
    $nameTokens = function(string $name): array {
        $stop = ['di','del','della','dei','degli','delle','da','in','con','per','su',
                 'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo'];
        $tokens = preg_split('/\s+/', strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $name)));
        return array_values(array_filter($tokens, fn($t) => strlen($t) > 2 && !in_array($t, $stop)));
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
               MAX(i.opened_at) as opened_at
        FROM inventory i
        WHERE i.quantity > 0
        GROUP BY i.product_id
    ");
    $inventory = [];
    foreach ($invStmt->fetchAll() as $inv) {
        $inventory[$inv['product_id']] = $inv;
    }

    // 3. Get transaction stats per product
    $txStmt = $db->query("
        SELECT product_id,
               COUNT(CASE WHEN type IN ('out','waste') THEN 1 END) as use_count,
               SUM(CASE WHEN type IN ('out','waste') THEN quantity ELSE 0 END) as total_used,
               COUNT(CASE WHEN type = 'in' THEN 1 END) as buy_count,
               SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) as total_bought,
               MIN(CASE WHEN type = 'in' THEN created_at END) as first_in,
               MAX(CASE WHEN type = 'in' THEN created_at END) as last_in,
               MAX(CASE WHEN type IN ('out','waste') THEN created_at END) as last_out
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
    foreach ($products as $pStock) {
        $qty = isset($inventory[$pStock['id']]) ? (float)$inventory[$pStock['id']]['total_qty'] : 0;
        if ($qty <= 0) continue;
        foreach ($nameTokens($pStock['name']) as $tok) {
            $stockByAnyToken[$tok] = ($stockByAnyToken[$tok] ?? 0) + $qty;
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

        // Average daily consumption rate
        $dailyRate = $daysSinceFirst < 999 && $totalUsed > 0 ? $totalUsed / $daysSinceFirst : 0;

        // Days of stock remaining
        $daysLeft = ($dailyRate > 0 && $qty > 0) ? $qty / $dailyRate : ($qty > 0 ? 999 : 0);

        // --- Expiry check ---
        $expiryDate = $inv ? $inv['nearest_expiry'] : null;
        $daysToExpiry = $expiryDate ? (strtotime($expiryDate) - $now) / 86400 : 999;
        $isExpired = $daysToExpiry < 0;
        $isExpiringSoon = !$isExpired && $daysToExpiry <= 3;

        // --- Stock level assessment ---
        // percentage_left: how much is left vs typical purchase size
        // Use average of totalBought/buyCount if available, else default_quantity, else best-guess from defQty or 1
        $refQty = $totalBought > 0 && $buyCount > 0
            ? $totalBought / $buyCount
            : ($defQty > 0 ? $defQty : max(1, $qty)); // avoid inflating pctLeft for products with no history
        $pctLeft = $refQty > 0 ? min(200, ($qty / $refQty) * 100) : ($qty > 0 ? 100 : 0);

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
            foreach ($pToks as $tok) {
                if (($stockByAnyToken[$tok] ?? 0) > 0) { $coveredByEquivalent = true; break; }
            }
            if ($coveredByEquivalent) continue;

            if ($isFrequent && $isRecent && $buyCount >= 2) {
                // Frequently used, recently active, AND bought multiple times → critical
                $urgency = 'critical';
                $reasons[] = 'Esaurito';
                $score += 100;
                if ($useCount >= 5) { $score += 20; $reasons[] = "Uso frequente ({$useCount}x)"; }
            } elseif ($isFrequent && $isRecent && $buyCount == 1 && $useCount >= 3) {
                // Bought once but used ≥3 times → proven consumption pattern → high
                $urgency = 'high';
                $reasons[] = 'Esaurito';
                $score += 75;
                if ($useCount >= 5) { $score += 10; $reasons[] = "Uso frequente ({$useCount}x)"; }
            } elseif ($isFrequent && $isRecent && $buyCount == 1) {
                // Frequent use, bought once, <3 uses — not yet proven → medium
                $urgency = 'medium';
                $reasons[] = 'Esaurito';
                $score += 45;
            } elseif ($isRegular && $isRecent && ($useCount >= 3 || $buyCount >= 2)) {
                // Regularly used, recently active → high
                $urgency = 'high';
                $reasons[] = 'Esaurito';
                $score += 70;
            } elseif ($isRecent && $buyCount >= 2) {
                // At least bought a couple times recently → low
                $urgency = 'low';
                $reasons[] = 'Esaurito';
                $score += 30;
            } else {
                // Rarely used or not used recently — skip
                continue;
            }
        }

        // Almost finished — only flag if usage frequency justifies it
        if ($qty > 0 && $pctLeft <= 15 && $isRegular) {
            $urgency = $isFrequent ? 'high' : 'medium';
            $reasons[] = 'Quasi finito (' . round($pctLeft) . '%)';
            $score += 80;
        } elseif ($qty > 0 && $pctLeft <= 30 && $isRegular) {
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
            $urgency = 'critical';
            $reasons[] = 'Scaduto!';
            $score += 90;
        } elseif ($isExpiringSoon && $qty > 0) {
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

        // Boost score for very frequent items
        if ($useCount >= 8) $score += 15;
        elseif ($useCount >= 5) $score += 10;

        // Compute generic shopping name for this product
        $shoppingName = $p['shopping_name'] ?: computeShoppingName($p['name'], $p['category'], $p['brand']);

        // Is already on Bring? check both product name and generic shopping name
        $onBring = _productOnBring($p['name'], $bringItems, $shoppingName);

        // "Just restocked" suppression: if bought in the last 3 days AND stock is above 50%
        // of reference qty, skip non-expiry urgency flags. The product doesn't need rebuying yet.
        if ($justRestocked && $pctLeft >= 50 && !$isExpired && !$isExpiringSoon) {
            continue;
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
    // Offline: derive suggestions from smart shopping cache (no AI needed)

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
    if ($auth) {
        $listUUID = $auth['bringListUUID'] ?? '';
    }

    // 3. Convert smart shopping items → suggestions (alta/media priority only, skip on_bring)
    $suggestions = [];
    $seasonalTips = [
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
    $seasonalTip = $seasonalTips[(int)date('n')] ?? '';

    foreach ($smartItems as $item) {
        if ($item['on_bring'] ?? false) continue; // already on shopping list

        $urgency = $item['urgency'] ?? 'low';
        if ($urgency === 'low') continue; // not urgent enough to suggest

        $priority = ($urgency === 'critical' || $urgency === 'high') ? 'alta' : 'media';
        $reasons = $item['reasons'] ?? [];
        $reason = !empty($reasons) ? implode(', ', $reasons) : 'Scorte basse';

        $suggestions[] = [
            'name'          => $item['name'],
            'specification' => '',
            'reason'        => $reason,
            'category'      => $item['category'] ?: 'altro',
            'priority'      => $priority,
        ];

        if (count($suggestions) >= 12) break;
    }

    echo json_encode([
        'success'      => true,
        'suggestions'  => $suggestions,
        'seasonal_tip' => $seasonalTip,
        'listUUID'     => $listUUID,
    ], JSON_UNESCAPED_UNICODE);
}

// ===== DUPLICLICK (GRUPPO POLI) =====

function dupliclickLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'POST required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['error' => 'Email e password sono obbligatori']);
        return;
    }

    $postData = http_build_query([
        'login' => $email,
        'password' => $password,
        'remember_me' => 'true',
        'show_sectors' => 'false'
    ]);

    $ch = curl_init('https://www.dupliclick.it/ebsn/api/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Accept: application/json',
            'Origin: https://www.dupliclick.it',
            'Referer: https://www.dupliclick.it/',
            'x-ebsn-client: production',
            'x-ebsn-client-redirect: production',
            'x-ebsn-client-uuid: 64b2d6318bb8f97bb1aba47dd8af38f6',
            'x-ebsn-version: 2.0.7'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo json_encode(['error' => 'Errore connessione: ' . curl_error($ch)]);
        curl_close($ch);
        return;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    // Extract JWT token from x-ebsn-account header
    $token = '';
    foreach (explode("\r\n", $headerStr) as $line) {
        if (stripos($line, 'x-ebsn-account:') === 0) {
            $token = trim(substr($line, strlen('x-ebsn-account:')));
            break;
        }
    }

    // The response body may have leading whitespace/newlines - trim it
    $body = trim($body);
    $bodyData = json_decode($body, true);

    // Check login success: status is at response.status (not root level)
    if ($bodyData === null) {
        echo json_encode(['error' => 'Risposta non valida dal server DupliClick', 'http_code' => $httpCode, 'raw' => substr($body, 0, 500)]);
        return;
    }

    $respStatus = $bodyData['response']['status'] ?? ($bodyData['status'] ?? -1);
    if ($respStatus !== 0) {
        $errors = $bodyData['response']['errors'] ?? $bodyData['errors'] ?? [];
        $errMsg = $errors[0]['error'] ?? $bodyData['message'] ?? 'Credenziali non valide';
        echo json_encode(['error' => $errMsg, 'status' => $respStatus]);
        return;
    }

    // User data is at root level, not inside data.user
    $userData = $bodyData['data']['user'] ?? $bodyData['user'] ?? null;
    $cartId = $bodyData['data']['cartId'] ?? $bodyData['cartId'] ?? null;

    // Save token to file for later use
    $tokenData = [
        'token' => $token,
        'email' => $email,
        'logged_at' => date('c'),
        'user' => $userData,
        'cart_id' => $cartId,
    ];
    file_put_contents(__DIR__ . '/../data/dupliclick_token.json', json_encode($tokenData, JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => true,
        'token' => !empty($token) ? substr($token, 0, 20) . '...' : '(non trovato)',
        'token_full' => $token,
        'http_code' => $httpCode,
        'data' => $bodyData['data'] ?? null,
        'user' => $userData,
        'response_status' => $respStatus,
        'infos' => $bodyData['response']['infos'] ?? [],
    ]);
}

// ===== DUPLICLICK PRODUCT SEARCH =====

function dupliclickSearch(): void {
    $query = $_GET['q'] ?? '';
    $spec = $_GET['spec'] ?? '';
    $aiPrompt = $_GET['prompt'] ?? '';
    if (empty($query)) {
        echo json_encode(['error' => 'Parametro q obbligatorio']);
        return;
    }

    // Load saved token
    $tokenFile = __DIR__ . '/../data/dupliclick_token.json';
    if (!file_exists($tokenFile)) {
        echo json_encode(['error' => 'Non sei loggato a DupliClick. Vai in Configurazione > Spesa Online.']);
        return;
    }
    $tokenData = json_decode(file_get_contents($tokenFile), true);
    $token = $tokenData['token'] ?? '';
    if (empty($token)) {
        echo json_encode(['error' => 'Token DupliClick non trovato. Effettua il login.']);
        return;
    }

    $baseHeaders = [
        'Accept: application/json',
        'Origin: https://www.dupliclick.it',
        'Referer: https://www.dupliclick.it/',
        'x-ebsn-client: production',
        'x-ebsn-client-uuid: 64b2d6318bb8f97bb1aba47dd8af38f6',
        'x-ebsn-version: 2.0.7',
        'x-ebsn-account: ' . $token,
    ];

    // Search catalog by item name only first
    $searchResults = dupliclickCatalogSearch($query, $baseHeaders);
    if ($searchResults === null) {
        echo json_encode(['error' => 'Errore nella ricerca']);
        return;
    }
    
    $products = $searchResults['products'];
    $total = $searchResults['total'];
    
    if (empty($products)) {
        // Fallback: try searching with spec keywords appended
        $specKeywords = dupliclickExtractSpecKeywords($spec);
        if ($specKeywords) {
            $searchResults = dupliclickCatalogSearch($query . ' ' . $specKeywords, $baseHeaders);
            if ($searchResults && !empty($searchResults['products'])) {
                $products = $searchResults['products'];
                $total = $searchResults['total'];
            }
        }
        if (empty($products)) {
            echo json_encode(['success' => true, 'query' => $query, 'product' => null, 'total' => 0]);
            return;
        }
    }

    // Format top 10 products
    $topProducts = array_slice($products, 0, 10);
    $formatted = array_map('formatDupliclickProduct', $topProducts);

    // If multiple results, use AI to pick the best match
    $bestProduct = $formatted[0];
    $aiUsed = false;
    if (count($formatted) > 1) {
        $aiResult = aiSelectBestProduct($query, $spec, $formatted, $aiPrompt);
        if ($aiResult !== null) {
            $bestProduct = $aiResult;
            $aiUsed = true;
        } elseif ($aiResult === null && !empty($spec)) {
            // AI said no match — try refined search with spec keywords
            $specKeywords = dupliclickExtractSpecKeywords($spec);
            if ($specKeywords) {
                $refined = dupliclickCatalogSearch($query . ' ' . $specKeywords, $baseHeaders);
                if ($refined && !empty($refined['products'])) {
                    $refinedFormatted = array_map('formatDupliclickProduct', array_slice($refined['products'], 0, 10));
                    $aiResult2 = aiSelectBestProduct($query, $spec, $refinedFormatted, $aiPrompt);
                    if ($aiResult2 !== null) {
                        $bestProduct = $aiResult2;
                        $aiUsed = true;
                    } else {
                        $bestProduct = $refinedFormatted[0];
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'query' => $query,
        'product' => $bestProduct,
        'total' => $total,
        'ai_used' => $aiUsed,
    ]);
}

/**
 * Search DupliClick catalog and return raw products array
 */
function dupliclickCatalogSearch(string $query, array $headers): ?array {
    $url = 'https://www.dupliclick.it/ebsn/api/products?' . http_build_query([
        'q' => $query,
        'page' => 1,
        'order_by' => 'search_score desc'
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) { curl_close($ch); return null; }
    curl_close($ch);

    $data = json_decode(trim($response), true);
    if (!$data || ($data['response']['status'] ?? -1) !== 0) return null;

    return [
        'products' => $data['data']['products'] ?? [],
        'total' => $data['data']['page']['totItems'] ?? 0,
    ];
}

/**
 * Extract meaningful product keywords from a Bring specification string,
 * stripping quantities, emojis, and noise words.
 */
function dupliclickExtractSpecKeywords(string $spec): string {
    if (empty($spec)) return '';
    // Remove priority emojis
    $clean = preg_replace('/[\x{1F534}\x{1F7E1}\x{1F7E2}]/u', '', $spec);
    // Remove quantities (150g, 500ml, 2x, 1 flacone, etc.)
    $clean = preg_replace('/\d+\s*(g|kg|ml|l|pz|pezzi|conf|flacon[ei]|x)\b/i', '', $clean);
    $clean = preg_replace('/\d+x\d*/i', '', $clean);
    // Remove standalone numbers
    $clean = preg_replace('/\b\d+\b/', '', $clean);
    // Remove noise words
    $noise = ['senza', 'con', 'più', 'meno', 'circa', 'tipo', 'lidl', 'coop', 'conad', 'esselunga'];
    $clean = preg_replace('/\b(' . implode('|', $noise) . ')\b/i', '', $clean);
    // Remove commas and extra spaces
    $clean = preg_replace('/[,+]/', ' ', $clean);
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    return $clean;
}

/**
 * Pick the best product from search results using offline text-scoring (no AI needed).
 * Returns null when nothing matches well enough (triggers refined search with spec keywords).
 */
function aiSelectBestProduct(string $itemName, string $spec, array $products, string $customPrompt = ''): ?array {
    if (empty($products)) return null;
    if (count($products) === 1) return $products[0];

    $stop = ['di','del','della','dei','degli','dalle','delle','da','in','con','per','su',
             'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli',
             'allo','gr','kg','ml','lt','cl','pz','conf','pack'];

    $tokenize = function(string $s) use ($stop): array {
        $clean = mb_strtolower(preg_replace('/[^\p{L}0-9\s]/u', ' ', $s), 'UTF-8');
        return array_values(array_filter(
            preg_split('/\s+/', trim($clean)),
            fn($t) => mb_strlen($t, 'UTF-8') > 2 && !in_array($t, $stop)
        ));
    };

    $queryTokens = $tokenize($itemName);
    $specTokens  = $tokenize($spec);

    if (empty($queryTokens)) return $products[0];

    // Variant conflict pairs: if spec says X, penalise products containing opposite
    $variantConflicts = [
        'cubetti'   => ['fette','affettata','intera','arrotolata'],
        'fette'     => ['cubetti','dadini'],
        'cotto'     => ['crudo','stagionato'],
        'crudo'     => ['cotto'],
        'intero'    => ['macinato','tritato','cubetti','fette'],
        'macinato'  => ['intero'],
        'biologico' => [],
    ];

    // Category mismatch: if query implies a category, penalise products from the wrong one
    $categoryGuards = [
        ['query' => ['frutta','mele','pere','pesche','fragole','uva','arance','limoni','banane','kiwi'],
         'exclude' => ['succo','succhi','nettare','sciroppo','aranciata','bevanda','bibita']],
        ['query' => ['verdura','spinaci','zucchine','carote','finocchio','sedano','broccoli'],
         'exclude' => ['surgelat','succo']],
        ['query' => ['formaggio','mozzarella','parmigiano','ricotta','pecorino'],
         'exclude' => ['ravioli','tortellini','cannelloni','lasagne','pizza']],
        ['query' => ['pasta','spaghetti','penne','fusilli','rigatoni','tagliatelle'],
         'exclude' => ['insalata','minestra','zuppa','brodo']],
    ];

    $scores = [];
    foreach ($products as $idx => $product) {
        $productName  = $product['name']  ?? '';
        $productBrand = $product['brand'] ?? '';
        $productTokens = $tokenize($productName . ' ' . $productBrand);
        $nameLower = mb_strtolower($productName, 'UTF-8');
        $score = 0;

        // --- Token overlap: query vs product ---
        foreach ($queryTokens as $qt) {
            foreach ($productTokens as $pt) {
                if ($qt === $pt)                          { $score += 6; break; }
                if (str_contains($pt, $qt) || str_contains($qt, $pt)) { $score += 2; break; }
            }
        }

        // --- Spec tokens get extra weight (user specified variant) ---
        foreach ($specTokens as $st) {
            foreach ($productTokens as $pt) {
                if ($st === $pt)                          { $score += 8; break; }
                if (str_contains($pt, $st) || str_contains($st, $pt)) { $score += 3; break; }
            }
        }

        // --- First-token anchor bonus ---
        if (!empty($queryTokens) && !empty($productTokens) && $queryTokens[0] === $productTokens[0]) {
            $score += 10;
        }

        // --- Category mismatch penalty ---
        foreach ($categoryGuards as $guard) {
            if (!empty(array_intersect($queryTokens, $guard['query']))) {
                foreach ($guard['exclude'] as $exc) {
                    if (str_contains($nameLower, $exc)) { $score -= 50; break; }
                }
            }
        }

        // --- Variant conflict penalty ---
        foreach ($specTokens as $st) {
            if (isset($variantConflicts[$st])) {
                foreach ($variantConflicts[$st] as $conflict) {
                    if (str_contains($nameLower, $conflict)) { $score -= 20; }
                }
            }
        }

        $scores[$idx] = $score;
    }

    arsort($scores);
    reset($scores);
    $topIdx    = key($scores);
    $topScore  = current($scores);
    next($scores);
    $secondScore = current($scores) ?: 0;

    // Return null (triggers spec-refined search) only when spec is given and no product
    // matches well, so the caller can retry with more specific keywords.
    if (!empty($spec) && $topScore < 4) return null;

    // Otherwise return the best scoring result (fallback to first if score is 0)
    return $products[$topIdx];
}

function formatDupliclickProduct(array $p): array {
    $promo = $p['warehousePromo'] ?? null;
    $result = [
        'productId' => $p['productId'] ?? $p['id'] ?? null,
        'name' => $p['name'] ?? '',
        'brand' => $p['shortDescr'] ?? '',
        'price' => $p['price'] ?? 0,
        'priceDisplay' => $p['priceDisplay'] ?? $p['price'] ?? 0,
        'priceUm' => $p['priceStandardUmDisplay'] ?? null,
        'weightUnit' => $p['weightUnitDisplay'] ?? '',
        'packageDescr' => $p['productInfos']['PACKAGE_DESCR'] ?? '',
        'barcode' => $p['barcode'] ?? '',
        'imageUrl' => $p['mediaURL'] ?? '',
        'slug' => $p['slug'] ?? '',
        'itemUrl' => $p['itemUrl'] ?? '',
        'url' => 'https://www.dupliclick.it' . ($p['itemUrl'] ?? ''),
        'available' => $p['available'] ?? 0,
    ];
    
    if ($promo) {
        $result['promo'] = [
            'discount' => $promo['discount'] ?? 0,
            'discountPerc' => $promo['discountPerc'] ?? 0,
            'originalPrice' => round(($p['price'] ?? 0) + ($promo['discount'] ?? 0), 2),
            'validFrom' => $promo['validityDate'] ?? '',
            'validTo' => $promo['expireDate'] ?? '',
            'label' => $promo['view']['body'] ?? 'OFFERTA',
            'type' => $promo['promoType'] ?? '',
        ];
    }
    
    return $result;
}

// ===== SHARED APP DATA FUNCTIONS =====

function appSettingsGet(PDO $db): void {
    $rows = $db->query("SELECT key, value FROM app_settings")->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key']] = json_decode($row['value'], true) ?? $row['value'];
    }
    echo json_encode(['success' => true, 'settings' => $settings]);
}

function appSettingsSave(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input['settings'] ?? null)) {
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
    $input = json_decode(file_get_contents('php://input'), true);
    $date = $input['date'] ?? date('Y-m-d');
    $meal = $input['meal'] ?? '';
    $recipe = $input['recipe'] ?? null;

    if (!$meal || !$recipe) {
        echo json_encode(['error' => 'Missing meal or recipe']);
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
        $db->prepare("DELETE FROM recipes WHERE id = ?")->execute([$id]);
    }
    echo json_encode(['success' => true]);
}

function chatList(PDO $db): void {
    $rows = $db->query("SELECT id, role, text, created_at FROM chat_messages ORDER BY id ASC LIMIT 100")->fetchAll();
    echo json_encode(['success' => true, 'messages' => $rows]);
}

function chatSave(PDO $db): void {
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
    echo json_encode(['success' => true]);
}

function chatClear(PDO $db): void {
    $db->exec("DELETE FROM chat_messages");
    echo json_encode(['success' => true]);
}

/**
 * One-time migration: convert all kg→g and l→ml in products table,
 * and scale inventory quantities accordingly.
 */
function migrateUnitsToBase(PDO $db): void {
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
