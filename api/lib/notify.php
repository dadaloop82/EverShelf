<?php
/**
 * EverShelf — outbound notifications.
 *
 * One entry point, `evershelfNotifySend()`, fans one event out to every enabled
 * channel. Everything here is free: ntfy.sh (or your own ntfy server) needs no
 * account and no credit card; the generic webhook is just an HTTP POST.
 *
 * Channels (`NOTIFY_CHANNELS`, comma-separated, default `ntfy`):
 *   ntfy     ntfy.sh or a self-hosted ntfy server   NTFY_URL / NTFY_TOPIC / NTFY_TOKEN
 *   webhook  any endpoint that accepts a JSON POST  NOTIFY_WEBHOOK_URL / NOTIFY_WEBHOOK_TOKEN
 *
 * Gating:
 *   - `NOTIFY_ENABLED` (default false) is the master switch for those two channels.
 *   - `NOTIFY_EVENTS`  (default `expiry`, or `all`) filters them per event, so a
 *     stock update on every scan cannot flood a phone.
 *   - the Home Assistant notify service is **not** part of NOTIFY_CHANNELS: it keeps
 *     its historical gate (`HA_ENABLED` + `HA_NOTIFY_SERVICE`) so installs that
 *     already receive HA push notifications behave exactly as before.
 *
 * Privacy: the ntfy topic is a shared secret — anyone who knows it can read and
 * publish. It is never written to the log, and never included in an error string.
 */
declare(strict_types=1);

/** Channels selectable through NOTIFY_CHANNELS (HA keeps its own legacy gate). */
const EVERSHELF_NOTIFY_CHANNELS_KNOWN = ['ntfy', 'webhook'];

/** Events a channel can subscribe to. `all` in NOTIFY_EVENTS means every one.
 *  The names are the ones `_fireHaWebhook()` already uses, so notifications and
 *  Home Assistant automations never drift apart. Only wired events are listed. */
const EVERSHELF_NOTIFY_EVENTS_KNOWN = [
    'expiry_alert',          // daily cron: items expiring / already expired
    'shopping_add',          // item added to the shopping list
    'shopping_remove',       // item removed (incl. purchased)
    'shopping_trip_complete', // user cleared list after a trip
    'stock_update',          // inventory quantity changed
    'weekly_digest',         // cron summary
];

/**
 * ntfy rejects a message body above 4096 bytes; stay safely below so the whole
 * body is delivered instead of truncated server-side.
 */
const EVERSHELF_NOTIFY_BODY_LIMIT = 3600;

/** Master switch for the ntfy/webhook channels. */
function evershelfNotifyEnabled(): bool
{
    return env('NOTIFY_ENABLED', 'false') === 'true';
}

/**
 * Pure: parse a comma-separated channel list, keeping only known names.
 * Unknown or empty entries are dropped (and never silently become "all").
 *
 * @return string[]
 */
function evershelfNotifyParseChannels(string $raw): array
{
    $out = [];
    foreach (explode(',', strtolower($raw)) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && in_array($candidate, EVERSHELF_NOTIFY_CHANNELS_KNOWN, true)
            && !in_array($candidate, $out, true)) {
            $out[] = $candidate;
        }
    }
    return $out;
}

/**
 * Pure: parse a comma-separated event list. The literal `all` selects every
 * known event; unknown entries are dropped.
 *
 * @return string[]
 */
function evershelfNotifyParseEvents(string $raw): array
{
    $raw = strtolower(trim($raw));
    if ($raw === 'all') {
        return EVERSHELF_NOTIFY_EVENTS_KNOWN;
    }
    $out = [];
    foreach (explode(',', $raw) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && in_array($candidate, EVERSHELF_NOTIFY_EVENTS_KNOWN, true)
            && !in_array($candidate, $out, true)) {
            $out[] = $candidate;
        }
    }
    return $out;
}

/** @return string[] Channels enabled in .env. */
function evershelfNotifyChannels(): array
{
    return evershelfNotifyParseChannels(env('NOTIFY_CHANNELS', 'ntfy'));
}

/** @return string[] Events the ntfy/webhook channels subscribe to. */
function evershelfNotifyEvents(): array
{
    return evershelfNotifyParseEvents(env('NOTIFY_EVENTS', 'expiry_alert'));
}

/** Language for server-generated texts (the UI language lives in the browser). */
function evershelfNotifyLanguage(): string
{
    $lang = strtolower(trim((string)env('NOTIFY_LANGUAGE', '')));
    return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
}

/**
 * Pure-ish: turn an event + its payload into a short title and a readable body,
 * in NOTIFY_LANGUAGE. Every lookup has an English fallback built from the payload
 * itself, so a missing translation degrades to plain data, never to a stub.
 *
 * @param array<string,mixed> $data Same payload the HA webhook receives.
 * @return array{0:string,1:string} [title, message]
 */
