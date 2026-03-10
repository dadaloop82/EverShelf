<?php
/**
 * Dispensa Manager - Main API Router
 * Handles all CRUD operations for products and inventory
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/database.php';

try {
    $db = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

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
        case 'inventory_summary':
            inventorySummary($db);
            break;

        // ===== TRANSACTIONS =====
        case 'transactions_list':
            listTransactions($db);
            break;

        // ===== STATS =====
        case 'stats':
            getStats($db);
            break;

        // ===== AI =====
        case 'gemini_expiry':
            geminiReadExpiry();
            break;

        case 'generate_recipe':
            generateRecipe($db);
            break;

        case 'gemini_identify':
            geminiIdentifyProduct();
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
        case 'bring_suggest':
            bringSuggestItems($db);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
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
    
    if (!empty($input['id'])) {
        // Update existing
        $stmt = $db->prepare("
            UPDATE products SET name=?, brand=?, category=?, image_url=?, unit=?, 
            default_quantity=?, notes=?, barcode=?, updated_at=CURRENT_TIMESTAMP
            WHERE id=?
        ");
        $stmt->execute([
            $input['name'], $input['brand'] ?? '', $input['category'] ?? '',
            $input['image_url'] ?? '', $input['unit'] ?? 'pz',
            $input['default_quantity'] ?? 1, $input['notes'] ?? '',
            $input['barcode'] ?? null, $input['id']
        ]);
        echo json_encode(['success' => true, 'id' => $input['id']]);
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO products (barcode, name, brand, category, image_url, unit, default_quantity, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $barcode = !empty($input['barcode']) ? $input['barcode'] : null;
        $stmt->execute([
            $barcode, $input['name'], $input['brand'] ?? '',
            $input['category'] ?? '', $input['image_url'] ?? '',
            $input['unit'] ?? 'pz', $input['default_quantity'] ?? 1,
            $input['notes'] ?? ''
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
        SELECT i.*, p.name, p.brand, p.category, p.image_url, p.unit, p.barcode
        FROM inventory i
        JOIN products p ON i.product_id = p.id
    ";
    $params = [];
    if (!empty($location)) {
        $query .= " WHERE i.location = ?";
        $params[] = $location;
    }
    $query .= " ORDER BY p.name ASC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    echo json_encode(['inventory' => $stmt->fetchAll()]);
}

function addToInventory(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = $input['product_id'] ?? 0;
    $quantity = $input['quantity'] ?? 1;
    $location = $input['location'] ?? 'dispensa';
    $expiry = $input['expiry_date'] ?? null;
    $unit = $input['unit'] ?? null;
    
    if (!$productId) {
        http_response_code(400);
        echo json_encode(['error' => 'Product ID required']);
        return;
    }
    
    // If a different unit was specified, update the product's unit
    if ($unit) {
        $stmt = $db->prepare("UPDATE products SET unit = ?, default_quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$unit, $quantity, $productId]);
    }
    
    // Check if product already exists in this location
    $stmt = $db->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location = ?");
    $stmt->execute([$productId, $location]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update quantity
        $newQty = $existing['quantity'] + $quantity;
        $stmt = $db->prepare("UPDATE inventory SET quantity = ?, expiry_date = COALESCE(?, expiry_date), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$newQty, $expiry, $existing['id']]);
    } else {
        // Insert new inventory entry
        $stmt = $db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productId, $location, $quantity, $expiry]);
    }
    
    // Log transaction
    $stmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location) VALUES (?, 'in', ?, ?)");
    $stmt->execute([$productId, $quantity, $location]);
    
    echo json_encode(['success' => true]);
}

function useFromInventory(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = $input['product_id'] ?? 0;
    $quantity = $input['quantity'] ?? 0;
    $useAll = $input['use_all'] ?? false;
    $location = $input['location'] ?? 'dispensa';
    
    if (!$productId) {
        http_response_code(400);
        echo json_encode(['error' => 'Product ID required']);
        return;
    }
    
    $stmt = $db->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location = ? AND quantity > 0");
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
    
    $newQty = max(0, $existing['quantity'] - $quantity);
    
    if ($newQty <= 0) {
        $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$existing['id']]);
    } else {
        $stmt = $db->prepare("UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$newQty, $existing['id']]);
    }
    
    // Log transaction
    $stmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location) VALUES (?, 'out', ?, ?)");
    $stmt->execute([$productId, $quantity, $location]);
    
    // Auto-add to Bring! if product is completely finished (no inventory left anywhere)
    $addedToBring = false;
    if ($newQty <= 0) {
        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM inventory WHERE product_id = ? AND quantity > 0");
        $stmt->execute([$productId]);
        $totalLeft = (float)($stmt->fetchColumn() ?: 0);
        
        if ($totalLeft <= 0) {
            // Get product name and brand for Bring!
            $stmt = $db->prepare("SELECT name, brand FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if ($product) {
                try {
                    $auth = bringAuth();
                    if ($auth) {
                        $listUUID = $auth['bringListUUID'];
                        $bringName = italianToBring($product['name']);
                        $spec = $product['brand'] ?: '';
                        $body = http_build_query([
                            'uuid' => $listUUID,
                            'purchase' => $bringName,
                            'specification' => $spec,
                        ]);
                        $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
                        $addedToBring = ($result !== null);
                    }
                } catch (Exception $e) {
                    // Silently fail — don't block inventory operation
                }
            }
        }
    }
    
    echo json_encode(['success' => true, 'remaining' => $newQty, 'added_to_bring' => $addedToBring]);
}

function updateInventory(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;
    
    $fields = [];
    $params = [];
    if (isset($input['quantity'])) { $fields[] = "quantity = ?"; $params[] = $input['quantity']; }
    if (isset($input['location'])) { $fields[] = "location = ?"; $params[] = $input['location']; }
    if (isset($input['expiry_date'])) { $fields[] = "expiry_date = ?"; $params[] = $input['expiry_date']; }
    $fields[] = "updated_at = CURRENT_TIMESTAMP";
    $params[] = $id;
    
    $stmt = $db->prepare("UPDATE inventory SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
    echo json_encode(['success' => true]);
}

function deleteInventory(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;
    $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->execute([$id]);
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
    $limit = $_GET['limit'] ?? 50;
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
    $query .= " ORDER BY t.created_at DESC LIMIT ?";
    $params[] = (int)$limit;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    echo json_encode(['transactions' => $stmt->fetchAll()]);
}

// ===== STATS =====

function getStats(PDO $db): void {
    $totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $totalItems = $db->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory")->fetchColumn();
    $locations = $db->query("SELECT COUNT(DISTINCT location) FROM inventory")->fetchColumn();
    $recentIn = $db->query("SELECT COUNT(*) FROM transactions WHERE type='in' AND created_at >= datetime('now', '-7 days')")->fetchColumn();
    $recentOut = $db->query("SELECT COUNT(*) FROM transactions WHERE type='out' AND created_at >= datetime('now', '-7 days')")->fetchColumn();
    
    // Expiring soonest (next 4 items to expire)
    $expiring = $db->query("
        SELECT i.*, p.name, p.brand, p.category 
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.expiry_date IS NOT NULL AND i.expiry_date >= date('now') AND i.quantity > 0
        ORDER BY i.expiry_date ASC
        LIMIT 4
    ")->fetchAll();
    
    // Expired
    $expired = $db->query("
        SELECT i.*, p.name, p.brand, p.category 
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.expiry_date IS NOT NULL AND i.expiry_date < date('now')
        ORDER BY i.expiry_date ASC
    ")->fetchAll();
    
    echo json_encode([
        'total_products' => (int)$totalProducts,
        'total_items' => (float)$totalItems,
        'locations' => (int)$locations,
        'recent_in' => (int)$recentIn,
        'recent_out' => (int)$recentOut,
        'expiring_soon' => $expiring,
        'expired' => $expired,
    ]);
}

// ===== GEMINI AI FUNCTIONS =====

function geminiReadExpiry(): void {
    // Load API key from .env
    $envFile = __DIR__ . '/../.env';
    $apiKey = '';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                if (trim($key) === 'GEMINI_API_KEY') {
                    $apiKey = trim($val);
                }
            }
        }
    }
    
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
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    
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
    
    $jsonPayload = json_encode($payload);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'Gemini API error', 'http_code' => $httpCode]);
        return;
    }
    
    $data = json_decode($response, true);
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

// ===== RECIPE GENERATION WITH GEMINI =====
function generateRecipe(PDO $db): void {
    // Load API key from .env
    $envFile = __DIR__ . '/../.env';
    $apiKey = '';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                if (trim($key) === 'GEMINI_API_KEY') {
                    $apiKey = trim($val);
                }
            }
        }
    }

    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'no_api_key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $mealType = $input['meal'] ?? 'pranzo';
    $persons = max(1, intval($input['persons'] ?? 1));

    // Fetch all inventory items with expiry info
    $stmt = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.category, i.quantity, p.unit, i.location, i.expiry_date,
               CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
        ORDER BY days_left ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        echo json_encode(['success' => false, 'error' => 'La dispensa è vuota!']);
        return;
    }

    // Build ingredient list with expiry info
    $ingredientLines = [];
    foreach ($items as $item) {
        $line = "- {$item['name']}";
        if ($item['brand']) $line .= " ({$item['brand']})";
        $line .= ": {$item['quantity']} {$item['unit']}";
        if ($item['expiry_date']) {
            $daysLeft = intval($item['days_left']);
            if ($daysLeft < 0) {
                $line .= " [SCADUTO da " . abs($daysLeft) . " giorni!]";
            } elseif ($daysLeft <= 3) {
                $line .= " [SCADE TRA $daysLeft GIORNI - PRIORITÀ ALTA!]";
            } elseif ($daysLeft <= 7) {
                $line .= " [scade tra $daysLeft giorni - priorità media]";
            }
        }
        $line .= " (in {$item['location']})";
        $ingredientLines[] = $line;
    }

    $ingredientsText = implode("\n", $ingredientLines);

    $mealLabels = [
        'colazione' => 'colazione (mattina)',
        'pranzo' => 'pranzo (mezzogiorno)',
        'cena' => 'cena (sera)'
    ];
    $mealLabel = $mealLabels[$mealType] ?? $mealType;

    $prompt = <<<PROMPT
Sei un nutrizionista e chef italiano esperto. Genera UNA ricetta per $mealLabel per $persons persona/e usando PRINCIPALMENTE gli ingredienti disponibili nella dispensa dell'utente.

REGOLE IMPORTANTI:
1. PRIORITÀ ASSOLUTA: usa prima gli ingredienti in scadenza o già scaduti (se ancora utilizzabili)
2. Prediligi una ricetta SANA, EQUILIBRATA e NUTRIENTE
3. Usa SOLO ingredienti dalla lista sotto, più al massimo acqua, sale, pepe e olio che si presumono sempre disponibili
4. Adatta le quantità per $persons persona/e
5. Se non ci sono abbastanza ingredienti per una ricetta completa, suggerisci la migliore combinazione possibile
6. La ricetta deve essere adatta al pasto: $mealLabel
7. IMPORTANTE - QUANTITÀ NUMERICHE: per ogni ingrediente dalla dispensa, il campo "qty_number" DEVE contenere il valore NUMERICO da scalare dall'inventario, espresso nella STESSA unità di misura della dispensa. Esempio: se in dispensa c'è "Farina: 1000 g" e la ricetta richiede 200g, qty_number = 200. Se "Riso: 2 kg" e servono 300g, qty_number = 0.3. Per ingredienti non dalla dispensa, qty_number = 0.

INGREDIENTI DISPONIBILI IN DISPENSA:
$ingredientsText

Rispondi SOLO con un JSON valido in questo formato esatto (senza markdown, senza backtick):
{
  "title": "Nome della ricetta",
  "meal": "$mealType",
  "persons": $persons,
  "prep_time": "tempo preparazione (es. 15 min)",
  "cook_time": "tempo cottura (es. 20 min)",
  "tags": ["sano", "veloce", "..."],
  "expiry_note": "Nota sugli ingredienti in scadenza usati (o stringa vuota)",
  "ingredients": [
    {"name": "nome ingrediente", "qty": "quantità leggibile (es: 200 g)", "qty_number": 200, "from_pantry": true},
    {"name": "sale", "qty": "q.b.", "qty_number": 0, "from_pantry": false}
  ],
  "steps": [
    "Passo 1: descrizione dettagliata",
    "Passo 2: descrizione dettagliata"
  ],
  "nutrition_note": "Breve nota nutrizionale sulla ricetta"
}
PROMPT;

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 2048
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'Errore API Gemini', 'http_code' => $httpCode]);
        return;
    }

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Clean markdown wrapping
    $text = preg_replace('/^```json\\s*/i', '', $text);
    $text = preg_replace('/\\s*```$/i', '', $text);
    $text = trim($text);

    $recipe = json_decode($text, true);

    if ($recipe && !empty($recipe['title'])) {
        // Enrich from_pantry ingredients with product_id and location for "use" feature
        if (!empty($recipe['ingredients'])) {
            foreach ($recipe['ingredients'] as &$ing) {
                if (!empty($ing['from_pantry'])) {
                    $ingNameLower = mb_strtolower(trim($ing['name']), 'UTF-8');
                    $bestMatch = null;
                    $bestScore = 0;
                    
                    foreach ($items as $item) {
                        $itemNameLower = mb_strtolower(trim($item['name']), 'UTF-8');
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
                        // Word-level matching: check if key words overlap
                        else {
                            $ingWords = preg_split('/\s+/', $ingNameLower);
                            $itemWords = preg_split('/\s+/', $itemNameLower);
                            $common = array_intersect($ingWords, $itemWords);
                            if (count($common) > 0) {
                                $score = (count($common) / max(count($ingWords), 1)) * 60;
                            }
                        }
                        
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestMatch = $item;
                        }
                    }
                    
                    // Only match if score is reasonable (> 30)
                    if ($bestMatch && $bestScore > 30) {
                        $ing['product_id'] = (int)$bestMatch['product_id'];
                        $ing['location'] = $bestMatch['location'];
                        $ing['available_qty'] = $bestMatch['quantity'] . ' ' . $bestMatch['unit'];
                        if (!empty($bestMatch['brand'])) {
                            $ing['brand'] = $bestMatch['brand'];
                        }
                        if (!empty($bestMatch['expiry_date'])) {
                            $ing['expiry_date'] = $bestMatch['expiry_date'];
                        }
                    }
                }
            }
            unset($ing);
        }
        
        echo json_encode(['success' => true, 'recipe' => $recipe]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Impossibile generare la ricetta', 'raw' => $text]);
    }
}

