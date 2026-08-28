<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * Resolves a ReasoningModelClient for a requested tier. A tier is used only when
 * it is enabled and fully configured; otherwise the request falls back to the
 * frontier tier, and if that too cannot resolve, to the fail-closed
 * UnavailableReasoningClient.
 */
final class ModelRouter
{
    /** @param array<string, mixed> $config The 'model' config array. */
    public function __construct(
        private array $config,
        private ?AiUsageRecorder $usageRecorder = null
    ) {
    }

    public function clientFor(ModelTier $tier, string $requesterService = 'conductor.elonn'): ReasoningModelClient
    {
        return $this->tierClient($tier, $requesterService)
            ?? ($tier === ModelTier::Frontier ? null : $this->tierClient(ModelTier::Frontier, $requesterService))
            ?? new UnavailableReasoningClient();
    }

    /**
     * @return array<string, string> tier key => 'active' | 'disabled' | 'unconfigured'
     */
    public function status(): array
    {
        $status = [];
        foreach (ModelTier::cases() as $tier) {
            $config = $this->tierConfig($tier);
            if (!($config['enabled'] ?? false)) {
                $status[$tier->key()] = 'disabled';
                continue;
            }
            $status[$tier->key()] = $this->tierClient($tier, 'conductor.elonn') === null ? 'unconfigured' : 'active';
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function tierConfig(ModelTier $tier): array
    {
        $tiers = (array) ($this->config['tiers'] ?? []);

        return (array) ($tiers[$tier->key()] ?? []);
    }

    private function tierClient(ModelTier $tier, string $requesterService): ?ReasoningModelClient
    {
        $config = $this->tierConfig($tier);
        if (!($config['enabled'] ?? false)) {
            return null;
        }

        $name = trim((string) ($config['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $timeout = (int) ($this->config['timeout_seconds'] ?? 20);
        $outputTokens = (int) ($this->config['output_tokens'] ?? 600);
        $inputPrice = $config['input_price_per_million'] ?? null;
        $cachedInputPrice = $config['cached_input_price_per_million'] ?? null;
        $outputPrice = $config['output_price_per_million'] ?? null;

        return match ((string) ($config['provider'] ?? '')) {
            'openai' => $this->openAiClient($name, $timeout, $outputTokens, $requesterService, $inputPrice, $cachedInputPrice, $outputPrice),
            'ollama' => new OllamaReasoningClient(
                (string) (((array) ($this->config['ollama'] ?? []))['url'] ?? 'http://127.0.0.1:11434'),
                $name,
                $timeout,
                $outputTokens,
                $this->usageRecorder,
                $requesterService,
                $inputPrice,
                $cachedInputPrice,
                $outputPrice
            ),
            default => null,
        };
    }

    private function openAiClient(
        string $name,
        int $timeout,
        int $outputTokens,
        string $requesterService,
        ?float $inputPrice,
        ?float $cachedInputPrice,
        ?float $outputPrice
    ): ?ReasoningModelClient {
        $openai = (array) ($this->config['openai'] ?? []);
        $responsesUrl = trim((string) ($openai['responses_url'] ?? ''));
        $apiKey = trim((string) ($openai['api_key'] ?? ''));
        if ($responsesUrl === '' || $apiKey === '') {
            return null;
        }

        return new OpenAiReasoningClient(
            $responsesUrl,
            $apiKey,
            $name,
            $timeout,
            $outputTokens,
            $this->usageRecorder,
            $requesterService,
            $inputPrice,
            $cachedInputPrice,
            $outputPrice
        );
    }
}