function evershelfNotifyFormatEvent(string $event, array $data, string $lang = 'en'): array
{
    $count = (int)($data['count'] ?? count($data['items'] ?? []));
    $names = trim((string)($data['summary'] ?? ''));
    if ($names === '' && !empty($data['items']) && is_array($data['items'])) {
        $names = implode(', ', array_slice(array_column($data['items'], 'name'), 0, 8));
    }

    switch ($event) {
        case 'expiry_alert':
            $expired = ($data['type'] ?? '') === 'expired';
            $title   = $expired
                ? evershelfTr('notify.expired_title', $lang)
                : evershelfTr('notify.expiring_title', $lang);
            $message = $expired
                ? evershelfTr('notify.expired_message', $lang, ['count' => $count, 'items' => $names])
                : evershelfTr('notify.expiring_message', $lang, [
                    'count' => $count,
                    'days'  => (int)($data['days'] ?? 0),
                    'items' => $names,
                ]);
            // Fallbacks: the translation could be missing (audit aside, be safe).
            if ($message === 'notify.expired_message') {
                $message = $count . ' product(s) expired: ' . $names;
            }
            if ($message === 'notify.expiring_message') {
                $message = $count . ' product(s) expiring within ' . (int)($data['days'] ?? 0) . ' days: ' . $names;
            }
            return [$title, rtrim($message, ': ')];

        case 'shopping_add':
            $item = trim((string)($data['item'] ?? ''));
            $spec = trim((string)($data['specification'] ?? ''));
            return [
                evershelfTr('notify.shopping_add_title', $lang),
                $item . ($spec !== '' ? ' — ' . $spec : ''),
            ];

        case 'stock_update':
            $item = trim((string)($data['item'] ?? ''));
            $qty  = rtrim(rtrim(number_format((float)($data['quantity'] ?? 0), 2, '.', ''), '0'), '.');
            return [
                evershelfTr('notify.stock_update_title', $lang),
                trim($item . ': ' . $qty),
            ];
    }

    // Unknown event: still deliver, with the raw payload as the body.
    return ['EverShelf', $event . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE)];
}

/**
 * Format and fan out one event. This is what `_fireHaWebhook()` calls, so every
 * Home Assistant event automatically reaches the NOTIFY_* channels too.
 *
 * @param array<string,mixed> $data
 * @return array{event:string,channels:array<string,array{ok:bool,http:int,error:string}>}
 */
function evershelfNotifyEvent(string $event, array $data = [], bool $isTest = false): array
{
    [$title, $message] = evershelfNotifyFormatEvent($event, $data, evershelfNotifyLanguage());
    return evershelfNotifySend($event, $title, $message, $data, $isTest);
}

/**
 * Is this event allowed out on the filtered channels?
 *
 * @param string[]|null $events Pre-parsed list (tests); defaults to NOTIFY_EVENTS.
 */
function evershelfNotifyEventEnabled(string $event, ?array $events = null): bool
{
    $events = $events ?? evershelfNotifyEvents();
    return in_array($event, $events, true);
}

/**
 * Pure: ntfy topics are URL path segments — letters, digits, `_` and `-`.
 * Rejecting everything else keeps the topic from escaping into the URL path.
 */
function evershelfNtfyTopicValid(string $topic): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', $topic);
}

/**
 * Pure: accept ntfy's numeric priorities (1–5) or its names; anything else
 * falls back to `3` (default), so a typo cannot break delivery.
 */
function evershelfNotifyPriority(string $priority): string
{
    $priority = strtolower(trim($priority));
    $named = ['min' => '1', 'low' => '2', 'default' => '3', 'high' => '4', 'urgent' => '5', 'max' => '5'];
    if (isset($named[$priority])) {
        return $named[$priority];
    }
    return in_array($priority, ['1', '2', '3', '4', '5'], true) ? $priority : '3';
}

/** Pure: clamp a message to the ntfy body limit on a UTF-8 boundary. */
function evershelfNotifyClampBody(string $body, int $limit = EVERSHELF_NOTIFY_BODY_LIMIT): string
{
    if (strlen($body) <= $limit) {
        return $body;
    }
    return rtrim((string)mb_strcut($body, 0, $limit - 3, 'UTF-8')) . '…';
}

/**
 * Pure: strip CR/LF (header injection) and control characters from any value
 * that goes into an HTTP header, and keep it short.
 */
function evershelfNotifyHeaderSafe(string $value, int $max = 200): string
{
    $value = str_replace(["\r", "\n", "\0"], ' ', $value);
    $value = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    $value = trim($value);
    return strlen($value) > $max ? rtrim((string)mb_strcut($value, 0, $max, 'UTF-8')) : $value;
}

