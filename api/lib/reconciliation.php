<?php
/**
 * Physical inventory reconciliation.
 *
 * Counting is isolated from live inventory. Only reconciliationApplyCore()
 * mutates inventory, inside one SQLite IMMEDIATE transaction.
 */

final class ReconciliationException extends RuntimeException {
    public function __construct(
        public readonly string $errorKey,
        public readonly int $httpStatus = 400,
        public readonly array $details = [],
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : $errorKey);
    }
}

function reconciliationRound(float $quantity): float {
    return round($quantity, 6);
}

function reconciliationSession(PDO $db, int $sessionId): array {
    $stmt = $db->prepare('SELECT * FROM inventory_reconciliations WHERE id = ?');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new ReconciliationException('reconciliation_not_found', 404);
    }
    $row['id'] = (int)$row['id'];
    return $row;
}

function reconciliationProduct(PDO $db, int $productId): array {
    $stmt = $db->prepare(
        "SELECT id, name, brand, image_url, unit, default_quantity, COALESCE(package_unit, '') AS package_unit
         FROM products WHERE id = ?"
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new ReconciliationException('product_not_found', 404);
    }
    return $row;
}

/** @return list<array<string,mixed>> */
function reconciliationCurrentRows(PDO $db, int $productId, string $location): array {
    $stmt = $db->prepare(
        'SELECT id, quantity, expiry_date, opened_at, updated_at
         FROM inventory
         WHERE product_id = ? AND location = ? AND quantity > 0
         ORDER BY id ASC'
    );
    $stmt->execute([$productId, $location]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function reconciliationInsertItem(PDO $db, int $sessionId, array $product, float $expected): int {
    $stmt = $db->prepare(
        'INSERT INTO inventory_reconciliation_items
         (reconciliation_id, product_id, product_name, product_brand, product_image_url, product_unit,
          product_default_quantity, product_package_unit, expected_quantity)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $sessionId,
        (int)$product['id'],
        (string)$product['name'],
        (string)($product['brand'] ?? ''),
        (string)($product['image_url'] ?? ''),
        (string)($product['unit'] ?? 'pz'),
        (float)($product['default_quantity'] ?? 1),
        (string)($product['package_unit'] ?? ''),
        reconciliationRound($expected),
    ]);
    return (int)$db->lastInsertId();
}

function reconciliationInsertSnapshotRow(PDO $db, int $itemId, array $row): void {
    $stmt = $db->prepare(
        'INSERT INTO inventory_reconciliation_item_rows
         (item_id, inventory_id, expected_quantity, expiry_date, opened_at, row_updated_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $itemId,
        (int)$row['id'],
        reconciliationRound((float)$row['quantity']),
        $row['expiry_date'] ?? null,
        $row['opened_at'] ?? null,
        $row['updated_at'] ?? null,
    ]);
}

/** Start a session, or resume the active session for the location. */
function reconciliationStartCore(PDO $db, string $location, array $validLocations): array {
    $location = trim($location);
    if ($location === '' || !in_array($location, $validLocations, true)) {
        throw new ReconciliationException('invalid_location', 422);
    }

    $sessionId = 0;
    $resumed = false;
    dbWithRetry(function () use ($db, $location, &$sessionId, &$resumed): void {
        dbBeginImmediate($db);
        try {
            $active = $db->prepare(
                "SELECT id FROM inventory_reconciliations
                 WHERE location = ? AND status IN ('in_progress', 'review')
                 ORDER BY id DESC LIMIT 1"
            );
            $active->execute([$location]);
            $existingId = $active->fetchColumn();
            if ($existingId) {
                $sessionId = (int)$existingId;
                $resumed = true;
                dbCommit($db);
                return;
            }

            $db->prepare('INSERT INTO inventory_reconciliations (location) VALUES (?)')
                ->execute([$location]);
            $sessionId = (int)$db->lastInsertId();

            $stmt = $db->prepare(
                "SELECT i.id, i.product_id, i.quantity, i.expiry_date, i.opened_at, i.updated_at,
                        p.name, p.brand, p.image_url, p.unit, p.default_quantity,
                        COALESCE(p.package_unit, '') AS package_unit
                 FROM inventory i
                 JOIN products p ON p.id = i.product_id
                 WHERE i.location = ? AND i.quantity > 0
                 ORDER BY i.product_id, i.id"
            );
            $stmt->execute([$location]);
            $grouped = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $grouped[(int)$row['product_id']][] = $row;
            }

            foreach ($grouped as $productId => $rows) {
                $first = $rows[0];
                $product = [
                    'id' => $productId,
                    'name' => $first['name'],
                    'brand' => $first['brand'],
                    'image_url' => $first['image_url'],
                    'unit' => $first['unit'],
                    'default_quantity' => $first['default_quantity'],
                    'package_unit' => $first['package_unit'],
                ];
                $expected = array_sum(array_map(static fn(array $r): float => (float)$r['quantity'], $rows));
                $itemId = reconciliationInsertItem($db, $sessionId, $product, $expected);
                foreach ($rows as $row) {
                    reconciliationInsertSnapshotRow($db, $itemId, $row);
                }
            }
            dbCommit($db);
        } catch (Throwable $e) {
            dbRollback($db);
            throw $e;
        }
    });

    $result = reconciliationGetCore($db, $sessionId);
    $result['resumed'] = $resumed;
    return $result;
}

