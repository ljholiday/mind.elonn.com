<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * Reasons over material with a self-hosted model through an Ollama chat endpoint
 * and returns free-form text. The in-house counterpart to OpenAiReasoningClient;
 * Ollama has no prompt-cache concept, so cached input tokens are always zero.
 */
final class OllamaReasoningClient implements ReasoningModelClient
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private int $timeoutSeconds,
        private int $outputTokens,
        private ?AiUsageRecorder $usageRecorder = null,
        private string $requesterService = 'conductor.elonn',
        private ?float $inputPricePerMillion = null,
        private ?float $cachedInputPricePerMillion = null,
        private ?float $outputPricePerMillion = null
    ) {
    }

    public function reason(string $material): ReasoningResult
    {
        if (trim($this->baseUrl) === '') {
            throw new ReasoningException('mind.model_unavailable', 'dependency', 'Ollama endpoint is not configured.', 503);
        }

        $startedAt = microtime(true);
        try {
            $response = $this->post([
                'model' => $this->model,
                'stream' => false,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are Mind, the Elonn platform\'s reasoning Service. Reason over the material '
                            . 'you are given and respond with your reasoning result as plain text. Be direct and useful; '
                            . 'do not describe your own process.',
                    ],
                    ['role' => 'user', 'content' => $material],
                ],
                'options' => ['num_predict' => $this->outputTokens],
            ]);
        } catch (ReasoningException $exception) {
            $this->recordUsage([], $startedAt, 'error', $exception->getMessage());
            throw $exception;
        }

        $this->recordUsage($response, $startedAt, 'ok', null);

        $text = $response['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new ReasoningException('mind.model_malformed', 'dependency', 'Ollama Model response did not contain reasoning text.', 503);
        }

        return new ReasoningResult(trim($text), $this->model);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordUsage(array $response, float $startedAt, string $status, ?string $errorMessage): void
    {
        $this->usageRecorder?->record(
            'ollama',
            $this->model,
            $this->requesterService,
            [
                'input_tokens' => (int) ($response['prompt_eval_count'] ?? 0),
                'output_tokens' => (int) ($response['eval_count'] ?? 0),
            ],
            (int) round((microtime(true) - $startedAt) * 1000),
            $status,
            $errorMessage,
            $this->inputPricePerMillion,
            $this->cachedInputPricePerMillion,
            $this->outputPricePerMillion
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new ReasoningException('mind.model_input_invalid', 'invalid_call', 'Unable to encode Ollama request.', 500);
        }

        $response = file_get_contents(rtrim($this->baseUrl, '/') . '/api/chat', false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]));

        if ($response === false) {
            throw new ReasoningException('mind.model_unavailable', 'dependency', 'Ollama Model endpoint request failed.', 503);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ReasoningException('mind.model_unavailable', 'dependency', 'Ollama Model endpoint returned a non-JSON response body.', 503);
        }
        if (isset($decoded['error'])) {
            $message = is_string($decoded['error'] ?? null)
                ? 'Ollama Model endpoint returned an error: ' . $decoded['error']
                : 'Ollama Model endpoint returned an error.';
            throw new ReasoningException('mind.model_unavailable', 'dependency', $message, 503);
        }

        return $decoded;
    }
}
