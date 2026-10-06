#!/usr/bin/env php
<?php
/**
 * Regression: generic shopping list matching (Farina ≠ Farina di riso).
 * Run: php scripts/test-shopping-list-match.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$fail = 0;
function ok(bool $c, string $m): void {
    global $fail;
    if (!$c) { echo "FAIL: $m\n"; $fail++; } else { echo "OK: $m\n"; }
}

$db = getDB();
$db->exec('DELETE FROM shopping_list WHERE name IN (\'Farina\', \'Farina di riso\', \'Ragù test\')');

$db->prepare('INSERT INTO products (name, shopping_name, unit) VALUES (?, ?, ?)')
    ->execute(['Farina di grano 00', 'Farina', 'g']);
$farinaId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO products (name, shopping_name, unit) VALUES (?, ?, ?)')
    ->execute(['Riso basmati', 'Riso', 'g']);
$risoId = (int)$db->lastInsertId();

$db->prepare('INSERT INTO shopping_list (name, raw_name) VALUES (?, ?)')->execute(['Farina di riso', '']);
$db->prepare('INSERT INTO shopping_list (name, raw_name) VALUES (?, ?)')->execute(['Farina', '']);

$farinaProd = $db->query("SELECT name, shopping_name FROM products WHERE id = $farinaId")->fetch(PDO::FETCH_ASSOC);
$risoProd = $db->query("SELECT name, shopping_name FROM products WHERE id = $risoId")->fetch(PDO::FETCH_ASSOC);

ok(
    shoppingListRowMatchesProduct($db, 'Farina', '', $farinaProd['name'], $farinaProd['shopping_name']),
    'Farina list row matches Farina product'
);
ok(
    !shoppingListRowMatchesProduct($db, 'Farina di riso', '', $farinaProd['name'], $farinaProd['shopping_name']),
    'Farina di riso list row does not match plain Farina product'
);
ok(
    shoppingListRowMatchesProduct($db, 'Farina di riso', '', $risoProd['name'], $risoProd['shopping_name']) === false,
    'Farina di riso row does not match Riso product (different generic)'
);

$keyRagu = internalShoppingListGenericKey($db, 'Ragù', 'Il mio gran ragù con salsiccia');
ok($keyRagu === mb_strtolower(computeShoppingName('ragù')), 'Ragù title wins over salsiccia in raw_name');

$db->exec('DELETE FROM shopping_list WHERE name IN (\'Farina\', \'Farina di riso\')');
$db->exec("DELETE FROM products WHERE id IN ($farinaId, $risoId)");

exit($fail > 0 ? 1 : 0);
