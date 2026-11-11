<?php
/**
 * EverShelf Med — the opt-in medicine cabinet, deliberately small and deterministic.
 *
 * Four rules, in this order:
 *   1. The module is OFF unless the owner turned it on (`MED_ENABLED` / the Settings switch)
 *      and the household accepted the disclaimer: an app that counts tomatoes must not start
 *      guessing therapies.
 *   2. Food and medicines never mix: a location can be marked `medical`, a product can be
 *      flagged, and those rows are excluded from recipes/shopping suggestions.
 *   3. "What do I have for a headache?" is answered by a CLOSED taxonomy of 8 household
 *      categories (data/medical_categories.json) and the user's own inventory — no AI, no
 *      network, no dosage, no diagnosis.
 *   4. The reply always carries the disclaimer: the app lists boxes, the doctor treats people.
 */

/**
 * The module is on only when the owner enabled it — via `.env` (`MED_ENABLED`) or the
 * Settings toggle (`app_settings.med_enabled`). Off by default, always.
 */
function medicalEnabled(?PDO $db = null): bool
{
    static $memo = null;
    if (strtolower(trim((string)env('MED_ENABLED', 'false'))) === 'true') {
        return true;
    }
    if ($memo !== null) {
        return $memo;
    }
    $memo = false;
    try {
        $db = $db ?: (function_exists('getDB') ? getDB() : null);
        if ($db instanceof PDO) {
            $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = 'med_enabled'");
            $stmt->execute();
            $raw = (string)($stmt->fetchColumn() ?: '');
            $memo = in_array(strtolower(trim($raw, " \t\n\r\"")), ['1', 'true', 'yes', 'on'], true);
        }
    } catch (Throwable $e) {
        $memo = false;
    }
    return $memo;
}

/** The closed Phase-1 taxonomy: data/medical_categories.json (8 household categories). */
function medicalCategories(): array
{
    static $cats = null;
    if ($cats !== null) {
        return $cats;
    }
    $path = dirname(__DIR__, 2) . '/data/medical_categories.json';
    $raw = is_readable($path) ? (string)file_get_contents($path) : '';
    $parsed = json_decode($raw, true);
    $cats = (is_array($parsed) && !empty($parsed['categories'])) ? array_values($parsed['categories']) : [];
    if (!$cats) {
        EverLog::warn('medical categories missing or unreadable', ['event' => 'medical_categories_missing', 'path' => $path]);
    }
    return $cats;
}

/** The ATC map (Phase 2): data/medical_atc.json, kept for the AIFA import. */
function medicalAtcData(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $path = dirname(__DIR__, 2) . '/data/medical_atc.json';
    $raw = is_readable($path) ? (string)file_get_contents($path) : '';
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || empty($parsed['groups'])) {
        EverLog::warn('ATC map missing or unreadable', ['event' => 'medical_atc_missing', 'path' => $path]);
        $parsed = ['groups' => []];
    }
    $data = $parsed;
    return $data;
}

/** The 8 valid category slugs (the only values `products.med_tags` may contain). */
function medicalCategorySlugs(): array
{
    return array_values(array_filter(array_map(static fn($c) => (string)($c['slug'] ?? ''), medicalCategories())));
}

/** The category slugs of a product (comma separated list in products.med_tags). */
function medicalTagsOf(array $row): array
{
    $raw = strtolower(trim((string)($row['med_tags'] ?? '')));
    if ($raw === '') {
        return [];
    }
    $valid = medicalCategorySlugs();
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($t) => in_array($t, $valid, true)));
}

/**
 * Which household categories a sentence points at — deterministic, offline, longest phrase first.
 *
 * @return array<int,array{slug:string,label_key:string,atc:array<int,string>,label:string}>
 */
