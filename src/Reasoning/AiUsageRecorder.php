<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * Persists one append-only JSON line per Model provider call so admin.elonn's
 * Operations Center can mirror it through GET /metrics/ai-usage. Token counts
 * are captured from the provider response; cost_usd is derived from a
 * per-(provider, model) price table, or left null when no rate is configured.
 */
final class AiUsageRecorder
{
    /**
     * @param array<string, array<string, array{input_per_million: float, output_per_million: float}>> $pricing
     *        USD per 1,000,000 tokens, keyed provider -> model.
     * @param int $maxRecords Newest N events kept on disk; 0 disables pruning.
     */
    public function __construct(
        private string $path,
        private array $pricing = [],
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
        ?string $errorMessage
    ): void {
        $inputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

        $this->append([
            'id' => $this->nextId(),
            'provider' => $provider,
            'model' => $model,
            'requester_service' => $requesterService,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $this->cost($provider, $model, $inputTokens, $outputTokens),
            'latency_ms' => $latencyMs,
            'status' => $status,
            'error_message' => $errorMessage,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
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

    private function cost(string $provider, string $model, int $inputTokens, int $outputTokens): ?float
    {
        $rates = $this->pricing[$provider][$model] ?? null;
        if (!is_array($rates)) {
            return null;
        }

        $inputPerMillion = (float) ($rates['input_per_million'] ?? 0);
        $outputPerMillion = (float) ($rates['output_per_million'] ?? 0);

        return round(
            $inputTokens / 1_000_000 * $inputPerMillion + $outputTokens / 1_000_000 * $outputPerMillion,
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

        file_put_contents($this->path, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
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