// ===== GEMINI AI PRODUCT IDENTIFICATION =====
function geminiIdentifyProduct(): void {
    // Load API key
    $envFile = __DIR__ . '/../.env';
    $apiKey = '';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                if (trim($key) === 'GEMINI_API_KEY') {
                    $apiKey = trim($val);
                }
            }
        }
    }

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

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

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

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'Errore API Gemini', 'http_code' => $httpCode]);
        return;
    }

    $data = json_decode($response, true);
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

function loadEnvVars(): array {
    $envFile = __DIR__ . '/../.env';
    $vars = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $vars[trim($key)] = trim($val);
            }
        }
    }
    return $vars;
}

function bringAuth(): ?array {
    $env = loadEnvVars();
    $email = $env['BRING_EMAIL'] ?? '';
    $password = $env['BRING_PASSWORD'] ?? '';
    
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
    
    // Exact match
    if (isset($catalog['it2de'][$lower])) {
        return $catalog['it2de'][$lower];
    }
    
    // Try partial match: "Spinaci freschi" → match "Spinaci"
    foreach ($catalog['it2de'] as $itLower => $deKey) {
        if (str_contains($lower, $itLower) || str_contains($itLower, $lower)) {
            return $deKey;
        }
    }
    
    // Try matching first word: "Petto di pollo" → "Pollo" = Poulet
    $words = explode(' ', $lower);
    foreach ($words as $word) {
        if (mb_strlen($word) < 3) continue;
        foreach ($catalog['it2de'] as $itLower => $deKey) {
            if ($itLower === $word) {
                return $deKey;
            }
        }
    }
    
    // No match - return original (Bring! will show as custom item)
    return $italianName;
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
}

