<?php
/**
 * EverShelf Med — the UNITED STATES module (dataset columns only).
 *
 * A US pack prints the **NDC** (National Drug Code) in clear text — `NDC 12345-678-90` — and
 * carries a UPC/EAN barcode that identifies the product. Both are in the schema; the printed
 * form is what the OCR looks for (the barcode of a US box is a product code, not a drug code).
 *
 * The catalogue IS free and open: the FDA NDC Directory is public domain, published daily as
 * JSON (`https://open.fda.gov/apis/drug/ndc/`). This module maps its fields, so
 * `scripts/import-medicines.php ndc.json --country=US` fills the local table and the scan can
 * name the box automatically.
 */

function medicalUsaColumnAliases(): array
{
    return [
        'aic' => ['product ndc', 'package ndc', 'ndc', 'productndc'],
        'name' => ['brand name', 'brandname', 'proprietary name', 'brand', 'name'],
        'ingredient' => ['generic name', 'genericname', 'nonproprietary name', 'substance name', 'active ingredients'],
        'form' => ['dosage form', 'dosageform', 'form'],
        'dosage' => ['strength', 'active numerator strength', 'dosage'],
        'pack' => ['packaging', 'package description', 'description'],
        'company' => ['labeler name', 'labelername', 'manufacturer', 'company'],
        'atc' => ['atc', 'pharm class epc'],
        'fascia' => ['product type', 'marketing category'],
    ];
}

function medicalUsaModule(): array
{
    return [
        'cc' => 'US',
        'slug' => 'usa',
        'dataset_columns' => 'medicalUsaColumnAliases',
        'dataset_id' => 'openfda_ndc',
    ];
}