function medicalMatchSymptom(string $text): array
{
    $hay = mb_strtolower(trim($text));
    if ($hay === '') {
        return [];
    }
    // Every [phrase => category], longest first so "mal di testa" beats a bare "testa".
    $phrases = [];
    foreach (medicalCategories() as $cat) {
        $slug = (string)($cat['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        foreach ((array)($cat['symptoms'] ?? []) as $words) {
            foreach ((array)$words as $word) {
                $w = mb_strtolower(trim((string)$word));
                if ($w !== '') {
                    $phrases[$w] = [
                        'slug' => $slug,
                        'label_key' => (string)($cat['label_key'] ?? ''),
                        'atc' => array_values((array)($cat['atc'] ?? [])),
                    ];
                }
            }
        }
    }
    uksort($phrases, static fn($a, $b) => mb_strlen((string)$b) <=> mb_strlen((string)$a));
    $out = [];
    $seen = [];
    foreach ($phrases as $phrase => $cat) {
        $slug = (string)$cat['slug'];
        if (isset($seen[$slug]) || mb_strpos($hay, (string)$phrase) === false) {
            continue;
        }
        $seen[$slug] = true;
        $key = (string)$cat['label_key'];
        $out[] = ['slug' => $slug, 'label_key' => $key, 'atc' => array_values($cat['atc']), 'label' => $key];
    }
    return $out;
}

/**
 * Is this pantry row a medicine? The flag on the product wins; otherwise the location.
 *
 * @param array<string,mixed> $row      inventory/product row (is_medical, location)
 * @param array<int,string>   $medLocs  location slugs marked medical in Settings
 */
function medicalIsMedicalRow(array $row, array $medLocs = []): bool
{
    if (!empty($row['is_medical'])) {
        return true;
    }
    $loc = mb_strtolower(trim((string)($row['location'] ?? '')));
    return $loc !== '' && in_array($loc, array_map('mb_strtolower', $medLocs), true);
}

/** The medical location slugs the household marked (app_settings.medical_locations). */
function medicalLocationSlugs(array $settings): array
{
    $raw = $settings['medical_locations'] ?? [];
    if (is_string($raw)) {
        $raw = json_decode($raw, true) ?: [];
    }
    return array_values(array_filter(array_map(static fn($v) => trim((string)$v), (array)$raw)));
}

/**
 * The per-country barcode schemas: data/medical_code_schemas.json.
 *
 * This file answers "can we tell a medicine pack from a jar here?" — it describes CODES, not
 * drugs. `status: none` means we have no schema for that country, and the app then claims
 * nothing: the scan offers manual entry and says so.
 */
function medicalCodeSchemas(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $path = dirname(__DIR__, 2) . '/data/medical_code_schemas.json';
    $raw = is_readable($path) ? (string)file_get_contents($path) : '';
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || empty($parsed['countries'])) {
        EverLog::warn('medical code schemas missing or unreadable', ['event' => 'medical_schemas_missing', 'path' => $path]);
        $parsed = ['countries' => [], 'unsupported_note_key' => 'medical.country_none_hint'];
    }
    $data = $parsed;
    return $data;
}

/** One country block, or null when the country has no schema. */
function medicalCountrySchema(string $cc): ?array
{
    $cc = strtoupper(trim($cc));
    if ($cc === '') {
        return null;
    }
    $all = medicalCodeSchemas()['countries'] ?? [];
    return isset($all[$cc]) && is_array($all[$cc]) ? $all[$cc] : null;
}

/**
 * A scanned read without the decoration a label or a scanner adds around it.
 *
 * `A 040 313 076`, `a040313076` and `A040313076` are one code; the printed labels of the
 * Italian packs are spaced and the OCR of a photo inserts whatever it likes in between.
 */
function medicalCompactCode(string $code): string
{
    return (string)preg_replace('/[\s\-\._\/]+/u', '', strtoupper(trim($code)));
}

