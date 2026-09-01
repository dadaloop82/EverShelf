<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/database.php';
require_once __DIR__ . '/../api/lib/reconciliation.php';

function testAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testSame(float $expected, float $actual, string $message): void {
    testAssert(abs($expected - $actual) < 0.000001, "$message (expected $expected, got $actual)");
}

function testQuantity(PDO $db, int $productId, string $location): float {
    $stmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE product_id = ? AND location = ?');
    $stmt->execute([$productId, $location]);
    return (float)$stmt->fetchColumn();
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys = ON');
initializeDB($db);
migrateDB($db);

$productStmt = $db->prepare(
    'INSERT INTO products (name, brand, image_url, unit, default_quantity, package_unit) VALUES (?, ?, ?, ?, ?, ?)'
);
$productStmt->execute(['Coffee', 'Test', 'https://example.test/coffee.png', 'pz', 1, '']);
$coffeeId = (int)$db->lastInsertId();
$productStmt->execute(['Milk', 'Test', '', 'l', 1, '']);
$milkId = (int)$db->lastInsertId();
$productStmt->execute(['Rice', 'Test', '', 'g', 1000, '']);
$riceId = (int)$db->lastInsertId();
$productStmt->execute(['Tea', 'Test', '', 'pz', 1, '']);
$teaId = (int)$db->lastInsertId();

// Negative variance: opened rows are depleted first; uncounted products stay untouched.
$db->prepare(
    "INSERT INTO inventory (product_id, location, quantity, expiry_date, opened_at)
     VALUES (?, 'dispensa', 4, '2026-09-01', '2026-08-29 10:00:00')"
)->execute([$coffeeId]);
$openedCoffeeRow = (int)$db->lastInsertId();
$db->prepare(
    "INSERT INTO inventory (product_id, location, quantity, expiry_date)
     VALUES (?, 'dispensa', 6, '2026-10-01')"
)->execute([$coffeeId]);
$db->prepare("INSERT INTO inventory (product_id, location, quantity) VALUES (?, 'dispensa', 5)")
    ->execute([$milkId]);

$session = reconciliationStartCore($db, 'dispensa', ['dispensa', 'frigo', 'freezer']);
$sessionId = (int)$session['session']['id'];
testAssert($session['session']['total_items'] === 2, 'start snapshots every stocked product');
testAssert($session['session']['counted_items'] === 0, 'new session starts uncounted');
$coffeeSnapshot = array_values(array_filter($session['items'], fn(array $item): bool => $item['product_id'] === $coffeeId))[0];
testAssert($coffeeSnapshot['product_image_url'] === 'https://example.test/coffee.png', 'session snapshots the catalog product image');

$resumed = reconciliationStartCore($db, 'dispensa', ['dispensa', 'frigo', 'freezer']);
testAssert($resumed['resumed'] === true && (int)$resumed['session']['id'] === $sessionId, 'one active session per location');

// Zero-quantity inventory is available on demand without changing the session.
$db->prepare("INSERT INTO inventory (product_id, location, quantity) VALUES (?, 'dispensa', 0)")
    ->execute([$riceId]);
$zeroCandidates = reconciliationZeroItemsCore($db, $sessionId);
testAssert(array_column($zeroCandidates, 'product_id') === [$riceId, $teaId], 'optional zero list includes every catalog product with no positive stock at the session location');
testAssert($zeroCandidates[0]['expected_quantity'] === 0.0 && $zeroCandidates[0]['counted_quantity'] === null, 'zero candidate is presented as expected zero and uncounted');
$unchangedSession = reconciliationGetCore($db, $sessionId);
testAssert($unchangedSession['session']['total_items'] === 2, 'revealing zero candidates does not change session progress');

$counted = reconciliationCountCore($db, $sessionId, $coffeeId, 7);
testAssert($counted['session']['uncounted_items'] === 1, 'uncounted products remain explicit');
reconciliationReviewCore($db, $sessionId);
$applied = reconciliationApplyCore($db, $sessionId);
testAssert($applied['already_applied'] === false, 'first apply is not idempotent replay');
testSame(7, testQuantity($db, $coffeeId, 'dispensa'), 'negative variance updates counted product');
testSame(5, testQuantity($db, $milkId, 'dispensa'), 'uncounted product remains unchanged');
$openedQty = (float)$db->query("SELECT quantity FROM inventory WHERE id = $openedCoffeeRow")->fetchColumn();
testSame(1, $openedQty, 'negative variance depletes opened row first');
testAssert((int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn() === 0, 'apply does not create ordinary transactions');
testSame(-3, (float)$db->query("SELECT SUM(delta) FROM inventory_adjustments WHERE reconciliation_id = $sessionId")->fetchColumn(), 'signed adjustment is audited');
$auditView = reconciliationGetCore($db, $sessionId);
testAssert(count($auditView['adjustments']) === 1 && $auditView['adjustments'][0]['delta'] === -3.0, 'applied session exposes its row-level audit trail');

$replayed = reconciliationApplyCore($db, $sessionId);
testAssert($replayed['already_applied'] === true, 'second apply is an idempotent replay');
testSame(7, testQuantity($db, $coffeeId, 'dispensa'), 'idempotent replay does not change stock');
testAssert((int)$db->query("SELECT COUNT(*) FROM inventory_adjustments WHERE reconciliation_id = $sessionId")->fetchColumn() === 1, 'idempotent replay creates no duplicate audit rows');

// Zero is a deliberate physical count, distinct from NULL/unseen.
$db->prepare("INSERT INTO inventory (product_id, location, quantity) VALUES (?, 'zero_bin', 2)")->execute([$milkId]);
$zero = reconciliationStartCore($db, 'zero_bin', ['zero_bin']);
$zeroId = (int)$zero['session']['id'];
$zeroCount = reconciliationCountCore($db, $zeroId, $milkId, 0);
testAssert($zeroCount['items'][0]['counted_quantity'] === 0.0, 'explicit zero remains counted rather than NULL');
reconciliationReviewCore($db, $zeroId);
reconciliationApplyCore($db, $zeroId);
testSame(0, testQuantity($db, $milkId, 'zero_bin'), 'explicit zero depletes the recorded stock');
testAssert(
    (int)$db->query("SELECT COUNT(*) FROM inventory WHERE product_id = $milkId AND location = 'zero_bin'")->fetchColumn() === 0,
    'explicit zero removes the depleted inventory row'
);
testAssert(
    (int)$db->query("SELECT COUNT(*) FROM products WHERE id = $milkId")->fetchColumn() === 1,
    'explicit zero preserves the catalog product'
);

// Positive remnants remain countable even when the normal inventory UI hides them.
$db->prepare("INSERT INTO inventory (product_id, location, quantity) VALUES (?, 'remnant_shelf', 10)")
    ->execute([$riceId]);
$remnant = reconciliationStartCore($db, 'remnant_shelf', ['remnant_shelf']);
testAssert($remnant['session']['total_items'] === 1, 'positive weight remnant is included in the reconciliation snapshot');
testAssert($remnant['items'][0]['expected_quantity'] === 10.0, 'weight remnant keeps its raw expected quantity');
reconciliationCancelCore($db, (int)$remnant['session']['id']);

// Positive variance with one row adds to that row.
$db->prepare("INSERT INTO inventory (product_id, location, quantity) VALUES (?, 'frigo', 2)")->execute([$milkId]);
$singleRowId = (int)$db->lastInsertId();
$single = reconciliationStartCore($db, 'frigo', ['dispensa', 'frigo', 'freezer']);
$singleId = (int)$single['session']['id'];
reconciliationCountCore($db, $singleId, $milkId, 5);
reconciliationReviewCore($db, $singleId);
reconciliationApplyCore($db, $singleId);
testSame(5, (float)$db->query("SELECT quantity FROM inventory WHERE id = $singleRowId")->fetchColumn(), 'positive variance uses sole existing row');

// Positive variance with multiple rows creates a neutral, null-expiry row.
$db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date) VALUES (?, 'freezer', 1, '2027-01-01')")->execute([$coffeeId]);
$db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date) VALUES (?, 'freezer', 2, '2027-02-01')")->execute([$coffeeId]);
$multiple = reconciliationStartCore($db, 'freezer', ['dispensa', 'frigo', 'freezer']);
$multipleId = (int)$multiple['session']['id'];
reconciliationCountCore($db, $multipleId, $coffeeId, 5);
reconciliationReviewCore($db, $multipleId);
reconciliationApplyCore($db, $multipleId);
testSame(5, testQuantity($db, $coffeeId, 'freezer'), 'multi-row positive variance reaches physical count');
$neutral = $db->query(
    "SELECT quantity, expiry_date, opened_at FROM inventory
     WHERE product_id = $coffeeId AND location = 'freezer' ORDER BY id DESC LIMIT 1"
)->fetch();
testSame(2, (float)$neutral['quantity'], 'neutral adjustment row contains only surplus');
testAssert($neutral['expiry_date'] === null && $neutral['opened_at'] === null, 'neutral adjustment row has no invented expiry/opened metadata');

