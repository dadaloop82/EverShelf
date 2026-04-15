<?php
/**
 * EverShelf Scale Gateway — Auto-discovery
 *
 * Scans the server's local /24 subnet for any host responding on the gateway
 * port (default 8765) and confirms it with a WebSocket handshake.
 *
 * Returns: {"found": ["ws://192.168.1.100:8765", ...]}
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$port = (int)($_GET['port'] ?? 8765);
if ($port < 1 || $port > 65535) $port = 8765;

// ── Determine server LAN IP ────────────────────────────────────────────────
// SERVER_ADDR may be 127.0.0.1 when accessed via internal vhost — fall back
// to a UDP trick (no actual packet sent) to find the default-route interface IP.
function localLanIp(): string {
    $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock) {
        @socket_connect($sock, '8.8.8.8', 53);
        @socket_getsockname($sock, $ip);
        socket_close($sock);
        if (isset($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
    }
    // Fallback: parse /proc/net/route for default gateway interface then ip neigh
    $ifaces = @net_get_interfaces();
    if ($ifaces) {
        foreach ($ifaces as $name => $info) {
            if ($name === 'lo') continue;
            foreach ($info['unicast'] ?? [] as $u) {
                $ip = $u['address'] ?? '';
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)) continue;
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
            }
        }
    }
    return '';
}

$serverIp = localLanIp();
$parts = explode('.', $serverIp);
if (count($parts) !== 4) {
    echo json_encode(['error' => 'Cannot determine local subnet', 'server_ip' => $serverIp]);
    exit;
}
$subnet = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.';

// ── Phase 1: Async TCP connect to all 254 hosts ────────────────────────────
// Non-blocking stream_socket_client + stream_select to detect open ports quickly.
// Total scan budget: 1.5 seconds.

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
    if ($n === false || $n === 0) break;

    // Sockets in $except = connection refused/error
    $failed = [];
    foreach ($except as $s) {
        $ip = array_search($s, $candidates, true);
        if ($ip !== false) $failed[$ip] = true;
    }
    // Sockets in $write = connection complete (may overlap with $except on error)
    foreach ($write as $s) {
        $ip = array_search($s, $candidates, true);
        if ($ip === false) continue;
        if (!isset($failed[$ip])) {
            $found_tcp[] = $ip;
        }
        @fclose($s);
        unset($candidates[$ip]);
    }
    // Close failed sockets too
    foreach ($failed as $ip => $_) {
        if (isset($candidates[$ip])) {
            @fclose($candidates[$ip]);
            unset($candidates[$ip]);
        }
    }
}
foreach ($candidates as $s) @fclose($s); // close remaining (timeout)

// ── Phase 2: WebSocket handshake to confirm each TCP responder ─────────────
$gateways = [];
foreach ($found_tcp as $ip) {
    $sock = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, 2);
    if (!$sock) continue;
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
        if ($line === false) break;
        $resp .= $line;
        if ($line === "\r\n") break;
    }
    fclose($sock);

    if (str_contains($resp, '101')) {
        $gateways[] = "ws://{$ip}:{$port}";
    }
}

echo json_encode([
    'found'  => $gateways,
    'subnet' => rtrim($subnet, '.') . '.0/24',
    'server_ip' => $serverIp,
]);