/**
 * The GS1 element string of a 2D DataMatrix (EU FMD), parsed into its AIs.
 *
 * Country-NEUTRAL on purpose: it knows the GS1 standard, not what a country means by a number.
 * A medicine box under the Falsified Medicines Directive carries GTIN (`01`), expiry (`17`),
 * batch (`10`) and serial (`21`); AI `71x` is the national reimbursement number, whose CONTENT
 * belongs to the country module (in Italy the AIC lives there — `api/lib/med/it.php`).
 *
 * Parsed by walking the string, because the AIs have different lengths: `01`/`02` take 14
 * characters, `11`/`15`/`17` take 6, and a variable-length AI (`10`, `21`, `30`, `37`, `71x`)
 * ends at the GS separator (FNC1, `\x1d`). An AI we do not know with no separator ends the
 * walk — guessing there is how a lot number becomes a drug code. A read that yields no AI at
 * all is not a GS1 element string (a plain EAN, for instance) and is left alone.
 *
 * @return array{fmd:bool,ais:array<string,string>,gtin:?string,expiry:?string,batch:?string,serial:?string}
 */
function medicalGs1Parse(string $read): array
{
    $out = ['fmd' => false, 'ais' => [], 'gtin' => null,
            'expiry' => null, 'batch' => null, 'serial' => null];
    $s = trim((string)$read);
    if ($s === '') {
        return $out;
    }
    // Some engines hand back the GS separator as the literal text "\u001d"/"\x1d".
    $s = str_replace(['\\u001d', '\\x1d'], "\x1d", $s);
    $fixed = ['01' => 14, '02' => 14, '11' => 6, '15' => 6, '17' => 6, '20' => 2];
    $variable = ['10', '21', '30', '37'];
    $len = strlen($s);
    $pos = 0;
    while ($pos < $len) {
        if ($s[$pos] === "\x1d") {
            $pos++;
            continue;
        }
        $three = substr($s, $pos, 3);
        if (preg_match('/^71\d$/', $three) === 1) {
            $end = strpos($s, "\x1d", $pos + 3);
            $end = $end === false ? $len : $end;
            $value = substr($s, $pos + 3, $end - $pos - 3);
            if ($value !== '') {
                $out['ais']['71'] = strtoupper($value);
            }
            $pos = $end;
            continue;
        }
        $two = substr($s, $pos, 2);
        if (isset($fixed[$two])) {
            $value = substr($s, $pos + 2, $fixed[$two]);
            if (strlen($value) < $fixed[$two]) {
                break;
            }
            $out['ais'][$two] = $value;
            $pos += 2 + $fixed[$two];
            continue;
        }
        if (in_array($two, $variable, true)) {
            $end = strpos($s, "\x1d", $pos + 2);
            $end = $end === false ? $len : $end;
            $value = substr($s, $pos + 2, $end - $pos - 2);
            if ($value !== '') {
                $out['ais'][$two] = $value;
            }
            $pos = $end;
            continue;
        }
        break;
    }
    if (!$out['ais']) {
        return $out;
    }
    $out['gtin'] = $out['ais']['01'] ?? null;
    $out['expiry'] = $out['ais']['17'] ?? null;
    $out['batch'] = $out['ais']['10'] ?? null;
    $out['serial'] = $out['ais']['21'] ?? null;
    $out['fmd'] = $out['gtin'] !== null || isset($out['ais']['71']) || $out['expiry'] !== null;
    return $out;
}

/**
 * The COUNTRY MODULE for this schema, or null.
 *
 * A country that needs more than a regex — Italy prints its AIC in three shapes and carries it
 * in AI 710 — declares `"module": "<slug>"` in `data/medical_code_schemas.json`, and its code
 * lives in `api/lib/med/<slug>.php` with a `medical<Slug>Module()` descriptor. The core stays
 * country-neutral: it only asks the module "is this read one of YOUR codes?".
 *
 * @return array{cc:string,slug:string,local_code?:string,dataset_columns?:string,dataset_id?:string}|null
 */
