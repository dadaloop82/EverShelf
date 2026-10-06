<?php
/**
 * Minimal Telegram bot webhook: /lista and /scadenze for household members.
 */
declare(strict_types=1);

function telegramWebhookHandle(PDO $db): void
{
    $token = trim((string)env('TELEGRAM_BOT_TOKEN', ''));
    if ($token === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'telegram_disabled']);
        return;
    }
    $allowed = array_filter(array_map('trim', explode(',', (string)env('TELEGRAM_ALLOWED_CHAT_IDS', ''))));
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
