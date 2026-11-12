<?php
/**
 * The household's own PAO (period after opening), editable — and averaged per family.
 *
 * `data/opened_shelf_life.json` is a DATA table: 32 food families × 3 places with the days
 * after which an opened product starts to be worth checking (`warn`) and the days after which
 * it is probably gone (`critical`). It is a good default, and it is not the household: «entro
 * 3-6 gg» on an opened yoghurt is the app's opinion, and the person in front of the shelf knows
 * their fridge better.
 *
 * So the edit sheet can set those two numbers, and the app REMEMBERS them: every product the
 * household corrects becomes ONE observation of its family in that place, and the value the
 * pantry then uses is the AVERAGE of those observations — never the last tap, because a single
 * correction must not overwrite a whole category. The row is keyed (product, family, place) and
 * replaced on the next edit of the same product, so the average is over PRODUCTS, not over taps.
 *
 * The clock the notice strip prints («Aperto da 6 gg · consigliato entro 3-6 gg») reads this
 * through `openedShelfLifeThresholds()`, so every device sees the same numbers and nothing of
 * the house is kept in a browser. With no observation the data table stays in charge.
 */

/**
 * Create the observations table when missing (idempotent, called from initializeDB).
 */
function paoEnsureTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS pao_observations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        family TEXT NOT NULL,
        location_kind TEXT NOT NULL,
        warn_days INTEGER NOT NULL,
        critical_days INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(product_id, family, location_kind)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pao_family_loc ON pao_observations(family, location_kind)");
}

/**
 * Keep a household value inside the field's own limits: one day … ten years, and `critical`
 * never shorter than `warn` (the two may be equal — the strip then prints one number).
 *
 * @return array{0:int,1:int} `[warn, critical]`
 */
function paoClampDays(int $warn, int $critical): array
{
    $warn = max(1, min(3650, $warn));
    $critical = max(1, min(3650, $critical));
    if ($critical < $warn) {
        $critical = $warn;
    }
    return [$warn, $critical];
}

/**
 * The average of the household's observations, keyed `family|place`.
 *
 * One query per request (static cache) and an empty map on an install that never edited
 * anything, so the data table keeps answering exactly as before. `$refresh` is what a write
 * uses to see its own effect inside the same request.
 *
 * @return array<string,array{warn:int,critical:int,sample:int}>
 */
function paoAverageMap(PDO $db, bool $refresh = false): array
{
    static $cache = null;
    if ($refresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = $db->query("
            SELECT family, location_kind,
                   AVG(warn_days) AS warn_avg,
                   AVG(critical_days) AS critical_avg,
                   COUNT(*) AS sample
            FROM pao_observations
            GROUP BY family, location_kind
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // A database that predates the table (or a read-only replica) keeps the data table.
        return $cache;
    }
    foreach ($rows as $row) {
        [$warn, $critical] = paoClampDays(
            (int)round((float)($row['warn_avg'] ?? 0)),
            (int)round((float)($row['critical_avg'] ?? 0))
        );
        $cache[(string)$row['family'] . '|' . (string)$row['location_kind']] = [
            'warn'     => $warn,
            'critical' => $critical,
            'sample'   => (int)($row['sample'] ?? 0),
        ];
    }
    return $cache;
}

/**
 * What the edit sheet and the strip need about one pantry row: the clock's family and place,
 * the effective thresholds, the data table's own numbers, and how much of it is the household's.
 *
 * @return array{inventory_id:int,product_id:int,name:string,family:string,location_kind:string,
 *               warn:int,critical:int,table_warn:int,table_critical:int,sample:int,source:string}|null
 */
function paoRowContext(PDO $db, int $inventoryId, bool $refreshAverages = false): ?array
{
    if ($inventoryId <= 0) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT i.id, i.product_id, i.location, p.name, p.category
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.id = ?
    ");
    $stmt->execute([$inventoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    require_once __DIR__ . '/opened_shelf_life.php';
    $overrides = paoAverageMap($db, $refreshAverages);
    $t = openedShelfLifeThresholds(
        (string)($row['name'] ?? ''),
        (string)($row['category'] ?? ''),
        (string)($row['location'] ?? ''),
        $overrides
    );
    $key = (string)$t['family'] . '|' . (string)$t['location'];
    $own = $overrides[$key] ?? null;
    return [
        'inventory_id'   => (int)$row['id'],
        'product_id'     => (int)$row['product_id'],
        'name'           => (string)($row['name'] ?? ''),
        'family'         => (string)$t['family'],
        'location_kind'  => (string)$t['location'],
        'warn'           => (int)$t['warn'],
        'critical'       => (int)$t['critical'],
        'table_warn'     => (int)($t['table_warn'] ?? $t['warn']),
        'table_critical' => (int)($t['table_critical'] ?? $t['critical']),
        'sample'         => (int)($own['sample'] ?? 0),
        'source'         => $own ? 'average' : 'table',
    ];
}

/**
 * Record what the household says about one row: the value belongs to its family in that place,
 * and the answer carries the NEW average so the sheet can show what it just changed.
 *
 * @return array|null the row context after the write, null when the row does not exist
 */
function paoSaveObservation(PDO $db, int $inventoryId, int $warn, int $critical): ?array
{
    $ctx = paoRowContext($db, $inventoryId);
    if (!$ctx) {
        return null;
    }
    [$warn, $critical] = paoClampDays($warn, $critical);
    $db->prepare("
        INSERT INTO pao_observations (product_id, family, location_kind, warn_days, critical_days)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(product_id, family, location_kind) DO UPDATE SET
            warn_days     = excluded.warn_days,
            critical_days = excluded.critical_days,
            updated_at    = CURRENT_TIMESTAMP
    ")->execute([
        (int)$ctx['product_id'],
        (string)$ctx['family'],
        (string)$ctx['location_kind'],
        $warn,
        $critical,
    ]);
    EverLog::info('PAO observation saved', [
        'event'         => 'api_pao_save',
        'inventory_id'  => $inventoryId,
        'product_id'    => (int)$ctx['product_id'],
        'family'        => $ctx['family'],
        'location_kind' => $ctx['location_kind'],
        'warn'          => $warn,
        'critical'      => $critical,
    ]);
    return paoRowContext($db, $inventoryId, true);
}

/**
 * Forget the household's numbers for the family of one row: the data table takes over again.
 *
 * @return array|null the row context after the reset, null when the row does not exist
 */
function paoResetFamily(PDO $db, int $inventoryId): ?array
{
    $ctx = paoRowContext($db, $inventoryId);
    if (!$ctx) {
        return null;
    }
    $stmt = $db->prepare("DELETE FROM pao_observations WHERE family = ? AND location_kind = ?");
    $stmt->execute([(string)$ctx['family'], (string)$ctx['location_kind']]);
    EverLog::info('PAO observations cleared', [
        'event'         => 'api_pao_reset',
        'inventory_id'  => $inventoryId,
        'family'        => $ctx['family'],
        'location_kind' => $ctx['location_kind'],
        'deleted'       => $stmt->rowCount(),
    ]);
    return paoRowContext($db, $inventoryId, true);
}