function medicalCountryModule(string $cc): ?array
{
    static $memo = [];
    $cc = strtoupper(trim($cc));
    if ($cc === '') {
        return null;
    }
    if (array_key_exists($cc, $memo)) {
        return $memo[$cc];
    }
    $memo[$cc] = null;
    $schema = medicalCountrySchema($cc);
    $slug = strtolower(trim((string)($schema['module'] ?? '')));
    if ($slug === '' || preg_match('/^[a-z]{3,16}$/', $slug) !== 1) {
        return null;
    }
    $path = __DIR__ . '/med/' . $slug . '.php';
    if (!is_readable($path)) {
        EverLog::warn('medical country module missing', [
            'event' => 'medical_country_module_missing',
            'country' => $cc,
            'slug' => $slug,
        ]);
        return null;
    }
    require_once $path;
    $fn = 'medical' . ucfirst($slug) . 'Module';
    if (!function_exists($fn)) {
        return null;
    }
    $module = $fn();
    if (!is_array($module)) {
        return null;
    }
    $module['cc'] = $cc;
    $module['slug'] = $slug;
    $memo[$cc] = $module;
    return $module;
}

/**
 * Is this read one of the DRUG CODES of the active country?
 *
 * Delegates to the country module (Italy: the AIC). Returns the module's answer untouched —
 * `{code, type, label_key, aic?}` — or null. The core never interprets it.
 *
 * @return array{code:string,type:string,label_key:string,aic?:string}|null
 */
function medicalLocalCode(string $code, string $country): ?array
{
    $module = medicalCountryModule($country);
    $fn = (string)($module['local_code'] ?? '');
    if ($fn === '' || !is_callable($fn)) {
        return null;
    }
    $result = $fn($code);
    return is_array($result) && !empty($result['code']) ? $result : null;
}

/**
 * Is this read a country-specific non-catalogue pack code (Italy: Farmadati PARAF 9xxxxxxxx)?
 *
 * Optional module hook — countries without `paraf_code` answer null. The core never interprets
 * the shape; it only forwards the module's `{code, type, label_key}`.
 *
 * @return array{code:string,type:string,label_key:string}|null
 */
function medicalParafCode(string $code, string $country): ?array
{
    $module = medicalCountryModule($country);
    $fn = (string)($module['paraf_code'] ?? '');
    if ($fn === '' || !is_callable($fn)) {
        return null;
    }
    $result = $fn($code);
    return is_array($result) && !empty($result['code']) ? $result : null;
}

/**
 * Is this read one of the PACKAGING codes the schema tells us to throw away?
 *
 * In Italy the SSN label is a Pharmacode/Laetus and the 2D DataMatrix carries a lot/serial:
 * neither says which drug the box contains, so claiming one as "a medicine code" put the
 * wrong identifier on the product. The schema lists them under `ignored` with the action the
 * scanner must take instead (`ocr`: read the AIC printed on the label).
 */
function medicalCodeIgnored(string $code, string $country): bool
{
    if (medicalIgnoredLabelKey($code, $country) !== '') {
        return true;
    }
    $schema = medicalCountrySchema($country);
    $c = medicalCompactCode($code);
    if (!$schema || $c === '' || empty($schema['ignored'])) {
        return false;
    }
    // The country's own drug code is never a packaging code.
    if (medicalLocalCode($c, $country) !== null) {
        return false;
    }
    foreach ((array)$schema['ignored'] as $def) {
        $pattern = (string)($def['pattern'] ?? '');
        if ($pattern === '') {
            continue;
        }
        if (@preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $c) === 1) {
            return true;
        }
    }
    return false;
}

