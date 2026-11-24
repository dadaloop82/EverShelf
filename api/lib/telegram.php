<?php
/**
 * Telegram bot webhook: /lista, /scadenze, /add, /use (with confirm), /help.
 * Also the notify channel that delivers expiry digests to the household chats.
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

function telegramLang(): string
{
    if (function_exists('evershelfNotifyLanguage')) {
        $lang = evershelfNotifyLanguage();
        if (is_string($lang) && preg_match('/^[a-z]{2}$/', $lang)) {
            return $lang;
        }
    }
    $lang = strtolower(trim((string)env('APP_LANG', 'en')));
    return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
}

function telegramTr(string $key, array $args = []): string
{
    return function_exists('evershelfTr')
        ? evershelfTr($key, telegramLang(), $args)
        : $key;
}

function telegramPendingPath(): string
{
    return (defined('EVERSHELF_ROOT') ? EVERSHELF_ROOT : dirname(__DIR__, 2)) . '/data/telegram_pending.json';
}

/** @return array<string,array<string,mixed>> */
function telegramPendingLoad(): array
{
    $path = telegramPendingPath();
    if (!is_readable($path)) {
        return [];
    }
    $raw = json_decode((string)file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

/** @param array<string,array<string,mixed>> $data */
function telegramPendingSave(array $data): void
{
    $path = telegramPendingPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Drop expired pending confirms (10 minutes).
    $now = time();
    foreach ($data as $k => $row) {
        if (!is_array($row) || (int)($row['exp'] ?? 0) < $now) {
            unset($data[$k]);
        }
    }
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function telegramWebhookHandle(PDO $db): void
{
    $token = trim((string)env('TELEGRAM_BOT_TOKEN', ''));
    if ($token === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'telegram_disabled']);
        return;
    }
    $allowed = array_values(array_filter(array_map('trim', explode(',', (string)env('TELEGRAM_ALLOWED_CHAT_IDS', '')))));

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

    // Confirm / cancel of /use (inline keyboard).
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        telegramHandleCallback($db, $token, $allowed, $update['callback_query']);
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
    // Strip @BotName from /cmd@BotName
    if (preg_match('/^(\/[a-z]+)@[^\s]+/i', $text, $m)) {
        $text = $m[1] . substr($text, strlen($m[0]));
    }
    $parts = preg_split('/\s+/u', $text, 2) ?: [$text];
    $cmd = strtolower((string)($parts[0] ?? ''));
    $arg = trim((string)($parts[1] ?? ''));

    if ($cmd === '/lista' || $cmd === '/list' || $cmd === '/shopping') {
        $rows = $db->query('SELECT name FROM shopping_list ORDER BY sort_order, id LIMIT 40')->fetchAll(PDO::FETCH_COLUMN);
        $reply = $rows
            ? ("🛒 " . implode("\n• ", array_merge([''], $rows)))
            : telegramTr('telegram.empty_list');
        telegramApiSend($token, $chatId, $reply);
    } elseif ($cmd === '/scadenze' || $cmd === '/expiring') {
        $rows = $db->query(
            "SELECT p.name, MIN(i.expiry_date) AS exp
             FROM inventory i JOIN products p ON p.id = i.product_id
             WHERE i.quantity > 0 AND i.expiry_date IS NOT NULL
               AND i.expiry_date <= date('now', '+7 days')
             GROUP BY p.id ORDER BY exp LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $reply = telegramTr('telegram.empty_expiring');
        } else {
            $lines = array_map(static fn($r) => '• ' . ($r['name'] ?? '') . ' → ' . ($r['exp'] ?? ''), $rows);
            $reply = telegramTr('telegram.expiring_header') . "\n" . implode("\n", $lines);
        }
        telegramApiSend($token, $chatId, $reply);
    } elseif ($cmd === '/help' || $cmd === '/start') {
        telegramApiSend($token, $chatId, telegramTr('telegram.help'));
    } elseif ($cmd === '/add') {
        telegramHandleAdd($db, $token, $chatId, $arg);
    } elseif ($cmd === '/use') {
        telegramHandleUse($db, $token, $chatId, $arg);
    } else {
        telegramApiSend($token, $chatId, telegramTr('telegram.unknown_cmd'));
    }
    echo json_encode(['ok' => true]);
}

/**
 * Parse "/add 2 eggs fridge" → qty, name, optional location.
 * @return array{qty:float,name:string,location:string}|null
 */
function telegramParseAddArgs(string $arg): ?array
{
    $arg = trim($arg);
    if ($arg === '') {
        return null;
    }
    $qty = 1.0;
    $rest = $arg;
    if (preg_match('/^(\d+[.,]?\d*)\s+(.+)$/u', $arg, $m)) {
        $qty = (float)str_replace(',', '.', $m[1]);
        $rest = trim($m[2]);
    }
    if ($qty <= 0 || $rest === '') {
        return null;
    }
    $location = 'dispensa';
    $name = $rest;
    // Trailing known location word (Italian + English labels / keys).
    $locMap = [
        'frigo' => 'frigo', 'fridge' => 'frigo', 'refrigerator' => 'frigo',
        'freezer' => 'freezer', 'congelatore' => 'freezer',
        'dispensa' => 'dispensa', 'pantry' => 'dispensa',
        'cantina' => 'cantina', 'cellar' => 'cantina',
    ];
    if (preg_match('/^(.+?)\s+(\S+)$/u', $rest, $m2)) {
        $maybe = mb_strtolower($m2[2]);
        if (isset($locMap[$maybe])) {
            $name = trim($m2[1]);
            $location = $locMap[$maybe];
        }
    }
    return ['qty' => $qty, 'name' => $name, 'location' => $location];
}

function telegramFindProducts(PDO $db, string $name, int $limit = 5): array
{
    $q = '%' . mb_strtolower(trim($name)) . '%';
    $st = $db->prepare(
        "SELECT id, name, brand, unit, COALESCE(default_quantity,0) AS default_quantity,
                COALESCE(package_unit,'') AS package_unit
         FROM products
         WHERE LOWER(name) LIKE ? OR LOWER(COALESCE(brand,'')) LIKE ? OR LOWER(COALESCE(shopping_name,'')) LIKE ?
         ORDER BY CASE WHEN LOWER(name) = LOWER(?) THEN 0
                       WHEN LOWER(name) LIKE LOWER(?) THEN 1 ELSE 2 END,
                  length(name)
         LIMIT ?"
    );
    $exact = trim($name);
    $st->execute([$q, $q, $q, $exact, $exact . '%', $limit]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function telegramHandleAdd(PDO $db, string $token, string $chatId, string $arg): void
{
    $parsed = telegramParseAddArgs($arg);
    if ($parsed === null) {
        telegramApiSend($token, $chatId, telegramTr('telegram.add_usage'));
        return;
    }
    $hits = telegramFindProducts($db, $parsed['name']);
    if (!$hits) {
        telegramApiSend($token, $chatId, telegramTr('telegram.product_not_found', ['name' => $parsed['name']]));
        return;
    }
    if (count($hits) > 1) {
        $lines = array_map(static fn($p) => '• ' . ($p['name'] ?? '') . (!empty($p['brand']) ? ' (' . $p['brand'] . ')' : ''), $hits);
        telegramApiSend($token, $chatId, telegramTr('telegram.ambiguous') . "\n" . implode("\n", $lines));
        return;
    }
    $p = $hits[0];
    $pid = (int)$p['id'];
    if (!function_exists('inventoryImportAddStock')) {
        telegramApiSend($token, $chatId, telegramTr('telegram.error_generic'));
        return;
    }
    try {
        inventoryImportAddStock($db, $pid, [
            'quantity' => $parsed['qty'],
            'location' => $parsed['location'],
            'unit'     => $p['unit'] ?? 'pz',
        ]);
        telegramApiSend($token, $chatId, telegramTr('telegram.add_ok', [
            'qty'  => rtrim(rtrim(number_format($parsed['qty'], 2, '.', ''), '0'), '.'),
            'name' => (string)$p['name'],
            'loc'  => $parsed['location'],
        ]));
    } catch (Throwable $e) {
        EverLog::exception($e, ['event' => 'telegram_add_failed']);
        telegramApiSend($token, $chatId, telegramTr('telegram.error_generic'));
    }
}

function telegramHandleUse(PDO $db, string $token, string $chatId, string $arg): void
{
    $parsed = telegramParseAddArgs($arg); // same qty+name grammar; location ignored for use
    if ($parsed === null) {
        telegramApiSend($token, $chatId, telegramTr('telegram.use_usage'));
        return;
    }
    $hits = telegramFindProducts($db, $parsed['name']);
    // Prefer products that actually have stock.
    $stocked = [];
    foreach ($hits as $p) {
        $st = $db->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id = ? AND quantity > 0');
        $st->execute([(int)$p['id']]);
        $qty = (float)$st->fetchColumn();
        if ($qty > 0) {
            $p['_stock'] = $qty;
            $stocked[] = $p;
        }
    }
    if (!$stocked) {
        telegramApiSend($token, $chatId, telegramTr('telegram.product_not_stocked', ['name' => $parsed['name']]));
        return;
    }
    if (count($stocked) > 1) {
        $lines = array_map(static fn($p) => '• ' . ($p['name'] ?? '') . ' (' . rtrim(rtrim(number_format((float)$p['_stock'], 2, '.', ''), '0'), '.') . ' ' . ($p['unit'] ?? '') . ')', $stocked);
        telegramApiSend($token, $chatId, telegramTr('telegram.ambiguous') . "\n" . implode("\n", $lines));
        return;
    }
    $p = $stocked[0];
    $pid = (int)$p['id'];
    $qty = $parsed['qty'];
    $tokenKey = bin2hex(random_bytes(8));
    $pending = telegramPendingLoad();
    $pending[$tokenKey] = [
        'chat_id'    => $chatId,
        'product_id' => $pid,
        'quantity'   => $qty,
        'name'       => (string)$p['name'],
        'exp'        => time() + 600,
    ];
    telegramPendingSave($pending);
    $qtyLabel = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' ' . ($p['unit'] ?? '');
    $keyboard = [
        'inline_keyboard' => [[
            ['text' => telegramTr('telegram.confirm_yes'), 'callback_data' => 'use_ok:' . $tokenKey],
            ['text' => telegramTr('telegram.confirm_no'),  'callback_data' => 'use_no:' . $tokenKey],
        ]],
    ];
    telegramApiSend($token, $chatId, telegramTr('telegram.use_confirm', [
        'qty'  => $qtyLabel,
        'name' => (string)$p['name'],
    ]), $keyboard);
}

function telegramHandleCallback(PDO $db, string $token, array $allowed, array $cb): void
{
    $chatId = (string)($cb['message']['chat']['id'] ?? $cb['from']['id'] ?? '');
    $data = (string)($cb['data'] ?? '');
    $cbId = (string)($cb['id'] ?? '');
    if ($allowed !== [] && !in_array($chatId, $allowed, true)) {
        telegramApiAnswerCallback($token, $cbId);
        return;
    }
    if (!preg_match('/^(use_ok|use_no):([a-f0-9]+)$/', $data, $m)) {
        telegramApiAnswerCallback($token, $cbId);
        return;
    }
    $action = $m[1];
    $key = $m[2];
    $pending = telegramPendingLoad();
    $row = $pending[$key] ?? null;
    unset($pending[$key]);
    telegramPendingSave($pending);
    telegramApiAnswerCallback($token, $cbId);

    if (!is_array($row) || (string)($row['chat_id'] ?? '') !== $chatId || (int)($row['exp'] ?? 0) < time()) {
        telegramApiSend($token, $chatId, telegramTr('telegram.confirm_expired'));
        return;
    }
    if ($action === 'use_no') {
        telegramApiSend($token, $chatId, telegramTr('telegram.use_cancelled'));
        return;
    }
    $pid = (int)($row['product_id'] ?? 0);
    $qty = (float)($row['quantity'] ?? 0);
    $name = (string)($row['name'] ?? '');
    if ($pid <= 0 || $qty <= 0 || !function_exists('useFromInventoryCore')) {
        telegramApiSend($token, $chatId, telegramTr('telegram.error_generic'));
        return;
    }
    // Resolve a location that still holds stock.
    $locSt = $db->prepare(
        "SELECT location FROM inventory WHERE product_id = ? AND quantity > 0
         ORDER BY CASE WHEN opened_at IS NOT NULL AND opened_at <> '' THEN 0 ELSE 1 END, quantity DESC LIMIT 1"
    );
    $locSt->execute([$pid]);
    $location = (string)($locSt->fetchColumn() ?: 'dispensa');

    ob_start();
    try {
        useFromInventoryCore($db, $pid, $qty, false, $location, 'telegram', false);
    } catch (Throwable $e) {
        EverLog::exception($e, ['event' => 'telegram_use_failed']);
    }
    $out = (string)ob_get_clean();
    $decoded = json_decode($out, true);
    if (is_array($decoded) && !empty($decoded['success'])) {
        telegramApiSend($token, $chatId, telegramTr('telegram.use_ok', [
            'qty'  => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
            'name' => $name,
        ]));
    } else {
        $err = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
        telegramApiSend($token, $chatId, $err !== '' ? $err : telegramTr('telegram.error_generic'));
    }
}

function telegramApiSend(string $token, string $chatId, string $text, ?array $replyMarkup = null): void
{
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = [
        'chat_id' => $chatId,
        'text'    => mb_substr($text, 0, 3900),
    ];
    if ($replyMarkup) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function telegramApiAnswerCallback(string $token, string $callbackId): void
{
    if ($callbackId === '') {
        return;
    }
    $url = 'https://api.telegram.org/bot' . $token . '/answerCallbackQuery';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['callback_query_id' => $callbackId]),
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Notify channel: send title+body to every allowed Telegram chat.
 * @return array{ok:bool,http:int,error:string}
 */
function telegramNotifySend(string $title, string $message): array
{
    $token = trim((string)env('TELEGRAM_BOT_TOKEN', ''));
    $chats = array_values(array_filter(array_map('trim', explode(',', (string)env('TELEGRAM_ALLOWED_CHAT_IDS', '')))));
    if ($token === '' || $chats === []) {
        return ['ok' => false, 'http' => 0, 'error' => 'telegram_not_configured'];
    }
    $text = trim($title . "\n" . $message);
    $ok = 0;
    foreach ($chats as $chatId) {
        telegramApiSend($token, $chatId, $text);
        $ok++;
    }
    return ['ok' => $ok > 0, 'http' => 200, 'error' => ''];
}
