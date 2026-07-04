<?php
/**
 * EverShelf — Mealie discovery, health checks, and optional Docker auto-install.
 */

function mealieRandomSecret(int $length = 24): string {
    return bin2hex(random_bytes((int)ceil($length / 2)));
}

/** Writable by www-data (data/) — not docker/ which is root-owned. */
function mealieWorkDir(): string {
    $dir = EVERSHELF_ROOT . '/data/mealie';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return '';
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0775);
    }
    return is_writable($dir) ? $dir : '';
}

function mealieComposePath(): string {
    $dir = mealieWorkDir();
    return $dir !== '' ? $dir . '/docker-compose.yml' : '';
}

function mealieDockerAvailable(): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    foreach (['/usr/bin/docker', '/usr/local/bin/docker', 'docker'] as $bin) {
        $out = [];
        @exec($bin . ' info --format "{{.ServerVersion}}" 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out[0])) {
            $cached = true;
            return true;
        }
    }
    $cached = false;
    return false;
}

function mealieDockerBin(): string {
    foreach (['/usr/bin/docker', '/usr/local/bin/docker', 'docker'] as $bin) {
        $out = [];
        @exec($bin . ' info --format "{{.ServerVersion}}" 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out[0])) {
            return $bin;
        }
    }
    return 'docker';
}

function mealieDockerComposeCmd(): ?string {
    $docker = mealieDockerBin();
    foreach ([$docker . ' compose', 'docker compose', '/usr/bin/docker compose', 'docker-compose', '/usr/bin/docker-compose'] as $cmd) {
        $out = [];
        @exec($cmd . ' version --short 2>/dev/null', $out, $code);
        if ($code === 0) {
            return $cmd;
        }
    }
    return null;
}

/** @return list<string> host URLs to probe */
function mealieDiscoveryCandidates(): array {
    $candidates = [];

    if (mealieConfigured()) {
        $candidates[] = mealieBaseUrl();
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: '127.0.0.1';
    if ($host === 'localhost') {
        $host = '127.0.0.1';
    }

    foreach ([9925, 9000, 8081] as $port) {
        $candidates[] = "http://127.0.0.1:{$port}";
        if ($host !== '127.0.0.1') {
            $candidates[] = "http://{$host}:{$port}";
        }
    }

    if (mealieDockerAvailable()) {
        $out = [];
        @exec('docker ps --filter name=mealie --format "{{.Ports}}" 2>/dev/null', $out, $code);
        if ($code === 0) {
            foreach ($out as $line) {
                if (preg_match('/0\.0\.0\.0:(\d+)->/', $line, $m)) {
                    $candidates[] = 'http://127.0.0.1:' . $m[1];
                }
            }
        }
    }

    $normalized = [];
    foreach ($candidates as $url) {
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !in_array($url, $normalized, true)) {
            $normalized[] = $url;
        }
    }
    return $normalized;
}

function mealieProbeUrl(string $url, float $timeout = 4.0): array {
    $base = rtrim(trim($url), '/');
    if ($base === '') {
        return ['ok' => false, 'url' => $url, 'error' => 'empty_url'];
    }
    $aboutUrl = $base . '/api/app/about';
    $ch = curl_init($aboutUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(2.0, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code === 0) {
        return ['ok' => false, 'url' => $base, 'error' => $err ?: 'unreachable'];
    }
    if ($code !== 200) {
        return ['ok' => false, 'url' => $base, 'error' => 'http_' . $code, 'http_code' => $code];
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'url' => $base, 'error' => 'invalid_json'];
    }
    return [
        'ok'           => true,
        'url'          => $base,
        'version'      => $data['version'] ?? null,
        'demo'         => !empty($data['demo']),
        'production'   => !empty($data['production']),
    ];
}

function mealieDiscover(): array {
    $instances = [];
    foreach (mealieDiscoveryCandidates() as $url) {
        $probe = mealieProbeUrl($url);
        if ($probe['ok']) {
            $key = $probe['url'];
            $instances[$key] = $probe;
        }
    }

    $configuredReachable = false;
    if (mealieConfigured()) {
        $configuredReachable = mealieProbeUrl(mealieBaseUrl())['ok'] ?? false;
    }

    $composePath = mealieComposePath();
    $legacyCompose = EVERSHELF_ROOT . '/docker/docker-compose.mealie.yml';
    $containerRunning = false;
    if (mealieDockerAvailable()) {
        $out = [];
        @exec('docker ps --filter name=evershelf-mealie --format "{{.Status}}" 2>/dev/null', $out, $code);
        $containerRunning = ($code === 0 && !empty($out[0]));
    }

    return [
        'success'              => true,
        'docker_available'     => mealieDockerAvailable(),
        'docker_compose'       => mealieDockerComposeCmd() !== null,
        'configured'           => mealieConfigured(),
        'configured_reachable' => $configuredReachable,
        'token_set'            => !empty(trim(env('MEALIE_API_TOKEN', ''))),
        'instances'            => array_values($instances),
        'compose_file'         => ($composePath !== '' && file_exists($composePath)) || file_exists($legacyCompose),
        'compose_writable'     => mealieWorkDir() !== '',
        'container_running'    => $containerRunning,
        'installable'          => mealieDockerAvailable()
            && mealieDockerComposeCmd() !== null
            && mealieWorkDir() !== ''
            && !$configuredReachable,
    ];
}

