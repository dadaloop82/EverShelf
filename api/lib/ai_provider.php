<?php
/**
 * AI provider abstraction — exclusive providers:
 *   gemini | openai (cloud) | llama (local/remote OpenAI-compatible)
 * Master switch: AI_ENABLED (false → all AI features off).
 *
 * Callers keep building Gemini-shaped payloads; callGeminiWithFallback() routes
 * openai/llama through /v1/chat/completions and normalizes to Gemini shape.
 */

function aiIsEnabled(): bool {
    $v = strtolower(trim((string)env('AI_ENABLED', 'true')));
    return !in_array($v, ['0', 'false', 'off', 'no'], true);
}

/** @return 'gemini'|'openai'|'llama' */
function aiProvider(): string {
    $p = strtolower(trim((string)env('AI_PROVIDER', 'gemini')));
    // Legacy aliases → openai / llama
    if (in_array($p, ['openai_compatible'], true)) {
        return 'openai';
    }
    if (in_array($p, ['ollama', 'vllm', 'local', 'llama.cpp', 'llamacpp'], true)) {
        return 'llama';
    }
    if (in_array($p, ['openai', 'llama', 'gemini'], true)) {
        return $p;
    }
    return 'gemini';
}

/** True when the active provider speaks OpenAI chat-completions protocol. */
function aiUsesOpenAiProtocol(): bool {
    return in_array(aiProvider(), ['openai', 'llama'], true);
}

function aiNormalizeBaseUrl(string $base): string {
    $base = rtrim(trim($base), '/');
    if ($base === '') {
        return '';
    }
    if (!preg_match('#/v1$#', $base)) {
        $base .= '/v1';
    }
    return $base;
}

function aiOpenAiBaseUrl(): string {
    if (aiProvider() === 'llama') {
        return aiNormalizeBaseUrl((string)env('LLAMA_BASE_URL', env('OPENAI_BASE_URL', '')));
    }
    if (aiProvider() === 'openai') {
        $base = trim((string)env('OPENAI_BASE_URL', ''));
        if ($base === '') {
            $base = 'https://api.openai.com/v1';
        }
        return aiNormalizeBaseUrl($base);
    }
    return '';
}

function aiOpenAiModel(): string {
    if (aiProvider() === 'llama') {
        $m = trim((string)env('LLAMA_MODEL', env('OPENAI_MODEL', '')));
        return $m !== '' ? $m : 'llama3.2';
    }
    if (aiProvider() === 'openai') {
        $m = trim((string)env('OPENAI_MODEL', ''));
        return $m !== '' ? $m : 'gpt-4o-mini';
    }
    return '';
}

function aiOpenAiApiKey(): string {
    if (aiProvider() === 'llama') {
        return trim((string)env('LLAMA_API_KEY', env('OPENAI_API_KEY', '')));
    }
    return trim((string)env('OPENAI_API_KEY', ''));
}

/**
 * Provider credentials are present (ignores AI_ENABLED).
 */
function aiProviderConfigured(): bool {
    $p = aiProvider();
    if ($p === 'openai') {
        // Cloud OpenAI needs an API key; base URL has a default
        return aiOpenAiApiKey() !== '';
    }
    if ($p === 'llama') {
        return aiOpenAiBaseUrl() !== '';
    }
    return trim((string)env('GEMINI_API_KEY', '')) !== '';
}

/** Enabled + credentials OK → AI features may run. */
function aiIsConfigured(): bool {
    return aiIsEnabled() && aiProviderConfigured();
}

/**
 * Credential for legacy gates that still read “API key”.
 * Llama local servers often need no key — return a placeholder when URL is set.
 */
function aiCredential(): string {
    if (!aiIsEnabled()) {
        return '';
    }
    if (aiUsesOpenAiProtocol()) {
        if (!aiProviderConfigured()) {
            return '';
        }
        $k = aiOpenAiApiKey();
        return $k !== '' ? $k : 'no-key';
    }
    return trim((string)env('GEMINI_API_KEY', ''));
}

/** Convert Gemini generateContent payload → OpenAI chat messages. */
function aiGeminiPayloadToOpenAiMessages(array $payload): array {
    $messages = [];
    $sys = $payload['systemInstruction']['parts'][0]['text']
        ?? $payload['system_instruction']['parts'][0]['text']
        ?? null;
    if (is_string($sys) && $sys !== '') {
        $messages[] = ['role' => 'system', 'content' => $sys];
    }
    $wantJson = (($payload['generationConfig']['responseMimeType'] ?? '') === 'application/json');
    if ($wantJson) {
        $messages[] = [
            'role' => 'system',
            'content' => 'Respond with valid JSON only. No markdown fences, no commentary.',
        ];
    }

    foreach ($payload['contents'] ?? [] as $content) {
        if (!is_array($content)) {
            continue;
        }
        $role = (($content['role'] ?? 'user') === 'model') ? 'assistant' : 'user';
        $partsOut = [];
        foreach ($content['parts'] ?? [] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $partsOut[] = ['type' => 'text', 'text' => $part['text']];
            } elseif (!empty($part['inline_data']['data'])) {
                $mime = $part['inline_data']['mime_type'] ?? 'image/jpeg';
                $data = $part['inline_data']['data'];
                $partsOut[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:' . $mime . ';base64,' . $data],
                ];
            }
        }
        if (!$partsOut) {
            continue;
        }
        if (count($partsOut) === 1 && ($partsOut[0]['type'] ?? '') === 'text') {
            $messages[] = ['role' => $role, 'content' => $partsOut[0]['text']];
        } else {
            $messages[] = ['role' => $role, 'content' => $partsOut];
        }
    }
    return $messages;
}

