<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Represents one HTTP request at Mind's public entry point.
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $parsedBody
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers,
        private readonly string $body,
        private readonly array $parsedBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $body = (string) file_get_contents('php://input');
        $parsedBody = [];
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($body !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $parsedBody = $decoded;
            }
        }

        $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders() ?: [], CASE_LOWER) : [];
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (!isset($headers['content-type']) && isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/')),
            $headers,
            $body,
            $parsedBody,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $parsedBody
     */
    public static function testing(string $method, string $path, array $headers = [], string $body = '', array $parsedBody = []): self
    {
        return new self(strtoupper($method), self::normalizePath($path), array_change_key_case($headers, CASE_LOWER), $body, $parsedBody);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, string $default = ''): string
    {
        return (string) ($this->headers[strtolower($name)] ?? $default);
    }

    /** @return array<string, mixed> */
    public function parsedBody(): array
    {
        return $this->parsedBody;
    }

    public function body(): string
    {
        return $this->body;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $normalized = rtrim($path, '/');
        return $normalized === '' ? '/' : $normalized;
    }
}
