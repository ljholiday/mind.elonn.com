<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * Calls an OpenAI Responses-compatible endpoint to reason over material and
 * return free-form text. Same transport shape as Conductor's own Model client,
 * without structured JSON schema output -- Mind's reasoning is prose, not a
 * selection decision.
 */
final class OpenAiReasoningClient implements ReasoningModelClient
{
    public function __construct(
        private string $responsesUrl,
        private string $apiKey,
        private string $model,
        private int $timeoutSeconds,
        private int $outputTokens,
        private ?AiUsageRecorder $usageRecorder = null,
        private string $requesterService = 'conductor.elonn'
    ) {
    }

    public function reason(string $material): ReasoningResult
    {
        if ($this->apiKey === '') {
            throw new ReasoningException('mind.model_unavailable', 'dependency', 'OpenAI API key is not configured.', 503);
        }
        if (trim($this->responsesUrl) === '') {
            throw new ReasoningException('mind.model_unavailable', 'dependency', 'OpenAI Responses endpoint is not configured.', 503);
        }

        $startedAt = microtime(true);
        try {
            $response = $this->post([
                'model' => $this->model,
                'input' => [
                    [
                        'role' => 'system',
                        'content' => 'You are Mind, the Elonn platform\'s reasoning Service. Reason over the material '
                            . 'you are given and respond with your reasoning result as plain text. Be direct and useful; '
                            . 'do not describe your own process.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $material,
                    ],
                ],
                'max_output_tokens' => $this->outputTokens,
            ]);
        } catch (ReasoningException $exception) {
            $this->recordUsage([], $startedAt, 'error', $exception->getMessage());
            throw $exception;
        }

        $this->recordUsage(
            is_array($response['usage'] ?? null) ? $response['usage'] : [],
            $startedAt,
            'ok',
            null
        );

        return new ReasoningResult($this->outputText($response), $this->model);
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function recordUsage(array $usage, float $startedAt, string $status, ?string $errorMessage): void
    {
        $this->usageRecorder?->record(
            'openai',
            $this->model,
            $this->requesterService,
            $usage,
            (int) round((microtime(true) - $startedAt) * 1000),
            $status,
            $errorMessage
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
            throw new ReasoningException('mind.model_input_invalid', 'invalid_call', 'Unable to encode OpenAI request.', 500);
        }

        $response = file_get_contents($this->responsesUrl, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ]) . "\r\n",
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]));
        $headers = $http_response_header ?? [];
        $statusCode = $this->httpStatusCode($headers);

        if ($response === false) {
            throw new ReasoningException('mind.model_unavailable', 'dependency', $this->transportErrorMessage($statusCode), 503);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ReasoningException('mind.model_unavailable', 'dependency', $this->nonJsonErrorMessage($statusCode), 503);
        }
        if (isset($decoded['error'])) {
            $message = is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)
                ? 'OpenAI Model endpoint returned an error: ' . $decoded['error']['message']
                : 'OpenAI Model endpoint returned an error.';
            throw new ReasoningException('mind.model_unavailable', 'dependency', $message, 503);
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     */
    private function httpStatusCode(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function transportErrorMessage(?int $statusCode): string
    {
        return $statusCode === null
            ? 'OpenAI Model endpoint request failed.'
            : 'OpenAI Model endpoint request failed with HTTP ' . $statusCode . '.';
    }

    private function nonJsonErrorMessage(?int $statusCode): string
    {
        $status = $statusCode === null ? '' : ' HTTP ' . $statusCode;
        return 'OpenAI Model endpoint returned a non-JSON' . $status . ' response body.';
    }

    /**
     * @param array<string, mixed> $response
     */
    private function outputText(array $response): string
    {
        if (is_string($response['output_text'] ?? null) && trim($response['output_text']) !== '') {
            return trim($response['output_text']);
        }

        foreach ((array) ($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (is_array($content) && is_string($content['text'] ?? null) && trim($content['text']) !== '') {
                    return trim($content['text']);
                }
            }
        }

        throw new ReasoningException('mind.model_malformed', 'dependency', 'OpenAI Model response did not contain reasoning text.', 503);
    }
}
