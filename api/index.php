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
    
    echo json_encode(['success' => true, 'remaining' => $newQty]);
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
    
    // Expiring soon (next 7 days)
    $expiring = $db->query("
        SELECT i.*, p.name, p.brand 
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.expiry_date IS NOT NULL AND i.expiry_date <= date('now', '+7 days') AND i.expiry_date >= date('now')
        ORDER BY i.expiry_date ASC
    ")->fetchAll();
    
    // Expired
    $expired = $db->query("
        SELECT i.*, p.name, p.brand 
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
        SELECT p.name, p.brand, p.category, i.quantity, p.unit, i.location, i.expiry_date,
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
    {"name": "nome ingrediente", "qty": "quantità per $persons persone", "from_pantry": true},
    {"name": "sale", "qty": "q.b.", "from_pantry": false}
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
        echo json_encode(['success' => true, 'recipe' => $recipe]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Impossibile generare la ricetta', 'raw' => $text]);
    }
}
