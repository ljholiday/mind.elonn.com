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
        'timeout_seconds' => mind_int_config('MODEL_TIMEOUT_SECONDS', 20),
        'output_tokens' => mind_int_config('MODEL_OUTPUT_TOKENS', 600),
        'openai' => [
            'responses_url' => mind_string_config('OPENAI_RESPONSES_URL'),
            'api_key' => mind_string_config('OPENAI_API_KEY'),
        ],
        'ollama' => [
            'url' => rtrim(mind_string_config('OLLAMA_URL', 'http://127.0.0.1:11434'), '/'),
        ],
        // Three model tiers. Only frontier is enabled by default; cheap / in_house
        // are opt-in for mundane reasoning. A Call may request a tier via content.tier.
        'tiers' => [
            'frontier' => mind_model_tier_config('FRONTIER', true),
            'cheap' => mind_model_tier_config('CHEAP', false),
            'in_house' => mind_model_tier_config('IN_HOUSE', false),
        ],
    ],
    'ai_usage' => [
        'path' => mind_path_config('MIND_AI_USAGE_PATH', dirname(__DIR__) . '/storage/ai-usage/mind-ai-usage.jsonl'),
        'max_records' => mind_int_config('MIND_AI_USAGE_MAX_RECORDS', 5000),
    ],
    'maintenance' => [
        // Mind's first real Admin Plane control capability (see ELONN.md, Admin <-> Models):
        // a single-file administrative flag, consistent with Mind's existing file-based
        // persistence (ai_usage above) rather than adding a database Mind does not otherwise
        // have. Toggled only through POST /administrative-interface/maintenance.
        'path' => mind_path_config('MIND_MAINTENANCE_PATH', dirname(__DIR__) . '/storage/maintenance.json'),
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
 * One model tier's configuration. The frontier tier falls back to the legacy
 * MODEL_PROVIDER / MODEL_NAME keys so an existing deployment's .env keeps working
 * unchanged (frontier = whatever single model it configured before).
 *
 * @return array{enabled:bool, provider:string, name:string, input_price_per_million:?float,
 *     cached_input_price_per_million:?float, output_price_per_million:?float}
 */
function mind_model_tier_config(string $tier, bool $enabledDefault): array
{
    $prefix = 'MODEL_' . $tier . '_';
    $providerFallback = $tier === 'FRONTIER' ? strtolower(mind_string_config('MODEL_PROVIDER')) : '';
    $nameFallback = $tier === 'FRONTIER' ? mind_string_config('MODEL_NAME', 'gpt-5.6') : '';

    return [
        'enabled' => mind_bool_config($prefix . 'ENABLED', $enabledDefault),
        'provider' => strtolower(mind_string_config($prefix . 'PROVIDER', $providerFallback)),
        'name' => mind_string_config($prefix . 'NAME', $nameFallback),
        'input_price_per_million' => mind_price_config($prefix . 'INPUT_PRICE_PER_MILLION'),
        'cached_input_price_per_million' => mind_price_config($prefix . 'CACHED_INPUT_PRICE_PER_MILLION'),
        'output_price_per_million' => mind_price_config($prefix . 'OUTPUT_PRICE_PER_MILLION'),
    ];
}

/**
 * A single model price in USD per 1,000,000 tokens. Deployment-specific, so nothing
 * is committed: an unset or non-numeric value is null, and usage events then record
 * token counts with cost_usd left null.
 */
function mind_price_config(string $key): ?float
{
    $value = mind_string_config($key);

    return is_numeric($value) ? (float) $value : null;
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
