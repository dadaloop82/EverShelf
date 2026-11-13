<?php
/**
 * EverShelf Med — the GERMANY module (dataset columns only).
 *
 * The German pack prints the **PZN** (8 digits = 7 + check) in clear text with the prefix `PZN`
 * AND as a bare Code 39 (the letters `PZN` are not inside the bars): the number can be read
 * either way, so the schema declares both, with an `ocr.sample` of `PZN 00000000`.
 *
 * There is NO free/open PZN dataset (the Informationsstelle für Arzneispezialitäten licenses
 * it), so this module only teaches the importer what a licensed export calls its columns. The
 * scan therefore identifies the box and the household types the name — which is the honest
 * outcome, and it says so (`catalogue.open: false`).
 *
 * Source: https://de.wikipedia.org/wiki/Pharmazentralnummer
 */

function medicalGermanyColumnAliases(): array
{
    return [
        'aic' => ['pzn', 'pharmazentralnummer', 'pzn8', 'pzn code'],
        'name' => ['bezeichnung', 'name', 'handelsname', 'produktname', 'artikelbezeichnung'],
        'ingredient' => ['wirkstoff', 'wirkstoffe', 'arzneistoff', 'active ingredient'],
        'form' => ['darreichungsform', 'form', 'pharmazeutische form'],
        'dosage' => ['staerke', 'stärke', 'dosierung', 'dosage'],
        'pack' => ['packung', 'packungsgroesse', 'packungsgröße', 'pack'],
        'company' => ['hersteller', 'anbieter', 'zulassungsinhaber', 'company'],
        'atc' => ['atc', 'atc code', 'atccode'],
        'fascia' => ['preis', 'kategorie'],
    ];
}

function medicalGermanyModule(): array
{
    return [
        'cc' => 'DE',
        'slug' => 'germany',
        'dataset_columns' => 'medicalGermanyColumnAliases',
        'dataset_id' => 'pzn',
    ];
}