function aiOpenAiChatCompletions(array $geminiPayload, int $timeout = 60): array {
    $base = aiOpenAiBaseUrl();
    if ($base === '') {
        return [
            'http_code' => 400,
            'body' => '{"error":{"message":"Base URL not configured"}}',
            'data' => ['error' => ['message' => 'Base URL not configured']],
            'tokens_in' => 0,
            'tokens_out' => 0,
        ];
    }
    $messages = aiGeminiPayloadToOpenAiMessages($geminiPayload);
    $gen = $geminiPayload['generationConfig'] ?? [];
    $body = [
        'model' => aiOpenAiModel(),
        'messages' => $messages,
        'temperature' => isset($gen['temperature']) ? (float)$gen['temperature'] : 0.7,
    ];
    if (!empty($gen['maxOutputTokens'])) {
        $body['max_tokens'] = (int)$gen['maxOutputTokens'];
    }

    $url = $base . '/chat/completions';
    $headers = ['Content-Type: application/json'];
    $key = aiOpenAiApiKey();
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $elapsed = microtime(true) - $t0;
    $label = aiProvider();
    if ($raw === false) {
        $raw = json_encode(['error' => ['message' => $err ?: 'curl_failed']]);
        $code = $code ?: 500;
    }
    $data = json_decode($raw, true);
    if ($code === 200) {
        EverLog::aiResponse($label, strlen($raw), $elapsed, true);
    } else {
        EverLog::aiResponse($label, strlen($raw), $elapsed, false, "HTTP {$code}: " . substr((string)$raw, 0, 300));
    }

    $text = '';
    if (is_array($data)) {
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            foreach ($content as $p) {
                if (is_string($p)) {
                    $text .= $p;
                } elseif (is_array($p) && isset($p['text'])) {
                    $text .= (string)$p['text'];
                }
            }
        }
    }
    $tokIn = (int)($data['usage']['prompt_tokens'] ?? 0);
    $tokOut = (int)($data['usage']['completion_tokens'] ?? 0);

    $normalized = [
        'candidates' => [
            [
                'content' => [
                    'parts' => [['text' => $text]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ],
        ],
        'usageMetadata' => [
            'promptTokenCount' => $tokIn,
            'candidatesTokenCount' => $tokOut,
            'totalTokenCount' => $tokIn + $tokOut,
        ],
    ];
    if ($code !== 200 && is_array($data)) {
        $normalized['error'] = $data['error'] ?? ['message' => substr((string)$raw, 0, 300)];
    }

    return [
        'http_code' => $code,
        'body' => $raw ?: '',
        'data' => $normalized,
        'tokens_in' => $tokIn,
        'tokens_out' => $tokOut,
        'latency_ms' => (int)round($elapsed * 1000),
    ];
}

/**
 * Lightweight connectivity test for the active provider.
 * @return array{success:bool,provider:string,latency_ms:int,reply?:string,error?:string}
 */
function aiRunConnectionTest(int $timeout = 25): array {
    $provider = aiProvider();
    if (!aiIsEnabled()) {
        return ['success' => false, 'provider' => $provider, 'latency_ms' => 0, 'error' => 'ai_disabled'];
    }
    if (!aiProviderConfigured()) {
        return ['success' => false, 'provider' => $provider, 'latency_ms' => 0, 'error' => 'not_configured'];
    }

    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => 'Reply with exactly this single word and nothing else: PONG']],
        ]],
        'generationConfig' => [
            'temperature' => 0,
            'maxOutputTokens' => 16,
        ],
    ];

    $t0 = microtime(true);
    if (aiUsesOpenAiProtocol()) {
        $result = aiOpenAiChatCompletions($payload, $timeout);
        $latency = (int)($result['latency_ms'] ?? round((microtime(true) - $t0) * 1000));
    } else {
        $key = trim((string)env('GEMINI_API_KEY', ''));
        $models = function_exists('geminiModelChain') ? geminiModelChain('lite') : ['gemini-2.5-flash-lite'];
        $result = ['http_code' => 0, 'data' => null, 'body' => ''];
        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
            if (function_exists('callGemini')) {
                $result = callGemini($url, function_exists('geminiEnsureNoThinking') ? geminiEnsureNoThinking($payload) : $payload, $timeout);
            } else {
                break;
            }
            if (($result['http_code'] ?? 0) === 200) {
                break;
            }
            if (function_exists('geminiModelUnavailable') && geminiModelUnavailable((int)$result['http_code'], $result['data'] ?? null)) {
                continue;
            }
            break;
        }
        $latency = (int)round((microtime(true) - $t0) * 1000);
    }

    $text = trim((string)($result['data']['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if (($result['http_code'] ?? 0) === 200 && $text !== '') {
        return [
            'success' => true,
            'provider' => $provider,
            'latency_ms' => $latency,
            'reply' => mb_substr($text, 0, 120),
            'model' => aiUsesOpenAiProtocol() ? aiOpenAiModel() : 'gemini',
        ];
    }

    $err = $result['data']['error']['message'] ?? substr((string)($result['body'] ?? ''), 0, 200);
    return [
        'success' => false,
        'provider' => $provider,
        'latency_ms' => $latency,
        'error' => $err !== '' ? $err : ('HTTP ' . ($result['http_code'] ?? 0)),
    ];
}
