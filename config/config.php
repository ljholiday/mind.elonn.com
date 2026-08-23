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
        'openai' => [
            'responses_url' => mind_string_config('OPENAI_RESPONSES_URL'),
            'api_key' => mind_string_config('OPENAI_API_KEY'),
        ],
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
