<?php
/**
 * EverShelf — ICS / WebCal feed of upcoming expiries.
 *
 * A read-only calendar subscription (Google Calendar, Apple Calendar, Thunderbird,
 * Nextcloud, …) with one all-day event per inventory item that has an expiry date,
 * so the pantry deadlines show up where the family already looks: the calendar.
 *
 * Why its own secret instead of the API token: a calendar client cannot send
 * X-API-Token or a Bearer header — it just GETs a URL, forever, with no browser
 * session. `calendar_ics` is therefore in evershelfPublicActions() and validates a
 * dedicated read-only `ICS_TOKEN` (?token=…, hash_equals) inside the handler. The
 * token is minted/rotated from Settings → 🗓️ Calendar, is redacted from logs, and
 * can be revoked by rotating it (no restart: the feed re-reads .env each request).
 *
 * Actions: calendar_ics (GET, text/calendar) · get_ics_settings · rotate_ics_token
 */

/** Master switch: ICS_ENABLED=true in .env / Settings → Calendar. */
function evershelfIcsEnabled(): bool {
    return env('ICS_ENABLED', 'false') === 'true';
}

/** Days ahead to publish (1–365, default 30). */
function evershelfIcsDays(): int {
    return max(1, min(365, (int)env('ICS_DAYS', '30')));
}

/** How many days of *already* expired items stay visible (they are actionable). */
function evershelfIcsPastDays(): int {
    return max(0, min(60, (int)env('ICS_PAST_DAYS', '7')));
}

function evershelfIcsToken(): string {
    return trim(env('ICS_TOKEN', ''));
}

/**
 * Mint (or rotate) the feed secret and persist it.
 * Writes .env when writable, otherwise the app_settings override table — the same
 * policy saveSettings() uses, so a read-only .env still works.
 */
function evershelfIcsRotateToken(): string {
    $token   = bin2hex(random_bytes(16)); // 32 hex chars, CSPRNG
    $newEnv  = ['ICS_TOKEN' => $token];
    $envFile = dirname(__DIR__, 2) . '/.env';
    $example = dirname(__DIR__, 2) . '/.env.example';

    $written = false;
    if (is_writable($envFile) || (!file_exists($envFile) && is_writable(dirname($envFile)))) {
        $written = evershelfWriteEnvFile($envFile, $newEnv, $example);
    }
    if ($written) {
        clearEnvOverrides(['ICS_TOKEN']); // a DB override would shadow the new value
    } else {
        saveEnvOverrides($newEnv);
    }
    return $token;
}

/** Absolute URL of the feed, as the calendar client must call it. */
function evershelfIcsFeedUrl(string $token = ''): string {
    $token = $token !== '' ? $token : evershelfIcsToken();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
    $basePath = preg_replace('#/api/(index\.php)?$#', '', $script) ?: '';
    $url = $scheme . '://' . $host . $basePath . '/api/index.php?action=calendar_ics';
    return $token !== '' ? $url . '&token=' . rawurlencode($token) : $url;
}

// ── RFC 5545 primitives ───────────────────────────────────────────────────────

/** Escape a TEXT value (RFC 5545 §3.3.11): backslash, semicolon, comma, newline. */
function evershelfIcsEscape(string $text): string {
    return str_replace(
        ["\\", ";", ",", "\r\n", "\n", "\r"],
        ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
        $text
    );
}

/**
 * Fold a content line to ≤73 octets (RFC 5545 §3.1): continuation lines start with
 * a single space. Never cuts inside a multi-byte UTF-8 character, so accented and
 * CJK product names survive.
 */
function evershelfIcsFold(string $line): string {
    if (strlen($line) <= 73) {
        return $line;
    }
    $out = [];
    $offset = 0;
    $take = 72;
    $len = strlen($line);
    while ($offset < $len) {
        $take = min($take, $len - $offset);
        while ($take > 1 && ($offset + $take) < $len && (ord($line[$offset + $take]) & 0xC0) === 0x80) {
            $take--; // would split a UTF-8 sequence — pull back to its first byte
        }
        $out[] = substr($line, $offset, $take);
        $offset += $take;
        $take = 71; // continuation lines: leading space + ≤72 payload octets
    }
    return implode("\r\n ", $out);
}

/** Content line: NAME[;PARAM=VALUE…]:value */
function evershelfIcsLine(string $name, string $value, array $params = []): string {
    $line = $name;
    foreach ($params as $p => $v) {
        $line .= ';' . $p . '=' . $v;
    }
    return evershelfIcsFold($line . ':' . $value);
}

/** 'YYYY-MM-DD' → 'YYYYMMDD', or null when the value is not a real date. */
function evershelfIcsYmd(?string $date): ?string {
    $date = trim((string)$date);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
        return null;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $m[1] . $m[2] . $m[3] : null;
}

