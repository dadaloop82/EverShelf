<?php
/**
 * EverShelf Med — the ITALY module. Country code, kept out of the core on purpose.
 *
 * What is Italian here, and nowhere else:
 *   1. the number that identifies the drug is the **AIC**, and the box PRINTS it —
 *      `A040313076` in Code 39, or the bare 9 digits of the older Code 32 form;
 *   2. the 2D DataMatrix of the EU FMD carries it as the national reimbursement number
 *      (AI **710**), next to the lot the scanner must not mistake for a drug code;
 *   3. the SSN label (`Pharmacode`/`Laetus`) and any short alphanumeric are PACKAGING: the
 *      schema lists them under `ignored` with `action: "ocr"`, because the AIC is printed and
 *      has to be read off the label;
 *   4. the catalogue is the AIFA open data (CSV/XML), imported once by
 *      `scripts/import-medicines.php --country=IT`: its column names are here.
 *
 * The core (`api/lib/medical.php`) knows none of this: it reads the country schema, walks the
 * generic GS1 element string, applies the `codes`/`ignored`/`ocr` lists of the data file, and
 * asks the country module only "is this read one of YOUR drug codes?". A new country = a block
 * in `data/medical_code_schemas.json` (`"module": "<slug>"`) + a file like this one.
 */

/** The AIC of a read, in its canonical 9-digit form, or null ("this is not a drug code"). */
function medicalItalyAicFromCode(string $code): ?string
{
    $c = medicalCompactCode($code);
    if ($c === '') {
        return null;
    }
    if (preg_match('/^A(\d{9})$/', $c, $m)) {
        return $m[1];
    }
    // NO bare 9-digit form. The SSN label prints a second Code 39 with the pack's progressive
    // number (`014058142`), which is indistinguishable from an old Code 32 AIC: accepting it
    // meant storing a serial as if it were a medicine. The printed block always carries the A.
    // The FMD DataMatrix: the AIC is the national reimbursement number (AI 710), possibly
    // zero-padded by the encoder. The lot (AI 10) is never a drug code.
    $gs1 = medicalGs1Parse($code);
    $nhrn = (string)($gs1['ais']['71'] ?? '');
    if ($nhrn !== '' && preg_match('/^0*(\d{9})$/', strtoupper($nhrn), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Is this read the Italian drug number? The core calls this (via `module.local_code`) before it
 * looks at the generic pattern list, because the three printed forms cannot be expressed as one
 * regex over the whole read.
 *
 * @return array{code:string,type:string,label_key:string,aic:string}|null
 *         `code` is what the box prints (`A` + 9), `aic` is the 9 digits the AIFA catalogue is
 *         keyed by.
 */
function medicalItalyLocalCode(string $code): ?array
{
    $aic = medicalItalyAicFromCode($code);
    if ($aic === null) {
        return null;
    }
    return ['code' => 'A' . $aic, 'type' => 'aic', 'label_key' => 'medical.code_aic', 'aic' => $aic];
}

/**
 * Italian PARAF / EAN for parafarmaci and medical devices (Farmadati Italia Srl).
 *
 * High-confidence shape only: exactly 9 digits starting with `9` (optional leading `A`).
 * These are NOT AIFA AICs — never returned from `medicalItalyLocalCode()`.
 *
 * @return array{code:string,type:string,label_key:string}|null
 */
function medicalItalyParafFromCode(string $code): ?array
{
    $c = medicalCompactCode($code);
    if ($c === '') {
        return null;
    }
    if (!preg_match('/^A?(\d{9})$/', $c, $m)) {
        return null;
    }
    $nine = $m[1];
    if ($nine[0] !== '9') {
        return null;
    }
    return ['code' => $nine, 'type' => 'paraf', 'label_key' => 'medical.code_paraf'];
}

/**
 * The AIFA open-data columns, by the many names the releases have used.
 *
 * The file is data somebody else writes: the headers change between editions (and between the
 * CSV and the XML export), so a column is recognised by its NORMALISED name and not by its
 * position. This map is Italian because the file is; another country's module brings its own.
 */
function medicalItalyColumnAliases(): array
{
    return [
        'aic' => ['aic', 'codice aic', 'cod aic', 'codiceaic', 'aic9', 'aic7', 'aic code', 'code aic',
                  'codice', 'code', 'cod medicinale', 'codice medicinale'],
        'name' => ['denominazione', 'nome', 'name', 'denominazione farmaco', 'descrizione', 'description',
                   'farmaco',
                   'nome commerciale', 'product name'],
        'ingredient' => ['principio attivo', 'principioattivo', 'pa associati', 'active ingredient', 'ingredient',
                         'sostanza attiva', 'sostanze attive', 'active substances'],
        'form' => ['forma', 'forma farmaceutica', 'pharmaceutical form', 'form', 'formafarmaceutica'],
        'dosage' => ['dosaggio', 'dose', 'strength', 'dosage', 'concentrazione'],
        'pack' => ['confezione', 'descrizione confezione', 'descrizione', 'pack', 'packaging', 'confezionamento',
                   'descrizione della confezione'],
        'company' => ['titolare', 'titolare aic', 'ditta', 'azienda', 'company', 'ragione sociale',
                      'marketing authorisation holder',
                      'titolare dell autorizzazione'],
        'atc' => ['atc', 'codice atc', 'atc code', 'codiceatc'],
        'fascia' => ['fascia', 'classe', 'fascia ssn', 'class', 'regime', 'fornitura'],
    ];
}

/**
 * The module descriptor the core reads. The naming convention is the contract: the schema says
 * `"module": "it"`, the core loads `api/lib/med/it.php` and calls `medicalItalyModule()`.
 */
function medicalItalyModule(): array
{
    return [
        'cc' => 'IT',
        'slug' => 'it',
        // Callable names, so the core never has to know what "an AIC" is.
        'local_code' => 'medicalItalyLocalCode',
        // Optional: Farmadati PARAF/EAN (9xxxxxxxx) — not AIFA, not in the public catalogue.
        'paraf_code' => 'medicalItalyParafFromCode',
        'dataset_columns' => 'medicalItalyColumnAliases',
        'dataset_id' => 'aifa',
        // Where the free file comes from (no API, no key): the official lists page publishes the
        // CSVs, and the dated names change with every release — hence the prefixes, not URLs.
        'dataset_sync' => [
            // The AIFA "Banca Dati Farmaci" export: every authorised pack, every class, with the
            // AIC, the name, the company, the ATC and the active ingredients. ~82 MB, one file.
            'urls' => ['https://drive.aifa.gov.it/farmaci/confezioni_fornitura.csv'],
            'max_bytes' => 157286400,
            // Older, partial lists (class A + equivalents), kept as a fallback if the export moves.
            'page' => 'https://www.aifa.gov.it/liste-dei-farmaci',
            'files' => [
                'Lista_farmaci_equivalenti',
                'Classe-A_per_principio-attivo',
            ],
        ],
    ];
}
