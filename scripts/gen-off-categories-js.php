#!/usr/bin/env php
<?php
/**
 * Generates assets/js/off_categories.generated.js from data/off_categories.json.
 *
 * OFF tags are English and language-neutral; the active language vocabulary still
 * owns the title → genre guess when a tag is a catch-all (null) or unknown.
 *
 * Run after editing data/off_categories.json.
 */
$root = dirname(__DIR__);
$src  = $root . '/data/off_categories.json';
$out  = $root . '/assets/js/off_categories.generated.js';

$data = json_decode((string)@file_get_contents($src), true);
if (!is_array($data) || !is_array($data['by_slug'] ?? null)) {
    fwrite(STDERR, "invalid JSON: {$src}\n");
    exit(1);
}

$bySlug = [];
foreach ($data['by_slug'] as $slug => $cat) {
    if (!is_string($slug) || $slug === '') {
        continue;
    }
    $slug = strtolower(trim($slug));
    if ($cat === null || $cat === '') {
        $bySlug[$slug] = null;
        continue;
    }
    if (!is_string($cat)) {
        continue;
    }
    $bySlug[$slug] = strtolower(trim($cat));
}

$payload = [
    'version' => (int)($data['version'] ?? 1),
    'by_slug' => $bySlug,
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

$js = "/**\n"
    . " * GENERATED FILE — do not edit. Source: data/off_categories.json\n"
    . " * Regenerate: php scripts/gen-off-categories-js.php\n"
    . " * Data only: no logic here.\n"
    . " */\n"
    . "const EVERSHELF_OFF_CATEGORIES = {$json};\n";

if (file_put_contents($out, $js) === false) {
    fwrite(STDERR, "write failed: {$out}\n");
    exit(1);
}

echo 'written: assets/js/off_categories.generated.js (' . count($bySlug) . " slug(s), " . strlen($js) . " bytes)\n";
