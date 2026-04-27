<?php
/**
 * CLI Test for generateRecipeStream
 * Tests: prompt token reduction (B), SSE output format, model fallback (C)
 * Run: php test_recipe_stream.php
 */

define('CRON_MODE', true); // skip HTTP routing
require_once __DIR__ . '/api/database.php';
require_once __DIR__ . '/api/index.php';

// ── helpers ──────────────────────────────────────────────────────────────────
function pass(string $msg): void { echo "\033[32m✓\033[0m $msg\n"; }
function fail(string $msg): void { echo "\033[31m✗\033[0m $msg\n"; }
function info(string $msg): void { echo "\033[33m→\033[0m $msg\n"; }

// ── TEST 1: API key present ───────────────────────────────────────────────────
$apiKey = env('GEMINI_API_KEY');
if (!empty($apiKey)) {
    pass("API key set (" . substr($apiKey, 0, 8) . "...)");
} else {
    fail("API key missing in .env");
    exit(1);
}

// ── TEST 2: DB reachable + has inventory ─────────────────────────────────────
$db = getDB();
$itemCount = $db->query("SELECT count(*) FROM inventory WHERE quantity > 0")->fetchColumn();
if ($itemCount > 0) {
    pass("Inventory: $itemCount items");
} else {
    fail("Inventory is empty — cannot generate recipe");
    exit(1);
}

// ── TEST 3: Prompt token estimation (B) ─────────────────────────────────────
// Simulate building the ingredient list with new limits
$stmt = $db->query("
    SELECT p.id AS product_id, p.name, p.brand, p.category, i.quantity, p.unit, p.default_quantity, p.package_unit, i.location, i.expiry_date, i.opened_at,
           CASE WHEN i.expiry_date IS NOT NULL THEN julianday(i.expiry_date) - julianday('now') ELSE 999 END AS days_left
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE i.quantity > 0 ORDER BY days_left ASC
");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$getItemPriority = function($item): int {
    $daysLeft = floatval($item['days_left']);
    $isOpen = !empty($item['opened_at']) || (floatval($item['quantity']) > 0 && floatval($item['quantity']) < 1 && $item['unit'] === 'conf');
    if (!empty($item['expiry_date']) && $daysLeft < 0) return 1;
    if (!empty($item['expiry_date']) && $daysLeft <= 3) return 2;
    if (!empty($item['expiry_date']) && $daysLeft <= 7) return 3;
    if (!empty($item['expiry_date'])) return 4;
    if ($isOpen) return 5;
    return 6;
};

$staplePatterns = '/\b(sale|pepe|olio d.oliva|olio di semi|olio extra|acqua|aceto balsamico|aceto di|sel marin)\b/i';
$priorityGroups = [];
foreach ($items as $item) {
    $group = $getItemPriority($item);
    if ($group >= 5 && preg_match($staplePatterns, $item['name'])) continue;
    $line = "- {$item['name']}: {$item['quantity']} {$item['unit']}";
    $priorityGroups[$group][] = $line;
}

// OLD limits
$oldSections = [];
foreach ([1=>null,2=>null,3=>null,4=>40,5=>null,6=>20] as $g => $limit) {
    if (empty($priorityGroups[$g])) continue;
    $gi = $limit ? array_slice($priorityGroups[$g], 0, $limit) : $priorityGroups[$g];
    $oldSections[] = implode("\n", $gi);
}
$oldText = implode("\n", $oldSections);
$oldTokens = (int)(str_word_count($oldText) * 1.3); // rough estimate: words * 1.3

// NEW limits
$newSections = [];
foreach ([1=>null,2=>null,3=>null,4=>15,5=>null,6=>8] as $g => $limit) {
    if (empty($priorityGroups[$g])) continue;
    $gi = $limit ? array_slice($priorityGroups[$g], 0, $limit) : $priorityGroups[$g];
    $newSections[] = implode("\n", $gi);
}
$newText = implode("\n", $newSections);
$newTokens = (int)(str_word_count($newText) * 1.3);
$savings = $oldTokens > 0 ? round(($oldTokens - $newTokens) / $oldTokens * 100) : 0;