/** Pure: notification targets must be plain http(s) URLs (never file://, gopher://…). */
function evershelfNotifyUrlValid(string $url): bool
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return false;
    }
    return in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true);
}

/**
 * POST a body to a URL. Never throws, never logs the URL (it may carry a secret
 * topic) and never returns it in the error string.
 *
 * @param string[] $headers Full header lines ("Name: value").
 * @return array{ok:bool,http:int,error:string}
 */
function evershelfNotifyPost(string $url, array $headers, string $body, int $timeout = 8, bool $verifyTls = true): array
{
    return evershelfNotifyRequest('POST', $url, $headers, $body, $timeout, $verifyTls);
}

/**
 * Send a request with an explicit method to a URL.
 *
 * GET exists for the cron watchdog: Uptime Kuma only accepts its push status as
 * query parameters, while Healthchecks.io wants a POST. Everything else (no
 * redirects, never the URL in the error string, TLS toggle) is shared, so the
 * URL can keep being treated as a credential.
 *
 * @param string[] $headers Full header lines ("Name: value").
 * @return array{ok:bool,http:int,error:string}
 */
function evershelfNotifyRequest(string $method, string $url, array $headers, string $body = '', int $timeout = 8, bool $verifyTls = true): array
{
    $method = strtoupper($method) === 'GET' ? 'GET' : 'POST';
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'error' => 'curl_missing'];
    }
    try {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method === 'POST') {
            // Always send POSTFIELDS (even empty: the body is the log line).
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['ok' => false, 'http' => $http, 'error' => 'curl: ' . evershelfNotifyHeaderSafe($err)];
        }
        return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'http' => 0, 'error' => 'exception: ' . evershelfNotifyHeaderSafe($e->getMessage())];
    }
}

/**
 * Deliver to ntfy. Priority/Tags are cosmetic; the topic is the only credential.
 *
 * @param array<string,mixed> $data Extra payload (not sent — ntfy has no body object).
 * @return array{ok:bool,http:int,error:string}
 */
function evershelfNtfySend(string $title, string $message, array $data = []): array
{
    $base  = rtrim(trim((string)env('NTFY_URL', 'https://ntfy.sh')), '/');
    $topic = trim((string)env('NTFY_TOPIC', ''));
    if (!evershelfNotifyUrlValid($base)) {
        return ['ok' => false, 'http' => 0, 'error' => 'ntfy_url_invalid'];
    }
    if (!evershelfNtfyTopicValid($topic)) {
        return ['ok' => false, 'http' => 0, 'error' => 'ntfy_topic_invalid'];
    }

    $headers = [
        'Content-Type: text/plain; charset=utf-8',
        'Title: ' . evershelfNotifyHeaderSafe($title),
        'Priority: ' . evershelfNotifyPriority((string)env('NTFY_PRIORITY', 'default')),
    ];
    $tags = trim((string)env('NTFY_TAGS', 'ever-shelf'));
    if ($tags !== '') {
        $headers[] = 'Tags: ' . evershelfNotifyHeaderSafe($tags);
    }
    $token = trim((string)env('NTFY_TOKEN', ''));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . evershelfNotifyHeaderSafe($token, 400);
    }
    $verified = env('NOTIFY_INSECURE_SSL', 'false') !== 'true';

    return evershelfNotifyPost($base . '/' . $topic, $headers, evershelfNotifyClampBody($message), 8, $verified);
}

/**
 * Deliver to a generic JSON webhook (Discord/Slack bridges, Matrix, Gotify, n8n…).
 * The payload is the same shape the Home Assistant webhook uses.
 *
 * @param array<string,mixed> $data Merged into the JSON body.
 * @return array{ok:bool,http:int,error:string}
 */
function evershelfWebhookSend(string $event, string $title, string $message, array $data = []): array
{
    $url = trim((string)env('NOTIFY_WEBHOOK_URL', ''));
    if ($url === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'webhook_not_configured'];
    }
    if (!evershelfNotifyUrlValid($url)) {
        return ['ok' => false, 'http' => 0, 'error' => 'webhook_url_invalid'];
    }

    $payload = json_encode(array_merge([
        'event'   => $event,
        'title'   => $title,
        'message' => $message,
        'source'  => 'evershelf',
        'ts'      => time(),
    ], $data), JSON_UNESCAPED_UNICODE);

    $headers = ['Content-Type: application/json'];
    $token   = trim((string)env('NOTIFY_WEBHOOK_TOKEN', ''));
    if ($token !== '') {
        $headerName = trim((string)env('NOTIFY_WEBHOOK_HEADER', '')) ?: 'Authorization';
        $value      = strtolower($headerName) === 'authorization' ? 'Bearer ' . $token : $token;
        $headers[]  = evershelfNotifyHeaderSafe($headerName, 60) . ': ' . evershelfNotifyHeaderSafe($value, 400);
    }

    return evershelfNotifyPost($url, $headers, (string)$payload, 8, env('NOTIFY_INSECURE_SSL', 'false') !== 'true');
}

