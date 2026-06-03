<?php
/**
 * EverShelf Scale Gateway — Auto-discovery (auth + rate limit + LAN only).
 */

require_once __DIR__ . '/lib/env.php';
require_once __DIR__ . '/lib/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');
evershelfSendCorsHeaders();

if (evershelfApiTokenRequired() && !evershelfApiTokenValid() && !evershelfIsSameOriginBrowser()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'api_token_required' => true]);
    exit;
}

// Simple rate limit: max 6 scans per minute per IP
$rlDir = dirname(__DIR__) . '/data/rate_limits';
if (!is_dir($rlDir)) {
    @mkdir($rlDir, 0755, true);
}
$rlFile = $rlDir . '/scale_discover_' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli') . '.json';
$now = time();
$hits = [];
if (file_exists($rlFile)) {
    $hits = array_filter(json_decode(file_get_contents($rlFile), true) ?: [], fn($t) => $t > $now - 60);
}
if (count($hits) >= 6) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many discovery scans']);
    exit;
}
$hits[] = $now;
@file_put_contents($rlFile, json_encode($hits), LOCK_EX);

$port = (int)($_GET['port'] ?? 8765);
if ($port < 1 || $port > 65535) {
    $port = 8765;
}

$serverIp = evershelfLocalLanIp();
$parts = explode('.', $serverIp);
if (count($parts) !== 4) {
    echo json_encode(['error' => 'Cannot determine local subnet', 'found' => []]);
    exit;
}
$subnet = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.';

$candidates = [];
for ($i = 1; $i <= 254; $i++) {
    $ip = $subnet . $i;
    $sock = @stream_socket_client(
        "tcp://{$ip}:{$port}", $errno, $errstr, 0,
        STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
    );
    if ($sock !== false) {
        stream_set_blocking($sock, false);
        $candidates[$ip] = $sock;
    }
}

$found_tcp = [];
$deadline = microtime(true) + 1.5;

while (!empty($candidates) && microtime(true) < $deadline) {
    $write  = array_values($candidates);
    $except = array_values($candidates);
    $read   = null;
    $usec   = (int)(max(0, $deadline - microtime(true)) * 1_000_000);
    $n = @stream_select($read, $write, $except, 0, $usec);
    if ($n === false || $n === 0) {
        break;
    }

    $failed = [];
    foreach ($except as $s) {
        $ip = array_search($s, $candidates, true);
        if ($ip !== false) {
            $failed[$ip] = true;
        }
    }
    foreach ($write as $s) {
        $ip = array_search($s, $candidates, true);
        if ($ip === false) {
            continue;
        }
        if (!isset($failed[$ip])) {
            $found_tcp[] = $ip;
        }
        @fclose($s);
        unset($candidates[$ip]);
    }
    foreach ($failed as $ip => $_) {
        if (isset($candidates[$ip])) {
            @fclose($candidates[$ip]);
            unset($candidates[$ip]);
        }
    }
}
foreach ($candidates as $s) {
    @fclose($s);
}

$gateways = [];
foreach ($found_tcp as $ip) {
    $sock = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, 2);
    if (!$sock) {
        continue;
    }
    stream_set_timeout($sock, 2);

    $key = base64_encode(random_bytes(16));
    fwrite($sock,
        "GET / HTTP/1.1\r\n" .
        "Host: {$ip}:{$port}\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Key: {$key}\r\n" .
        "Sec-WebSocket-Version: 13\r\n" .
        "\r\n"
    );

    $resp = '';
    $dl = microtime(true) + 2;
    while (microtime(true) < $dl && !feof($sock)) {
        $line = fgets($sock, 256);
        if ($line === false) {
            break;
        }
        $resp .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    fclose($sock);

    if (str_contains($resp, '101')) {
        $gateways[] = "ws://{$ip}:{$port}";
    }
}

echo json_encode([
    'found'  => $gateways,
    'subnet' => rtrim($subnet, '.') . '.0/24',
]);
