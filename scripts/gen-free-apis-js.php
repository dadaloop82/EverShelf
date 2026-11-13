#!/usr/bin/env php
<?php
/**
 * Generates assets/js/free_apis.generated.js from data/free_apis.json.
 *
 * Why baked in: the splash is on screen *before* the app can talk to the API (there is no
 * token yet) and has to work offline. The credits row under the boot rail, the Settings
 * card and the footer all read the same list, and a service is added in one row of JSON.
 *
 * Run it after editing data/free_apis.json: the bundle must match the data.
 * generated file and the data drift apart.
 */
$root = dirname(__DIR__);
$src  = $root . '/data/free_apis.json';
$out  = $root . '/assets/js/free_apis.generated.js';

$data = json_decode((string)@file_get_contents($src), true);
if (!is_array($data) || !is_array($data['apis'] ?? null)) {
    fwrite(STDERR, "invalid JSON: {$src}\n");
    exit(1);
}

$apis = [];
foreach ($data['apis'] as $api) {
    if (!is_array($api) || trim((string)($api['id'] ?? '')) === '') {
        continue;
    }
    $url = (string)($api['url'] ?? '');
    $logo = (string)($api['logo'] ?? '');
    // The logo is a LOCAL asset: the splash must paint it offline, before any network.
    $logoOk = preg_match('#^assets/img/[A-Za-z0-9_./-]+\.(svg|png)$#', $logo) && !str_contains($logo, '..');
    $apis[] = [
        'id'      => (string)$api['id'],
        'name'    => (string)($api['name'] ?? $api['id']),
        'mark'    => (string)($api['mark'] ?? ''),
        'logo'    => $logoOk ? $logo : '',
        // Only a real http(s) URL: the field must never become a javascript: link.
        'url'     => preg_match('#^https?://#i', $url) ? $url : '',
        'licence' => (string)($api['licence'] ?? ''),
        // A horizontal wordmark needs a wide box (a 28 px square turns it into a sliver),
        // and `splash: false` keeps it off the boot splash: only marks readable at a glance
        // belong there. Both are data, so adding a service needs no code change.
        'wide'    => !empty($api['wide']),
        'splash'  => !array_key_exists('splash', $api) || $api['splash'] !== false,
        // A source can be CONDITIONAL (`when`): the national medicine catalogue exists only
        // for the instance whose module is on and whose country matches. The splash and the
        // credits read this, so the logo of a source that is not in use is never shown.
        'when'    => _freeApiWhen($api['when'] ?? null),
    ];
}

/**
 * The `when` gate of a source, normalised for the generated file.
 *
 * @return array{medical?:bool,country?:array<int,string>}|null
 */
function _freeApiWhen($when): ?array
{
    if (!is_array($when)) {
        return null;
    }
    $out = [];
    if (!empty($when['medical'])) {
        $out['medical'] = true;
    }
    if (is_array($when['country'] ?? null)) {
        $countries = [];
        foreach ($when['country'] as $cc) {
            $cc = strtoupper(trim((string)$cc));
            if (preg_match('/^[A-Z]{2}$/', $cc) === 1) {
                $countries[] = $cc;
            }
        }
        if ($countries) {
            $out['country'] = $countries;
        }
    }
    return $out ?: null;
}
// Reference sources: the documents the app's own DATA files are built from (the medical
// code schemas cite GS1, Wikipedia, the EU regulation). They are not called at runtime; they
// are credited like the APIs, because a credit list that hides its sources is not honest.
$sources = [];
foreach (($data['sources'] ?? []) as $src) {
    if (!is_array($src) || trim((string)($src['id'] ?? '')) === '') {
        continue;
    }
    $url = (string)($src['url'] ?? '');
    $sources[] = [
        'id'      => (string)$src['id'],
        'name'    => (string)($src['name'] ?? $src['id']),
        'mark'    => (string)($src['mark'] ?? ''),
        'url'     => preg_match('#^https?://#i', $url) ? $url : '',
        'licence' => (string)($src['licence'] ?? ''),
        'use_key' => (string)($src['use_key'] ?? ''),
        'planned' => !empty($src['planned']),
    ];
}

if (!$apis) {
    fwrite(STDERR, "no apis in {$src}\n");
    exit(1);
}

$json = json_encode($apis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jsonSources = json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$js = "/**\n"
    . " * GENERATED FILE — do not edit. Source: data/free_apis.json\n"
    . " * Regenerate: php scripts/gen-free-apis-js.php\n"
    . " * Data only: no logic here.\n"
    . " */\n"
    . "const EVERSHELF_FREE_APIS = {$json};\n"
    . "const EVERSHELF_DATA_SOURCES = {$jsonSources};\n";
file_put_contents($out, $js);
echo 'written: assets/js/free_apis.generated.js (' . count($apis) . ' service(s), ' . count($sources) . " source(s), " . strlen($js) . " bytes)\n";
