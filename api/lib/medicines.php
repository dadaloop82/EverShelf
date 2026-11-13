<?php
/**
 * The local medicine catalogue (AIFA open data, imported once — no HTTP call at all).
 *
 * Rationale: there is NO free, unlimited, key-less REST API for Italian medicines. AIFA
 * publishes the national list as open data (CSV/XML); `scripts/import-medicines.php --country=IT` loads it into
 * this SQLite table, and the app answers by AIC or by name from there. That keeps the promise
 * the module already made — offline-first, no third party, no key — while making a box
 * *recognisable* instead of merely stored.
 *
 * What a row may contain: identification only (AIC, commercial name, active ingredient,
 * pharmaceutical form, pack description, marketing authorisation holder, ATC). No dose advice,
 * no indication: the household still reads the leaflet, and `med_lookup` never answers a
 * therapy question (see medical.php).
 */

/**
 * The canonical key of the local catalogue: the NATIONAL drug number of the active country.
 *
 * Deliberately country-neutral: Italy keys on the AIC (9 digits), the US on the NDC (10-11),
 * Germany on the PZN (8). A leading `A` in front of exactly 9 digits is dropped because the
 * Italian pack PRINTS it there but the number is the 9 digits; everything that is not a digit
 * or a letter (dashes, dots, spaces) is decoration. 6..20 characters, or the value is refused.
 */
function medicineNormalizeKey(string $value): string
{
    $c = strtoupper(trim($value));
    $c = (string)preg_replace('/^A(?=\d{9}$)/', '', $c);
    $c = (string)preg_replace('/[^0-9A-Z]/', '', $c);
    return (strlen($c) >= 6 && strlen($c) <= 20) ? $c : '';
}

/** Backwards-compatible name: the AIC is simply Italy's national key. */
function medicineNormalizeAic(string $aic): string
{
    return medicineNormalizeKey($aic);
}

/** A searchable form of a title: lowercase, accents and punctuation removed. */
function medicineNormalizeName(string $name): string
{
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = strtr($s, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
    ]);
    $s = (string)preg_replace('/[^a-z0-9]+/u', ' ', $s);
    return trim((string)preg_replace('/\s+/', ' ', $s));
}

/**
 * The table. `aic` is the primary key (one pack per AIC in the AIFA list, so an upsert is the
 * natural import: re-running the importer updates names instead of duplicating rows).
 */
function medicinesEnsureTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS medicines (
        aic TEXT PRIMARY KEY,
        name TEXT NOT NULL DEFAULT '',
        name_norm TEXT NOT NULL DEFAULT '',
        ingredient TEXT DEFAULT '',
        form TEXT DEFAULT '',
        dosage TEXT DEFAULT '',
        pack TEXT DEFAULT '',
        company TEXT DEFAULT '',
        atc TEXT DEFAULT '',
        fascia TEXT DEFAULT '',
        imported_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_medicines_name ON medicines(name_norm)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_medicines_ingredient ON medicines(ingredient)');
}


/**
 * Fetch a URL server-side (curl when present, streams otherwise). Returns the body or null.
 *
 * The app never queries a medicine API at scan time; this is used ONCE to download the free
 * open-data file a national agency publishes, and by the periodic refresh.
 */
function medicinesFetchUrl(string $url, int $maxBytes = 41943040, int $timeout = 25): ?string
{
    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'EverShelf/1 medicine catalogue sync',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE => $maxBytes,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300 || $size > $maxBytes) {
            return null;
        }
        return (string)$body;
    }
    if (!ini_get('allow_url_fopen')) {
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'EverShelf/1']]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes);
    return is_string($body) && $body !== '' ? $body : null;
}

/**
 * Fetch a URL server-side with extra headers (the optional catalogue provider's key).
 *
 * Same contract as `medicinesFetchUrl()`: the body, or null on any failure. Kept separate so the
 * open-data download (no headers, 40 MB cap) stays exactly as it is.
 *
 * @param array<int,string> $headers raw header lines, e.g. `Authorization: Bearer ...`
 */
function medicinesFetchUrlWithHeaders(string $url, array $headers = [], int $maxBytes = 1048576, int $timeout = 8): ?string
{
    if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'EverShelf/1 medicine catalogue',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_MAXFILESIZE => $maxBytes,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300 || strlen((string)$body) > $maxBytes) {
        return null;
    }
    return (string)$body;
}

/**
 * Download a URL straight to a file (curl streams it: the AIFA formulary is ~82 MB).
 *
 * @returns int bytes written, or -1 on failure
 */
