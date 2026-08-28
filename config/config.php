<?php

declare(strict_types=1);

$environment = mind_string_config('APP_ENV', 'local');

return [
    'app' => [
        'environment' => $environment,
        'debug' => mind_bool_config('APP_DEBUG', false),
        'url' => mind_service_url('APP_URL', $environment, 'https://mind.elonn.local', 'https://mind.elonn.com'),
    ],
    'model' => [
        'provider' => strtolower(mind_string_config('MODEL_PROVIDER')),
        'name' => mind_string_config('MODEL_NAME', 'gpt-5.6'),
        'timeout_seconds' => mind_int_config('MODEL_TIMEOUT_SECONDS', 20),
        'output_tokens' => mind_int_config('MODEL_OUTPUT_TOKENS', 600),
        'pricing' => mind_pricing_config(),
        'openai' => [
            'responses_url' => mind_string_config('OPENAI_RESPONSES_URL'),
            'api_key' => mind_string_config('OPENAI_API_KEY'),
        ],
    ],
    'ai_usage' => [
        'path' => mind_path_config('MIND_AI_USAGE_PATH', dirname(__DIR__) . '/storage/ai-usage/mind-ai-usage.jsonl'),
        'max_records' => mind_int_config('MIND_AI_USAGE_MAX_RECORDS', 5000),
    ],
    'service_auth' => [
        'admin.elonn' => mind_string_config('ELONN_ADMIN_MIND_TOKEN'),
        'conductor_keys_url' => rtrim(
            mind_string_config(
                'CONDUCTOR_SERVICE_KEYS_URL',
                mind_peer_url('conductor', $environment) . '/.well-known/elonn-service-keys.json'
            ),
            '/'
        ),
    ],
];

function mind_string_config(string $key, string $default = ''): string
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    return trim((string) $value);
}

function mind_path_config(string $key, string $default): string
{
    $value = mind_string_config($key, $default);
    if ($value === '' || str_starts_with($value, '/')) {
        return $value;
    }

    return dirname(__DIR__) . '/' . ltrim($value, '/');
}

/**
 * Per-(provider, model) Model prices in USD per 1,000,000 tokens, used to turn the
 * token counts captured on each reasoning provider call into a cost_usd figure on
 * the AI usage event stream. Rates are deployment-specific operational data, so
 * nothing is committed: without MODEL_PRICING_JSON, events still record token
 * counts and cost_usd is null. One JSON object (provider -> model ->
 * {input_per_million, output_per_million}) so a dotted model id never becomes an env key.
 *
 * @return array<string, array<string, array{input_per_million: float, output_per_million: float}>>
 */
function mind_pricing_config(): array
{
    $decoded = json_decode(mind_string_config('MODEL_PRICING_JSON', ''), true);
    if (!is_array($decoded)) {
        return [];
    }

    $pricing = [];
    foreach ($decoded as $provider => $models) {
        if (!is_array($models)) {
            continue;
        }
        foreach ($models as $model => $rates) {
            if (!is_array($rates)) {
                continue;
            }
            $pricing[(string) $provider][(string) $model] = [
                'input_per_million' => (float) ($rates['input_per_million'] ?? 0),
                'output_per_million' => (float) ($rates['output_per_million'] ?? 0),
            ];
        }
    }

    return $pricing;
}

function mind_int_config(string $key, int $default): int
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    if (!is_numeric($value)) {
        return $default;
    }

    return max(1, (int) $value);
}

function mind_bool_config(string $key, bool $default): bool
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL);
}

function mind_service_url(string $key, string $environment, string $localDefault, string $productionDefault): string
{
    $default = in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true)
        ? $localDefault
        : $productionDefault;

    return rtrim(mind_string_config($key, $default), '/');
}

/**
 * Every Service on this platform follows one naming rule: <name>.elonn.local or <name>.elonn.com.
 * A compliant peer's address is therefore always derivable from this Service's own environment
 * classification -- never a separately hand-typed URL literal to keep in sync per Service.
 */
function mind_peer_url(string $peerName, string $environment): string
{
    $local = in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true);

    return 'https://' . $peerName . '.elonn.' . ($local ? 'local' : 'com');
}