/** The label of an ignored code (`medical.code_line`), so the UI can say what it read. */
function medicalIgnoredLabelKey(string $code, string $country): string
{
    $schema = medicalCountrySchema($country);
    $c = medicalCompactCode($code);
    if (!$schema || $c === '' || empty($schema['ignored'])) {
        return '';
    }
    // The country's own drug code is never a packaging code, whatever the patterns say.
    if (medicalLocalCode($c, $country) !== null) {
        return '';
    }
    foreach ((array)$schema['ignored'] as $def) {
        $pattern = (string)($def['pattern'] ?? '');
        if ($pattern !== '' && @preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $c) === 1) {
            return (string)($def['label_key'] ?? '');
        }
    }
    return '';
}

/** The OCR patterns of the country block: what the label prints, written down as data. */
/**
 * Is the TEMPORARY medicine-reader diagnostic on? (`MED_DEBUG=true` in the environment.)
 *
 * The client asks the server (`med_status.debug`) and only then reports what each OCR pass saw,
 * with a bounded number of small frames, into `data/med_debug/`. Off by default: a pantry does
 * not need to ship pictures anywhere.
 */
function medicalDebugEnabled(): bool
{
    return strtolower(trim((string)env('MED_DEBUG', 'false'))) === 'true';
}

function medicalOcrPatterns(string $country): array
{
    $schema = medicalCountrySchema($country);
    $patterns = (array)($schema['ocr']['patterns'] ?? []);
    return array_values(array_filter(array_map('strval', $patterns)));
}

/**
 * Which country's labels are on the box. `MED_COUNTRY` wins, then the instance language
 * (it→IT, de→DE, fr→FR, es→ES); anything else stays empty instead of guessing (an English UI
 * says nothing about the country of the pharmacy).
 *
 * `$uiLang` is the client's own UI language, used only as the LAST fallback: an Italian
 * interface over an instance that never set `APP_LANG` still carries Italian boxes, and
 * without this every Italian medicine answered "no schema for this country".
 */
function medicalActiveCountry(?PDO $db = null, string $uiLang = ''): string
{
    $env = strtoupper(trim((string)env('MED_COUNTRY', '')));
    if ($env !== '') {
        return $env;
    }
    $stored = '';
    try {
        $db = $db ?: (function_exists('getDB') ? getDB() : null);
        if ($db instanceof PDO) {
            $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = 'med_country'");
            $stmt->execute();
            $stored = strtoupper(trim((string)($stmt->fetchColumn() ?: ''), " \t\n\r\""));
        }
    } catch (Throwable $e) { /* no setting yet */ }
    if ($stored !== '') {
        return $stored;
    }
    $lang = strtolower(substr(trim((string)env('APP_LANG', '')), 0, 2));
    if ($lang === '') {
        $lang = strtolower(substr(trim($uiLang), 0, 2));
    }
    $map = ['it' => 'IT', 'de' => 'DE', 'fr' => 'FR', 'es' => 'ES'];
    return $map[$lang] ?? '';
}

/**
 * Can the medicine scanner identify a box in this country AT ALL?
 *
 * `false` means the country has no schema, or a schema that declares neither a national code nor
 * the FMD DataMatrix (see `data/medical_code_schemas.json`). The UI then BLOCKS the scan with a
 * dialog that says so, offers the manual entry and points at the contribution guide — silently
 * scanning a label the app knows nothing about is how a household loses trust in the feature.
 */
function medicalScanSupported(string $country): bool
{
    $schema = medicalCountrySchema($country);
    if (!$schema || (string)($schema['status'] ?? 'none') === 'none') {
        return false;
    }
    if (!empty($schema['scan']['supported'])) {
        return true;
    }
    return !empty($schema['codes']) || !empty($schema['fmd']);
}

/**
 * Is this scanned code a medicine pack, for the active country?
 *
 * Country-NEUTRAL: the identifying code comes from the country module (`medicalLocalCode()` —
 * Italy: the printed AIC in its three forms), the packaging codes from the schema's `ignored`
 * list, and the remaining national formats are plain patterns (`pzn`, `cn`, `ndc`, …). A
 * country with no schema gets `none` and the app claims nothing.
 *
 * @return array{detected:bool,confidence:string,type:string,label_key:string,status:string,country:string,aic:?string,ignored:bool}
 */
