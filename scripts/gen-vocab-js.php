#!/usr/bin/env php
<?php
/**
 * Generates assets/js/vocab.generated.js from the vocabulary DATA in api/vocab/*.json.
 *
 * The JSON is the single source: the server reads it directly (api/lib/vocabulary.php),
 * the browser reads this generated file, which is committed (the PWA must work offline
 * and the guessers are synchronous). One file per language, all shipped together so the
 * app can switch language at runtime.
 *
 * Run it after editing api/vocab/<lang>.json: the browser bundle must match the data.
 * generated file is out of sync.
 */
$root = dirname(__DIR__);
$dir  = $root . '/api/vocab';
$out  = $root . '/assets/js/vocab.generated.js';

$files = glob($dir . '/*.json') ?: [];
sort($files);
$all = [];
foreach ($files as $file) {
    $lang = basename($file, '.json');
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "invalid JSON: {$file}\n");
        exit(1);
    }
    $all[$lang] = $data;
}
if (!$all) {
    fwrite(STDERR, "no vocabulary files in api/vocab/\n");
    exit(1);
}

$json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$js = "/**\n"
    . " * GENERATED FILE — do not edit. Source: api/vocab/*.json\n"
    . " * Regenerate: php scripts/gen-vocab-js.php\n"
    . " * Language data only: no logic here.\n"
    . " */\n"
    . "const EVERSHELF_VOCAB_ALL = {$json};\n";
file_put_contents($out, $js);
echo 'written: assets/js/vocab.generated.js (' . count($all) . " language(s), " . strlen($js) . " bytes)\n";
