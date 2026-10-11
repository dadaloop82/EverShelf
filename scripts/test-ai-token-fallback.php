#!/usr/bin/env php
<?php
/** Regression tests for OpenAI token-limit parameter compatibility. */
require_once __DIR__ . '/../api/lib/ai_provider.php';

$cases = [
    [
        'max_tokens',
        "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.",
        'max_completion_tokens',
    ],
    [
        'max_completion_tokens',
        "Unsupported parameter: 'max_completion_tokens' is not supported with this model. Use 'max_tokens' instead.",
        'max_tokens',
    ],
    ['max_tokens', "Unsupported parameter: 'temperature' is not supported.", null],
    ['max_tokens', 'Invalid request', null],
];

$temperatureCases = [
    [
        "Unsupported value: 'temperature' does not support 0 with this model. Only the default (1) value is supported.",
        true,
    ],
    ["Unsupported parameter: 'temperature' is not supported.", false],
    ['Invalid request', false],
];

$failures = 0;
foreach ($cases as [$param, $message, $expected]) {
    $actual = aiOpenAiTokenLimitFallback($param, $message);
    if ($actual !== $expected) {
        echo "FAIL: {$param}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
        $failures++;
    }
}
foreach ($temperatureCases as [$message, $expected]) {
    $actual = aiOpenAiRequiresDefaultTemperature($message);
    if ($actual !== $expected) {
        echo 'FAIL: temperature fallback: expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
        $failures++;
    }
}

if ($failures > 0) {
    exit(1);
}
echo "All AI token fallback tests passed\n";