function medicalDetectCode(string $code, string $country): array
{
    $out = ['detected' => false, 'confidence' => 'none', 'type' => '', 'label_key' => '',
            'status' => 'none', 'country' => strtoupper($country), 'aic' => null, 'ignored' => false];
    $schema = medicalCountrySchema($country);
    if (!$schema) {
        return $out;
    }
    $out['status'] = (string)($schema['status'] ?? 'none');
    $c = strtoupper(trim($code));
    if ($c === '') {
        return $out;
    }
    $digits = preg_replace('/\D/', '', $c);

    // 1. The country's own drug code (Italy: the AIC) — only if the schema declares that type,
    //    so a data file stays in charge of what is recognisable.
    $local = medicalLocalCode($c, $country);
    if ($local !== null) {
        foreach ((array)($schema['codes'] ?? []) as $codeDef) {
            if (($codeDef['type'] ?? '') === ($local['type'] ?? '')) {
                $out['detected'] = true;
                $out['confidence'] = $out['status'] === 'full' ? 'high' : 'probable';
                $out['type'] = (string)$local['type'];
                $out['label_key'] = (string)($local['label_key'] ?? ($codeDef['label_key'] ?? ''));
                $out['aic'] = isset($local['aic']) ? (string)$local['aic'] : null;
                return $out;
            }
        }
    }

    // 2. A packaging code (a line code of the box): say so, claim nothing.
    if (medicalCodeIgnored($c, $country)) {
        $out['ignored'] = true;
        $out['label_key'] = medicalIgnoredLabelKey($c, $country);
        return $out;
    }

    // EAN-13 (the box barcode): it is "ours" when the GS1 prefix belongs to the country AND
    // the country schema is complete enough to say so. A foreign EAN is left alone.
    if (preg_match('/^\d{13}$/', $c)) {
        foreach ((array)($schema['gs1'] ?? []) as $range) {
            $lo = (string)($range[0] ?? '');
            $hi = (string)($range[1] ?? $lo);
            $prefix = substr($c, 0, strlen($lo));
            if ($lo !== '' && $prefix >= $lo && $prefix <= $hi) {
                foreach ((array)($schema['codes'] ?? []) as $codeDef) {
                    if (($codeDef['type'] ?? '') === 'ean13' && (int)($codeDef['digits'] ?? 13) === 13) {
                        $out['detected'] = true;
                        $out['confidence'] = $out['status'] === 'full' ? 'high' : 'probable';
                        $out['type'] = 'ean13';
                        $out['label_key'] = (string)($codeDef['label_key'] ?? '');
                        return $out;
                    }
                }
            }
        }
        // Austrian PZN is embedded in the EAN (908888…), see the schema.
        foreach ((array)($schema['codes'] ?? []) as $codeDef) {
            if (($codeDef['type'] ?? '') === 'pzn_in_ean' && str_starts_with($c, (string)($codeDef['prefix'] ?? '\0'))) {
                $out['detected'] = true;
                $out['confidence'] = 'high';
                $out['type'] = 'pzn_in_ean';
                $out['label_key'] = (string)($codeDef['label_key'] ?? '');
                return $out;
            }
        }
        return $out;
    }

    // National short codes: Pharmacode/Laetus (IT), PZN as Code 39 (DE), CN (ES), NDC (US).
    foreach ((array)($schema['codes'] ?? []) as $codeDef) {
        $type = (string)($codeDef['type'] ?? '');
        if ($type === 'ean13' || $type === 'pzn_in_ean') {
            continue;
        }
        if (!medicalCodeMatches($codeDef, $c, $digits)) {
            continue;
        }
        $out['detected'] = true;
        $out['confidence'] = $out['status'] === 'full' ? 'high' : 'probable';
        $out['type'] = $type;
        $out['label_key'] = (string)($codeDef['label_key'] ?? '');
        return $out;
    }
    return $out;
}