function medicinesDownloadToFile(string $url, string $path, int $maxBytes = 157286400, int $timeout = 300): int
{
    if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
        return -1;
    }
    $fh = @fopen($path, 'wb');
    if (!$fh) {
        return -1;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'EverShelf/1 medicine catalogue sync',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function ($resource, $total, $done) use ($maxBytes) {
            return ($done > $maxBytes) ? 1 : 0;
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    $size = (int)@filesize($path);
    if ($ok === false || $status < 200 || $status >= 300 || $size <= 0 || $size > $maxBytes) {
        @unlink($path);
        return -1;
    }
    return $size;
}

/**
 * Download and import the national catalogue from the free source the country module declares.
 *
 * `dataset_sync` in the module says WHERE (a public page) and WHAT (file-name prefixes: the
 * releases are dated, so a pinned URL would be stale within days). Every matching file is
 * downloaded, imported with the country's column map, and deleted. No key, no quota, no API:
 * the official open data, read once locally — the scans then answer from the table.
 *
 * @param array{page?:string,files?:array<int,string>} $sync
 * @param array<string,array<int,string>> $aliases the country's column map
 * @returns array{ok:bool,files:array<int,array<string,mixed>>,saved:int,skipped:int,error:string}
 */
function medicinesSyncSources(PDO $db, array $sync, array $aliases, array $options = []): array
{
    $out = ['ok' => false, 'files' => [], 'saved' => 0, 'skipped' => 0, 'error' => ''];
    $page = (string)($sync['page'] ?? '');
    $prefixes = array_values(array_filter(array_map('strval', (array)($sync['files'] ?? []))));
    $direct = array_values(array_filter(array_map('strval', (array)($sync['urls'] ?? []))));
    // Preferred: the national formulary published as ONE file (Italy: the AIFA "confezioni"
    // export). It is the complete list — every box, every class — so a scan can be verified
    // against it. The page scrape below is the older, partial path kept as a fallback.
    if ($direct) {
        $maxBytes = (int)($sync['max_bytes'] ?? 157286400);
        foreach ($direct as $url) {
            $file = basename((string)parse_url($url, PHP_URL_PATH));
            $tmp = tempnam(sys_get_temp_dir(), 'evershelf_meds_');
            $path = $tmp !== false ? $tmp . '.' . (strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: 'csv') : '';
            $bytes = $path !== '' ? medicinesDownloadToFile($url, $path, $maxBytes) : -1;
            if ($bytes < 0) {
                $out['files'][] = ['file' => $file, 'saved' => 0, 'error' => 'download_failed'];
                continue;
            }
            $result = medicinesImportFile($db, $path, [
                'limit' => (int)($options['limit'] ?? 0),
                'truncate' => !empty($options['truncate']),
            ], $aliases);
            @unlink($path);
            if ($tmp !== false) {
                @unlink($tmp);
            }
            $saved = (int)($result['saved'] ?? 0);
            $out['saved'] += $saved;
            $out['skipped'] += (int)($result['skipped'] ?? 0);
            $out['files'][] = [
                'file' => $file,
                'bytes' => $bytes,
                'format' => (string)($result['format'] ?? ''),
                'rows' => (int)($result['rows'] ?? 0),
                'saved' => $saved,
                'error' => (string)($result['error'] ?? ''),
            ];
        }
        $out['ok'] = $out['saved'] > 0;
        if (!$out['ok']) {
            $out['error'] = 'nothing_imported';
        }
        return $out;
    }
    if ($page === '' || !$prefixes) {
        $out['error'] = 'no_source';
        return $out;
    }
    $html = medicinesFetchUrl($page, 4194304);
    if ($html === null) {
        $out['error'] = 'page_unreachable';
        return $out;
    }
    // Relative links resolved against the page: the site serves `/documents/...` paths.
    $base = (string)preg_replace('#^(https?://[^/]+).*$#i', '$1', $page);
    $links = [];
    if (preg_match_all('/href="([^"]+\.(?:csv|xml|json))[^"]*"/i', $html, $m)) {
        foreach ($m[1] as $href) {
            $href = str_starts_with($href, 'http') ? $href : $base . '/' . ltrim($href, '/');
            $file = basename((string)parse_url($href, PHP_URL_PATH));
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && stripos($file, $prefix) === 0) {
                    $links[] = $href;
                    break;
                }
            }
        }
    }
    $links = array_values(array_unique($links));
    $maxFiles = max(1, (int)($options['max_files'] ?? 6));
    $links = array_slice($links, 0, $maxFiles);
    if (!$links) {
        $out['error'] = 'no_file_found';
        return $out;
    }
    foreach ($links as $url) {
        $file = basename((string)parse_url($url, PHP_URL_PATH));
        $body = medicinesFetchUrl($url);
        if ($body === null) {
            $out['files'][] = ['file' => $file, 'saved' => 0, 'error' => 'download_failed'];
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: 'csv';
        $tmp = tempnam(sys_get_temp_dir(), 'evershelf_meds_');
        $path = $tmp !== false ? $tmp . '.' . $ext : '';
        if ($path === '' || file_put_contents($path, $body) === false) {
            $out['files'][] = ['file' => $file, 'saved' => 0, 'error' => 'write_failed'];
            continue;
        }
        $result = medicinesImportFile($db, $path, ['limit' => (int)($options['limit'] ?? 0)], $aliases);
        @unlink($path);
        if ($tmp !== false) {
            @unlink($tmp);
        }
        $saved = (int)($result['saved'] ?? 0);
        $out['saved'] += $saved;
        $out['skipped'] += (int)($result['skipped'] ?? 0);
        $out['files'][] = [
            'file' => $file,
            'format' => (string)($result['format'] ?? ''),
            'rows' => (int)($result['rows'] ?? 0),
            'saved' => $saved,
            'error' => (string)($result['error'] ?? ''),
        ];
    }
    $out['ok'] = $out['saved'] > 0;
    if (!$out['ok'] && $out['error'] === '') {
        $out['error'] = 'nothing_imported';
    }
    return $out;
}

/**
 * Insert or update one row. Everything but the AIC and the name is optional (the AIFA files
 * differ between releases); an empty field never overwrites a value a previous import had.
 */