/** All-day DTEND is exclusive: expiry + 1 day (RFC 5545 §3.8.2.2). */
function evershelfIcsNextDay(string $ymd): string {
    $d = DateTimeImmutable::createFromFormat('!Ymd', $ymd, new DateTimeZone('UTC'));
    return $d === false ? $ymd : $d->add(new DateInterval('P1D'))->format('Ymd');
}

function evershelfIcsStamp(): string {
    return gmdate('Ymd\THis\Z');
}

// ── Feed data ─────────────────────────────────────────────────────────────────

/**
 * Inventory rows that belong in the feed: still in stock, with an expiry date
 * between today − ICS_PAST_DAYS (still actionable) and today + ICS_DAYS.
 *
 * @return array<int,array<string,mixed>>
 */
function evershelfIcsRows(PDO $db): array {
    $stmt = $db->prepare("
        SELECT i.id, i.quantity, i.location, i.expiry_date,
               p.name, COALESCE(p.brand, '') AS brand, COALESCE(p.unit, '') AS unit
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.quantity > 0
          AND i.expiry_date IS NOT NULL AND i.expiry_date <> ''
          AND date(i.expiry_date) BETWEEN date('now', ?) AND date('now', ?)
        ORDER BY date(i.expiry_date) ASC, p.name ASC
    ");
    $stmt->execute(['-' . evershelfIcsPastDays() . ' days', '+' . evershelfIcsDays() . ' days']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** '2' → '2', '1.50' → '1.5' — quantity as a human would type it. */
function evershelfIcsFormatQty(float $qty): string {
    $s = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

/**
 * Build the whole VCALENDAR.
 *
 * @return array{ics:string,count:int}
 */
function evershelfIcsBuild(PDO $db, string $lang): array {
    $rows = evershelfIcsRows($db);
    // UID domain: the host only, no port (a UID domain-part must not contain ':'),
    // and never empty — a UID with a blank domain makes clients reject the item.
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]) ?: 'evershelf.local';
    $today = gmdate('Ymd');
    $stamp = evershelfIcsStamp();

    $lines = [
        evershelfIcsLine('BEGIN', 'VCALENDAR'),
        evershelfIcsLine('VERSION', '2.0'),
        evershelfIcsLine('PRODID', '-//EverShelf//Pantry Expiry Feed//EN'),
        evershelfIcsLine('CALSCALE', 'GREGORIAN'),
        evershelfIcsLine('METHOD', 'PUBLISH'),
        evershelfIcsLine('X-WR-CALNAME', evershelfIcsEscape(evershelfTr('ics.calendar.name', $lang))),
        evershelfIcsLine('X-WR-CALDESC', evershelfIcsEscape(evershelfTr('ics.calendar.description', $lang))),
        evershelfIcsLine('X-WR-TIMEZONE', 'UTC'),
        evershelfIcsLine('REFRESH-INTERVAL', 'PT6H', ['VALUE' => 'DURATION']),
        evershelfIcsLine('X-PUBLISHED-TTL', 'PT6H'),
    ];

    $count = 0;
    foreach ($rows as $r) {
        $ymd = evershelfIcsYmd($r['expiry_date'] ?? null);
        $name = trim((string)($r['name'] ?? ''));
        if ($ymd === null || $name === '') {
            continue; // unparsable date or anonymous row — never emit a broken VEVENT
        }
        $brand = trim((string)($r['brand'] ?? ''));
        $unit = trim((string)($r['unit'] ?? ''));
        $loc = trim((string)($r['location'] ?? ''));
        $qty = evershelfIcsFormatQty((float)($r['quantity'] ?? 0)) . ($unit !== '' ? ' ' . $unit : '');
        $expired = $ymd < $today;

        $summary = ($expired ? '⚠️ ' . evershelfTr('ics.event.expired', $lang) . ' — ' : '') . $name;
        if ($brand !== '') {
            $summary .= ' (' . $brand . ')';
        }

        $desc = [];
        if ($loc !== '') {
            $locLabel = evershelfTr('locations.' . $loc, $lang);
            $desc[] = evershelfTr('ics.event.location', $lang) . ': ' . ($locLabel === 'locations.' . $loc ? $loc : $locLabel);
        }
        $desc[] = evershelfTr('ics.event.quantity', $lang) . ': ' . $qty;
        if ($brand !== '') {
            $desc[] = evershelfTr('ics.event.brand', $lang) . ': ' . $brand;
        }
        $desc[] = evershelfTr('ics.event.expiry', $lang) . ': ' . substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);

        $lines[] = evershelfIcsLine('BEGIN', 'VEVENT');
        $lines[] = evershelfIcsLine('UID', 'evershelf-inv-' . (int)$r['id'] . '@' . $host);
        $lines[] = evershelfIcsLine('DTSTAMP', $stamp);
        $lines[] = evershelfIcsLine('LAST-MODIFIED', $stamp);
        $lines[] = evershelfIcsLine('DTSTART', $ymd, ['VALUE' => 'DATE']);
        $lines[] = evershelfIcsLine('DTEND', evershelfIcsNextDay($ymd), ['VALUE' => 'DATE']);
        $lines[] = evershelfIcsLine('SUMMARY', evershelfIcsEscape($summary));
        $lines[] = evershelfIcsLine('DESCRIPTION', evershelfIcsEscape(implode("\n", $desc)));
        $lines[] = evershelfIcsLine('CATEGORIES', 'EverShelf');
        $lines[] = evershelfIcsLine('TRANSP', 'TRANSPARENT');
        $lines[] = evershelfIcsLine('BEGIN', 'VALARM');
        $lines[] = evershelfIcsLine('TRIGGER', '-P1D');
        $lines[] = evershelfIcsLine('ACTION', 'DISPLAY');
        $lines[] = evershelfIcsLine('DESCRIPTION', evershelfIcsEscape($summary));
        $lines[] = evershelfIcsLine('END', 'VALARM');
        $lines[] = evershelfIcsLine('END', 'VEVENT');
        $count++;
    }

    $lines[] = evershelfIcsLine('END', 'VCALENDAR');
    return ['ics' => implode("\r\n", $lines) . "\r\n", 'count' => $count];
}

// ── HTTP handlers (called from the router switch) ─────────────────────────────

/**
 * GET ?action=calendar_ics&token=… — the feed itself.
 *
 * Never JSON: this response goes to a calendar client, not to fetch(). Errors are
 * therefore plain text with the right status code, so a wrong/revoked token shows
 * as "Forbidden" instead of a parse error.
 */
function calendarIcsFeed(PDO $db): void {
    header('Content-Type: text/calendar; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow'); // pantry contents must never be indexed
    header('Cache-Control: private, max-age=900'); // clients re-poll every 6 h anyway

    if (!evershelfIcsEnabled()) {
        EverLog::warn('ICS feed requested while disabled', ['event' => 'ics_feed_disabled']);
        http_response_code(404);
        echo "Calendar feed is disabled (Settings > Calendar).\r\n";
        return;
    }
    $expected = evershelfIcsToken();
    $provided = (string)($_GET['token'] ?? '');
    // Read-only secret, compared in constant time; the token itself is never logged.
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        EverLog::warn('ICS feed rejected', ['event' => 'ics_feed_unauthorized']);
        http_response_code(403);
        echo "Forbidden\r\n";
        return;
    }

    try {
        $feed = evershelfIcsBuild($db, substr((string)($_GET['lang'] ?? 'en'), 0, 5));
    } catch (Throwable $e) {
        EverLog::exception($e, 'calendar_ics');
        http_response_code(500);
        echo "Could not build the feed.\r\n";
        return;
    }

    header('Content-Disposition: attachment; filename="evershelf-expiries.ics"');
    EverLog::info('ICS expiry feed served', ['event' => 'ics_feed_served', 'items' => $feed['count']]);
    echo $feed['ics'];
}

