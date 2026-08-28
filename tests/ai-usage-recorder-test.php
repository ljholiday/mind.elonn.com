<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application;
use App\Http\Request;
use App\Reasoning\AiUsageRecorder;

function mind_ai_usage_temp_path(): string
{
    return sys_get_temp_dir() . '/mind-ai-usage-' . bin2hex(random_bytes(4)) . '.jsonl';
}

function mind_ai_usage_records_cost_from_the_tier_rates(): bool
{
    $path = mind_ai_usage_temp_path();
    $recorder = new AiUsageRecorder($path);

    // GPT-5.6 Sol rates: 4.00 / 0.40 cached / 20.00 per 1M.
    $recorder->record('openai', 'gpt-5.6-sol', 'conductor.elonn', [
        'input_tokens' => 1_000_000,
        'output_tokens' => 500_000,
        'total_tokens' => 1_500_000,
    ], 640, 'ok', null, 4.00, 0.40, 20.00);

    $events = $recorder->summaries(0, 10);
    unlink($path);

    // 1M @ 4.00 + 0.5M @ 20.00 = 14.00
    return count($events) === 1
        && $events[0]['id'] === 1
        && $events[0]['requester_service'] === 'conductor.elonn'
        && $events[0]['cost_usd'] === 14.00
        && $events[0]['cached_input_tokens'] === 0
        && $events[0]['status'] === 'ok';
}

function mind_ai_usage_prices_cached_input_separately(): bool
{
    $path = mind_ai_usage_temp_path();
    $recorder = new AiUsageRecorder($path);

    $recorder->record('openai', 'gpt-5.6-sol', 'conductor.elonn', [
        'input_tokens' => 1_000_000,
        'output_tokens' => 0,
        'input_tokens_details' => ['cached_tokens' => 400_000],
    ], 100, 'ok', null, 4.00, 0.40, 20.00);

    $events = $recorder->summaries(0, 10);
    unlink($path);

    // 0.6M @ 4.00 + 0.4M @ 0.40 = 2.56
    return $events[0]['cached_input_tokens'] === 400_000 && $events[0]['cost_usd'] === 2.56;
}

function mind_ai_usage_leaves_cost_null_without_a_rate(): bool
{
    $path = mind_ai_usage_temp_path();
    $recorder = new AiUsageRecorder($path);
    $recorder->record('ollama', 'llama3.1', 'conductor.elonn', ['input_tokens' => 10, 'output_tokens' => 20], 5, 'ok', null);
    $events = $recorder->summaries(0, 10);
    unlink($path);

    return count($events) === 1 && $events[0]['cost_usd'] === null && $events[0]['total_tokens'] === 30;
}

function mind_ai_usage_pages_and_prunes(): bool
{
    $path = mind_ai_usage_temp_path();
    $recorder = new AiUsageRecorder($path, 3);
    for ($i = 0; $i < 6; $i++) {
        $recorder->record('openai', 'm', 'conductor.elonn', ['input_tokens' => 1, 'output_tokens' => 1], 1, 'ok', null);
    }

    $afterFour = $recorder->summaries(4, 100);
    $onDisk = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $nextSince = $recorder->nextSinceId();
    unlink($path);

    return array_map(static fn (array $e): int => $e['id'], $afterFour) === [5, 6]
        && count($onDisk) === 3
        && $nextSince === 6;
}

/** @return array<string, mixed> */
function mind_ai_usage_test_config(): array
{
    return [
        'app' => ['environment' => 'testing', 'debug' => false, 'url' => 'https://mind.elonn.local'],
        'model' => [
            'timeout_seconds' => 20,
            'output_tokens' => 600,
            'openai' => ['responses_url' => '', 'api_key' => ''],
            'ollama' => ['url' => 'http://127.0.0.1:11434'],
            'tiers' => [
                'frontier' => ['enabled' => true, 'provider' => 'openai', 'name' => 'gpt-5.6-sol', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
                'cheap' => ['enabled' => false, 'provider' => 'openai', 'name' => '', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
                'in_house' => ['enabled' => false, 'provider' => 'ollama', 'name' => '', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
            ],
        ],
        'ai_usage' => ['path' => '', 'max_records' => 5000],
        'service_auth' => ['admin.elonn' => 'admin-token', 'conductor_keys_url' => ''],
    ];
}

function mind_ai_usage_endpoint_requires_admin_auth(): bool
{
    $app = new Application(mind_ai_usage_test_config());

    $unauthenticated = $app->handleRequest(Request::testing('GET', '/metrics/ai-usage', []));
    $authenticated = $app->handleRequest(Request::testing('GET', '/metrics/ai-usage', [
        'x-elonn-service' => 'admin.elonn',
        'authorization' => 'Bearer admin-token',
    ]));
    $body = json_decode($authenticated->body(), true);

    return $unauthenticated->status() === 401
        && (json_decode($unauthenticated->body(), true)['errors'][0]['code'] ?? '') === 'mind.service_auth_failed'
        && $authenticated->status() === 200
        && ($body['events'] ?? null) === []
        && ($body['next_since_id'] ?? null) === 0;
}

$checks = [
    'records cost_usd from the per-tier rates passed to record()' => mind_ai_usage_records_cost_from_the_tier_rates(),
    'prices cached input tokens at their own rate' => mind_ai_usage_prices_cached_input_separately(),
    'leaves cost_usd null when no rate is supplied' => mind_ai_usage_leaves_cost_null_without_a_rate(),
    'summaries() pages from the cursor and the file is pruned to max_records' => mind_ai_usage_pages_and_prunes(),
    'GET /metrics/ai-usage requires an authenticated admin.elonn caller' => mind_ai_usage_endpoint_requires_admin_auth(),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