// A product absent from the start snapshot can be counted as an expected-zero find.
$empty = reconciliationStartCore($db, 'cantina', ['cantina']);
$emptyId = (int)$empty['session']['id'];
reconciliationCountCore($db, $emptyId, $riceId, 750);
reconciliationReviewCore($db, $emptyId);
reconciliationApplyCore($db, $emptyId);
testSame(750, testQuantity($db, $riceId, 'cantina'), 'expected-zero find creates inventory only on apply');

// Concurrent stock mutation aborts the whole apply and leaves the session reviewable.
$db->prepare("INSERT INTO inventory (product_id, location, quantity) VALUES (?, 'garage', 3)")->execute([$coffeeId]);
$conflict = reconciliationStartCore($db, 'garage', ['garage']);
$conflictId = (int)$conflict['session']['id'];
reconciliationCountCore($db, $conflictId, $coffeeId, 2);
reconciliationReviewCore($db, $conflictId);
$db->prepare("UPDATE inventory SET quantity = 4, updated_at = '2099-01-01 00:00:00' WHERE product_id = ? AND location = 'garage'")
    ->execute([$coffeeId]);
$conflicted = false;
try {
    reconciliationApplyCore($db, $conflictId);
} catch (ReconciliationException $e) {
    $conflicted = $e->errorKey === 'inventory_changed';
}
testAssert($conflicted, 'concurrent inventory change returns inventory_changed');
testSame(4, testQuantity($db, $coffeeId, 'garage'), 'conflicted apply rolls back without overwriting live stock');
testAssert(reconciliationSession($db, $conflictId)['status'] === 'review', 'conflicted session remains in review');
testAssert((int)$db->query("SELECT COUNT(*) FROM inventory_adjustments WHERE reconciliation_id = $conflictId")->fetchColumn() === 0, 'conflicted apply writes no audit rows');