/**
 * Does this read match ONE code definition of the country schema?
 *
 * `pattern` is matched against the WHOLE read, letters included — the same rule as the
 * browser's `_medCodeMatches()`: the digit-stripped form of `16G87N` is `1687`, which used to
 * pass as a 4-digit Laetus Pharmacode. A definition without a pattern falls back to its digit
 * count (a schema written before the patterns still works), and the numeric bounds
 * (Pharmacode 3…131070) always apply.
 */
function medicalCodeMatches(array $def, string $code, string $digits): bool
{
    if (!empty($def['pattern'])) {
        $re = '~' . str_replace('~', '\\~', (string)$def['pattern']) . '~';
        if (@preg_match($re, $code) !== 1) {
            return false;
        }
    } else {
        $wanted = $def['digits'] ?? null;
        if (is_array($wanted)) {
            if (!in_array(strlen($digits), array_map('intval', $wanted), true)) {
                return false;
            }
        } elseif (is_int($wanted) && $wanted > 0) {
            if (strlen($digits) !== $wanted) {
                return false;
            }
        } elseif (($def['type'] ?? '') !== 'pharmacode') {
            return false;
        }
    }
    if (($def['type'] ?? '') === 'pharmacode' || isset($def['min']) || isset($def['max'])) {
        if (!ctype_digit($code)) {
            return false;
        }
        $min = (int)($def['min'] ?? 3);
        $max = (int)($def['max'] ?? 131070);
        $n = (int)$code;
        if ($n < $min || $n > $max) {
            return false;
        }
    }
    return true;
}

/**
 * The PAO rule table (`data/medical_pao.json`), read once per request.
 *
 * The vocabulary is DATA, not code: the form words of a medicine (collirio, sciroppo, crema…)
 * belong next to the other product words, and `scripts/…` counts any that sneak back into the
 * logic files. Each rule is an ordered list of words: the first one the name contains wins, so
 * "unguento oftalmico" is listed before the generic "unguento".
 */
function medicalPaoRules(): array
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }
    $rules = [];
    $path = dirname(__DIR__, 2) . '/data/medical_pao.json';
    $raw = is_file($path) ? (string)file_get_contents($path) : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    foreach ((array)($data['rules'] ?? []) as $rule) {
        $words = [];
        foreach ((array)($rule['match'] ?? []) as $word) {
            $w = trim((string)$word);
            if ($w === '') {
                continue;
            }
            $words[] = function_exists('mb_strtolower') ? mb_strtolower($w) : strtolower($w);
        }
        if (!$words) {
            continue;
        }
        $rules[] = ['match' => $words, 'days' => max(0, min(365, (int)($rule['days'] ?? 0)))];
    }
    return $rules;
}

/**
 * The PAO suggested for a medicine, from the FORM its name (or its pack notes) declares.
 *
 * The notes matter as much as the name: the cabinet stores «Fastum» with «gel 2,5% 25 mg/g tubo
 * 50 g» in `products.notes`, and the first run of the migration filled nothing because it only
 * looked at the title. 0 means "opening changes nothing" (tablets, capsules, suppositories) or
 * "nothing recognised" — in both cases nothing is written. It is a SUGGESTION: `med_classify`
 * uses it only when the household left the field empty, and the migration that applies it to the
 * existing cabinet never overwrites a typed value.
 */
function medicalPaoDaysForName(string $name, string $notes = ''): int
{
    $hay = trim(function_exists('mb_strtolower') ? mb_strtolower($name . ' ' . $notes) : strtolower($name . ' ' . $notes));
    if ($hay === '') {
        return 0;
    }
    foreach (medicalPaoRules() as $rule) {
        foreach ($rule['match'] as $word) {
            if ($word !== '' && str_contains($hay, $word)) {
                return (int)$rule['days'];
            }
        }
    }
    return 0;
}
