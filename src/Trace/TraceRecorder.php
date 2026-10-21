<?php

declare(strict_types=1);

namespace App\Trace;

/**
 * Persists Mind reasoning traces as append-only JSON lines for Admin polling, mirroring
 * conductor.elonn.local's own TraceRecorder so admin.elonn's generic trace viewer (list +
 * "Visual replay" detail) works identically for Mind: GET /metrics/reasoning-traces serves
 * summaries(), GET /metrics/reasoning-trace?trace_id=... serves detail(). Unlike Conductor's
 * trace -- which records Conductor's own orchestration leg and may later gain a second,
 * appended stage from World -- Mind's trace always has exactly one stage, mind.reason, because
 * Mind performs the entire reasoning call itself in one request with no further leg to attach.
 */
final class TraceRecorder
{
    public function __construct(private string $path)
    {
    }

    /**
     * @param array<string, mixed> $call The Call Conductor sent to POST /mind/call.
     * @param array<string, mixed> $dataset The Dataset Mind returned (success or error shape).
     * @param array<string, mixed> $aiTotals AiUsageRecorder::requestTotals() for this request.
     * @return array<string, mixed>
     */
    public function record(array $call, array $dataset, float $startedAt, array $aiTotals = []): array
    {
        $completedAt = microtime(true);
        $traceId = 'trace:mind:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));
        $callContent = is_array($call['content'] ?? null) ? $call['content'] : [];
        $datasetContext = is_array($dataset['context'] ?? null) ? $dataset['context'] : [];
        $errors = is_array($dataset['errors'] ?? null) ? $dataset['errors'] : [];
        $objects = is_array($dataset['objects'] ?? null) ? $dataset['objects'] : [];
        $service = (string) ($datasetContext['service'] ?? 'mind.elonn');
        $operation = (string) ($datasetContext['operation'] ?? ((string) ($callContent['operation'] ?? 'mind.reason')));
        $provider = (string) ($aiTotals['providers'][0] ?? '');
        $model = (string) ($aiTotals['models'][0] ?? '');
        $latencyMs = (int) round(($completedAt - $startedAt) * 1000);
        $createdAt = gmdate('Y-m-d H:i:s', (int) $startedAt);
        $completedAtText = gmdate('Y-m-d H:i:s', (int) $completedAt);

        $record = [
            'id' => $this->nextId(),
            'trace_id' => $traceId,
            'world_call_id' => $this->worldCallId($call),
            'member_id' => $this->memberId($call),
            'runtime' => $this->runtime($call),
            'status' => $errors === [] ? 'ok' : 'error',
            'total_latency_ms' => $latencyMs,
            'model_latency_ms' => (int) ($aiTotals['latency_ms'] ?? 0),
            'service_latency_ms' => $latencyMs,
            'input_tokens' => (int) ($aiTotals['input_tokens'] ?? 0),
            'output_tokens' => (int) ($aiTotals['output_tokens'] ?? 0),
            'total_tokens' => (int) ($aiTotals['total_tokens'] ?? 0),
            'cost_usd' => $aiTotals['cost_usd'] ?? 0,
            'retrieval_rounds' => 1,
            'provider_calls' => (int) ($aiTotals['provider_calls'] ?? 0),
            'service_count' => $service === '' ? 0 : 1,
            'dataset_count' => 1,
            'object_count' => count($objects),
            'object_preserved_count' => 0,
            'retention_percent' => null,
            'stop_reason' => $errors === [] ? 'completed' : 'dataset_error',
            'error_count' => count($errors),
            'max_prompt_size_bytes' => strlen(json_encode($call, JSON_UNESCAPED_SLASHES) ?: ''),
            'services_text' => $this->pipeText([$service]),
            'operations_text' => $this->pipeText([$operation]),
            'providers_text' => $this->pipeText(is_array($aiTotals['providers'] ?? null) ? $aiTotals['providers'] : []),
            'models_text' => $this->pipeText(is_array($aiTotals['models'] ?? null) ? $aiTotals['models'] : []),
            'created_at' => $createdAt,
            'completed_at' => $completedAtText,
            'detail' => [
                'trace_id' => $traceId,
                'service' => 'mind.elonn',
                'stages' => [[
                    'stage_name' => 'mind.reason',
                    'latency_ms' => $latencyMs,
                    'service' => $service,
                    'operation' => $operation,
                    'provider' => $provider,
                    'model' => $model,
                    'input' => $call,
                    'output' => $dataset,
                    'errors' => $errors,
                    'validation' => [
                        'dataset_type' => (string) ($dataset['type'] ?? ''),
                        'source_dataset' => (string) ($dataset['id'] ?? ''),
                    ],
                ]],
            ],
        ];

        $this->append($record);

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summaries(int $sinceId, int $limit): array
    {
        $records = array_filter($this->records(), static fn (array $record): bool => (int) ($record['id'] ?? 0) > $sinceId);
        $records = array_slice(array_values($records), 0, max(1, min(500, $limit)));

        return array_map(static function (array $record): array {
            unset($record['detail']);

            return $record;
        }, $records);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $traceId): ?array
    {
        foreach ($this->records() as $record) {
            if (($record['trace_id'] ?? '') === $traceId) {
                return is_array($record['detail'] ?? null) ? $record['detail'] : null;
            }
        }

        return null;
    }

    public function nextSinceId(): int
    {
        $max = 0;
        foreach ($this->records() as $record) {
            $max = max($max, (int) ($record['id'] ?? 0));
        }

        return $max;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        if ($this->path === '' || !is_file($this->path)) {
            return [];
        }

        $records = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /** @param array<string, mixed> $record */
    private function append(array $record): void
    {
        if ($this->path === '') {
            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($this->path, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function nextId(): int
    {
        return $this->nextSinceId() + 1;
    }

    /**
     * The Call Mind receives is Conductor's own per-Service Call (ServicePlanner::plan()),
     * whose own `id` is a Conductor-generated call id, not the originating World Call's id. The
     * real originating id survives on context.source_call.id (ServicePlanner::serviceContext()).
     *
     * @param array<string, mixed> $call
     */
    private function worldCallId(array $call): ?string
    {
        $context = is_array($call['context'] ?? null) ? $call['context'] : [];
        $sourceCall = is_array($context['source_call'] ?? null) ? $context['source_call'] : [];
        $id = trim((string) ($sourceCall['id'] ?? ($call['id'] ?? '')));

        return $id === '' ? null : $id;
    }

    /** @param array<string, mixed> $call */
    private function memberId(array $call): ?string
    {
        $context = is_array($call['context'] ?? null) ? $call['context'] : [];
        $member = is_array($context['authenticated_member'] ?? null) ? $context['authenticated_member'] : [];
        $id = trim((string) ($member['id'] ?? ''));

        return $id === '' ? null : $id;
    }

    /** @param array<string, mixed> $call */
    private function runtime(array $call): ?string
    {
        $context = is_array($call['context'] ?? null) ? $call['context'] : [];
        $runtime = is_array($context['runtime'] ?? null) ? $context['runtime'] : [];
        $id = trim((string) ($runtime['id'] ?? ''));

        return $id === '' ? null : $id;
    }

    /** @param list<string> $items */
    private function pipeText(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));

        return $items === [] ? '' : '|' . implode('|', $items) . '|';
    }
}