/** Save a count. NULL deliberately restores the item to uncounted. */
function reconciliationCountCore(PDO $db, int $sessionId, int $productId, mixed $rawCount): array {
    if ($sessionId <= 0 || $productId <= 0) {
        throw new ReconciliationException('session_and_product_required', 422);
    }
    $counted = null;
    if ($rawCount !== null) {
        if (!is_numeric($rawCount)) {
            throw new ReconciliationException('invalid_count', 422);
        }
        $counted = (float)$rawCount;
        if (!is_finite($counted) || $counted < 0 || $counted > 1000000000) {
            throw new ReconciliationException('invalid_count', 422);
        }
        $counted = reconciliationRound($counted);
    }

    dbWithRetry(function () use ($db, $sessionId, $productId, $counted): void {
        dbBeginImmediate($db);
        try {
            $session = reconciliationSession($db, $sessionId);
            if (!in_array($session['status'], ['in_progress', 'review'], true)) {
                throw new ReconciliationException('reconciliation_not_editable', 409, ['status' => $session['status']]);
            }

            $stmt = $db->prepare(
                'SELECT id FROM inventory_reconciliation_items
                 WHERE reconciliation_id = ? AND product_id = ?'
            );
            $stmt->execute([$sessionId, $productId]);
            $itemId = $stmt->fetchColumn();
            if (!$itemId) {
                if ($counted === null) {
                    throw new ReconciliationException('reconciliation_item_not_found', 404);
                }
                // Products absent from the start snapshot are expected-zero finds.
                $itemId = reconciliationInsertItem($db, $sessionId, reconciliationProduct($db, $productId), 0.0);
            }

            if ($counted === null) {
                $db->prepare(
                    'UPDATE inventory_reconciliation_items
                     SET counted_quantity = NULL, counted_at = NULL, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([(int)$itemId]);
            } else {
                $db->prepare(
                    'UPDATE inventory_reconciliation_items
                     SET counted_quantity = ?, counted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([$counted, (int)$itemId]);
            }
            if ($session['status'] === 'review') {
                $db->prepare(
                    "UPDATE inventory_reconciliations
                     SET status = 'in_progress', reviewed_at = NULL WHERE id = ?"
                )->execute([$sessionId]);
            }
            dbCommit($db);
        } catch (Throwable $e) {
            dbRollback($db);
            throw $e;
        }
    });

    return reconciliationGetCore($db, $sessionId);
}

function reconciliationReviewCore(PDO $db, int $sessionId): array {
    dbWithRetry(function () use ($db, $sessionId): void {
        dbBeginImmediate($db);
        try {
            $session = reconciliationSession($db, $sessionId);
            if (!in_array($session['status'], ['in_progress', 'review'], true)) {
                throw new ReconciliationException('reconciliation_not_reviewable', 409, ['status' => $session['status']]);
            }
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM inventory_reconciliation_items
                 WHERE reconciliation_id = ? AND counted_quantity IS NOT NULL'
            );
            $stmt->execute([$sessionId]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new ReconciliationException('no_counted_items', 422);
            }
            $db->prepare(
                "UPDATE inventory_reconciliations
                 SET status = 'review', reviewed_at = CURRENT_TIMESTAMP WHERE id = ?"
            )->execute([$sessionId]);
            dbCommit($db);
        } catch (Throwable $e) {
            dbRollback($db);
            throw $e;
        }
    });
    return reconciliationGetCore($db, $sessionId);
}

function reconciliationCancelCore(PDO $db, int $sessionId): array {
    dbWithRetry(function () use ($db, $sessionId): void {
        dbBeginImmediate($db);
        try {
            $session = reconciliationSession($db, $sessionId);
            if ($session['status'] === 'cancelled') {
                dbCommit($db);
                return;
            }
            if ($session['status'] === 'applied') {
                throw new ReconciliationException('applied_reconciliation_cannot_be_cancelled', 409);
            }
            $db->prepare(
                "UPDATE inventory_reconciliations
                 SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP WHERE id = ?"
            )->execute([$sessionId]);
            dbCommit($db);
        } catch (Throwable $e) {
            dbRollback($db);
            throw $e;
        }
    });
    return reconciliationGetCore($db, $sessionId);
}

/** @return list<array<string,mixed>> */
function reconciliationSnapshotRows(PDO $db, int $itemId): array {
    $stmt = $db->prepare(
        'SELECT inventory_id, expected_quantity, expiry_date, opened_at, row_updated_at
         FROM inventory_reconciliation_item_rows WHERE item_id = ? ORDER BY inventory_id'
    );
    $stmt->execute([$itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function reconciliationRowsMatch(array $snapshot, array $current): bool {
    if (count($snapshot) !== count($current)) {
        return false;
    }
    foreach ($snapshot as $index => $saved) {
        $live = $current[$index];
        if ((int)$saved['inventory_id'] !== (int)$live['id']) {
            return false;
        }
        if (abs((float)$saved['expected_quantity'] - (float)$live['quantity']) > 0.000001) {
            return false;
        }
        if ((string)($saved['expiry_date'] ?? '') !== (string)($live['expiry_date'] ?? '')) {
            return false;
        }
        if ((string)($saved['opened_at'] ?? '') !== (string)($live['opened_at'] ?? '')) {
            return false;
        }
        if ((string)($saved['row_updated_at'] ?? '') !== (string)($live['updated_at'] ?? '')) {
            return false;
        }
    }
    return true;
}

function reconciliationInsertAdjustment(
    PDO $db,
    array $session,
    array $item,
    ?int $inventoryId,
    int $sequence,
    float $delta,
    float $before,
    float $after
): void {
    $stmt = $db->prepare(
        'INSERT INTO inventory_adjustments
         (reconciliation_id, reconciliation_item_id, product_id, product_name,
          inventory_id, sequence, delta, before_quantity, after_quantity)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$session['id'],
        (int)$item['id'],
        (int)$item['product_id'],
        (string)$item['product_name'],
        $inventoryId,
        $sequence,
        reconciliationRound($delta),
        reconciliationRound($before),
        reconciliationRound($after),
    ]);
}

function reconciliationApplyDecrease(PDO $db, array $session, array $item, float $amount): void {
    $stmt = $db->prepare(
        "SELECT id, quantity FROM inventory
         WHERE product_id = ? AND location = ? AND quantity > 0
         ORDER BY CASE WHEN opened_at IS NOT NULL THEN 0 ELSE 1 END,
                  CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
                  expiry_date ASC, added_at ASC, id ASC"
    );
    $stmt->execute([(int)$item['product_id'], (string)$session['location']]);
    $remaining = $amount;
    $sequence = 1;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($remaining <= 0.000001) {
            break;
        }
        $before = (float)$row['quantity'];
        $taken = min($before, $remaining);
        $after = reconciliationRound(max(0.0, $before - $taken));
        $db->prepare('UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$after, (int)$row['id']]);
        reconciliationInsertAdjustment(
            $db, $session, $item, (int)$row['id'], $sequence++, -$taken, $before, $after
        );
        if ($after <= 0.000001) {
            $db->prepare('DELETE FROM inventory WHERE id = ?')->execute([(int)$row['id']]);
        }
        $remaining = reconciliationRound($remaining - $taken);
    }
    if ($remaining > 0.000001) {
        throw new ReconciliationException('reconciliation_apply_underflow', 409);
    }
}

function reconciliationApplyIncrease(PDO $db, array $session, array $item, float $amount): void {
    $stmt = $db->prepare(
        'SELECT id, quantity FROM inventory
         WHERE product_id = ? AND location = ? AND quantity > 0 ORDER BY id'
    );
    $stmt->execute([(int)$item['product_id'], (string)$session['location']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) {
        $row = $rows[0];
        $before = (float)$row['quantity'];
        $after = reconciliationRound($before + $amount);
        $db->prepare('UPDATE inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$after, (int)$row['id']]);
        reconciliationInsertAdjustment($db, $session, $item, (int)$row['id'], 1, $amount, $before, $after);
        return;
    }

    // Ambiguous expiry allocation (or no existing row): preserve existing rows
    // and create a neutral adjustment row with no expiry/opened metadata.
    $db->prepare('INSERT INTO inventory (product_id, location, quantity) VALUES (?, ?, ?)')
        ->execute([(int)$item['product_id'], (string)$session['location'], reconciliationRound($amount)]);
    $inventoryId = (int)$db->lastInsertId();
    reconciliationInsertAdjustment($db, $session, $item, $inventoryId, 1, $amount, 0.0, $amount);
}

function reconciliationAppliedSummary(PDO $db, int $sessionId): array {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS adjustment_rows, COALESCE(SUM(delta), 0) AS net_delta,
                COUNT(DISTINCT reconciliation_item_id) AS adjusted_items
         FROM inventory_adjustments WHERE reconciliation_id = ?'
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'adjustment_rows' => (int)($row['adjustment_rows'] ?? 0),
        'adjusted_items' => (int)($row['adjusted_items'] ?? 0),
        'net_delta' => reconciliationRound((float)($row['net_delta'] ?? 0)),
    ];
}

/** Validate every counted snapshot, then atomically apply only counted items. */
function reconciliationApplyCore(PDO $db, int $sessionId): array {
    $alreadyApplied = false;
    $result = dbWithRetry(function () use ($db, $sessionId, &$alreadyApplied): array {
        dbBeginImmediate($db);
        try {
            $session = reconciliationSession($db, $sessionId);
            if ($session['status'] === 'applied') {
                $alreadyApplied = true;
                $summary = reconciliationAppliedSummary($db, $sessionId);
                dbCommit($db);
                return $summary;
            }
            if ($session['status'] !== 'review') {
                throw new ReconciliationException('reconciliation_must_be_reviewed', 409, ['status' => $session['status']]);
            }

            $stmt = $db->prepare(
                'SELECT * FROM inventory_reconciliation_items
                 WHERE reconciliation_id = ? AND counted_quantity IS NOT NULL ORDER BY id'
            );
            $stmt->execute([$sessionId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) {
                throw new ReconciliationException('no_counted_items', 422);
            }

            $conflicts = [];
            foreach ($items as $item) {
                $snapshot = reconciliationSnapshotRows($db, (int)$item['id']);
                $current = reconciliationCurrentRows($db, (int)$item['product_id'], (string)$session['location']);
                $productChanged = false;
                try {
                    $liveProduct = reconciliationProduct($db, (int)$item['product_id']);
                    $productChanged = (string)$liveProduct['unit'] !== (string)$item['product_unit'];
                } catch (ReconciliationException $e) {
                    $productChanged = $e->errorKey === 'product_not_found';
                }
                if ($productChanged || !reconciliationRowsMatch($snapshot, $current)) {
                    $conflicts[] = [
                        'product_id' => (int)$item['product_id'],
                        'product_name' => (string)$item['product_name'],
                    ];
                }
            }
            if ($conflicts) {
                throw new ReconciliationException('inventory_changed', 409, ['conflicts' => $conflicts]);
            }

            foreach ($items as $item) {
                // The product may have been deleted even when its expected snapshot was empty.
                reconciliationProduct($db, (int)$item['product_id']);
                $delta = reconciliationRound(
                    (float)$item['counted_quantity'] - (float)$item['expected_quantity']
                );
                if (abs($delta) <= 0.000001) {
                    continue;
                }
                if ($delta < 0) {
                    reconciliationApplyDecrease($db, $session, $item, abs($delta));
                } else {
                    reconciliationApplyIncrease($db, $session, $item, $delta);
                }
            }

            $db->prepare(
                "UPDATE inventory_reconciliations
                 SET status = 'applied', applied_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'review'"
            )->execute([$sessionId]);
            $summary = reconciliationAppliedSummary($db, $sessionId);
            dbCommit($db);
            return $summary;
        } catch (Throwable $e) {
            dbRollback($db);
            throw $e;
        }
    });

    $result['already_applied'] = $alreadyApplied;
    $result['session'] = reconciliationGetCore($db, $sessionId)['session'];
    return $result;
}

function reconciliationGetCore(PDO $db, int $sessionId): array {
    $session = reconciliationSession($db, $sessionId);
    $stmt = $db->prepare(
        'SELECT i.*,
                (SELECT COUNT(*) FROM inventory_reconciliation_item_rows r WHERE r.item_id = i.id) AS snapshot_row_count
         FROM inventory_reconciliation_items i
         WHERE i.reconciliation_id = ? ORDER BY lower(i.product_name), i.id'
    );
    $stmt->execute([$sessionId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counted = 0;
    $varianceItems = 0;
    $netVariance = 0.0;
    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['product_id'] = (int)$item['product_id'];
        $item['snapshot_row_count'] = (int)$item['snapshot_row_count'];
        $item['expected_quantity'] = (float)$item['expected_quantity'];
        $item['product_default_quantity'] = (float)$item['product_default_quantity'];
        if ($item['counted_quantity'] !== null) {
            $item['counted_quantity'] = (float)$item['counted_quantity'];
            $item['variance'] = reconciliationRound($item['counted_quantity'] - $item['expected_quantity']);
            $counted++;
            $netVariance += $item['variance'];
            if (abs($item['variance']) > 0.000001) {
                $varianceItems++;
            }
        } else {
            $item['variance'] = null;
        }
    }
    unset($item);
    $total = count($items);
    $session['total_items'] = $total;
    $session['counted_items'] = $counted;
    $session['uncounted_items'] = $total - $counted;
    $session['variance_items'] = $varianceItems;
    $session['net_variance'] = reconciliationRound($netVariance);

    $adjustments = [];
    if ($session['status'] === 'applied') {
        $adjustmentStmt = $db->prepare(
            'SELECT id, product_id, product_name, inventory_id, sequence,
                    delta, before_quantity, after_quantity, created_at
             FROM inventory_adjustments WHERE reconciliation_id = ?
             ORDER BY reconciliation_item_id, sequence'
        );
        $adjustmentStmt->execute([$sessionId]);
        $adjustments = $adjustmentStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($adjustments as &$adjustment) {
            $adjustment['id'] = (int)$adjustment['id'];
            $adjustment['product_id'] = (int)$adjustment['product_id'];
            $adjustment['inventory_id'] = $adjustment['inventory_id'] === null ? null : (int)$adjustment['inventory_id'];
            $adjustment['sequence'] = (int)$adjustment['sequence'];
            $adjustment['delta'] = (float)$adjustment['delta'];
            $adjustment['before_quantity'] = (float)$adjustment['before_quantity'];
            $adjustment['after_quantity'] = (float)$adjustment['after_quantity'];
        }
        unset($adjustment);
    }

    return ['session' => $session, 'items' => $items, 'adjustments' => $adjustments];
}

/**
 * Catalog products that currently have no positive row at the session
 * location. They remain outside the session until a count is saved, so merely
 * revealing this optional list does not change progress.
 *
 * @return list<array<string,mixed>>
 */
function reconciliationZeroItemsCore(PDO $db, int $sessionId): array {
    $session = reconciliationSession($db, $sessionId);
    $stmt = $db->prepare(
        "SELECT p.id AS product_id, p.name AS product_name,
                COALESCE(p.brand, '') AS product_brand,
                COALESCE(p.image_url, '') AS product_image_url,
                COALESCE(p.unit, 'pz') AS product_unit,
                COALESCE(p.default_quantity, 1) AS product_default_quantity,
                COALESCE(p.package_unit, '') AS product_package_unit
         FROM products p
         WHERE NOT EXISTS (
                   SELECT 1 FROM inventory live
                   WHERE live.product_id = p.id AND live.location = ? AND live.quantity > 0
               )
         ORDER BY lower(p.name), p.id"
    );
    $location = (string)$session['location'];
    $stmt->execute([$location]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $item['product_id'] = (int)$item['product_id'];
        $item['product_default_quantity'] = (float)$item['product_default_quantity'];
        $item['expected_quantity'] = 0.0;
        $item['counted_quantity'] = null;
        $item['variance'] = null;
        $item['snapshot_row_count'] = 0;
        $item['zero_inventory'] = true;
    }
    unset($item);
    return $items;
}

function reconciliationListCore(PDO $db, int $limit = 25): array {
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare(
        "SELECT r.*,
                COUNT(i.id) AS total_items,
                COALESCE(SUM(CASE WHEN i.counted_quantity IS NOT NULL THEN 1 ELSE 0 END), 0) AS counted_items,
                COALESCE(SUM(CASE WHEN i.counted_quantity IS NOT NULL
                                  THEN i.counted_quantity - i.expected_quantity ELSE 0 END), 0) AS net_variance
         FROM inventory_reconciliations r
         LEFT JOIN inventory_reconciliation_items i ON i.reconciliation_id = r.id
         GROUP BY r.id
         ORDER BY CASE WHEN r.status IN ('in_progress','review') THEN 0 ELSE 1 END,
                  r.started_at DESC, r.id DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['total_items'] = (int)$row['total_items'];
        $row['counted_items'] = (int)$row['counted_items'];
        $row['net_variance'] = reconciliationRound((float)$row['net_variance']);
    }
    unset($row);
    return $rows;
}

function reconciliationJsonInput(): array {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($input)) {
        throw new ReconciliationException('invalid_json', 400);
    }
    return $input;
}

function reconciliationRespond(callable $callback): void {
    try {
        $payload = $callback();
        echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    } catch (ReconciliationException $e) {
        http_response_code($e->httpStatus);
        echo json_encode([
            'success' => false,
            'error' => $e->errorKey,
            'details' => $e->details,
        ], JSON_UNESCAPED_UNICODE);
    }
}

function reconciliationListAction(PDO $db): void {
    reconciliationRespond(fn(): array => ['reconciliations' => reconciliationListCore($db, (int)($_GET['limit'] ?? 25))]);
}

function reconciliationGetAction(PDO $db): void {
    reconciliationRespond(fn(): array => reconciliationGetCore($db, (int)($_GET['id'] ?? 0)));
}

function reconciliationZeroItemsAction(PDO $db): void {
    reconciliationRespond(fn(): array => ['items' => reconciliationZeroItemsCore($db, (int)($_GET['id'] ?? 0))]);
}

function reconciliationStartAction(PDO $db): void {
    reconciliationRespond(function () use ($db): array {
        $input = reconciliationJsonInput();
        $locations = function_exists('validInventoryLocations') ? validInventoryLocations() : ['dispensa', 'frigo', 'freezer'];
        return reconciliationStartCore($db, (string)($input['location'] ?? ''), $locations);
    });
}

function reconciliationCountAction(PDO $db): void {
    reconciliationRespond(function () use ($db): array {
        $input = reconciliationJsonInput();
        if (!array_key_exists('counted_quantity', $input)) {
            throw new ReconciliationException('counted_quantity_required', 422);
        }
        return reconciliationCountCore(
            $db,
            (int)($input['session_id'] ?? 0),
            (int)($input['product_id'] ?? 0),
            $input['counted_quantity']
        );
    });
}

function reconciliationReviewAction(PDO $db): void {
    reconciliationRespond(function () use ($db): array {
        $input = reconciliationJsonInput();
        return reconciliationReviewCore($db, (int)($input['session_id'] ?? 0));
    });
}

function reconciliationApplyAction(PDO $db): void {
    reconciliationRespond(function () use ($db): array {
        $input = reconciliationJsonInput();
        $result = reconciliationApplyCore($db, (int)($input['session_id'] ?? 0));
        if (function_exists('invalidateSmartShoppingCache')) {
            invalidateSmartShoppingCache();
        }
        return $result;
    });
}

function reconciliationCancelAction(PDO $db): void {
    reconciliationRespond(function () use ($db): array {
        $input = reconciliationJsonInput();
        return reconciliationCancelCore($db, (int)($input['session_id'] ?? 0));
    });
}
