<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * Persists one append-only JSON line per Model provider call so admin.elonn's
 * Operations Center can mirror it through GET /metrics/ai-usage. Token counts are
 * captured from the provider response; cost_usd is computed from the per-tier
 * rates the caller passes in (USD per 1,000,000 tokens), cached input priced
 * separately, and left null when no rate is supplied. The current request's
 * events are also kept in memory so TraceRecorder can backfill the same totals
 * onto the reasoning trace it records for the same /mind/call request.
 */
final class AiUsageRecorder
{
    /** @var list<array<string, mixed>> Events recorded during the current request. */
    private array $events = [];

    /**
     * @param int $maxRecords Newest N events kept on disk; 0 disables pruning.
     */
    public function __construct(
        private string $path,
        private int $maxRecords = 5000
    ) {
    }

    /**
     * @param array<string, mixed> $usage Provider usage block (OpenAI Responses shape).
     */
    public function record(
        string $provider,
        string $model,
        string $requesterService,
        array $usage,
        int $latencyMs,
        string $status,
        ?string $errorMessage,
        ?float $inputPricePerMillion = null,
        ?float $cachedInputPricePerMillion = null,
        ?float $outputPricePerMillion = null
    ): void {
        $inputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));
        $cachedInputTokens = (int) (
            $usage['input_tokens_details']['cached_tokens']
            ?? $usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['cached_input_tokens']
            ?? 0
        );

        $record = [
            'id' => $this->nextId(),
            'provider' => $provider,
            'model' => $model,
            'requester_service' => $requesterService,
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $this->cost(
                $inputTokens,
                $cachedInputTokens,
                $outputTokens,
                $inputPricePerMillion,
                $cachedInputPricePerMillion,
                $outputPricePerMillion
            ),
            'latency_ms' => $latencyMs,
            'status' => $status,
            'error_message' => $errorMessage,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        $this->events[] = $record;
        $this->append($record);
    }

    /**
     * Aggregate of every event recorded this request, for TraceRecorder backfill.
     *
     * @return array{input_tokens:int, cached_input_tokens:int, output_tokens:int, total_tokens:int,
     *     cost_usd:float|null, provider_calls:int, latency_ms:int, providers:list<string>, models:list<string>}
     */
    public function requestTotals(): array
    {
        $inputTokens = 0;
        $cachedInputTokens = 0;
        $outputTokens = 0;
        $totalTokens = 0;
        $latencyMs = 0;
        $cost = 0.0;
        $hasCost = false;
        $providers = [];
        $models = [];

        foreach ($this->events as $event) {
            $inputTokens += (int) $event['input_tokens'];
            $cachedInputTokens += (int) ($event['cached_input_tokens'] ?? 0);
            $outputTokens += (int) $event['output_tokens'];
            $totalTokens += (int) $event['total_tokens'];
            $latencyMs += (int) $event['latency_ms'];
            if ($event['cost_usd'] !== null) {
                $cost += (float) $event['cost_usd'];
                $hasCost = true;
            }
            if ((string) $event['provider'] !== '') {
                $providers[(string) $event['provider']] = true;
            }
            if ((string) $event['model'] !== '') {
                $models[(string) $event['model']] = true;
            }
        }

        return [
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $hasCost ? round($cost, 6) : null,
            'provider_calls' => count($this->events),
            'latency_ms' => $latencyMs,
            'providers' => array_keys($providers),
            'models' => array_keys($models),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summaries(int $sinceId, int $limit): array
    {
        $records = array_filter($this->records(), static fn (array $record): bool => (int) ($record['id'] ?? 0) > $sinceId);

        return array_slice(array_values($records), 0, max(1, min(500, $limit)));
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
     * cost_usd from the tier's rates. Null only when no rate at all was supplied
     * (token counts still recorded). Cached input is billed at its own rate,
     * falling back to the uncached input rate when only that is set.
     */
    private function cost(
        int $inputTokens,
        int $cachedInputTokens,
        int $outputTokens,
        ?float $inputPricePerMillion,
        ?float $cachedInputPricePerMillion,
        ?float $outputPricePerMillion
    ): ?float {
        if ($inputPricePerMillion === null && $cachedInputPricePerMillion === null && $outputPricePerMillion === null) {
            return null;
        }

        $cachedInputTokens = max(0, min($cachedInputTokens, $inputTokens));
        $uncachedInputTokens = $inputTokens - $cachedInputTokens;
        $inputRate = $inputPricePerMillion ?? 0.0;
        $cachedRate = $cachedInputPricePerMillion ?? $inputRate;
        $outputRate = $outputPricePerMillion ?? 0.0;

        return round(
            $uncachedInputTokens / 1_000_000 * $inputRate
            + $cachedInputTokens / 1_000_000 * $cachedRate
            + $outputTokens / 1_000_000 * $outputRate,
            6
        );
    }

    private function nextId(): int
    {
        return $this->nextSinceId() + 1;
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

        // JSON_PRESERVE_ZERO_FRACTION keeps a whole-dollar cost_usd (e.g. 14.0) a float on read-back
        // rather than decoding as an int.
        file_put_contents(
            $this->path,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        $this->prune();
    }

    /**
     * Keep only the newest $maxRecords lines. Ids keep climbing across a prune,
     * so the Admin poll cursor stays monotonic.
     */
    private function prune(): void
    {
        if ($this->maxRecords <= 0 || $this->path === '' || !is_file($this->path)) {
            return;
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) <= $this->maxRecords) {
            return;
        }

        file_put_contents(
            $this->path,
            implode(PHP_EOL, array_slice($lines, -$this->maxRecords)) . PHP_EOL,
            LOCK_EX
        );
    }
}
