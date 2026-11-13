#!/usr/bin/env php
<?php
/**
 * Generates assets/js/food_facts.generated.js from data/food_facts_fallback.json.
 *
 * Those are the anti-waste sentences the screensaver shows when the API (action
 * `food_facts`) has never been reached — first boot offline, or before the first fetch.
 * They are TEXT, so they live in a data file and never in app.js: one list per language,
 * and a language without a list falls back to English/Italian at runtime.
 *
 * Run it after editing data/food_facts_fallback.json: the bundle must match the data.
 * when the generated file is out of sync.
 */
$root = dirname(__DIR__);
$src  = $root . '/data/food_facts_fallback.json';
$out  = $root . '/assets/js/food_facts.generated.js';

$data = json_decode((string)@file_get_contents($src), true);
if (!is_array($data)) {
    fwrite(STDERR, "invalid JSON: {$src}\n");
    exit(1);
}
$facts = [];
foreach ($data as $lang => $list) {
    if (!is_string($lang) || preg_match('/^[a-z]{2}$/', $lang) !== 1 || !is_array($list)) {
        continue;                       // "note" and any other key is documentation
    }
    $sentences = array_values(array_filter(array_map('strval', $list), static fn($s) => trim($s) !== ''));
    if ($sentences) {
        $facts[$lang] = $sentences;
    }
}
if (!$facts) {
    fwrite(STDERR, "no facts in {$src}\n");
    exit(1);
}

$json = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$js = "/**\n"
    . " * GENERATED FILE — do not edit. Source: data/food_facts_fallback.json\n"
    . " * Regenerate: php scripts/gen-food-facts-js.php\n"
    . " * Text only: no logic here.\n"
    . " */\n"
    . "const EVERSHELF_FOOD_FACTS_FALLBACK = {$json};\n";
file_put_contents($out, $js);
echo 'written: assets/js/food_facts.generated.js (' . count($facts) . " language(s), " . strlen($js) . " bytes)\n";
