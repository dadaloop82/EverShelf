#!/usr/bin/env php
<?php
/**
 * Re-apply stock hints and 5% use-all rule to an archived recipe.
 * Usage: php scripts/re-enrich-recipe.php <recipe_id>
 */
define('CRON_MODE', true);
require __DIR__ . '/../api/index.php';

$id = (int)($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php scripts/re-enrich-recipe.php <recipe_id>\n");
    exit(1);
}

$db = getDB();
$stmt = $db->prepare('SELECT id, recipe_json FROM recipes WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "Recipe {$id} not found\n");
    exit(1);
}

$recipe = json_decode($row['recipe_json'], true);
if (!is_array($recipe)) {
    fwrite(STDERR, "Invalid recipe JSON for id {$id}\n");
    exit(1);
}

recipeApplyStockHintsToRecipe($db, $recipe);

$upd = $db->prepare('UPDATE recipes SET recipe_json = ? WHERE id = ?');
$upd->execute([json_encode($recipe, JSON_UNESCAPED_UNICODE), $id]);

echo "Updated recipe {$id}: " . ($recipe['title'] ?? '?') . "\n";
foreach ($recipe['ingredients'] ?? [] as $ing) {
    if (empty($ing['from_pantry'])) continue;
    $useAll = !empty($ing['use_all_suggested']) ? ' [USE ALL]' : '';
    echo sprintf(
        "  %s: %s | hai %.1f %s | restano %.1f %s%s\n",
        $ing['name'] ?? '?',
        $ing['qty'] ?? '?',
        $ing['stock_have'] ?? 0,
        $ing['stock_unit'] ?? '',
        $ing['stock_remain'] ?? 0,
        $ing['stock_unit'] ?? '',
        $useAll
    );
}
