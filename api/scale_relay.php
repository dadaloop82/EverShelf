<?php
/**
 * EverShelf Scale Gateway — SSE Relay
 *
 * Bridges the Android BLE gateway (ws://) to Server-Sent Events (SSE) so that
 * browsers on HTTPS pages can receive weight data without mixed-content errors.
 *
 * Usage: GET /api/scale_relay.php?url=ws%3A%2F%2F192.168.1.100%3A8765
 */

// ── Input validation ──────────────────────────────────────────────────────────
$rawUrl = $_GET['url'] ?? '';

// Only accept ws:// scheme with valid host and optional port/path
if (!preg_match('#^ws://[0-9a-zA-Z][\w.\-]*(:\d{1,5})?(/.*)?$#', $rawUrl)) {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Invalid gateway URL (must start with ws://)']) . "\n\n";
    exit;
}

$parsed = parse_url($rawUrl);
$wsHost = $parsed['host'] ?? '';
$wsPort = (int)($parsed['port'] ?? 8765);
$wsPath = ($parsed['path'] ?? '') ?: '/';

if (!$wsHost || $wsPort < 1 || $wsPort > 65535) {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Invalid host or port']) . "\n\n";
    exit;
}

// ── SSE headers ───────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');       // Disable nginx / Caddy buffering
header('Content-Encoding: identity');  // Prevent gzip/deflate compression

set_time_limit(0);
ignore_user_abort(false); // stop when browser closes connection

// Clear all PHP output-buffering levels so echo/flush goes straight to SAPI
while (ob_get_level() > 0) {
    ob_end_clean();
}

// ── Connect to Android gateway ────────────────────────────────────────────────
$sock = @stream_socket_client("tcp://{$wsHost}:{$wsPort}", $errno, $errstr, 5);
if (!$sock) {
    echo 'data: ' . json_encode([
        'type'    => 'error',
        'message' => "Cannot connect to gateway ({$wsHost}:{$wsPort}): {$errstr}",
    ]) . "\n\n";
    flush();
    exit;
}

// ── WebSocket handshake (PHP acts as client) ──────────────────────────────────
// RFC 6455: client frames MUST be masked.
$wsKey = base64_encode(random_bytes(16));

stream_set_blocking($sock, true);
stream_set_timeout($sock, 5);

fwrite($sock,
    "GET {$wsPath} HTTP/1.1\r\n" .
    "Host: {$wsHost}:{$wsPort}\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "Sec-WebSocket-Key: {$wsKey}\r\n" .
    "Sec-WebSocket-Version: 13\r\n" .
    "\r\n"
);

// Read HTTP 101 Switching Protocols response
$httpResp = '';
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline && !feof($sock)) {
    $line = fgets($sock, 1024);
    if ($line === false) break;
    $httpResp .= $line;
    if ($line === "\r\n") break;
}

if (!str_contains($httpResp, '101')) {
    fclose($sock);
    echo 'data: ' . json_encode([
        'type'    => 'error',
        'message' => 'WebSocket handshake failed — is the gateway URL correct?',
    ]) . "\n\n";
    flush();
    exit;
}

// Switch to non-blocking for poll-based relay loop
stream_set_blocking($sock, false);
stream_set_timeout($sock, 0);

// Ask gateway for current status immediately
wsSend($sock, json_encode(['type' => 'get_status']));

// ── SSE relay loop ────────────────────────────────────────────────────────────
$buf      = '';
$lastPing = time();

while (!connection_aborted()) {
    $chunk = @fread($sock, 8192);

    if ($chunk === false || (feof($sock) && $chunk === '')) {
        // Gateway closed the connection
        echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Gateway disconnected']) . "\n\n";
        flush();
        break;
    }

    if ($chunk !== '') {
        $buf .= $chunk;
        while (($payload = wsRead($buf, $sock)) !== null) {
            if ($payload === false) goto done; // close frame received
            if ($payload === '') continue;     // ping/pong control frames (handled inside wsRead)
            echo "data: {$payload}\n\n";
            flush();
        }
    }

    // SSE keep-alive comment + gateway WebSocket ping every 20 seconds
    if (time() - $lastPing >= 20) {
        echo ": keep-alive\n\n";
        flush();
        $lastPing = time();
        wsPing($sock);
    }

    usleep(100_000); // 100 ms polling interval
}

done:
fclose($sock);

// ── WebSocket helpers ─────────────────────────────────────────────────────────

/** Send a masked text frame from PHP (client) to the gateway (server). */
function wsSend($sock, string $payload): void
{
    $len  = strlen($payload);
    $mask = random_bytes(4);

    if ($len < 126) {
        $header = "\x81" . chr(0x80 | $len);
    } elseif ($len < 65536) {
        $header = "\x81\xFE" . pack('n', $len); // opcode=text, mask bit, 2-byte length
    } else {
        $header = "\x81\xFF" . pack('J', $len); // opcode=text, mask bit, 8-byte length
    }

    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }

    @fwrite($sock, $header . $mask . $masked);
}

/** Send a masked ping to keep the gateway connection alive. */
function wsPing($sock): void
{
    @fwrite($sock, "\x89\x80" . random_bytes(4)); // FIN+ping, MASK bit, 4-byte mask, no payload
}

/**
 * Try to decode one complete WebSocket frame from the read buffer.
 * The gateway (server) sends unmasked frames to PHP (client).
 *
 * Returns:
 *   string  — decoded text/binary payload
 *   ''      — control frame (ping/pong) handled internally, skip
 *   false   — close frame received
 *   null    — not enough data yet, read more
 */
function wsRead(string &$buf, $sock): string|null|false
{
    if (strlen($buf) < 2) return null;

    $b0  = ord($buf[0]);
    $b1  = ord($buf[1]);
    $op  = $b0 & 0x0F;
    $msk = ($b1 & 0x80) !== 0; // servers never mask, but handle defensively
    $len = $b1 & 0x7F;
    $off = 2;

    if ($len === 126) {
        if (strlen($buf) < 4) return null;
        $len = unpack('n', substr($buf, 2, 2))[1];
        $off = 4;
    } elseif ($len === 127) {
        if (strlen($buf) < 10) return null;
        $len = unpack('J', substr($buf, 2, 8))[1];
        $off = 10;
    }

    if ($msk) $off += 4;
    if (strlen($buf) < $off + $len) return null;

    $maskKey = $msk ? substr($buf, $off - 4, 4) : null;
    $payload = substr($buf, $off, $len);

    if ($msk && $maskKey) {
        for ($i = 0; $i < strlen($payload); $i++) {
            $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
        }
    }

    // Consume frame from buffer
    $buf = substr($buf, $off + $len);

    if ($op === 0x8) return false;  // close frame
    if ($op === 0x9) {              // ping → send masked pong
        $pLen = strlen($payload);
        $mask = random_bytes(4);
        $maskedPayload = '';
        for ($i = 0; $i < $pLen; $i++) {
            $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
        }
        @fwrite($sock, "\x8A" . chr(0x80 | $pLen) . $mask . $maskedPayload);
        return '';
    }
    if ($op === 0xA) return '';     // pong — ignore

    return $payload;               // 0x1 = text, 0x2 = binary
}
