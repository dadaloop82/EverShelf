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
        case 'bring_suggest':
            bringSuggestItems($db);
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

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ===== CLIENT LOG =====

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
    
    if (!empty($input['id'])) {
        // Update existing
        $stmt = $db->prepare("
            UPDATE products SET name=?, brand=?, category=?, image_url=?, unit=?, 
            default_quantity=?, notes=?, barcode=?, package_unit=?, updated_at=CURRENT_TIMESTAMP
            WHERE id=?
        ");
        $stmt->execute([
            $input['name'], $input['brand'] ?? '', $input['category'] ?? '',
            $input['image_url'] ?? '', $input['unit'] ?? 'pz',
            $input['default_quantity'] ?? 1, $input['notes'] ?? '',
            $input['barcode'] ?? null, $input['package_unit'] ?? '', $input['id']
        ]);
        echo json_encode(['success' => true, 'id' => $input['id']]);
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO products (barcode, name, brand, category, image_url, unit, default_quantity, notes, package_unit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $barcode = !empty($input['barcode']) ? $input['barcode'] : null;
        $stmt->execute([
            $barcode, $input['name'], $input['brand'] ?? '',
            $input['category'] ?? '', $input['image_url'] ?? '',
            $input['unit'] ?? 'pz', $input['default_quantity'] ?? 1,
            $input['notes'] ?? '', $input['package_unit'] ?? ''
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
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed
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
        $stmt = $db->prepare("SELECT name FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $prodName = $stmt->fetchColumn();
        if ($prodName) {
            $auth = bringAuth();
            if ($auth) {
                $listUUID = $auth['bringListUUID'];
                $bringKey = italianToBring($prodName);
                $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
                if ($listData && isset($listData['purchase'])) {
                    foreach ($listData['purchase'] as $item) {
                        if (strcasecmp($item['name'] ?? '', $bringKey) === 0) {
                            $body = http_build_query(['uuid' => $listUUID, 'remove' => $bringKey]);
                            bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
                            $removedFromBring = true;
                            break;
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
    
    // Handle "throw all from all locations"
    if ($useAll && $location === '__all__') {
        $stmt = $db->prepare("SELECT id, quantity, location FROM inventory WHERE product_id = ? AND quantity > 0");
        $stmt->execute([$productId]);
        $allItems = $stmt->fetchAll();
        $totalRemoved = 0;
        foreach ($allItems as $item) {
            $totalRemoved += $item['quantity'];
            $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->execute([$item['id']]);
            $type = ($notes === 'Buttato') ? 'waste' : 'out';
            $stmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $type, $item['quantity'], $item['location'], $notes]);
        }
        echo json_encode(['success' => true, 'remaining' => 0, 'removed' => $totalRemoved]);
        return;
    }
    
    $stmt = $db->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location = ? AND quantity > 0 ORDER BY (quantity != CAST(CAST(quantity AS INTEGER) AS REAL)) DESC, quantity ASC");
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
    $stmt2 = $db->prepare("SELECT unit, default_quantity, package_unit FROM products WHERE id = ?");
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
                $stmt3 = $db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date, vacuum_sealed) VALUES (?, ?, ?, ?, ?)");
                $stmt3->execute([$productId, $location, $newFraction, $origRow['expiry_date'], $origRow['vacuum_sealed'] ?? 0]);
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
    
    if ($newQty <= 0) {
        $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$existing['id']]);
    } else {
        $stmt = $db->prepare("UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$newQty, $existing['id']]);
    }
    
    // Log transaction
    $type = ($notes === 'Buttato') ? 'waste' : 'out';
    $stmt = $db->prepare("INSERT INTO transactions (product_id, type, quantity, location, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$productId, $type, $quantity, $location, $notes]);
    
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
                        $body = http_build_query([
                            'uuid' => $listUUID,
                            'purchase' => $bringName,
                            'specification' => '',
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
    $stmt = $db->prepare("SELECT name, unit, default_quantity, package_unit FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $prodInfo = $stmt->fetch();
    
    $response = ['success' => true, 'remaining' => $remaining, 'added_to_bring' => $addedToBring,
                  'total_remaining' => $totalRemaining];
    if ($prodInfo) {
        $response['product_name'] = $prodInfo['name'];
        $response['product_unit'] = $prodInfo['unit'];
        $response['product_default_qty'] = (float)($prodInfo['default_quantity'] ?: 0);
        $response['product_package_unit'] = $prodInfo['package_unit'] ?: '';
    }
    if ($openedId) $response['opened_id'] = $openedId;
    echo json_encode($response);
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

// ===== STATS =====

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
    
    // Opened (partially used items with known package capacity)
    $opened = $db->query("
        SELECT i.*, p.name, p.brand, p.category, p.unit, p.default_quantity, p.package_unit, p.image_url,
               COALESCE(i.vacuum_sealed, 0) as vacuum_sealed
        FROM inventory i JOIN products p ON i.product_id = p.id 
        WHERE i.quantity > 0 AND p.default_quantity > 0
          AND (
            -- conf products with fractional quantity
            (p.unit = 'conf' AND p.package_unit IS NOT NULL
              AND CAST(i.quantity AS REAL) != CAST(CAST(i.quantity AS INTEGER) AS REAL))
            OR
            -- non-conf products where quantity is not a clean multiple of package size (>2% tolerance)
            (p.unit != 'conf'
              AND ABS(i.quantity - ROUND(CAST(i.quantity AS REAL) / p.default_quantity) * p.default_quantity) > (p.default_quantity * 0.02))
          )
        ORDER BY i.updated_at DESC
    ")->fetchAll();

    // Waste vs consumption stats (last 30 days)
    $wasteStats = $db->query("
        SELECT type, COUNT(*) as count
        FROM transactions
        WHERE type IN ('out', 'waste') AND created_at >= datetime('now', '-30 days')
        GROUP BY type
    ")->fetchAll();
    $used30 = 0; $wasted30 = 0;
    foreach ($wasteStats as $ws) {
        if ($ws['type'] === 'out') $used30 = (int)$ws['count'];
        if ($ws['type'] === 'waste') $wasted30 = (int)$ws['count'];
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
        'used_30d' => $used30,
        'wasted_30d' => $wasted30,
    ]);
}

// ===== SETTINGS =====

function getServerSettings(): void {
    $envFile = __DIR__ . '/../.env';
    $envVars = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
            list($key, $val) = explode('=', $line, 2);
            $envVars[trim($key)] = trim($val);
        }
    }
    
    // Return masked versions for security
    $geminiKey = $envVars['GEMINI_API_KEY'] ?? '';
    $bringEmail = $envVars['BRING_EMAIL'] ?? '';
    $bringPassword = $envVars['BRING_PASSWORD'] ?? '';
    
    echo json_encode([
        'gemini_key' => $geminiKey,
        'gemini_key_set' => !empty($geminiKey),
        'bring_email' => $bringEmail,
        'bring_password_set' => !empty($bringPassword)
    ]);
}

function saveSettings(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $envFile = __DIR__ . '/../.env';
    
    // Read existing .env content
    $envContent = '';
    $envVars = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            list($key, $val) = explode('=', $line, 2);
            $envVars[trim($key)] = trim($val);
        }
    }
    
    // Update values from input — only overwrite if new value is non-empty
    if (!empty($input['gemini_key'])) {
        $envVars['GEMINI_API_KEY'] = $input['gemini_key'];
    }
    if (!empty($input['bring_email'])) {
        $envVars['BRING_EMAIL'] = $input['bring_email'];
    }
    if (!empty($input['bring_password'])) {
        $envVars['BRING_PASSWORD'] = $input['bring_password'];
    }
    
    // Write .env file
    $lines = [];
    foreach ($envVars as $key => $val) {
        $lines[] = "{$key}={$val}";
    }
    $result = file_put_contents($envFile, implode("\n", $lines) . "\n");
    
    if ($result !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not write .env file']);
    }
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

// ===== GEMINI CHAT =====
function geminiChat(PDO $db): void {
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
        SELECT p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date,
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

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.8,
            'maxOutputTokens' => 1500
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
    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($reply)) {
        echo json_encode(['success' => false, 'error' => 'Risposta vuota da Gemini']);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $reply]);
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
    $options = $input['options'] ?? [];
    $appliances = $input['appliances'] ?? [];
    $dietaryRestrictions = $input['dietary_restrictions'] ?? '';
    $todayRecipes = $input['today_recipes'] ?? [];

    // Fetch all inventory items with expiry info
    $stmt = $db->query("
        SELECT p.id AS product_id, p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date,
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
        if ($item['unit'] === 'conf' && !empty($item['package_unit']) && $item['default_quantity'] > 0) {
            $line .= " (da {$item['default_quantity']} {$item['package_unit']} ciascuna, totale: " . ($item['quantity'] * $item['default_quantity']) . " {$item['package_unit']})";
        }
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
        // Flag fridge items for priority
        if (strtolower($item['location']) === 'frigo') {
            $line .= " [IN FRIGO - PRIORITÀ]";
        }
        // Flag opened packages (fractional quantity = already opened)
        $qty = floatval($item['quantity']);
        if ($qty > 0 && $qty < 1 && $item['unit'] === 'conf') {
            $line .= " [CONFEZIONE APERTA - USA PRIMA]";
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

    // Build extra rules from options
    $extraRules = [];
    $optionLabels = [
        'veloce' => 'La ricetta deve essere VELOCE: massimo 15-20 minuti totali di preparazione e cottura.',
        'pocafame' => 'L\'utente ha POCA FAME: proponi una porzione leggera, magari uno snack, un\'insalata o qualcosa di semplice e poco abbondante.',
        'scadenze' => 'PRIORITÀ SCADENZE: usa ASSOLUTAMENTE per primi gli ingredienti più vicini alla scadenza o già scaduti (se ancora commestibili).',
        'salutare' => 'Ricetta EXTRA SALUTARE: prediligi ingredienti integrali, tante verdure, pochi grassi, cotture leggere.',
        'opened' => 'PRIORITÀ COSE APERTE: dai la MASSIMA PRIORITÀ ai prodotti con confezione aperta (contrassegnati [CONFEZIONE APERTA]) e a quelli in FRIGO (contrassegnati [IN FRIGO]). Questi prodotti si deteriorano più in fretta e DEVONO essere usati per primi. Costruisci la ricetta attorno a questi ingredienti.',
        'zerowaste' => 'ZERO SPRECHI: cerca di usare quanti più ingredienti in scadenza possibile, combina anche ingredienti insoliti pur di non sprecare nulla.'
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
        $appliancesText = "\n\nELETTRODOMESTICI DISPONIBILI:\nL'utente dispone di: " . implode(', ', $appliances) . ".\nPuoi usare SOLO questi elettrodomestici (più fornelli e forno che si presumono sempre disponibili). Non suggerire ricette che richiedano elettrodomestici non elencati.";
    }
    
    // Dietary restrictions
    $dietaryText = '';
    if (!empty($dietaryRestrictions)) {
        $dietaryText = "\n\nRESTRIZIONI ALIMENTARI:\n{$dietaryRestrictions}\nRispetta SEMPRE queste restrizioni.";
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
        $varietyText .= "\n\nRICETTE GIÀ PREPARATE OGGI:\n{$todayList}\nNON proporre una ricetta simile o con lo stesso concetto di quelle già fatte oggi. Varia il tipo di piatto, gli ingredienti principali e lo stile di cucina. Ad esempio se a pranzo c'era una piadina, a cena proponi pasta, riso, zuppa o altro — MAI un'altra piadina o wrap o piatto concettualmente simile.";
    }
    // Weekly variety: list all recent recipes so AI avoids repetition
    $weekOnly = array_diff($weekTitles, $todayTitles);
    if (!empty($weekOnly)) {
        $weekList = implode(', ', array_map(function($t) { return '"' . $t . '"'; }, array_values($weekOnly)));
        $varietyText .= "\n\nRICETTE DEGLI ULTIMI 7 GIORNI:\n{$weekList}\nCerca di variare rispetto a queste ricette recenti: evita piatti troppo simili o con gli stessi ingredienti principali. Alterna pasta, riso, zuppe, carne, pesce, verdure, piatti freddi, ecc.";
    }

    $prompt = <<<PROMPT
Sei un nutrizionista e chef italiano esperto. Genera UNA ricetta per $mealLabel per $persons persona/e usando PRINCIPALMENTE gli ingredienti disponibili nella dispensa dell'utente.
{$extraRulesText}{$appliancesText}{$dietaryText}{$varietyText}

REGOLE IMPORTANTI:
1. PRIORITÀ ASSOLUTA: usa prima gli ingredienti in scadenza o già scaduti (se ancora utilizzabili)
2. SUGGERIMENTO (non obbligatorio): quando possibile, preferisci ingredienti in FRIGO (contrassegnati [IN FRIGO]) e quelli con CONFEZIONE APERTA (contrassegnati [CONFEZIONE APERTA]) perché si deteriorano più in fretta. Ma se la ricetta migliore usa altri ingredienti, va benissimo.
3. Prediligi una ricetta SANA, EQUILIBRATA e NUTRIENTE
4. Usa SOLO ingredienti dalla lista sotto, più al massimo acqua, sale, pepe e olio che si presumono sempre disponibili
5. Adatta le quantità per $persons persona/e
6. Se non ci sono abbastanza ingredienti per una ricetta completa, suggerisci la migliore combinazione possibile
7. La ricetta deve essere adatta al pasto: $mealLabel
8. IMPORTANTE - QUANTITÀ NUMERICHE: per ogni ingrediente dalla dispensa, il campo "qty_number" DEVE contenere il valore NUMERICO da scalare dall'inventario, espresso nella STESSA unità di misura della dispensa. Esempio: se in dispensa c'è "Farina: 1000 g" e la ricetta richiede 200g, qty_number = 200. Se "Riso: 2 kg" e servono 300g, qty_number = 0.3. Per ingredienti non dalla dispensa, qty_number = 0.
9. GESTIONE SMART QUANTITÀ: NON lasciare rimasugli poco usabili in dispensa. Se un ingrediente ha una quantità piccola (es. 50g di formaggio, 1 uovo, 100ml di latte), preferisci usarlo TUTTO piuttosto che lasciarne una quantità inutilizzabile. Se invece la quantità è abbondante, usa solo il necessario lasciando abbastanza per un altro pasto. Pensa sempre: "quello che resta sarà sufficiente per un altro utilizzo?"

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
                        $ing['inventory_unit'] = $bestMatch['unit'];
                        $ing['inventory_qty'] = (float)$bestMatch['quantity'];
                        $ing['default_quantity'] = (float)($bestMatch['default_quantity'] ?? 0);
                        $ing['package_unit'] = $bestMatch['package_unit'] ?? '';
                        $ing['available_qty'] = $bestMatch['quantity'] . ' ' . $bestMatch['unit'];
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
                                elseif ($ru === 'kg') $recipeUnit = 'kg';
                                elseif ($ru === 'ml') $recipeUnit = 'ml';
                                elseif ($ru === 'cl') $recipeUnit = 'ml'; // cl→ml
                                elseif ($ru === 'l' || strpos($ru, 'litr') === 0) $recipeUnit = 'l';
                                elseif (strpos($ru, 'pz') === 0 || strpos($ru, 'pezz') === 0) $recipeUnit = 'pz';
                                elseif (strpos($ru, 'conf') === 0) $recipeUnit = 'conf';
                            }
                            
                            // Convert qty_number to inventory unit if mismatch detected
                            if ($recipeUnit && $recipeUnit !== $invUnit) {
                                // Weight conversions
                                if ($recipeUnit === 'g' && $invUnit === 'kg') {
                                    $qtyNum = $recipeVal / 1000;
                                } elseif ($recipeUnit === 'kg' && $invUnit === 'g') {
                                    $qtyNum = $recipeVal * 1000;
                                // Volume conversions
                                } elseif ($recipeUnit === 'ml' && $invUnit === 'l') {
                                    $qtyNum = $recipeVal / 1000;
                                } elseif ($recipeUnit === 'l' && $invUnit === 'ml') {
                                    $qtyNum = $recipeVal * 1000;
                                // g/kg/ml/l → pz (approximate to nearest piece)
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
    $skipped = 0;
    $errors = [];
    
    // Fetch current list to check for duplicates
    $existingNames = [];
    $listData = bringRequest('GET', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}");
    if ($listData && isset($listData['purchase'])) {
        foreach ($listData['purchase'] as $existingItem) {
            $existingNames[] = strtolower($existingItem['name'] ?? '');
        }
    }
    
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        if (empty($name)) continue;
        
        // Map Italian name to Bring! catalog key (German) for proper recognition
        $bringName = italianToBring($name);
        
        // Skip if already on the list
        if (in_array(strtolower($bringName), $existingNames)) {
            $skipped++;
            continue;
        }
        
        $body = http_build_query([
            'uuid' => $listUUID,
            'purchase' => $bringName,
            'specification' => '',
        ]);
        
        $result = bringRequest('PUT', "https://api.getbring.com/rest/v2/bringlists/{$listUUID}", $body);
        if ($result !== null) {
            $added++;
        } else {
            $errors[] = $name;
        }
    }
    
    echo json_encode(['success' => true, 'added' => $added, 'skipped' => $skipped, 'errors' => $errors]);
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
- NON inserire quantità, marche o dettagli nel campo "specification" — lascialo sempre vuoto.
- Usa nomi GENERICI dal catalogo Bring! (es: "Latte" non "Latte Granarolo 1L").

Rispondi SOLO con un JSON valido (senza markdown, senza backtick):
{
  "suggestions": [
    {
      "name": "nome prodotto generico in italiano",
      "specification": "",
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
 * Use Gemini AI to pick the best product from search results
 */
function aiSelectBestProduct(string $itemName, string $spec, array $products, string $customPrompt = ''): ?array {
    $env = loadEnvVars();
    $apiKey = $env['GEMINI_API_KEY'] ?? '';
    if (empty($apiKey)) return null;

    $defaultPrompt = "Sei un assistente per la spesa online. Ti viene dato il nome di un prodotto che l'utente vuole comprare (con eventuale descrizione tra parentesi) e una lista di prodotti trovati nel catalogo del supermercato.

Regole di selezione:
- Scegli il prodotto che corrisponde ESATTAMENTE a quello richiesto (stessa categoria merceologica)
- La DESCRIZIONE tra parentesi è FONDAMENTALE: se l'utente cerca \"Pancetta (a cubetti)\", DEVI trovare pancetta A CUBETTI, non pancetta generica
- Se la descrizione include un tipo specifico (\"a cubetti\", \"a fette\", \"biologico\", \"cotto\", \"a pasta dura\"), il prodotto DEVE contenere quella caratteristica nel nome
- Preferisci prodotti freschi/sfusi rispetto a trasformati (es. \"Arance\" = arance frutta, NON aranciata bevanda)
- Se ci sono più varianti valide, scegli quella con il miglior rapporto qualità/prezzo
- Preferisci formati standard per una famiglia
- NON scegliere mai un prodotto di categoria diversa (bevanda vs frutta, surgelato vs fresco, condimento vs ortaggio, pasta ripiena vs formaggio, ecc.)
- \"Finocchio\" = ortaggio fresco, NON semi di finocchio o tisana
- \"Arance\" = frutta fresca, NON aranciata o succo
- \"Formaggio\" = formaggio intero/pezzo, NON prodotti che contengono formaggio come ingrediente (ravioli, sfogliavelo, ecc.)
- \"Detergente intimo\" = detergente per igiene intima, NON detersivo generico
- Rispondi -1 se NESSUN prodotto corrisponde ragionevolmente alla richiesta

Rispondi SOLO con il numero (indice 0-based) del prodotto migliore, oppure -1 se nessun prodotto è appropriato.";

    $prompt = !empty($customPrompt) ? $customPrompt : $defaultPrompt;

    // Build product list
    $productList = '';
    foreach ($products as $i => $p) {
        $productList .= "[$i] \"{$p['name']}\" - {$p['brand']} - €" . number_format($p['price'], 2) . " - {$p['packageDescr']}\n";
    }

    $fullPrompt = "{$prompt}\n\nProdotto cercato: \"{$itemName}\"" . ($spec ? " ({$spec})" : '') . "\n\nProdotti trovati:\n{$productList}\nRispondi SOLO con il numero (es. 0, 1, 2... oppure -1):";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    $payload = json_encode([
        'contents' => [['parts' => [['text' => $fullPrompt]]]],
        'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 16],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) return null;

    $data = json_decode($response, true);
    $text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if (preg_match('/-?\d+/', $text, $m)) {
        $idx = (int)$m[0];
        if ($idx >= 0 && $idx < count($products)) {
            return $products[$idx];
        } elseif ($idx === -1) {
            return null; // AI says nothing matches
        }
    }

    return null; // Could not parse, caller will use first result
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