// Expiry/opened metadata is also part of the snapshot because it controls depletion order.
$db->prepare("INSERT INTO inventory (product_id, location, quantity, expiry_date) VALUES (?, 'shelf', 1, '2027-03-01')")
    ->execute([$milkId]);
$metadata = reconciliationStartCore($db, 'shelf', ['shelf']);
$metadataId = (int)$metadata['session']['id'];
reconciliationCountCore($db, $metadataId, $milkId, 1);
reconciliationReviewCore($db, $metadataId);
$db->prepare("UPDATE inventory SET expiry_date = '2027-04-01' WHERE product_id = ? AND location = 'shelf'")
    ->execute([$milkId]);
$metadataConflict = false;
try {
    reconciliationApplyCore($db, $metadataId);
} catch (ReconciliationException $e) {
    $metadataConflict = $e->errorKey === 'inventory_changed';
}
testAssert($metadataConflict, 'expiry metadata change invalidates the row snapshot');

// Cancellation is terminal and never mutates stock.
$cancelled = reconciliationStartCore($db, 'ripostiglio', ['ripostiglio']);
$cancelledId = (int)$cancelled['session']['id'];
reconciliationCancelCore($db, $cancelledId);
testAssert(reconciliationSession($db, $cancelledId)['status'] === 'cancelled', 'cancel closes a session');

echo "reconciliation tests: ok\n";