/**
 * Home Assistant notify service (legacy path, moved here so the daily cron and
 * the API share one implementation).
 * Gate: HA_ENABLED + HA_URL + HA_TOKEN + HA_NOTIFY_SERVICE.
 *
 * @param array<string,mixed> $data Forwarded as the service `data` object.
 * @return array{ok:bool,http:int,error:string}
 */
function evershelfHaNotifySend(string $message, array $data = []): array
{
    if (env('HA_ENABLED', 'false') !== 'true') {
        return ['ok' => false, 'http' => 0, 'error' => 'ha_disabled'];
    }
    $haUrl   = rtrim((string)env('HA_URL', ''), '/');
    $token   = (string)env('HA_TOKEN', '');
    $service = (string)env('HA_NOTIFY_SERVICE', '');
    if ($haUrl === '' || $token === '' || $service === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'ha_not_configured'];
    }
    // service format: "notify.mobile_app_xyz" → POST /api/services/notify/mobile_app_xyz
    [$domain, $svcName] = array_pad(explode('.', $service, 2), 2, '');
    if ($svcName === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'ha_service_invalid'];
    }

    $payload = json_encode(['message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    // HA on a LAN usually serves a self-signed certificate — same as before.
    return evershelfNotifyPost(
        $haUrl . '/api/services/' . urlencode($domain) . '/' . urlencode($svcName),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        (string)$payload,
        8,
        false
    );
}

/**
 * Fan one event out to every configured channel.
 *
 * @param string              $event   One of EVERSHELF_NOTIFY_EVENTS_KNOWN.
 * @param string              $title   Short title (ntfy header / webhook field).
 * @param string              $message Human-readable body.
 * @param array<string,mixed> $data    Extra context for the webhook payload.
 * @param bool                $isTest  True = ignore the master switch and the event
 *                                     filter (the Settings "Send test" button).
 * @return array{event:string,channels:array<string,array{ok:bool,http:int,error:string}>}
 */
function evershelfNotifySend(string $event, string $title, string $message, array $data = [], bool $isTest = false): array
{
    $result   = ['event' => $event, 'channels' => []];
    $channels = evershelfNotifyChannels();
    $allowed  = $isTest || (evershelfNotifyEnabled() && evershelfNotifyEventEnabled($event));

    if ($allowed && in_array('ntfy', $channels, true)) {
        $result['channels']['ntfy'] = evershelfNtfySend($title, $message, $data);
    }
    if ($allowed && in_array('webhook', $channels, true)) {
        $result['channels']['webhook'] = evershelfWebhookSend($event, $title, $message, $data);
    }
    // Legacy HA channel: unchanged gate, so nothing regresses for existing users.
    if (env('HA_ENABLED', 'false') === 'true' && env('HA_NOTIFY_SERVICE', '') !== '') {
        $result['channels']['ha'] = evershelfHaNotifySend($message, $data);
    }

    foreach ($result['channels'] as $name => $outcome) {
        if (!empty($outcome['ok'])) {
            EverLog::info("Notify[$event] delivered via $name", [
                'event'        => 'notify_sent',
                'channel'      => $name,
                'notify_event' => $event,
            ]);
        } elseif ($outcome['error'] !== '') {
            EverLog::warn("Notify[$event] via $name failed ({$outcome['error']})", [
                'event'        => 'notify_failed',
                'channel'      => $name,
                'notify_event' => $event,
                'error'        => $outcome['error'],
                'http'         => $outcome['http'],
            ]);
        }
    }

    return $result;
}

/**
 * Which channels are configured right now? The Settings panel uses it to explain
 * *why* nothing was delivered instead of failing silently.
 *
 * @return array{ntfy:bool,webhook:bool,ha:bool}
 */
function evershelfNotifyConfigured(): array
{
    return [
        'ntfy'    => evershelfNotifyUrlValid((string)env('NTFY_URL', 'https://ntfy.sh'))
                     && evershelfNtfyTopicValid(trim((string)env('NTFY_TOPIC', ''))),
        'webhook' => evershelfNotifyUrlValid(trim((string)env('NOTIFY_WEBHOOK_URL', ''))),
        'ha'      => env('HA_ENABLED', 'false') === 'true' && env('HA_NOTIFY_SERVICE', '') !== '',
    ];
}
