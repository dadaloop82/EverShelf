<?php
/**
 * Open Prices (Open Food Facts) — free crowdsourced barcode prices.
 * Used as an optional fallback / primary source for shopping list estimates.
 * Licence: ODbL — attribute Open Prices / Open Food Facts.
 */

declare(strict_types=1);

/** @return 'ai'|'open_prices'|'auto' */
function evershelfPriceSource(): string
{
    $raw = strtolower(trim((string)env('PRICE_SOURCE', 'auto')));
    return in_array($raw, ['ai', 'open_prices', 'auto'], true) ? $raw : 'auto';
}

function evershelfPriceEnabledDecided(): bool
{
    return env('PRICE_ENABLED', '') !== '';
}

function evershelfOpenPricesEnabled(): bool
{
    // Master switch is PRICE_ENABLED; source chooses AI / Open Prices / auto.
    if (env('PRICE_ENABLED', 'false') !== 'true') {
        return false;
    }
    $src = evershelfPriceSource();
    return $src === 'open_prices' || $src === 'auto';
}

/**
 * Average recent crowd prices for a GTIN. Returns null when nothing usable.
 *
 * @return array{price_per_unit:float,unit_label:string,currency:string,source_note:string}|null
 */
function evershelfOpenPricesLookup(string $barcode, string $preferCurrency = 'EUR'): ?array
{
    $code = preg_replace('/\D+/', '', $barcode) ?? '';
    if (strlen($code) < 8) {
        return null;
    }
    $url = 'https://prices.openfoodfacts.org/api/v1/prices?'
        . http_build_query([
            'product_code' => $code,
            'size'         => 30,
            'order_by'     => '-date',
        ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: EverShelf/1.11 (self-hosted pantry; +https://github.com/dadaloop82/EverShelf)',
        ],
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $http >= 400) {
        EverLog::debug('Open Prices lookup miss', ['event' => 'open_prices_miss', 'http' => $http, 'barcode' => $code]);
        return null;
    }
    $json = json_decode($raw, true);
    $items = $json['items'] ?? $json['results'] ?? [];
    if (!is_array($items) || $items === []) {
        return null;
    }
    $sum = 0.0;
    $n = 0;
    $currency = $preferCurrency;
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $price = $row['price'] ?? $row['price_without_discount'] ?? null;
        if ($price === null || !is_numeric($price)) {
            continue;
        }
        $cur = strtoupper(trim((string)($row['currency'] ?? $preferCurrency)));
        if ($preferCurrency !== '' && $cur !== '' && strtoupper($preferCurrency) !== $cur) {
            // Skip foreign currencies rather than inventing FX.
            continue;
        }
        $sum += (float)$price;
        $n++;
        if ($cur !== '') {
            $currency = $cur;
        }
    }
    if ($n === 0) {
        return null;
    }
    $avg = round($sum / $n, 4);
    return [
        'price_per_unit' => $avg,
        'unit_label'     => 'pz',
        'currency'       => $currency,
        'source_note'    => 'Open Prices (OFF) · n=' . $n,
    ];
}

/**
 * Resolve a shopping-list name to a barcode from products, then look up Open Prices.
 *
 * @return array{price_per_unit:float,unit_label:string,currency:string,source_note:string}|null
 */
function evershelfOpenPricesForProductName(PDO $db, string $name, string $currency = 'EUR'): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    try {
        $stmt = $db->prepare(
            "SELECT barcode FROM products
             WHERE barcode IS NOT NULL AND TRIM(barcode) != ''
               AND (
                 LOWER(name) = LOWER(?)
                 OR LOWER(COALESCE(shopping_name,'')) = LOWER(?)
                 OR LOWER(name) LIKE LOWER(?)
               )
             ORDER BY CASE WHEN LOWER(COALESCE(shopping_name,'')) = LOWER(?) THEN 0
                           WHEN LOWER(name) = LOWER(?) THEN 1 ELSE 2 END
             LIMIT 1"
        );
        $like = '%' . $name . '%';
        $stmt->execute([$name, $name, $like, $name, $name]);
        $barcode = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return null;
    }
    if ($barcode === '') {
        return null;
    }
    return evershelfOpenPricesLookup($barcode, $currency);
}
