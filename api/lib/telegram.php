<?php
/**
 * Minimal Telegram bot webhook: /lista and /scadenze for household members.
 */
declare(strict_types=1);

/**
 * Who may call the Telegram webhook: `null` when the update is allowed, else the error code.
 *
 * Telegram cannot present the API token, so this endpoint is public by design (it is in
 * `evershelfPublicActions()`). Its one real authenticator is the value Telegram echoes back:
 * `setWebhook(..., secret_token: …)` makes every update carry
 * `X-Telegram-Bot-Api-Secret-Token`, and only Telegram knows it. Without a secret the only
 * gate is the chat allowlist — and with an EMPTY allowlist every chat id in the body used to
 * be accepted, i.e. anyone who could reach this URL could have the bot send the shopping or
 * the expiry list to a chat of their choice. Hence: no secret and no allowlist = refuse.
 */
function telegramWebhookAuthVerdict(string $secret, string $got, array $allowed): ?string
{
    $secret = trim($secret);
    if ($secret !== '') {
        return ($got !== '' && hash_equals($secret, $got)) ? null : 'forbidden';
    }
    return $allowed !== [] ? null : 'webhook_not_configured';
}

function telegramWebhookHandle(PDO $db): void
{
    $token = trim((string)env('TELEGRAM_BOT_TOKEN', ''));
    if ($token === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'telegram_disabled']);
        return;
    }
    $allowed = array_filter(array_map('trim', explode(',', (string)env('TELEGRAM_ALLOWED_CHAT_IDS', ''))));

    // ── Who is allowed to talk to the bot ────────────────────────────────────────
    // Telegram cannot present the API token, so this endpoint is public by design (it is in
    // `evershelfPublicActions()`). Its only authenticator is what Telegram itself is told to
    // send: `setWebhook(..., secret_token: …)` makes every update carry
    // `X-Telegram-Bot-Api-Secret-Token`, and only Telegram knows that value.
    //
    // Without it the sole gate was the chat allowlist — and with an EMPTY allowlist every
    // chat id in the body was accepted, i.e. anyone who could reach this URL could have the
    // bot send the shopping or the expiry list to a chat of their choice.
    $secret = trim((string)env('TELEGRAM_WEBHOOK_SECRET', ''));
    $got    = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    $verdict = telegramWebhookAuthVerdict($secret, $got, $allowed);
    if ($verdict === 'forbidden') {
        EverLog::warn('Telegram webhook rejected: missing or wrong secret token', [
            'event' => 'telegram_webhook_bad_secret',
        ]);
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        return;
    }
    if ($verdict === 'webhook_not_configured') {
        // No secret AND no allowlist: there is nothing left to authenticate the caller, so the
        // webhook refuses to run at all instead of answering whoever knocks.
        EverLog::warn('Telegram webhook refused: set TELEGRAM_WEBHOOK_SECRET (and the setWebhook secret_token) or TELEGRAM_ALLOWED_CHAT_IDS', [
            'event' => 'telegram_webhook_unconfigured',
        ]);
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'webhook_not_configured']);
        return;
    }

    $raw = file_get_contents('php://input') ?: '';
    $update = json_decode($raw, true);
    if (!is_array($update)) {
        echo json_encode(['ok' => true]);
        return;
    }
    $msg = $update['message'] ?? $update['edited_message'] ?? null;
    if (!is_array($msg)) {
        echo json_encode(['ok' => true]);
        return;
    }
    $chatId = (string)($msg['chat']['id'] ?? '');
    if ($allowed !== [] && !in_array($chatId, $allowed, true)) {
        EverLog::warn('Telegram chat rejected', ['event' => 'telegram_chat_rejected', 'chat_id' => $chatId]);
        echo json_encode(['ok' => true]);
        return;
    }
    $text = trim((string)($msg['text'] ?? ''));
    if ($text === '') {
        echo json_encode(['ok' => true]);
        return;
    }
    $cmd = strtolower(explode(' ', $text, 2)[0]);
    $reply = '';
    if ($cmd === '/lista' || $cmd === '/list') {
        $rows = $db->query('SELECT name FROM shopping_list ORDER BY sort_order, id LIMIT 40')->fetchAll(PDO::FETCH_COLUMN);
        $reply = $rows ? ("🛒 " . implode("\n• ", array_merge([''], $rows))) : '🛒 (empty)';
    } elseif ($cmd === '/scadenze' || $cmd === '/expiring') {
        $rows = $db->query(
            "SELECT p.name, MIN(i.expiry_date) AS exp
             FROM inventory i JOIN products p ON p.id = i.product_id
             WHERE i.quantity > 0 AND i.expiry_date IS NOT NULL
               AND i.expiry_date <= date('now', '+7 days')
             GROUP BY p.id ORDER BY exp LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $reply = '📅 Nothing expiring in 7 days';
        } else {
            $lines = array_map(static fn($r) => '• ' . ($r['name'] ?? '') . ' → ' . ($r['exp'] ?? ''), $rows);
            $reply = "📅 Expiring soon:\n" . implode("\n", $lines);
        }
    } else {
        $reply = "Commands: /lista, /scadenze";
    }
    telegramApiSend($token, $chatId, $reply);
    echo json_encode(['ok' => true]);
}

function telegramApiSend(string $token, string $chatId, string $text): void
{
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $body = http_build_query(['chat_id' => $chatId, 'text' => mb_substr($text, 0, 3900)]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
