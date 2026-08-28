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

function mind_ai_usage_records_cost_from_the_configured_rate_table(): bool
{
    $path = mind_ai_usage_temp_path();
    $recorder = new AiUsageRecorder($path, [
        'openai' => ['gpt-5.6' => ['input_per_million' => 1.25, 'output_per_million' => 10.0]],
    ]);

    $recorder->record('openai', 'gpt-5.6', 'conductor.elonn', [
        'input_tokens' => 1_000_000,
        'output_tokens' => 500_000,
        'total_tokens' => 1_500_000,
    ], 640, 'ok', null);

    $events = $recorder->summaries(0, 10);
    unlink($path);

    return count($events) === 1
        && $events[0]['id'] === 1
        && $events[0]['requester_service'] === 'conductor.elonn'
        && $events[0]['cost_usd'] === 6.25
        && $events[0]['status'] === 'ok';
}

function mind_ai_usage_leaves_cost_null_without_a_rate(): bool
{
    $path = mind_ai_usage_temp_path();
    $recorder = new AiUsageRecorder($path, []);
    $recorder->record('openai', 'gpt-5.6', 'conductor.elonn', ['input_tokens' => 10, 'output_tokens' => 20], 5, 'ok', null);
    $events = $recorder->summaries(0, 10);
    unlink($path);

    return count($events) === 1 && $events[0]['cost_usd'] === null && $events[0]['total_tokens'] === 30;
}

function mind_ai_usage_pages_and_prunes(): bool
{
    $path = mind_ai_usage_temp_path();
    $recorder = new AiUsageRecorder($path, [], 3);
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
            'provider' => '',
            'name' => 'gpt-5.6',
            'timeout_seconds' => 20,
            'output_tokens' => 600,
            'pricing' => [],
            'openai' => ['responses_url' => '', 'api_key' => ''],
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
    'records cost_usd from the configured per-model rate table' => mind_ai_usage_records_cost_from_the_configured_rate_table(),
    'leaves cost_usd null when no rate is configured' => mind_ai_usage_leaves_cost_null_without_a_rate(),
    'summaries() pages from the cursor and the file is pruned to max_records' => mind_ai_usage_pages_and_prunes(),
    'GET /metrics/ai-usage requires an authenticated admin.elonn caller' => mind_ai_usage_endpoint_requires_admin_auth(),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
