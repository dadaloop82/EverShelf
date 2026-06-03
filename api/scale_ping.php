<?php
/**
 * EverShelf Scale Gateway — Connection ping / test (SSRF-hardened)
 */

require_once __DIR__ . '/lib/env.php';
require_once __DIR__ . '/lib/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

if (evershelfApiTokenRequired() && !evershelfApiTokenValid() && !evershelfIsSameOriginBrowser()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'api_token_required' => true]);
    exit;
}

$rawUrl = $_GET['url'] ?? '';

if (!preg_match('#^ws://[0-9a-zA-Z][\w.\-]*(:\d{1,5})?(/.*)?$#', $rawUrl)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid gateway URL (must start with ws://)']);
    exit;
}

$parsed = parse_url($rawUrl);
$host   = strtolower($parsed['host'] ?? '');
$port   = (int)($parsed['port'] ?? 8765);
$path   = ($parsed['path'] ?? '') ?: '/';

if (!$host || $port < 1 || $port > 65535) {
    echo json_encode(['ok' => false, 'error' => 'Invalid host or port']);
    exit;
}

if (!evershelfScaleHostAllowed($host)) {
    echo json_encode(['ok' => false, 'error' => 'Gateway host not allowed']);
    exit;
}

// Try to open a TCP connection with a 5-second timeout
$sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
if (!$sock) {
    echo json_encode(['ok' => false, 'error' => "Cannot connect to {$host}:{$port} — {$errstr}"]);
    exit;
}

stream_set_timeout($sock, 5);

// Perform WebSocket handshake
$wsKey = base64_encode(random_bytes(16));
fwrite($sock,
    "GET {$path} HTTP/1.1\r\n" .
    "Host: {$host}:{$port}\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "Sec-WebSocket-Key: {$wsKey}\r\n" .
    "Sec-WebSocket-Version: 13\r\n" .
    "\r\n"
);

// Read HTTP response (looking for 101 Switching Protocols)
$resp = '';
while (!feof($sock)) {
    $line = fgets($sock, 1024);
    if ($line === false) break;
    $resp .= $line;
    if ($line === "\r\n") break;
}

fclose($sock);

if (str_contains($resp, '101')) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'WebSocket handshake failed — check that the gateway app is running']);
}
