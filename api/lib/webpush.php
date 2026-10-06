<?php
/**
 * Browser Web Push (optional, free, user-opt-in).
 *
 * Empty-payload push (VAPID JWT only — no ECE payload crypto) wakes the
 * service worker; the worker GETs webpush_inbox and shows the notification.
 * Requires HTTPS and WEB_PUSH_ENABLED=true after the user decides in Settings.
 */

declare(strict_types=1);

function evershelfWebPushEnabled(): bool
{
    return env('WEB_PUSH_ENABLED', '') === 'true';
}

function evershelfWebPushDecided(): bool
{
    return env('WEB_PUSH_ENABLED', '') !== '';
}

function evershelfWebPushBase64Url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function evershelfWebPushBase64UrlDecode(string $data): string
{
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) {
        $data .= str_repeat('=', $pad);
    }
    $out = base64_decode(strtr($data, '-_', '+/'), true);
    return $out === false ? '' : $out;
}

/** @return array{public:string,private_pem:string}|null */
function evershelfWebPushVapidMaterial(): ?array
{
    $pub = trim((string)env('VAPID_PUBLIC_KEY', ''));
    $pem = trim((string)env('VAPID_PRIVATE_PEM', ''));
    // Allow single-line PEM stored in .env (literal \n).
    $pem = str_replace(['\\n', "\r"], ["\n", ''], $pem);
    if ($pub === '' || $pem === '') {
        return null;
    }
    if (!str_contains($pem, 'BEGIN')) {
        return null;
    }
    return ['public' => $pub, 'private_pem' => $pem];
}

/**
 * Mint a VAPID key pair. Public = base64url(uncompressed P-256);
 * private = PEM (store as VAPID_PRIVATE_PEM with \n escapes in .env).
 *
 * @return array{public:string,private_pem:string}
 */
function evershelfWebPushGenerateVapidKeys(): array
{
    $key = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($key === false) {
        throw new RuntimeException('openssl_pkey_new failed for VAPID');
    }
    $details = openssl_pkey_get_details($key);
    if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y'])) {
        throw new RuntimeException('openssl_pkey_get_details missing EC coordinates');
    }
    $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $pub = evershelfWebPushBase64Url("\x04" . $x . $y);
    $pem = '';
    if (!openssl_pkey_export($key, $pem) || $pem === '') {
        throw new RuntimeException('openssl_pkey_export failed for VAPID');
    }
    return ['public' => $pub, 'private_pem' => $pem];
}

function evershelfWebPushEnsureTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS webpush_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        endpoint TEXT NOT NULL UNIQUE,
        p256dh TEXT NOT NULL DEFAULT '',
        auth TEXT NOT NULL DEFAULT '',
        user_agent TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

function evershelfWebPushInboxPath(): string
{
    return dirname(__DIR__, 2) . '/data/webpush_inbox.json';
}

/** @return list<array{title:string,body:string,ts:int,url?:string}> */
function evershelfWebPushInboxLoad(): array
{
    $path = evershelfWebPushInboxPath();
    if (!is_file($path)) {
        return [];
    }
    try {
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? array_values($data) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function evershelfWebPushInboxSave(array $items): void
{
    $path = evershelfWebPushInboxPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (count($items) > 20) {
        $items = array_slice($items, -20);
    }
    file_put_contents($path, json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function evershelfWebPushInboxPush(string $title, string $body, string $url = ''): void
{
    $items = evershelfWebPushInboxLoad();
    $items[] = [
        'title' => $title,
        'body'  => $body,
        'ts'    => time(),
        'url'   => $url !== '' ? $url : './',
    ];
    evershelfWebPushInboxSave($items);
}

function evershelfWebPushDerToJose(string $der): ?string
{
    $offset = 0;
    if (($der[$offset++] ?? '') !== "\x30") {
        return null;
    }
    $len = ord($der[$offset++]);
    if ($len & 0x80) {
        $offset += ($len & 0x7f);
    }
    if (($der[$offset++] ?? '') !== "\x02") {
        return null;
    }
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    if (($der[$offset++] ?? '') !== "\x02") {
        return null;
    }
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    if (strlen($r) !== 32 || strlen($s) !== 32) {
        return null;
    }
    return $r . $s;
}

function evershelfWebPushVapidAuthorization(string $endpoint, string $publicKey, string $privatePem): ?string
{
    $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    if ($audience === '://' || $audience === '') {
        return null;
    }
    $header = evershelfWebPushBase64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = evershelfWebPushBase64Url(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:evershelf@localhost',
    ]));
    $unsigned = $header . '.' . $claims;
    $pkey = openssl_pkey_get_private($privatePem);
    if ($pkey === false) {
        return null;
    }
    $sigDer = '';
    if (!openssl_sign($unsigned, $sigDer, $pkey, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $sig = evershelfWebPushDerToJose($sigDer);
    if ($sig === null) {
        return null;
    }
    return 'vapid t=' . $unsigned . '.' . evershelfWebPushBase64Url($sig) . ', k=' . $publicKey;
}

/** @return array{ok:int,fail:int,removed:int} */
function evershelfWebPushFanout(PDO $db): array
{
    if (!evershelfWebPushEnabled()) {
        return ['ok' => 0, 'fail' => 0, 'removed' => 0];
    }
    $mat = evershelfWebPushVapidMaterial();
    if ($mat === null) {
        return ['ok' => 0, 'fail' => 0, 'removed' => 0];
    }
    evershelfWebPushEnsureTable($db);
    $rows = $db->query('SELECT id, endpoint FROM webpush_subscriptions')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ok = 0;
    $fail = 0;
    $removed = 0;
    foreach ($rows as $row) {
        $endpoint = (string)$row['endpoint'];
        $auth = evershelfWebPushVapidAuthorization($endpoint, $mat['public'], $mat['private_pem']);
        if ($auth === null) {
            $fail++;
            continue;
        }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'TTL: 86400',
                'Content-Length: 0',
                'Urgency: normal',
                'Authorization: ' . $auth,
            ],
            CURLOPT_POSTFIELDS     => '',
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (in_array($http, [200, 201, 204], true)) {
            $ok++;
        } elseif (in_array($http, [404, 410], true)) {
            $stmt = $db->prepare('DELETE FROM webpush_subscriptions WHERE id = ?');
            $stmt->execute([(int)$row['id']]);
            $removed++;
        } else {
            $fail++;
            EverLog::warn('Web Push delivery failed', ['event' => 'webpush_fail', 'http' => $http]);
        }
    }
    return ['ok' => $ok, 'fail' => $fail, 'removed' => $removed];
}

function evershelfWebPushNotify(PDO $db, string $title, string $message, string $url = ''): array
{
    if (!evershelfWebPushEnabled()) {
        return ['skipped' => true];
    }
    evershelfWebPushInboxPush($title, $message, $url);
    return ['skipped' => false] + evershelfWebPushFanout($db);
}
