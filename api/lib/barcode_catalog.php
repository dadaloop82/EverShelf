<?php
/**
 * Offline barcode catalog — persistent local copy of free barcode DB lookups.
 * Refreshed weekly via cron (BARCODE_OFFLINE_SYNC_DAYS, default 7).
 */

function barcodeOfflineEnabled(): bool {
    return env('BARCODE_OFFLINE_ENABLED', 'true') === 'true';
}

function barcodeOfflineSyncDays(): int {
    $days = (int)env('BARCODE_OFFLINE_SYNC_DAYS', '7');
    return max(1, min(90, $days));
}

function barcodeLookupTimeoutSec(): int {
    $sec = (int)env('BARCODE_LOOKUP_TIMEOUT', '8');
    return max(4, min(20, $sec));
}

function barcodeCatalogEnsureTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS barcode_catalog (
        barcode TEXT PRIMARY KEY,
        name TEXT NOT NULL DEFAULT '',
        brand TEXT DEFAULT '',
        category TEXT DEFAULT '',
        image_url TEXT DEFAULT '',
        quantity_info TEXT DEFAULT '',
        source TEXT DEFAULT '',
        payload_json TEXT,
        synced_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_barcode_catalog_synced ON barcode_catalog(synced_at)');
}

/** @return array{found:bool,source:string,product:array}|null */
function barcodeCatalogGet(PDO $db, string $barcode): ?array {
    if (!barcodeOfflineEnabled()) {
        return null;
    }
    $barcode = barcodeNormalizeDigits($barcode);
    if ($barcode === '') {
        return null;
    }
    foreach (barcodeLookupCandidates($barcode) as $bc) {
        $stmt = $db->prepare('SELECT * FROM barcode_catalog WHERE barcode = ?');
        $stmt->execute([$bc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if (is_array($payload) && !empty($payload['product'])) {
            return [
                'found'   => true,
                'source'  => ($row['source'] ?? 'offline') . '_offline',
                'product' => $payload['product'],
            ];
        }
        return [
            'found'   => true,
            'source'  => ($row['source'] ?? 'offline') . '_offline',
            'product' => [
                'name'          => $row['name'] ?? '',
                'brand'         => $row['brand'] ?? '',
                'category'      => $row['category'] ?? '',
                'image_url'     => $row['image_url'] ?? '',
                'quantity_info' => $row['quantity_info'] ?? '',
                'nutriscore'    => '', 'ingredients' => '', 'allergens' => '',
                'conservation'  => '', 'origin' => '', 'nova_group' => '',
                'ecoscore'      => '', 'labels' => '', 'stores' => '',
            ],
        ];
    }
    return null;
}

function barcodeCatalogUpsert(PDO $db, string $barcode, array $result, string $source): void {
    if (empty($result['found']) || empty($result['product']['name'])) {
        return;
    }
    $barcode = barcodeNormalizeDigits($barcode);
    if ($barcode === '') {
        return;
    }
    $p = $result['product'];
    $stmt = $db->prepare("INSERT INTO barcode_catalog
        (barcode, name, brand, category, image_url, quantity_info, source, payload_json, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ON CONFLICT(barcode) DO UPDATE SET
            name = excluded.name,
            brand = excluded.brand,
            category = excluded.category,
            image_url = excluded.image_url,
            quantity_info = excluded.quantity_info,
            source = excluded.source,
            payload_json = excluded.payload_json,
            synced_at = excluded.synced_at");
    $stmt->execute([
        $barcode,
        $p['name'] ?? '',
        $p['brand'] ?? '',
        $p['category'] ?? '',
        $p['image_url'] ?? '',
        $p['quantity_info'] ?? '',
        $source,
        json_encode($result, JSON_UNESCAPED_UNICODE),
    ]);
}

function barcodeCatalogImportFromCache(PDO $db): int {
    barcodeCatalogEnsureTable($db);
    $rows = $db->query('SELECT barcode, payload, source FROM barcode_cache WHERE found = 1')->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $row) {
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        if (!is_array($payload) || empty($payload['product']['name'])) {
            continue;
        }
        barcodeCatalogUpsert($db, (string)$row['barcode'], $payload, (string)($row['source'] ?? 'cache'));
        $count++;
    }
    return $count;
}

/** @return string[] */
function barcodeCatalogBarcodesForSync(PDO $db): array {
    barcodeCatalogEnsureTable($db);
    $days = barcodeOfflineSyncDays();
    $codes = [];

    $productRows = $db->query("SELECT DISTINCT barcode FROM products WHERE barcode IS NOT NULL AND TRIM(barcode) != ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($productRows as $bc) {
        $n = barcodeNormalizeDigits((string)$bc);
        if ($n !== '') {
            $codes[$n] = true;
        }
    }

    $catRows = $db->query('SELECT barcode FROM barcode_catalog')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($catRows as $bc) {
        $n = barcodeNormalizeDigits((string)$bc);
        if ($n !== '') {
            $codes[$n] = true;
        }
    }

    $staleStmt = $db->prepare("SELECT barcode FROM barcode_catalog WHERE synced_at IS NULL OR synced_at < datetime('now', ?)");
    $staleStmt->execute(['-' . $days . ' days']);
    foreach ($staleStmt->fetchAll(PDO::FETCH_COLUMN) as $bc) {
        $n = barcodeNormalizeDigits((string)$bc);
        if ($n !== '') {
            $codes[$n] = true;
        }
    }

    return array_keys($codes);
}