function mealieWriteEnvKeys(array $updates): array {
    if (empty($updates)) {
        return ['success' => false, 'error' => 'nothing_to_write'];
    }
    $envFile = EVERSHELF_ROOT . '/.env';
    $vars = loadEnv();
    foreach ($updates as $key => $val) {
        $vars[(string)$key] = (string)$val;
    }
    $lines = [];
    foreach ($vars as $key => $val) {
        $lines[] = "{$key}={$val}";
    }
    $payload = implode("\n", $lines) . "\n";
    $keys = array_keys($updates);

    if (is_writable($envFile) || (!file_exists($envFile) && is_writable(dirname($envFile)))) {
        if (file_put_contents($envFile, $payload, LOCK_EX) !== false) {
            clearEnvOverrides($keys);
            return ['success' => true, 'stored' => 'env'];
        }
    }

    if (saveEnvOverrides($updates)) {
        return ['success' => true, 'stored' => 'database'];
    }

    return ['success' => false, 'error' => 'env_write_failed'];
}

function mealieWaitForReady(string $baseUrl, int $maxSeconds = 120): bool {
    $deadline = time() + $maxSeconds;
    while (time() < $deadline) {
        $probe = mealieProbeUrl($baseUrl, 3.0);
        if ($probe['ok']) {
            return true;
        }
        sleep(2);
    }
    return false;
}

function mealieLoginAccessToken(string $baseUrl, string $email, string $password): array {
    $attempts = [
        [$email, $password],
        ['changeme@example.com', 'MyPassword'],
        ['changeme@example.org', 'MyPassword'],
    ];
    $seen = [];
    $tokenUrl = rtrim($baseUrl, '/') . '/api/auth/token';
    foreach ($attempts as [$user, $pass]) {
        if ($user === '' || $pass === '') {
            continue;
        }
        $key = $user . "\0" . $pass;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['username' => $user, 'password' => $pass]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            continue;
        }
        $data = json_decode((string)$body, true);
        $access = $data['access_token'] ?? '';
        if ($access === '') {
            continue;
        }
        return [
            'success'      => true,
            'access_token' => $access,
            'login_email'  => $user,
            'used_default' => ($user !== $email),
        ];
    }
    return ['success' => false, 'error' => 'login_failed'];
}

function mealieObtainApiToken(string $baseUrl, string $email, string $password): array {
    $login = mealieLoginAccessToken($baseUrl, $email, $password);
    if (!$login['success']) {
        return $login;
    }
    $access = $login['access_token'];

    $apiTokUrl = rtrim($baseUrl, '/') . '/api/users/api-tokens';
    $ch = curl_init($apiTokUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['name' => 'EverShelf']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $access,
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || ($code !== 200 && $code !== 201)) {
        return ['success' => false, 'error' => 'api_token_failed', 'http_code' => $code];
    }
    $tokData = json_decode((string)$body, true);
    $apiToken = $tokData['token'] ?? $tokData['api_token'] ?? '';
    if ($apiToken === '') {
        return ['success' => false, 'error' => 'empty_api_token'];
    }
    return [
        'success'      => true,
        'token'        => $apiToken,
        'login_email'  => $login['login_email'] ?? $email,
        'used_default' => !empty($login['used_default']),
    ];
}

function mealieConfigureFromInstance(string $url, ?string $email = null, ?string $password = null): array {
    $probe = mealieProbeUrl($url);
    if (!$probe['ok']) {
        return ['success' => false, 'error' => 'instance_unreachable', 'detail' => $probe];
    }
    $base = $probe['url'];

    $token = trim(env('MEALIE_API_TOKEN', ''));
    if ($token === '') {
        $tok = mealieObtainApiToken(
            $base,
            $email ?? '',
            $password ?? ''
        );
        if (!$tok['success']) {
            return ['success' => false, 'error' => $tok['error'] ?? 'token_failed', 'url' => $base];
        }
        $token = $tok['token'];
    }

    $saved = mealieWriteEnvKeys([
        'MEALIE_URL'        => $base,
        'MEALIE_API_TOKEN'  => $token,
        'MEALIE_OFFLINE'    => env('MEALIE_OFFLINE', 'auto') ?: 'auto',
        'RECIPE_SOURCE'     => env('RECIPE_SOURCE', 'auto') ?: 'auto',
    ]);
    if (!$saved['success']) {
        return $saved;
    }

    return [
        'success' => true,
        'url'     => $base,
        'token'   => $token,
        'stored'  => $saved['stored'] ?? 'env',
    ];
}