function medicineUpsert(PDO $db, array $row): bool
{
    $aic = medicineNormalizeAic((string)($row['aic'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    if ($aic === '' || $name === '') {
        return false;
    }
    $stmt = $db->prepare('SELECT * FROM medicines WHERE aic = ?');
    $stmt->execute([$aic]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pick = static function (string $key) use ($row, $existing): string {
        $value = trim((string)($row[$key] ?? ''));
        return $value !== '' ? $value : (string)($existing[$key] ?? '');
    };
    $finalName = $pick('name');
    $db->prepare("INSERT INTO medicines (aic, name, name_norm, ingredient, form, dosage, pack, company, atc, fascia, imported_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP)
                  ON CONFLICT(aic) DO UPDATE SET
                    name = excluded.name, name_norm = excluded.name_norm,
                    ingredient = excluded.ingredient, form = excluded.form,
                    dosage = excluded.dosage, pack = excluded.pack,
                    company = excluded.company, atc = excluded.atc, fascia = excluded.fascia,
                    imported_at = CURRENT_TIMESTAMP")
       ->execute([
            $aic,
            $finalName,
            medicineNormalizeName($finalName),
            $pick('ingredient'),
            $pick('form'),
            $pick('dosage'),
            $pick('pack'),
            $pick('company'),
            $pick('atc'),
            $pick('fascia'),
        ]);
    return true;
}

/**
 * How far two national codes are (edit distance). Used to order the neighbours of an OCR read:
 * `040313072` is distance 1 from `040313076`, distance 3 from `04031309x`. Codes are ASCII
 * digits/letters by definition (see `medicineNormalizeKey`), so the byte-wise distance is exact.
 */
function medicineLevenshtein(string $a, string $b): int
{
    return levenshtein($a, $b);
}

/**
 * The REAL codes closest to a read: rows whose number starts like it.
 *
 * This is what turns "the OCR read A040313072" into a question the household can answer instead
 * of a silent wrong product: an AIC is dense, so the packs that differ by a digit or two are real
 * rows of the same family (a different pack size, a different strength), with names on them. The
 * caller ranks these by how far they are from the read and shows the closest four.
 *
 * @param string $prefix leading digits of the read (the caller decides how many)
 */
function medicineFindNeighbours(PDO $db, string $prefix, int $limit = 12): array
{
    $prefix = (string)preg_replace('/[^0-9A-Z]/', '', strtoupper($prefix));
    if (strlen($prefix) < 5) {
        return [];
    }
    try {
        $stmt = $db->prepare('SELECT * FROM medicines WHERE aic LIKE ? ORDER BY aic LIMIT ?');
        $stmt->bindValue(1, $prefix . '%', PDO::PARAM_STR);
        $stmt->bindValue(2, max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Catalogue rows one digit away from a read (primary-key lookups only — never a full scan).
 *
 * Prefix matching catches the usual OCR miss on the pack suffix (`029788027` → family `029788*`).
 * A single wrong digit in the middle (`040313076` → `040393076`) shares no long prefix, so this
 * second pass asks the 90 possible one-edit codes. Cheap: each candidate is `WHERE aic = ?`.
 *
 * @return list<array<string,mixed>>
 */
function medicineFindOneEdit(PDO $db, string $aic, int $limit = 8): array
{
    $code = medicineNormalizeAic($aic);
    if (strlen($code) !== 9 || !ctype_digit($code)) {
        return [];
    }
    $alts = [];
    $seen = [$code => true];
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        for ($d = 0; $d <= 9; $d++) {
            $ch = (string)$d;
            if ($code[$i] === $ch) {
                continue;
            }
            $alt = $code;
            $alt[$i] = $ch;
            if (isset($seen[$alt])) {
                continue;
            }
            $seen[$alt] = true;
            $alts[] = $alt;
        }
    }
    if ($alts === []) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    try {
        // One IN query instead of up to 90 primary-key round-trips.
        $placeholders = implode(',', array_fill(0, count($alts), '?'));
        $stmt = $db->prepare("SELECT * FROM medicines WHERE aic IN ($placeholders)");
        $stmt->execute($alts);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    usort($rows, static function ($a, $b) use ($code) {
        return medicineLevenshtein($code, (string)($a['aic'] ?? ''))
            <=> medicineLevenshtein($code, (string)($b['aic'] ?? ''));
    });
    return array_slice($rows, 0, $limit);
}

/**
 * Italian AICs in the AIFA confezioni export are 9 digits; today's formulary is entirely in the
 * `0………` range. A read that opens with 1–9 and matches nothing is almost never a missing pack —
 * it is a lot/serial/OCR invention. The UI can say that instead of "unknown medicine".
 */
function medicineAicLooksImplausible(string $aic): bool
{
    $code = medicineNormalizeAic($aic);
    return strlen($code) === 9 && ctype_digit($code) && $code[0] !== '0';
}

/** One medicine by AIC (with or without the printed `A`), or null. */
function medicineFindByAic(PDO $db, string $aic): ?array
{
    $key = medicineNormalizeAic($aic);
    if ($key === '') {
        return null;
    }
    try {
        $stmt = $db->prepare('SELECT * FROM medicines WHERE aic = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $row ?: null;
}

/**
 * Search by commercial name or active ingredient.
 *
 * The household types what is printed on the box — marketing words AIFA never stored
 * ("Connettivina Bio Plus"), truncated stems ("connettivin"), and typos ("conettivina",
 * "tachiprina"). Pipeline:
 *   1. AND of tokens, each expanded to stem/prefix variants; pack is searched too (strengths);
 *   2. drop trailing tokens until something hits (optional marketing suffixes);
 *   3. if still empty, fuzzy-match the brand word (edit distance) against catalogue first words.
 */
function medicineSearch(PDO $db, string $query, int $limit = 8, int $offset = 0): array
{
    $q = medicineNormalizeName($query);
    if (mb_strlen($q) < 3) {
        return [];
    }
    $limit = max(1, min(25, $limit));
    $offset = max(0, min(200, $offset));
    $tokens = array_slice(array_values(array_filter(explode(' ', $q), static function ($t) {
        return $t !== '';
    })), 0, 5);
    if ($tokens === []) {
        return [];
    }

    $use = $tokens;
    while (count($use) >= 1) {
        if (medicineSearchProbe($db, $use)) {
            return medicineSearchWithTokens($db, $use, $tokens, $q, $limit, $offset);
        }
        array_pop($use);
    }

    // LIKE found nothing (typo on the brand): closest catalogue names by edit distance.
    return medicineSearchFuzzy($db, $tokens, $q, $limit, $offset);
}

/**
 * Variants of one typed word for a forgiving LIKE (stem + short prefix). Digits stay exact.
 *
 * @return list<string>
 */
function medicineSearchTokenVariants(string $token): array
{
    $token = trim($token);
    if ($token === '' || mb_strlen($token) < 3) {
        return [];
    }
    if (ctype_digit($token)) {
        return [$token];
    }
    $out = [$token];
    $len = mb_strlen($token);
    // Trailing-letter slip / plural: connettivina → connettivin / connettivi
    if ($len >= 6) {
        $out[] = mb_substr($token, 0, $len - 1);
    }
    if ($len >= 7) {
        $out[] = mb_substr($token, 0, $len - 2);
    }
    // Shared stem prefix (not a tiny 3–4 letter hook: that pulls half the catalogue).
    if ($len >= 6) {
        $out[] = mb_substr($token, 0, min(6, $len));
    }
    $out = array_values(array_unique(array_filter($out, static function ($t) {
        return mb_strlen($t) >= 3;
    })));
    return $out;
}

/**
 * Cheap existence check for a token set (LIMIT 1).
 *
 * @param list<string> $tokens
 */
function medicineSearchProbe(PDO $db, array $tokens): bool
{
    return medicineSearchWithTokens($db, $tokens, $tokens, '', 1, 0) !== [];
}

/**
 * @param list<string> $whereTokens AND filter (relaxed)
 * @param list<string> $rankTokens original words — more hits sort first
 * @return list<array<string,mixed>>
 */
function medicineSearchWithTokens(
    PDO $db,
    array $whereTokens,
    array $rankTokens,
    string $qPrefix,
    int $limit,
    int $offset
): array {
    if ($whereTokens === []) {
        return [];
    }
    $where = [];
    $params = [];
    // Pack carries the strength ("1000 MG COMPRESSE") that the commercial name omits.
    foreach ($whereTokens as $token) {
        $variants = medicineSearchTokenVariants($token);
        if ($variants === []) {
            continue;
        }
        $parts = [];
        foreach ($variants as $v) {
            $parts[] = '(name_norm LIKE ? OR IFNULL(ingredient, \'\') LIKE ? OR IFNULL(pack, \'\') LIKE ?)';
            $params[] = '%' . $v . '%';
            $params[] = '%' . $v . '%';
            $params[] = '%' . $v . '%';
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    if ($where === []) {
        return [];
    }
    $scoreParts = [];
    foreach ($rankTokens as $token) {
        // Name beats ingredient beats pack — "destrometorfano gocce" must surface
        // DESTROMETORFANO… before DESTROTUS (same molecule in the ingredient field).
        $scoreParts[] = '(CASE'
            . ' WHEN name_norm LIKE ? THEN 8'
            . ' WHEN IFNULL(ingredient, \'\') LIKE ? THEN 3'
            . ' WHEN IFNULL(pack, \'\') LIKE ? THEN 1'
            . ' ELSE 0 END)';
        $params[] = '%' . $token . '%';
        $params[] = '%' . $token . '%';
        $params[] = '%' . $token . '%';
        foreach (medicineSearchTokenVariants($token) as $v) {
            if ($v === $token) {
                continue;
            }
            $scoreParts[] = '(CASE'
                . ' WHEN name_norm LIKE ? THEN 4'
                . ' WHEN IFNULL(ingredient, \'\') LIKE ? THEN 2'
                . ' WHEN IFNULL(pack, \'\') LIKE ? THEN 1'
                . ' ELSE 0 END)';
            $params[] = '%' . $v . '%';
            $params[] = '%' . $v . '%';
            $params[] = '%' . $v . '%';
        }
    }
    $scoreSql = $scoreParts !== [] ? implode(' + ', $scoreParts) : '0';
    $params[] = ($qPrefix !== '' ? $qPrefix : $whereTokens[0]) . '%';
    $sql = 'SELECT * FROM medicines WHERE ' . implode(' AND ', $where)
         . ' ORDER BY (' . $scoreSql . ') DESC, (name_norm LIKE ?) DESC, LENGTH(name) ASC'
         . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Brand typo fallback: edit-distance against catalogue first words that share a short prefix.
 *
 * @param list<string> $tokens
 * @return list<array<string,mixed>>
 */
function medicineSearchFuzzy(PDO $db, array $tokens, string $qPrefix, int $limit, int $offset): array
{
    $brand = '';
    foreach ($tokens as $t) {
        if (!ctype_digit($t) && mb_strlen($t) > mb_strlen($brand)) {
            $brand = $t;
        }
    }
    if ($brand === '' || mb_strlen($brand) < 4) {
        return [];
    }
    $maxDist = mb_strlen($brand) <= 5 ? 1 : (mb_strlen($brand) <= 9 ? 2 : 3);
    // 3-letter prefix keeps the candidate set tiny; if empty, retry with 2.
    $candidates = [];
    foreach ([3, 2] as $prefixLen) {
        if (mb_strlen($brand) < $prefixLen) {
            continue;
        }
        $prefix = mb_substr($brand, 0, $prefixLen);
        try {
            $stmt = $db->prepare(
                'SELECT DISTINCT name_norm FROM medicines WHERE name_norm LIKE ? LIMIT 500'
            );
            $stmt->execute([$prefix . '%']);
            $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $candidates = [];
        }
        if ($candidates !== []) {
            break;
        }
    }
    if ($candidates === []) {
        return [];
    }

    /** @var array<string,int> $bestWord word => distance */
    $bestWord = [];
    foreach ($candidates as $nameNorm) {
        foreach (explode(' ', (string)$nameNorm) as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }
            // levenshtein() is byte-wise; name_norm is ASCII after medicineNormalizeName().
            if (strlen($brand) > 255 || strlen($word) > 255) {
                continue;
            }
            $d = levenshtein($brand, $word);
            if ($d > $maxDist) {
                continue;
            }
            if (!isset($bestWord[$word]) || $d < $bestWord[$word]) {
                $bestWord[$word] = $d;
            }
        }
    }
    if ($bestWord === []) {
        return [];
    }
    asort($bestWord);
    $words = array_slice(array_keys($bestWord), 0, 3);
    $where = [];
    $params = [];
    foreach ($words as $w) {
        $where[] = 'name_norm = ? OR name_norm LIKE ?';
        $params[] = $w;
        $params[] = $w . ' %';
    }
    // Prefer the closest brand, then rows that still match leftover tokens (plus, 1000…).
    $scoreParts = [];
    foreach ($words as $i => $w) {
        $weight = 10 - $i;
        $scoreParts[] = '(CASE WHEN name_norm = ? OR name_norm LIKE ? THEN ' . (int)$weight . ' ELSE 0 END)';
        $params[] = $w;
        $params[] = $w . ' %';
    }
    foreach ($tokens as $token) {
        if ($token === $brand) {
            continue;
        }
        $scoreParts[] = '(CASE WHEN name_norm LIKE ? OR IFNULL(pack, \'\') LIKE ? THEN 2 ELSE 0 END)';
        $params[] = '%' . $token . '%';
        $params[] = '%' . $token . '%';
    }
    $scoreSql = implode(' + ', $scoreParts);
    $sql = 'SELECT * FROM medicines WHERE (' . implode(' OR ', $where) . ')'
         . ' ORDER BY (' . $scoreSql . ') DESC, LENGTH(name) ASC'
         . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * What the import left behind: how many rows and when. The UI must be able to say "not loaded
 * yet" instead of letting an unknown box look like a catalogue that disagrees.
 */
function medicinesDatasetInfo(PDO $db): array
{
    $info = ['loaded' => false, 'count' => 0, 'updated_at' => ''];
    try {
        $info['count'] = (int)$db->query('SELECT COUNT(*) FROM medicines')->fetchColumn();
        $info['updated_at'] = (string)($db->query('SELECT MAX(imported_at) FROM medicines')->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return $info;
    }
    $info['loaded'] = $info['count'] > 0;
    return $info;
}

/** The public shape of a medicine row (no internal columns, no advice). */
function medicinePublicRow(array $row): array
{
    return [
        'aic' => (string)($row['aic'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'ingredient' => (string)($row['ingredient'] ?? ''),
        'form' => (string)($row['form'] ?? ''),
        'dosage' => (string)($row['dosage'] ?? ''),
        'pack' => (string)($row['pack'] ?? ''),
        'company' => (string)($row['company'] ?? ''),
        'atc' => (string)($row['atc'] ?? ''),
        'fascia' => (string)($row['fascia'] ?? ''),
    ];
}

/**
 * The catalogue field a header cell maps to, or '' when it is none of them.
 *
 * `$aliases` is the COUNTRY map (Italy: `medicalItalyColumnAliases()` in api/lib/med/it.php):
 * the store knows how to import a table, not what a national export calls its columns.
 *
 * @param array<string,array<int,string>> $aliases field => accepted header names
 */
function medicineFieldForHeader(string $label, array $aliases, array $taken = []): string
{
    $key = medicineNormalizeName($label);
    $key = trim((string)preg_replace('/\s+/', ' ', $key));
    if ($key === '') {
        return '';
    }
    // A field already filled by an earlier column stays filled (the AIFA export has both
    // `DENOMINAZIONE` and `DESCRIZIONE`: the first is the name, the second the pack).
    $free = static fn(string $field): bool => !in_array($field, $taken, true);
    foreach ($aliases as $field => $names) {
        if ($free((string)$field) && in_array($key, $names, true)) {
            return (string)$field;
        }
    }
    // "CODICE_AIC_9" / "AIC 9 CIFRE": a PREFIX is enough, because the suffix only adds the
    // width ("codice aic 9"). The reverse (a key ENDING with an alias) is deliberately not
    // accepted: it mapped openFDA's `labeler_name` to `name`, so the company became the drug.
    foreach ($aliases as $field => $names) {
        if (!$free((string)$field)) {
            continue;
        }
        foreach ($names as $alias) {
            if (str_starts_with($key, $alias . ' ') || $key === $alias) {
                return (string)$field;
            }
        }
    }
    return '';
}

/**
 * Map a header row (list of labels) to column index → field.
 *
 * @param array<int,mixed> $header
 * @param array<string,array<int,string>> $aliases
 */
function medicinesHeaderMap(array $header, array $aliases): array
{
    $map = [];
    foreach ($header as $index => $label) {
        $field = medicineFieldForHeader((string)$label, $aliases, array_keys($map));
        if ($field !== '' && !isset($map[$field])) {
            $map[$field] = (int)$index;
        }
    }
    return $map;
}

/** Pick the delimiter of the first line (`;` is what the Italian exports use). */
function medicinesDetectDelimiter(string $line): string
{
    $best = ',';
    $bestCount = 0;
    foreach ([';', ',', "\t", '|'] as $candidate) {
        $count = substr_count($line, $candidate);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $candidate;
        }
    }
    return $best;
}

/**
 * Import a national medicine export into the local catalogue.
 *
 * Country-neutral on purpose: the column names arrive from the country module (Italy:
 * `medicalItalyColumnAliases()`), so the same importer serves any national dataset that is a
 * table of `aic`/`name`/`ingredient`/… columns.
 *
 * Streaming on purpose (a national list is tens of MB): CSV is read line by line with `fgetcsv`,
 * XML with `XMLReader` when the extension is there, JSON (the openFDA NDC shape) in one piece, and the whole import runs inside one
 * transaction — an interrupted import leaves the previous catalogue intact instead of half of a
 * new one. `dry_run` counts without writing, so a contributor can check the header map before
 * touching a household's database.
 *
 * @param array{dry_run?:bool,truncate?:bool,limit?:int} $options
 * @param array<string,array<int,string>> $aliases field => accepted header names (country map)
 * @return array{ok:bool,format:string,saved:int,skipped:int,rows:int,columns:array<string,string>,error?:string}
 */
function medicinesImportFile(PDO $db, string $path, array $options = [], array $aliases = []): array
{
    $out = ['ok' => false, 'format' => '', 'saved' => 0, 'skipped' => 0, 'rows' => 0, 'columns' => []];
    // No country map, no import: guessing the columns is how a whole catalogue lands in the
    // wrong field.
    if ($aliases === [] || !isset($aliases['aic'], $aliases['name'])) {
        $out['error'] = 'missing_columns';
        return $out;
    }
    if (!is_readable($path)) {
        $out['error'] = 'unreadable_file';
        return $out;
    }
    $dryRun = !empty($options['dry_run']);
    $limit = max(0, (int)($options['limit'] ?? 0));
    $head = (string)file_get_contents($path, false, null, 0, 512);
    $trimmed = ltrim($head);
    $looksJson = str_ends_with(strtolower($path), '.json')
        || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    $looksXml = !$looksJson && (str_ends_with(strtolower($path), '.xml')
        || str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<'));
    $out['format'] = $looksJson ? 'json' : ($looksXml ? 'xml' : 'csv');
    $save = static function (array $row) use ($db, $dryRun, &$out): void {
        $out['rows']++;
        if (medicineNormalizeAic((string)($row['aic'] ?? '')) === '' || trim((string)($row['name'] ?? '')) === '') {
            $out['skipped']++;
            return;
        }
        if ($dryRun) {
            $out['saved']++;
            return;
        }
        if (medicineUpsert($db, $row)) {
            $out['saved']++;
        } else {
            $out['skipped']++;
        }
    };

    try {
        if (!$dryRun) {
            $db->beginTransaction();
            if (!empty($options['truncate'])) {
                $db->exec('DELETE FROM medicines');
            }
        }
        if ($out['format'] === 'csv') {
            $fh = fopen($path, 'rb');
            if (!$fh) {
                $out['error'] = 'unreadable_file';
                return $out;
            }
            $first = (string)fgets($fh);
            rewind($fh);
            $delim = medicinesDetectDelimiter($first);
            $header = fgetcsv($fh, 0, $delim);
            $header = is_array($header) ? $header : [];
            $map = medicinesHeaderMap($header, $aliases);
            foreach ($map as $field => $index) {
                $out['columns'][$field] = (string)($header[(int)$index] ?? '');
            }
            if (!isset($map['aic'], $map['name'])) {
                if (!$dryRun) { $db->rollBack(); }
                fclose($fh);
                $out['error'] = 'missing_columns';
                return $out;
            }
            $read = 0;
            while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
                if (!is_array($cells) || $cells === [null]) {
                    continue;
                }
                $read++;
                if ($limit > 0 && $read > $limit) {
                    break;
                }
                $row = [];
                foreach ($map as $field => $index) {
                    $row[$field] = trim((string)($cells[(int)$index] ?? ''));
                }
                $save($row);
            }
            fclose($fh);
        } elseif ($out['format'] === 'json') {
            $out['columns'] = _medicinesImportJson($path, $limit, $save, $aliases);
            if ($out['columns'] === []) {
                if (!$dryRun) { $db->rollBack(); }
                $out['error'] = 'missing_columns';
                return $out;
            }
        } else {
            $out['columns'] = _medicinesImportXml($path, $limit, $save, $aliases);
            if ($out['columns'] === []) {
                if (!$dryRun) { $db->rollBack(); }
                $out['error'] = 'missing_columns';
                return $out;
            }
        }
        if (!$dryRun) {
            $db->commit();
        }
        $out['ok'] = $out['saved'] > 0;
    } catch (Throwable $e) {
        if (!$dryRun && $db->inTransaction()) {
            $db->rollBack();
        }
        $out['error'] = 'import_failed';
        // The message is what the operator needs: a wrong delimiter or a locked database is a
        // five-second fix, and this script only ever runs from a shell.
        $out['detail'] = $e->getMessage();
        EverLog::error('Medicine catalogue import failed', [
            'event' => 'medicines_import_failed',
            'path' => $path,
            'message' => $e->getMessage(),
        ]);
    }
    return $out;
}


/**
 * The JSON half — the openFDA NDC Directory shape (`{ "results": [ {…}, … ] }`), and any bare
 * array of objects with the same field names.
 *
 * A national catalogue that is published as JSON needs no CSV conversion: the same column map
 * (`$aliases`) is applied to the object keys, and a nested array (openFDA's
 * `active_ingredients`) is flattened to its scalar parts so the active ingredient column is
 * still filled.
 *
 * @param array<string,array<int,string>> $aliases
 * @return array<string,string> field → the JSON key it came from (empty = refused)
 */
function _medicinesImportJson(string $path, int $limit, callable $save, array $aliases): array
{
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }
    $rows = isset($decoded['results']) && is_array($decoded['results']) ? $decoded['results'] : $decoded;
    if (!is_array($rows)) {
        return [];
    }
    $columns = [];
    $read = 0;
    foreach ($rows as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = [];
        foreach ($item as $key => $value) {
            $field = medicineFieldForHeader((string)$key, $aliases);
            if ($field === '') {
                continue;
            }
            if (is_array($value)) {
                // openFDA nests objects (active_ingredients); flatten the scalar parts and never
                // overwrite a column that a flat key already filled with a better value.
                $value = implode(' ', array_filter(array_map(
                    static fn($v) => is_scalar($v) ? trim((string)$v) : '',
                    $value
                )));
                if (trim($value) === '') {
                    continue;
                }
            }
            if (!is_scalar($value)) {
                continue;
            }
            $columns[$field] = (string)$key;
            $row[$field] = trim((string)$value);
        }
        $read++;
        if ($limit > 0 && $read > $limit) {
            break;
        }
        if ($row) {
            $save($row);
        }
    }
    return $columns;
}

/**
 * The XML half: `<record>`, `<row>`, `<farmaco>` or `<medicinale>` elements whose CHILD TAGS
 * name the fields. Kept separate so the CSV path stays readable; returns the mapped columns
 * (empty = refused, i.e. no AIC/name tag).
 *
 * @param array<string,array<int,string>> $aliases
 * @return array<string,string> field → the tag name it came from
 */
function _medicinesImportXml(string $path, int $limit, callable $save, array $aliases): array
{
    $columns = [];
    $read = 0;
    if (class_exists('XMLReader')) {
        $reader = new XMLReader();
        if (!$reader->open($path)) {
            return [];
        }
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT
                || !in_array($reader->localName, ['record', 'row', 'farmaco', 'medicinale'], true)) {
                continue;
            }
            $node = $reader->readOuterXML();
            $xml = @simplexml_load_string($node);
            if ($xml === false) {
                continue;
            }
            $row = [];
            foreach ($xml->children() as $child) {
                $field = medicineFieldForHeader((string)$child->getName(), $aliases);
                if ($field !== '') {
                    $columns[$field] = (string)$child->getName();
                    $row[$field] = trim((string)$child);
                }
            }
            $read++;
            if ($limit > 0 && $read > $limit) {
                break;
            }
            if ($row) {
                $save($row);
            }
        }
        $reader->close();
        return $columns;
    }
    // No XMLReader: the file is small enough to load (a fixture, or a stripped export).
    $raw = (string)file_get_contents($path);
    if (preg_match_all('/<(record|row|farmaco|medicinale)\b[^>]*>(.*?)<\/\1>/si', $raw, $matches)) {
        foreach ($matches[2] as $body) {
            $row = [];
            if (preg_match_all('/<([a-z0-9_:-]+)\b[^>]*>(.*?)<\/\1>/si', $body, $kids, PREG_SET_ORDER)) {
                foreach ($kids as $kid) {
                    $field = medicineFieldForHeader((string)$kid[1], $aliases);
                    if ($field !== '') {
                        $columns[$field] = (string)$kid[1];
                        $row[$field] = trim((string)$kid[2]);
                    }
                }
            }
            $read++;
            if ($limit > 0 && $read > $limit) {
                break;
            }
            if ($row) {
                $save($row);
            }
        }
    }
    return $columns;
}

/**
 * The OPTIONAL online lookup, for a pack the local catalogue does not have.
 *
 * OFF by default, and deliberately so: EverShelf already imports the WHOLE official formulary
 * (`med_catalog_sync` → the AIFA "confezioni" export, ~160k packs), so an online provider would
 * answer with the same file — while sending the AIC of what a household just scanned to a third
 * party, which is a health signal, and while introducing a key, a quota and a network failure in
 * the one flow that must always work (the box in your hand, in a pharmacy with no signal).
 *
 * When a household turns it on (`MED_LOOKUP_PROVIDER=medifontis`), it is consulted ONLY for a
 * code the local table knows nowhere — no exact row, no pack family — and the answer is written
 * INTO the local table (`medicineUpsert`), so the next scan of that box is instant and offline.
 *
 * Config (.env):
 *   MED_LOOKUP_PROVIDER=off|medifontis|custom   (default: off)
 *   MED_LOOKUP_URL=https://api.medifontis.com/v1/drugs
 *   MED_LOOKUP_KEY=mf_live_...                 (the free key the provider gives you)
 *
 * @returns array{enabled:bool, provider:string, url:string, key:string}
 */
function medicinesProviderConfig(): array
{
    $provider = strtolower(trim((string)env('MED_LOOKUP_PROVIDER', 'off')));
    if (!in_array($provider, ['medifontis', 'custom'], true)) {
        $provider = '';
    }
    $url = trim((string)env('MED_LOOKUP_URL', ''));
    if ($url === '' && $provider === 'medifontis') {
        $url = 'https://api.medifontis.com/v1/drugs';
    }
    $key = trim((string)env('MED_LOOKUP_KEY', ''));
    // A provider needs somewhere to ask; a key is required by every commercial one, not by a
    // self-hosted endpoint of your own (`custom`).
    $enabled = $provider !== '' && preg_match('#^https?://#i', $url) === 1
        && ($provider === 'custom' || $key !== '');
    return ['enabled' => $enabled, 'provider' => $provider, 'url' => $url, 'key' => $key];
}

/**
 * Parse one provider answer into a catalogue row (no I/O: this is the part worth testing).
 *
 * Two shapes are understood, because they are the two that exist: Medifontis' record
 * (`identifiers[]` + `data{}`, the AIFA fields renamed) and a plain `{results:[{aic,name,...}]}`
 * that an own endpoint returns. Anything else yields null — a provider that answers nonsense must
 * not become a medicine.
 *
 * @param array $payload the decoded JSON body
 * @param string $aic the code that was asked for
 */
function medicinesProviderParseRow(array $payload, string $aic): ?array
{
    $want = medicineNormalizeAic($aic);
    if ($want === '') {
        return null;
    }
    $rows = [];
    if (isset($payload['results']) && is_array($payload['results'])) {
        $rows = $payload['results'];
    } elseif (isset($payload['result']) && is_array($payload['result'])) {
        $rows = [$payload['result']];
    } elseif (isset($payload['data']) && is_array($payload['data'])) {
        $rows = [$payload];
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $data = isset($row['data']) && is_array($row['data']) ? $row['data'] : $row;
        $code = medicineNormalizeAic((string)($data['aic'] ?? $row['aic'] ?? ''));
        if ($code === '' && isset($row['identifiers']) && is_array($row['identifiers'])) {
            foreach ($row['identifiers'] as $id) {
                if (is_array($id) && strtolower((string)($id['scheme'] ?? '')) === 'aic') {
                    $code = medicineNormalizeAic((string)($id['value'] ?? ''));
                    break;
                }
            }
        }
        if ($code === '' || $code !== $want) {
            continue; // the answer must be the code we asked for, never a neighbour
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        return [
            'aic' => $code,
            'name' => $name,
            'ingredient' => (string)($data['ingredient'] ?? $data['activeIngredient'] ?? ''),
            'form' => (string)($data['form'] ?? ''),
            'dosage' => (string)($data['dosage'] ?? ''),
            'pack' => (string)($data['pack'] ?? $data['description'] ?? ''),
            'company' => (string)($data['holder'] ?? $data['company'] ?? ''),
            'atc' => (string)($data['atc'] ?? ''),
            'fascia' => (string)($data['fascia'] ?? ''),
        ];
    }
    return null;
}

/**
 * Ask the optional provider for ONE code and keep the answer in the local catalogue.
 *
 * @returns array|null the row now stored locally (or null: no provider, unknown code, failure)
 */
function medicinesProviderLookup(PDO $db, string $country, string $aic): ?array
{
    $cfg = medicinesProviderConfig();
    $code = medicineNormalizeAic($aic);
    if (!$cfg['enabled'] || $code === '') {
        return null;
    }
    $url = $cfg['url'] . (str_contains($cfg['url'], '?') ? '&' : '?')
        . http_build_query(['country' => $country !== '' ? $country : 'it', 'q' => $code]);
    $body = medicinesFetchUrlWithHeaders($url, $cfg['key'] !== '' ? ['Authorization: Bearer ' . $cfg['key']] : [], 1048576, 8);
    if ($body === null) {
        EverLog::warn('Medicine provider lookup failed', ['event' => 'med_lookup_provider_failed', 'provider' => $cfg['provider'], 'aic' => $code]);
        return null;
    }
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return null;
    }
    $row = medicinesProviderParseRow($payload, $code);
    if ($row === null) {
        EverLog::info('Medicine provider does not know this code', ['event' => 'med_lookup_provider_miss', 'provider' => $cfg['provider'], 'aic' => $code]);
        return null;
    }
    // Cached: a provider is a way to FILL the local catalogue, never a dependency of the scan.
    medicineUpsert($db, $row);
    EverLog::info('Medicine provider filled a missing code', ['event' => 'med_lookup_provider_hit', 'provider' => $cfg['provider'], 'aic' => $code]);
    return medicineFindByAic($db, $code) ?: $row;
}