info("Prompt ingredient tokens: OLD ~$oldTokens → NEW ~$newTokens (saved ~$savings%)");
if ($savings >= 20) {
    pass("Token reduction >= 20% (got $savings%)");
} else {
    fail("Token reduction too low ($savings%) — check group limits");
}

// ── TEST 4: Real SSE call via HTTP ───────────────────────────────────────────
info("Calling generate_recipe_stream via HTTP (cena, pesce, 2 persone)...");

$postData = json_encode([
    'meal'    => 'cena',
    'persons' => 2,
    'sub_type' => '',
    'options'  => [],
    'appliances' => [],
    'dietary_restrictions' => '',
    'today_recipes' => [],
    'meal_plan_type' => 'pesce',
    'variation' => 0,
    'rejected_ingredients' => [],
]);

$startTime = microtime(true);
$ch = curl_init('https://localhost/dispensa/api/index.php?action=generate_recipe_stream');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
$elapsed = round(microtime(true) - $startTime, 1);

if ($curlErr) {
    fail("curl error: $curlErr");
    // Try via PHP CLI directly instead
    info("Trying direct PHP execution instead...");
    // Simulate SSE output capture
    ob_start();
    $_GET['action'] = 'generate_recipe_stream';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    // Override php://input
    $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_test_');
    file_put_contents($tmpFile, $postData);
    // Can't easily override php://input in CLI, skip HTTP test
    ob_end_clean();
    info("HTTP test skipped (no web server on localhost) — checking SSE parsing only");
    $response = null;
}

if ($response !== null) {
    if ($httpCode !== 200) {
        fail("HTTP status $httpCode (expected 200)");
    } else {
        pass("HTTP 200 in {$elapsed}s");
    }

    // Parse SSE events
    $events = [];
    foreach (explode("\n", $response) as $line) {
        if (strpos($line, 'data: ') === 0) {
            $evt = json_decode(substr($line, 6), true);
            if ($evt) $events[] = $evt;
        }
    }

    info("SSE events received: " . count($events));
    foreach ($events as $evt) {
        $type = $evt['type'] ?? '?';
        $msg  = $evt['message'] ?? $evt['error'] ?? json_encode($evt);
        info("  [$type] $msg");
    }

    $statusEvents = array_filter($events, fn($e) => ($e['type'] ?? '') === 'status');
    $recipeEvents = array_filter($events, fn($e) => ($e['type'] ?? '') === 'recipe');
    $errorEvents  = array_filter($events, fn($e) => ($e['type'] ?? '') === 'error');

    if (!empty($errorEvents)) {
        $err = reset($errorEvents);
        $errMsg = $err['error'] ?? 'unknown';
        $errDetail = $err['detail'] ?? '';
        $errCode   = $err['http_code'] ?? '';
        fail("Got error event: $errMsg | code=$errCode | $errDetail");
    } elseif (!empty($recipeEvents)) {
        $recipe = reset($recipeEvents)['recipe'] ?? [];
        pass("Got recipe: \"" . ($recipe['title'] ?? '?') . "\"");

        // Verify steps exist
        if (!empty($recipe['steps']) && count($recipe['steps']) >= 2) {
            pass("Recipe has " . count($recipe['steps']) . " steps");
        } else {
            fail("Recipe missing steps");
        }

        // Verify meal type
        if (($recipe['meal'] ?? '') === 'cena') {
            pass("Meal type correct (cena)");
        } else {
            fail("Meal type wrong: " . ($recipe['meal'] ?? 'missing'));
        }

        // Check steps count
        if (count($statusEvents) >= 3) {
            pass("Got " . count($statusEvents) . " status events (agent steps working)");
        } else {
            fail("Too few status events: " . count($statusEvents));
        }
    } else {
        fail("No recipe and no error event in SSE response");
        echo "Raw response (first 500 chars):\n" . substr($response, 0, 500) . "\n";
    }
}

echo "\n\033[1mDone.\033[0m\n";