function bringAddItems(): void {
    $auth = bringAuth();
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Credenziali Bring! non configurate']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $items = $input['items'] ?? [];
    $listUUID = $input['listUUID'] ?? $auth['bringListUUID'];
    
    if (empty($listUUID)) {
        echo json_encode(['success' => false, 'error' => 'Lista non trovata']);
        return;
    }
    
    $added = 0;
    $errors = [];
    
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        $spec = $item['specification'] ?? '';
        if (empty($name)) continue;
        
        // Map Italian name to Bring! catalog key (German) for proper recognition
        $bringName = italianToBring($name);
        
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
    
    echo json_encode(['success' => true, 'added' => $added, 'errors' => $errors]);
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
    echo json_encode(['success' => $result !== null]);
}

function bringSuggestItems(PDO $db): void {
    $env = loadEnvVars();
    $apiKey = $env['GEMINI_API_KEY'] ?? '';
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'API Key Gemini non configurata']);
        return;
    }
    
    // Get current Bring! list
    $auth = bringAuth();
    $bringItems = [];
    $listUUID = '';
    if ($auth) {
        $listUUID = $auth['bringListUUID'];
        if (empty($listUUID)) {
            $lists = bringRequest('GET', "https://api.getbring.com/rest/v2/bringusers/{$auth['uuid']}/lists");
            if ($lists && isset($lists['lists'][0]['listUuid'])) {
                $listUUID = $lists['lists'][0]['listUuid'];
            }
        }
        if ($listUUID) {
            $data = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
            if ($data && isset($data['purchase'])) {
                foreach ($data['purchase'] as $item) {
                    $rawName = $item['name'] ?? '';
                    $bringItems[] = bringToItalian($rawName);
                }
            }
        }
    }
    
    // Get inventory
    $stmt = $db->query("
        SELECT p.name, p.brand, p.category, i.quantity, p.unit, i.location, i.expiry_date,
               CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
        ORDER BY p.category, p.name
    ");
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build detailed context with expiry info
    $invLines = [];
    $expiringItems = [];
    $expiredItems = [];
    $categories = [];
    foreach ($inventory as $item) {
        $cat = $item['category'] ?: 'altro';
        $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        $line = "- {$item['name']}";
        if ($item['brand']) $line .= " ({$item['brand']})";
        $line .= ": {$item['quantity']} {$item['unit']} in {$item['location']}";
        if ($item['expiry_date']) {
            $dl = intval($item['days_left']);
            if ($dl < 0) {
                $line .= " [⚠️ SCADUTO da " . abs($dl) . " giorni]";
                $expiredItems[] = $item['name'];
            } elseif ($dl <= 2) {
                $line .= " [🔴 SCADE TRA {$dl} GIORNI - USARE SUBITO]";
                $expiringItems[] = $item['name'] . " (tra {$dl}g)";
            } elseif ($dl <= 7) {
                $line .= " [🟡 scade tra {$dl} giorni]";
                $expiringItems[] = $item['name'] . " (tra {$dl}g)";
            } elseif ($dl <= 14) {
                $line .= " [scade tra {$dl} giorni]";
            }
        }
        $invLines[] = $line;
    }
    $inventoryText = empty($invLines) ? 'La dispensa è COMPLETAMENTE VUOTA.' : implode("\n", $invLines);
    
    $expiryContext = '';
    if (!empty($expiredItems)) {
        $expiryContext .= "\n\nPRODOTTI SCADUTI da sostituire: " . implode(', ', $expiredItems);
    }
    if (!empty($expiringItems)) {
        $expiryContext .= "\n\nPRODOTTI IN SCADENZA (priorità per sostituzione): " . implode(', ', $expiringItems);
    }
    
    $bringText = empty($bringItems) 
        ? 'La lista della spesa Bring! è attualmente VUOTA.' 
        : "PRODOTTI GIÀ NELLA LISTA DELLA SPESA BRING! (NON suggerire nessuno di questi, sono già stati aggiunti):\n- " . implode("\n- ", $bringItems);
    
    // Current month for seasonal suggestions
    $mese = strftime('%B') ?: date('F');
    $mesi_it = ['January'=>'Gennaio','February'=>'Febbraio','March'=>'Marzo','April'=>'Aprile','May'=>'Maggio','June'=>'Giugno','July'=>'Luglio','August'=>'Agosto','September'=>'Settembre','October'=>'Ottobre','November'=>'Novembre','December'=>'Dicembre'];
    $meseIt = $mesi_it[date('F')] ?? date('F');
    $anno = date('Y');
    
    // Get catalog Italian names for AI to use
    $catalog = bringCatalog();
    $catalogNames = array_values($catalog['de2it']);
    // Filter only food-related items (exclude categories and non-food)
    $catalogNames = array_filter($catalogNames, function($n) {
        $skip = ['Fai da te', 'Giardino', 'Atrezzi', 'Annaffiatoio', 'Rasaerba', 'Sementi', 'Propangas', 'Vernice', 'Pennello', 'Viti', 'Chiodi', 'Barbecue', 'Ombrellone', 'Terriccio', 'Concime', 'Articoli propri', 'Usati di recente'];
        foreach ($skip as $s) { if (str_contains($n, $s)) return false; }
        return mb_strlen($n) > 1;
    });
    $catalogList = implode(', ', array_slice($catalogNames, 0, 200));
    
    $prompt = <<<PROMPT
Sei un nutrizionista e consulente per la spesa domestica italiano. Il tuo obiettivo è aiutare l'utente a fare una spesa SANA, EQUILIBRATA e INTELLIGENTE.

DATA ATTUALE: {$meseIt} {$anno}

=== INVENTARIO ATTUALE (cosa ha già in casa) ===
{$inventoryText}{$expiryContext}

=== LISTA BRING! (già pianificato per la spesa) ===
{$bringText}

=== CATALOGO PRODOTTI RICONOSCIUTI ===
Usa ESATTAMENTE questi nomi quando possibile (sono i nomi che il sistema riconosce con icone e categorie):
{$catalogList}

=== IL TUO COMPITO ===
Analizza attentamente l'inventario dell'utente e suggerisci cosa MANCA per una settimana di alimentazione sana.

REGOLA FONDAMENTALE SUI NOMI:
- Il campo "name" DEVE essere uno dei nomi dal CATALOGO PRODOTTI sopra, scritto ESATTAMENTE come appare.
- Esempio: usa "Spinaci" (non "Spinaci freschi"), "Pollo" (non "Petto di pollo"), "Mele" (non "Mele Golden").
- Se vuoi specificare la variante, mettila nel campo "specification" (es: name="Pollo", specification="petto, 500g").
- Se il prodotto non è nel catalogo, usa il nome più generico possibile in italiano.

RAGIONA COSÌ:
1. CONTROLLA cosa ha già: guarda OGNI prodotto nell'inventario prima di suggerire. Se ha già pollo, non suggerire pollo. Se ha già pasta, non suggerire altra pasta.
2. CONTROLLA la lista Bring!: NON suggerire nulla che sia già nella lista. Neanche varianti simili (es. "Fagioli" se c'è "Fagioli in lattina").
3. PRODOTTI SCADUTI/IN SCADENZA: se qualcosa sta per scadere o è scaduto, suggerisci un sostituto fresco.
4. STAGIONALITÀ ({$meseIt}): prediligi FRUTTA e VERDURA di stagione. A {$meseIt} in Italia: carciofi, asparagi, spinaci, bietole, finocchi, radicchio, arance, kiwi, mele, pere.
5. DIETA SANA: assicurati che l'utente abbia proteine, fibre, vitamine, carboidrati complessi. Evita eccessi di prodotti trasformati.
6. BASI MANCANTI: controlla se mancano alimenti essenziali come uova, latte, pane, frutta fresca, verdura, proteine.
7. VARIETÀ: non suggerire 5 tipi di frutta se manca la carne. Bilancia le categorie.

LIMITI:
- Massimo 12 suggerimenti
- Ordina per PRIORITÀ REALE (prima le mancanze gravi, poi i nice-to-have)
- Ogni motivo deve essere SPECIFICO ("non hai proteine fresche" non "è buono")
- Nel campo "specification" indica SEMPRE la quantità consigliata per una famiglia (es: "500g", "6 uova bio", "2 mazzetti", "1 bottiglia da 1L")

Rispondi SOLO con un JSON valido (senza markdown, senza backtick):
{
  "suggestions": [
    {
      "name": "nome prodotto in italiano",
      "specification": "quantità consigliata e dettagli (es: 500g, 6 uova bio, 2 mazzetti, 1 bottiglia da 1L)",
      "reason": "motivo breve",
      "category": "frutta|verdura|latticini|carne|pesce|pane|pasta|conserve|condimenti|bevande|snack|surgelati|cereali|igiene|pulizia|altro",
      "priority": "alta|media|bassa"
    }
  ],
  "seasonal_tip": "Un consiglio stagionale breve"
}
PROMPT;

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    
    $payload = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.8,
            'maxOutputTokens' => 2048,
        ]
    ];
    
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 30,
        ]
    ]);
    
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        echo json_encode(['success' => false, 'error' => 'Errore di connessione a Gemini']);
        return;
    }
    
    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    // Clean markdown artifacts
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/```\s*$/', '', $text);
    $text = trim($text);
    
    $suggestions = json_decode($text, true);
    if (!$suggestions || !isset($suggestions['suggestions'])) {
        echo json_encode(['success' => false, 'error' => 'Risposta AI non valida', 'raw' => $text]);
        return;
    }
    
    // Post-filter: remove any suggestions that match Bring! list items (safety net)
    $bringLower = array_map('mb_strtolower', $bringItems);
    $filtered = array_values(array_filter($suggestions['suggestions'], function($s) use ($bringLower) {
        $sName = mb_strtolower($s['name'] ?? '');
        foreach ($bringLower as $b) {
            // Check exact match or if one contains the other
            if ($sName === $b || str_contains($sName, $b) || str_contains($b, $sName)) {
                return false;
            }
        }
        return true;
    }));
    
    echo json_encode([
        'success' => true,
        'suggestions' => $filtered,
        'seasonal_tip' => $suggestions['seasonal_tip'] ?? '',
        'listUUID' => $listUUID,
    ]);
}