/** GET ?action=get_ics_settings — status + the subscribe URL shown in Settings. */
function getIcsSettings(PDO $db): void {
    EverLog::debug('getIcsSettings');
    $token = evershelfIcsToken();
    $count = 0;
    try {
        $count = count(evershelfIcsRows($db));
    } catch (Throwable $e) {
        EverLog::warn('ICS feed count failed', ['event' => 'ics_count_failed', 'error' => $e->getMessage()]);
    }
    echo json_encode([
        'success'       => true,
        'ics_enabled'   => evershelfIcsEnabled(),
        'ics_days'      => evershelfIcsDays(),
        'ics_past_days' => evershelfIcsPastDays(),
        'ics_token'     => $token,
        'ics_url'       => $token !== '' ? evershelfIcsFeedUrl($token) : '',
        'ics_count'     => $count,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * POST ?action=rotate_ics_token — mint a new secret (also the "enable" step:
 * an enabled feed without a token would be reachable by anyone who guesses one).
 */
function rotateIcsToken(): void {
    $token = evershelfIcsRotateToken();
    EverLog::info('ICS feed token rotated', ['event' => 'ics_token_rotated']);
    echo json_encode([
        'success'   => true,
        'ics_token' => $token,
        'ics_url'   => evershelfIcsFeedUrl($token),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

