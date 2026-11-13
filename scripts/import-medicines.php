#!/usr/bin/env php
<?php
/**
 * Import a NATIONAL medicine catalogue into the local database.
 *
 * One importer, one module per country: the columns of a national export are a country fact, so
 * they live in `api/lib/med/<slug>.php` (Italy: `medicalItalyColumnAliases()`, the AIFA open
 * data). The store (`api/lib/medicines.php`) only knows how to read a table and write rows.
 *
 * Why a local file and not an API: there is no free, unlimited, key-less REST service for
 * Italian medicines. AIFA publishes the national list as open data; this script puts it in the
 * `medicines` table and the app answers from there — offline, in milliseconds, with no request
 * leaving the household's server.
 *
 * Where to get the Italian file (no registration):
 *   - AIFA open data / "Banca dati dei farmaci": https://www.aifa.gov.it/web/guest/-/open-data
 *   - the national open-data catalogue: https://www.dati.gov.it/ (search "AIFA farmaci")
 * CSV or XML, any column order: the columns are recognised by name (`CODICE_AIC`,
 * `DENOMINAZIONE`, `PRINCIPIO_ATTIVO`, `FORMA`, `DOSAGGIO`, `CONFEZIONE`, `TITOLARE`, `ATC`,
 * `FASCIA`, …).
 *
 * Usage:
 *   php scripts/import-medicines.php <file.csv|file.xml> [--country=IT] [--dry-run] [--truncate] [--limit=N]
 *
 *   --country=IT  which national module reads the file (default IT; the only one shipped)
 *   --dry-run     parse and count, write nothing (check the header map first)
 *   --truncate    empty the table before importing (a full refresh)
 *   --limit=N     stop after N records (a quick test on a big file)
 *
 * The catalogue is identification only: AIC, name, active ingredient, form, pack, company,
 * ATC. No dose, no indication, no therapy advice — the module's disclaimer still applies.
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$args = array_slice($argv, 1);
$path = '';
$country = 'IT';
$options = ['dry_run' => false, 'truncate' => false, 'limit' => 0];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        $name = substr($arg, 2);
        $value = '';
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        }
        if ($name === 'dry-run') { $options['dry_run'] = true; }
        elseif ($name === 'truncate') { $options['truncate'] = true; }
        elseif ($name === 'limit') { $options['limit'] = max(0, (int)$value); }
        elseif ($name === 'country') { $country = strtoupper(trim($value)); }
        elseif ($name === 'help' || $name === 'h') { $path = ''; }
        else {
            fwrite(STDERR, "Unknown option: --{$name}\n");
            exit(2);
        }
        continue;
    }
    $path = $arg;
}
if ($path === '') {
    fwrite(STDERR, "Usage: php scripts/import-medicines.php <file.csv|file.xml> [--country=IT] [--dry-run] [--truncate] [--limit=N]\n");
    exit(2);
}
if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read {$path}\n");
    exit(1);
}

// The country module owns the column names of its national export.
$module = medicalCountryModule($country);
if (!$module) {
    fwrite(STDERR, "No medicine module for country {$country}: add data/medical_code_schemas.json"
        . " (\"module\": \"<cc>\") and api/lib/med/<cc>.php.\n");
    exit(1);
}
$aliasesFn = (string)($module['dataset_columns'] ?? '');
if ($aliasesFn === '' || !is_callable($aliasesFn)) {
    fwrite(STDERR, "The {$country} module declares no dataset_columns(): nothing to map.\n");
    exit(1);
}
$aliases = (array)$aliasesFn();

$db = getDB();
medicinesEnsureTable($db);
$result = medicinesImportFile($db, $path, $options, $aliases);
$info = medicinesDatasetInfo($db);

fwrite(STDERR, sprintf(
    "%s [%s] country=%s format=%s rows=%d saved=%d skipped=%d\n",
    $result['ok'] ? 'OK' : 'FAILED',
    $options['dry_run'] ? 'dry run' : 'written',
    $country,
    (string)$result['format'],
    (int)$result['rows'],
    (int)$result['saved'],
    (int)$result['skipped']
));
if ($result['columns'] !== []) {
    $pairs = [];
    foreach ($result['columns'] as $field => $column) {
        $pairs[] = $field . '=' . $column;
    }
    fwrite(STDERR, 'Columns: ' . implode(', ', $pairs) . "\n");
}
if (!empty($result['error'])) {
    fwrite(STDERR, 'Error: ' . $result['error'] . "\n");
    if ($result['error'] === 'missing_columns') {
        fwrite(STDERR, "The file needs the columns of the {$country} module (see its column aliases).\n");
    }
    exit(1);
}
if (!$options['dry_run']) {
    fwrite(STDERR, sprintf("Catalogue now holds %d medicines (last import: %s)\n",
        (int)$info['count'], $info['updated_at'] !== '' ? $info['updated_at'] : '—'));
    EverLog::info('Medicine catalogue imported', [
        'event' => 'medicines_imported',
        'country' => $country,
        'dataset' => (string)($module['dataset_id'] ?? ''),
        'count' => (int)$info['count'],
        'saved' => (int)$result['saved'],
        'skipped' => (int)$result['skipped'],
        'truncate' => !empty($options['truncate']),
    ]);
}
exit($result['ok'] ? 0 : 1);
