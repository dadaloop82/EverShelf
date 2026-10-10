#!/usr/bin/env php
<?php
/**
 * Heal titles poisoned by English UI-category genres
 * ("Snacks & sweets frollini…", "Vegetables basilico", "Dairy trentingrana…")
 * and merge the barcode-less stock row onto the barcode twin when one exists.
 *
 * Only touches mangled rows — does NOT re-run the singularize pass on the whole pantry.
 *
 * Usage:
 *   php scripts/fix-mangled-kind-titles.php --dry-run
 *   php scripts/fix-mangled-kind-titles.php
 */
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
// Direct PDO: the logging wrapper can lock SQLite while the web app holds the WAL.
$dbPath = dirname(__DIR__) . '/data/evershelf.db';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout = 8000');
$db->exec('PRAGMA journal_mode = WAL');

/** @var list<array{keep:int,drop:int,note:string}> */
$merges = [
    ['keep' => 538, 'drop' => 502, 'note' => 'frollini grano saraceno: stock → barcode'],
];

/** Manual Italian titles when strip+dictionary still leave English junk */
$manualNames = [
    537 => 'Rotolo sponge mirtilli',
    501 => 'Frutta secca',
];

function titleLooksMangled(string $name, string $kind): bool {
    $stripped = productKindStripUmbrellaCategoryPrefix($name);
    if ($stripped !== trim($name)) {
        return true;
    }
    if ($kind !== '' && !productKindIsPlausibleGenre($kind)) {
        return true;
    }
    return false;
}

echo ($dryRun ? "[dry-run] " : '') . "Fixing mangled kind titles…\n";

foreach ($merges as $m) {
    $keep = (int)$m['keep'];
    $drop = (int)$m['drop'];
    $keepStmt = $db->prepare('SELECT id, name, barcode FROM products WHERE id = ?');
    $dropStmt = $db->prepare('SELECT id, name, barcode FROM products WHERE id = ?');
    $keepStmt->execute([$keep]);
    $dropStmt->execute([$drop]);
    $k = $keepStmt->fetch(PDO::FETCH_ASSOC);
    $d = $dropStmt->fetch(PDO::FETCH_ASSOC);
    if (!$k || !$d) {
        echo "  skip merge #{$drop}→#{$keep} (missing row)\n";
        continue;
    }
    $stock = (float)$db->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id = {$drop}")->fetchColumn();
    echo "  merge #{$drop} \"{$d['name']}\" (stock {$stock}) → #{$keep} \"{$k['name']}\" — {$m['note']}\n";
    if (!$dryRun) {
        mergeProducts($db, $keep, $drop);
    }
}

$rows = $db->query(
    "SELECT id, name, brand, category, COALESCE(kind,'') AS kind, COALESCE(shopping_name,'') AS shopping_name
     FROM products ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$update = $db->prepare(
    'UPDATE products SET name = ?, kind = ?, shopping_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
);

$renamed = 0;
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $still = $db->prepare('SELECT 1 FROM products WHERE id = ?');
    $still->execute([$id]);
    if (!$still->fetchColumn()) {
        continue;
    }

    $oldName = (string)$row['name'];
    $oldKind = (string)$row['kind'];
    $brand = (string)$row['brand'];
    $category = (string)$row['category'];
    $forceManual = isset($manualNames[$id]);

    if (!$forceManual && !titleLooksMangled($oldName, $oldKind)) {
        continue;
    }

    $seed = $forceManual ? $manualNames[$id] : $oldName;
    $applied = productKindApply($seed, $brand, $category, 'it', false, '');
    $newName = $applied['name'];
    $newKind = $applied['kind'];

    $shopping = trim((string)$row['shopping_name']);
    $shopLower = mb_strtolower($shopping);
    $shopBad = $shopping === ''
        || !productKindIsPlausibleGenre($shopping)
        || in_array($shopLower, ['snacks', 'dairy', 'vegetables', 'meat', 'condiments', 'fruits', 'beverages'], true);
    if ($shopBad) {
        $shopping = computeShoppingName($newName, $category, $brand, false);
    }

    if ($newName === $oldName && $newKind === $oldKind && $shopping === trim((string)$row['shopping_name'])) {
        continue;
    }

    echo "  #{$id}: \"{$oldName}\" → \"{$newName}\" (kind: {$oldKind} → {$newKind})\n";
    $renamed++;
    if (!$dryRun) {
        $update->execute([$newName, $newKind, $shopping, $id]);
    }
}

echo ($dryRun ? "Dry run: " : "Done: ") . "{$renamed} product(s) renamed/healed.\n";
