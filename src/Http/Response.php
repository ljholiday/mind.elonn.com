<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Emits HTTP responses for Mind routes.
 */
final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self(
            $status,
            $encoded === false ? '{}' : $encoded,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }
}
