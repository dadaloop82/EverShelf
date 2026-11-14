<?php
/**
 * Actionable push notifications (#122) — the proof a notification carries.
 *
 * The two buttons of a system notification (“✓ Usato” / “🔥 Buttato”) are handled by
 * the service worker: no page, no pairing, no API token. The write they perform is
 * therefore authorised by a token that travels INSIDE the notification:
 *
 *   v1|<inventory_id>|<action>|<expiry>|<hmac-sha256>
 *
 * signed with the instance token (`evershelfEffectiveApiToken()`), base64url-encoded.
 * Only a device that received the push can present it, it names one row and one
 * action, it expires (12 h: a notification is read the same day), and a wrong value
 * never writes anything. Nothing is stored server-side: the signature IS the state.
 */
declare(strict_types=1);

/** How long a notification button stays usable. */
const EVERSHELF_PUSH_ACTION_TTL = 43200; // 12 hours

/** Actions a notification can carry (the browser shows at most two). */
const EVERSHELF_PUSH_ACTIONS = ['use', 'waste'];

/**
 * The signing secret: the instance token, minted on first boot and already rotated
 * by the operator through Settings → Sistema → Sicurezza. No second key to keep.
 */
function evershelfPushActionSecret(): string
{
    return function_exists('evershelfEffectiveApiToken') ? evershelfEffectiveApiToken() : '';
}

/** Sign one action for one inventory row. Empty string = actions not available. */
function evershelfPushActionSign(int $inventoryId, string $action, ?int $expiresAt = null): string
{
    $secret = evershelfPushActionSecret();
    if ($secret === '' || $inventoryId <= 0 || !in_array($action, EVERSHELF_PUSH_ACTIONS, true)) {
        return '';
    }
    if (!function_exists('evershelfWebPushBase64Url')) {
        return '';
    }
    $exp = $expiresAt ?? (time() + EVERSHELF_PUSH_ACTION_TTL);
    $payload = 'v1|' . $inventoryId . '|' . $action . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, $secret);
    return evershelfWebPushBase64Url($payload . '|' . $sig);
}

/**
 * Verify a token coming from a notification.
 *
 * @return array{inventory_id:int,action:string,exp:int}|null null = refuse
 */
function evershelfPushActionVerify(string $token): ?array
{
    $secret = evershelfPushActionSecret();
    if ($secret === '' || $token === '' || !function_exists('evershelfWebPushBase64UrlDecode')) {
        return null;
    }
    $raw = evershelfWebPushBase64UrlDecode($token);
    if ($raw === '') {
        return null;
    }
    $parts = explode('|', $raw);
    if (count($parts) !== 5 || $parts[0] !== 'v1') {
        return null;
    }
    [, $id, $action, $exp, $sig] = $parts;
    if (!ctype_digit($id) || !ctype_digit($exp) || $sig === '') {
        return null;
    }
    if (!in_array($action, EVERSHELF_PUSH_ACTIONS, true)) {
        return null;
    }
    if ((int)$exp < time()) {
        return null;
    }
    $expected = hash_hmac('sha256', 'v1|' . $id . '|' . $action . '|' . $exp, $secret);
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    return ['inventory_id' => (int)$id, 'action' => $action, 'exp' => (int)$exp];
}


/**
 * The two actions of one notification, titled in the server language.
 *
 * @return list<array{action:string,title:string,token:string}>
 */
function evershelfPushActionsForRow(array $row, string $lang): array
{
    $id = (int)($row['inventory_id'] ?? 0);
    if ($id <= 0) {
        return [];
    }
    $out = [];
    foreach (EVERSHELF_PUSH_ACTIONS as $action) {
        $token = evershelfPushActionSign($id, $action);
        if ($token === '') {
            continue;
        }
        $out[] = [
            'action' => $action,
            'title'  => $action === 'use'
                ? (evershelfTr('notify.action_use', $lang) ?: 'Consumed')
                : (evershelfTr('notify.action_waste', $lang) ?: 'Thrown away'),
            'token'  => $token,
        ];
    }
    return $out;
}

/**
 * One notification per product, or the aggregate line when the event does not name
 * rows (or names too many of them: a wall of notifications is not a feature).
 *
 * The payload is the same one the Home Assistant webhook and the ntfy/webhook
 * channels receive, so an expiry alert carries `items` with `inventory_id`, `name`,
 * `expiry_date` and `days_remaining` (`_haFormatProduct()`).
 *
 * @return list<array{title:string,body:string,url:string,actions:list<array<string,string>>}>
 */
function evershelfPushInboxEntries(string $title, string $message, string $url, array $data): array
{
    $rows = [];
    if (!empty($data['items']) && is_array($data['items'])) {
        foreach ($data['items'] as $row) {
            if (is_array($row) && (int)($row['inventory_id'] ?? 0) > 0) {
                $rows[] = $row;
            }
        }
    } elseif ((int)($data['inventory_id'] ?? 0) > 0) {
        $rows[] = $data;
    }

    $lang = evershelfNotifyLanguage();
    $entries = [];
    if ($rows !== [] && count($rows) <= 3) {
        foreach ($rows as $row) {
            $actions = evershelfPushActionsForRow($row, $lang);
            $name = trim((string)($row['name'] ?? ''));
            if ($actions === [] || $name === '') {
                $entries = []; // no secret, no name → the aggregate line, never a half-button
                break;
            }
            $date = trim((string)($row['expiry_date'] ?? ''));
            $days = isset($row['days_remaining']) && is_numeric($row['days_remaining'])
                ? (int)$row['days_remaining']
                : null;
            if ($days === null) {
                $body = '';
            } elseif ($days < 0) {
                $body = evershelfTr('notify.push_expired_body', $lang, ['days' => abs($days), 'date' => $date]);
            } elseif ($days === 0) {
                $body = evershelfTr('notify.push_expires_today', $lang, ['date' => $date]);
            } else {
                $body = evershelfTr('notify.push_expiring_body', $lang, ['days' => $days, 'date' => $date]);
            }
            $entries[] = ['title' => $name, 'body' => $body, 'url' => $url, 'actions' => $actions];
        }
    }
    if ($entries === []) {
        $entries[] = ['title' => $title, 'body' => $message, 'url' => $url, 'actions' => []];
    }
    return $entries;
}
