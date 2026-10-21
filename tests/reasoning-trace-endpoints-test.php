<?php

declare(strict_types=1);

use App\Application;
use App\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

function mind_trace_endpoints_temp_path(): string
{
    return sys_get_temp_dir() . '/mind-traces-' . bin2hex(random_bytes(4)) . '.jsonl';
}

/** @return array<string, mixed> */
function mind_trace_endpoints_config(string $tracePath): array
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
        'tracing' => ['path' => $tracePath],
        'service_auth' => ['admin.elonn' => 'admin-token', 'conductor_keys_url' => ''],
    ];
}

function mind_trace_endpoints_admin_headers(): array
{
    return ['x-elonn-service' => 'admin.elonn', 'authorization' => 'Bearer admin-token'];
}

/** @param array<string, mixed> $body */
function mind_trace_endpoints_call(Application $app, array $body): \App\Http\Response
{
    return $app->handleRequest(Request::testing('POST', '/mind/call', mind_trace_endpoints_admin_headers(), json_encode($body) ?: '', $body));
}

function mind_trace_endpoints_reasoning_trace_endpoints_require_admin_auth(): bool
{
    $app = new Application(mind_trace_endpoints_config(''));

    $unauthenticated = $app->handleRequest(Request::testing('GET', '/metrics/reasoning-traces', []));
    $unauthenticatedDetail = $app->handleRequest(Request::testing('GET', '/metrics/reasoning-trace', []));

    return $unauthenticated->status() === 401
        && (json_decode($unauthenticated->body(), true)['errors'][0]['code'] ?? '') === 'mind.service_auth_failed'
        && $unauthenticatedDetail->status() === 401;
}

function mind_trace_endpoints_a_rejected_mind_call_is_still_traced_and_retrievable(): bool
{
    $path = mind_trace_endpoints_temp_path();
    $app = new Application(mind_trace_endpoints_config($path));

    // Frontier is "enabled" in config but has no real OpenAI credentials, so this fails closed
    // with mind.model_unavailable -- exactly the real-world case admin.elonn's Mind trace page
    // showed "Full trace detail could not be loaded from Mind" for, because the routes below
    // did not exist at all before this change.
    $callResponse = mind_trace_endpoints_call($app, ['content' => ['operation' => 'mind.reason', 'material' => 'What is the capital of France?']]);

    $listResponse = $app->handleRequest(Request::testing('GET', '/metrics/reasoning-traces', mind_trace_endpoints_admin_headers()));
    $listBody = json_decode($listResponse->body(), true);
    $traceId = (string) ($listBody['traces'][0]['trace_id'] ?? '');

    // The real route handler reads trace_id from $_GET directly (same pattern /metrics/ai-usage
    // already uses for since_id/limit) rather than through Request, which has no query-string
    // parsing of its own -- Request::testing()'s $path is matched verbatim against the route
    // table, so a query string belongs on $_GET, not appended to $path.
    $_GET['trace_id'] = $traceId;
    $detailResponse = $app->handleRequest(Request::testing('GET', '/metrics/reasoning-trace', mind_trace_endpoints_admin_headers()));
    unset($_GET['trace_id']);
    $detailBody = json_decode($detailResponse->body(), true);

    unlink($path);

    return $callResponse->status() === 503
        && $listResponse->status() === 200
        && count($listBody['traces'] ?? []) === 1
        && ($listBody['traces'][0]['status'] ?? '') === 'error'
        && !array_key_exists('detail', $listBody['traces'][0])
        && $traceId !== ''
        && $detailResponse->status() === 200
        && count($detailBody['stages'] ?? []) === 1
        && ($detailBody['stages'][0]['stage_name'] ?? '') === 'mind.reason'
        && ($detailBody['stages'][0]['errors'][0]['code'] ?? '') === 'mind.model_unavailable';
}

function mind_trace_endpoints_unknown_trace_id_404s(): bool
{
    $path = mind_trace_endpoints_temp_path();
    $app = new Application(mind_trace_endpoints_config($path));

    $_GET['trace_id'] = 'trace:mind:does-not-exist';
    $response = $app->handleRequest(Request::testing('GET', '/metrics/reasoning-trace', mind_trace_endpoints_admin_headers()));
    unset($_GET['trace_id']);
    is_file($path) && unlink($path);

    return $response->status() === 404
        && (json_decode($response->body(), true)['errors'][0]['code'] ?? '') === 'mind.trace_not_found';
}

function mind_trace_endpoints_an_unauthenticated_mind_call_is_not_traced(): bool
{
    $path = mind_trace_endpoints_temp_path();
    $app = new Application(mind_trace_endpoints_config($path));

    $app->handleRequest(Request::testing('POST', '/mind/call', [], '', ['content' => ['operation' => 'mind.reason', 'material' => 'hi']]));
    $wasCreated = is_file($path);
    is_file($path) && unlink($path);

    return !$wasCreated;
}

$checks = [
    'GET /metrics/reasoning-traces and /metrics/reasoning-trace both require an authenticated admin.elonn caller' => mind_trace_endpoints_reasoning_trace_endpoints_require_admin_auth(),
    'A rejected /mind/call is still traced, and the trace is retrievable through the list and detail endpoints' => mind_trace_endpoints_a_rejected_mind_call_is_still_traced_and_retrievable(),
    'GET /metrics/reasoning-trace with an unknown trace_id returns mind.trace_not_found' => mind_trace_endpoints_unknown_trace_id_404s(),
    'An unauthenticated /mind/call is rejected before any trace is written' => mind_trace_endpoints_an_unauthenticated_mind_call_is_not_traced(),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
