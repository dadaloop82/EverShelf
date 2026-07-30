<?php
/**
 * AI provider abstraction — Gemini (default) or OpenAI-compatible endpoints
 * (OpenAI, Ollama, vLLM, llama.cpp server, etc.).
 *
 * Callers keep building Gemini-shaped payloads; callGeminiWithFallback() routes
 * and normalizes OpenAI responses back to the Gemini candidates[] shape.
 */

/** @return 'gemini'|'openai' */
function aiProvider(): string {
    $p = strtolower(trim((string)env('AI_PROVIDER', 'gemini')));
    if (in_array($p, ['openai', 'openai_compatible', 'ollama', 'vllm', 'local'], true)) {
        return 'openai';
    }
    return 'gemini';
}

function aiOpenAiBaseUrl(): string {
    $base = rtrim(trim((string)env('OPENAI_BASE_URL', '')), '/');
    if ($base === '') {
        return '';
    }
    // Accept https://host/v1 or https://host
    if (!preg_match('#/v1$#', $base)) {
        $base .= '/v1';
    }
    return $base;
}

function aiOpenAiModel(): string {
    $m = trim((string)env('OPENAI_MODEL', ''));
    return $m !== '' ? $m : 'gpt-4o-mini';
}

function aiIsConfigured(): bool {
    if (aiProvider() === 'openai') {
        return aiOpenAiBaseUrl() !== '';
    }
    return trim((string)env('GEMINI_API_KEY', '')) !== '';
}

/**
 * Credential for legacy gates that still read “API key”.
 * OpenAI-compatible local servers often need no key — return a placeholder.
 */
function aiCredential(): string {
    if (aiProvider() === 'openai') {
        if (!aiIsConfigured()) {
            return '';
        }
        $k = trim((string)env('OPENAI_API_KEY', ''));
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
            'body' => '{"error":{"message":"OPENAI_BASE_URL not configured"}}',
            'data' => ['error' => ['message' => 'OPENAI_BASE_URL not configured']],
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
    // json_object is not supported by all local servers — omit; prompt already asks for JSON

    $url = $base . '/chat/completions';
    $headers = ['Content-Type: application/json'];
    $key = trim((string)env('OPENAI_API_KEY', ''));
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
    if ($raw === false) {
        $raw = json_encode(['error' => ['message' => $err ?: 'curl_failed']]);
        $code = $code ?: 500;
    }
    $data = json_decode($raw, true);
    if ($code === 200) {
        EverLog::aiResponse('openai', strlen($raw), $elapsed, true);
    } else {
        EverLog::aiResponse('openai', strlen($raw), $elapsed, false, "HTTP {$code}: " . substr((string)$raw, 0, 300));
    }

    $text = '';
    if (is_array($data)) {
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            // Some servers return content parts
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

    // Normalize to Gemini-shaped response so existing parsers keep working
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
    ];
}
