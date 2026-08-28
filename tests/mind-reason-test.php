<?php

declare(strict_types=1);

use App\Application;
use App\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array<string, mixed> */
function mind_test_config(): array
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
                'cheap' => ['enabled' => false, 'provider' => 'openai', 'name' => 'gpt-5.6-luna', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
                'in_house' => ['enabled' => false, 'provider' => 'ollama', 'name' => '', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
            ],
        ],
        'ai_usage' => ['path' => '', 'max_records' => 5000],
        'service_auth' => [
            'admin.elonn' => 'admin-token',
            'conductor_keys_url' => '',
        ],
    ];
}

function mind_test_headers(): array
{
    return ['x-elonn-service' => 'admin.elonn', 'authorization' => 'Bearer admin-token'];
}

$app = new Application(mind_test_config());

/** @param array<string, mixed> $body */
function mind_call(Application $app, array $headers, array $body): \App\Http\Response
{
    return $app->handleRequest(Request::testing('POST', '/mind/call', $headers, json_encode($body) ?: '', $body));
}

$unauthenticated = mind_call($app, [], ['content' => ['operation' => 'mind.reason', 'material' => 'hi']]);

$missingOperation = mind_call($app, mind_test_headers(), ['content' => []]);

$unsupportedOperation = mind_call($app, mind_test_headers(), ['content' => ['operation' => 'mind.unknown']]);

$missingMaterial = mind_call($app, mind_test_headers(), ['content' => ['operation' => 'mind.reason', 'material' => '']]);

$modelUnavailable = mind_call($app, mind_test_headers(), ['content' => ['operation' => 'mind.reason', 'material' => 'What is the capital of France?']]);
$modelUnavailableBody = json_decode($modelUnavailable->body(), true);

// A caller-supplied tier -- known-but-disabled, or unrecognized -- must not change the
// outcome here: it falls back to frontier, which is itself unconfigured in this test.
$disabledTier = mind_call($app, mind_test_headers(), ['content' => ['operation' => 'mind.reason', 'material' => 'hi', 'tier' => 'cheap']]);
$bogusTier = mind_call($app, mind_test_headers(), ['content' => ['operation' => 'mind.reason', 'material' => 'hi', 'tier' => 'nonsense']]);

$checks = [
    'Unauthenticated caller is rejected' => $unauthenticated->status() === 401
        && (json_decode($unauthenticated->body(), true)['errors'][0]['code'] ?? '') === 'mind.service_auth_failed',
    'Missing operation is rejected' => $missingOperation->status() === 400
        && (json_decode($missingOperation->body(), true)['errors'][0]['code'] ?? '') === 'mind.operation_required',
    'Unsupported operation is rejected' => $unsupportedOperation->status() === 422
        && (json_decode($unsupportedOperation->body(), true)['errors'][0]['code'] ?? '') === 'mind.unsupported_operation',
    'Empty material is rejected' => $missingMaterial->status() === 422
        && (json_decode($missingMaterial->body(), true)['errors'][0]['code'] ?? '') === 'mind.material_required',
    'Unconfigured Model provider fails closed with a real dependency error, not a fake result' =>
        $modelUnavailable->status() === 503
        && ($modelUnavailableBody['errors'][0]['code'] ?? '') === 'mind.model_unavailable'
        && ($modelUnavailableBody['objects'] ?? []) === [],
    'A disabled content.tier falls back to frontier (same fail-closed outcome here)' =>
        $disabledTier->status() === 503
        && (json_decode($disabledTier->body(), true)['errors'][0]['code'] ?? '') === 'mind.model_unavailable',
    'An unrecognized content.tier is treated as frontier, never an error' =>
        $bogusTier->status() === 503
        && (json_decode($bogusTier->body(), true)['errors'][0]['code'] ?? '') === 'mind.model_unavailable',
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
