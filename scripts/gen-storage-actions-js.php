#!/usr/bin/env php
<?php
/**
 * Generates assets/js/storage_actions.generated.js from data/storage_actions.json.
 *
 * Why baked in: the product sheet hides Freeze / Vacuum by category with no network
 * round-trip and no AI. The table is language-neutral (category slugs), so one JSON
 * serves all six locales; UI labels stay in translations/*.json.
 *
 * Run it after editing data/storage_actions.json: the bundle must match the data.
 */
$root = dirname(__DIR__);
$src  = $root . '/data/storage_actions.json';
$out  = $root . '/assets/js/storage_actions.generated.js';

$data = json_decode((string)@file_get_contents($src), true);
if (!is_array($data) || !is_array($data['by_category'] ?? null)) {
    fwrite(STDERR, "invalid JSON: {$src}\n");
    exit(1);
}

$defaults = $data['defaults'] ?? ['freeze' => true, 'vacuum' => true];
$byCat = [];
foreach ($data['by_category'] as $slug => $row) {
    if (!is_string($slug) || $slug === '' || !is_array($row)) {
        continue;
    }
    $byCat[$slug] = [
        'freeze'  => !array_key_exists('freeze', $row) || $row['freeze'] !== false,
        'vacuum'  => !array_key_exists('vacuum', $row) || $row['vacuum'] !== false,
    ];
}

$payload = [
    'version'     => (int)($data['version'] ?? 1),
    'defaults'    => [
        'freeze' => !empty($defaults['freeze']),
        'vacuum' => !empty($defaults['vacuum']),
    ],
    'by_category' => $byCat,
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

$js = "/**\n"
    . " * GENERATED FILE — do not edit. Source: data/storage_actions.json\n"
    . " * Regenerate: php scripts/gen-storage-actions-js.php\n"
    . " * Data only: no logic here.\n"
    . " */\n"
    . "const EVERSHELF_STORAGE_ACTIONS = {$json};\n";

if (file_put_contents($out, $js) === false) {
    fwrite(STDERR, "write failed: {$out}\n");
    exit(1);
}

echo 'written: assets/js/storage_actions.generated.js (' . count($byCat) . " categor(y/ies), " . strlen($js) . " bytes)\n";
