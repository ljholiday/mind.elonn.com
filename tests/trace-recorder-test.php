<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Trace\TraceRecorder;

function mind_trace_recorder_temp_path(): string
{
    return sys_get_temp_dir() . '/mind-trace-' . bin2hex(random_bytes(4)) . '.jsonl';
}

/** @return array<string, mixed> */
function mind_trace_recorder_call(): array
{
    return [
        'id' => 'call:conductor:mind:test',
        'content' => ['operation' => 'mind.reason', 'material' => 'What is the capital of France?'],
        'context' => [
            'source_call' => ['id' => 'call:world:test:1', 'boundary' => 'world-conductor'],
            'boundary' => 'conductor-service',
            'authenticated_member' => ['id' => 42],
            'runtime' => ['id' => 'runtime:web:1'],
        ],
    ];
}

/** @return array<string, mixed> */
function mind_trace_recorder_success_dataset(): array
{
    return [
        'id' => 'dataset:service:mind:test',
        'type' => 'service',
        'objects' => [['id' => 'mind.reasoning:test']],
        'errors' => [],
        'context' => ['service' => 'mind', 'operation' => 'mind.reason', 'caller' => 'conductor.elonn'],
    ];
}

/** @return array<string, mixed> */
function mind_trace_recorder_error_dataset(): array
{
    return [
        'id' => 'dataset:service:mind:test',
        'type' => 'service',
        'objects' => [],
        'errors' => [['code' => 'mind.model_unavailable', 'class' => 'dependency', 'message' => 'Model is not configured.']],
        'context' => ['service' => 'mind', 'caller' => 'conductor.elonn', 'owner' => 'conductor'],
    ];
}

/** @return array<string, mixed> */
function mind_trace_recorder_ai_totals(): array
{
    return [
        'input_tokens' => 120,
        'output_tokens' => 40,
        'total_tokens' => 160,
        'cost_usd' => 0.0024,
        'provider_calls' => 1,
        'latency_ms' => 640,
        'providers' => ['openai'],
        'models' => ['gpt-5.6-sol'],
    ];
}

function mind_trace_recorder_records_a_single_mind_reason_stage(): bool
{
    $path = mind_trace_recorder_temp_path();
    $recorder = new TraceRecorder($path);
    $record = $recorder->record(mind_trace_recorder_call(), mind_trace_recorder_success_dataset(), microtime(true) - 0.05, mind_trace_recorder_ai_totals());
    unlink($path);

    $stages = $record['detail']['stages'];

    return $record['id'] === 1
        && $record['status'] === 'ok'
        && $record['world_call_id'] === 'call:world:test:1' // from context.source_call.id, not the Conductor-generated call id
        && $record['member_id'] === '42'
        && $record['runtime'] === 'runtime:web:1'
        && $record['object_count'] === 1
        && $record['total_tokens'] === 160
        && count($stages) === 1
        && $stages[0]['stage_name'] === 'mind.reason'
        && $stages[0]['service'] === 'mind'
        && $stages[0]['operation'] === 'mind.reason'
        && $stages[0]['provider'] === 'openai'
        && $stages[0]['model'] === 'gpt-5.6-sol'
        && $stages[0]['input'] === mind_trace_recorder_call()
        && $stages[0]['output'] === mind_trace_recorder_success_dataset();
}

function mind_trace_recorder_records_an_error_outcome_with_the_calls_own_operation(): bool
{
    $path = mind_trace_recorder_temp_path();
    $recorder = new TraceRecorder($path);
    // The error Dataset's own context carries no 'operation' key (datasetErrorPayload() never
    // sets one) -- operation must fall back to the Call's own content.operation.
    $record = $recorder->record(mind_trace_recorder_call(), mind_trace_recorder_error_dataset(), microtime(true) - 0.01);
    unlink($path);

    return $record['status'] === 'error'
        && $record['error_count'] === 1
        && $record['stop_reason'] === 'dataset_error'
        && $record['object_count'] === 0
        && $record['detail']['stages'][0]['operation'] === 'mind.reason'
        && $record['detail']['stages'][0]['errors'][0]['code'] === 'mind.model_unavailable';
}

function mind_trace_recorder_detail_and_summaries_round_trip(): bool
{
    $path = mind_trace_recorder_temp_path();
    $recorder = new TraceRecorder($path);
    $record = $recorder->record(mind_trace_recorder_call(), mind_trace_recorder_success_dataset(), microtime(true) - 0.02, mind_trace_recorder_ai_totals());

    $detail = $recorder->detail($record['trace_id']);
    $summaries = $recorder->summaries(0, 10);
    $nextSinceId = $recorder->nextSinceId();
    unlink($path);

    return $detail !== null
        && $detail['stages'][0]['stage_name'] === 'mind.reason'
        && count($summaries) === 1
        && !array_key_exists('detail', $summaries[0])
        && $summaries[0]['trace_id'] === $record['trace_id']
        && $nextSinceId === 1;
}

function mind_trace_recorder_is_a_noop_without_a_configured_path(): bool
{
    $recorder = new TraceRecorder('');
    $record = $recorder->record(mind_trace_recorder_call(), mind_trace_recorder_success_dataset(), microtime(true));

    return $record['trace_id'] !== '' && $recorder->summaries(0, 10) === [] && $recorder->detail($record['trace_id']) === null;
}

$checks = [
    'record() captures exactly one mind.reason stage with provider/model from aiTotals' => mind_trace_recorder_records_a_single_mind_reason_stage(),
    'record() captures an error outcome, falling back to the Call\'s own operation' => mind_trace_recorder_records_an_error_outcome_with_the_calls_own_operation(),
    'detail() and summaries() round-trip a recorded trace' => mind_trace_recorder_detail_and_summaries_round_trip(),
    'an unconfigured path is a safe no-op, not a failure' => mind_trace_recorder_is_a_noop_without_a_configured_path(),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