function mealieInstall(array $opts = []): array {
    @set_time_limit(600);
    @ignore_user_abort(true);
    if (!mealieDockerAvailable()) {
        return ['success' => false, 'error' => 'docker_not_available'];
    }
    $composeCmd = mealieDockerComposeCmd();
    if ($composeCmd === null) {
        return ['success' => false, 'error' => 'docker_compose_not_available'];
    }

    $discover = mealieDiscover();
    if (!empty($discover['instances'])) {
        $first = $discover['instances'][0];
        $configured = mealieConfigureFromInstance($first['url']);
        if ($configured['success']) {
            return [
                'success'  => true,
                'action'   => 'configured_existing',
                'url'      => $configured['url'],
                'token'    => $configured['token'] ?? null,
                'stored'   => $configured['stored'] ?? null,
            ];
        }
    }

    $port = (int)($opts['port'] ?? 9925);
    if ($port < 1024 || $port > 65535) {
        $port = 9925;
    }
    $baseUrl = 'http://127.0.0.1:' . $port;
    $email = trim((string)($opts['email'] ?? 'admin@mealie.local'));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'admin@mealie.local';
    }
    $password = mealieRandomSecret(16);

    $composePath = mealieComposePath();
    if ($composePath === '') {
        return ['success' => false, 'error' => 'docker_dir_not_writable'];
    }
    $compose = <<<YAML
services:
  mealie:
    image: ghcr.io/mealie-recipes/mealie:latest
    container_name: evershelf-mealie
    restart: unless-stopped
    ports:
      - "{$port}:9000"
    volumes:
      - evershelf_mealie_data:/app/data
    environment:
      ALLOW_SIGNUP: "false"
      PUID: "1000"
      PGID: "1000"
      TZ: "Europe/Rome"
      BASE_URL: "{$baseUrl}"
      DEFAULT_EMAIL: "{$email}"
      DEFAULT_PASSWORD: "{$password}"
      API_DOCS: "true"

volumes:
  evershelf_mealie_data:
    driver: local
YAML;

    $written = @file_put_contents($composePath, $compose, LOCK_EX);
    if ($written === false) {
        return [
            'success' => false,
            'error'   => 'compose_write_failed',
            'path'    => $composePath,
            'detail'  => 'data/mealie not writable by web server user',
        ];
    }
    @chmod($composePath, 0664);

    $cmd = $composeCmd . ' -f ' . escapeshellarg($composePath) . ' up -d 2>&1';
    $out = [];
    @exec($cmd, $out, $code);
    if ($code !== 0) {
        return ['success' => false, 'error' => 'docker_up_failed', 'detail' => implode("\n", $out)];
    }

    if (!mealieWaitForReady($baseUrl, 120)) {
        return ['success' => false, 'error' => 'mealie_start_timeout', 'url' => $baseUrl];
    }

    $tokenResult = null;
    for ($i = 0; $i < 8; $i++) {
        $tokenResult = mealieObtainApiToken($baseUrl, $email, $password);
        if ($tokenResult['success']) {
            break;
        }
        sleep(3);
    }
    if (!$tokenResult || !$tokenResult['success']) {
        return [
            'success'  => false,
            'error'    => $tokenResult['error'] ?? 'token_failed',
            'url'      => $baseUrl,
            'email'    => $email,
            'password' => $password,
        ];
    }

    $saved = mealieWriteEnvKeys([
        'MEALIE_URL'       => $baseUrl,
        'MEALIE_API_TOKEN' => $tokenResult['token'],
        'MEALIE_OFFLINE'   => 'auto',
        'RECIPE_SOURCE'    => 'auto',
    ]);
    if (!$saved['success']) {
        return [
            'success'  => false,
            'error'    => 'env_write_failed',
            'url'      => $baseUrl,
            'email'    => $email,
            'password' => $password,
            'token'    => $tokenResult['token'],
        ];
    }

    return [
        'success'        => true,
        'action'         => 'installed',
        'url'            => $baseUrl,
        'email'          => $email,
        'password'       => $password,
        'token'          => $tokenResult['token'],
        'stored'         => $saved['stored'] ?? 'env',
        'port'           => $port,
        'mealie_login'   => $tokenResult['login_email'] ?? $email,
        'mealie_web_pass'=> !empty($tokenResult['used_default']) ? 'MyPassword' : $password,
        'used_default_login' => !empty($tokenResult['used_default']),
    ];
}

function mealieSetupStatus(): array {
    $discover = mealieDiscover();
    return array_merge(mealieStatus(), $discover);
}
