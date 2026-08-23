<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Small method/path router for Mind's service routes.
 */
final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> */
    private array $routes = [];

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$this->normalizePath($path)] = $handler;
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$this->normalizePath($path)] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method()][$this->normalizePath($request->path())] ?? null;
        if ($handler === null) {
            return Response::json(['error' => 'Not found.'], 404);
        }

        return $handler($request);
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $normalized = rtrim($path, '/');
        return $normalized === '' ? '/' : $normalized;
    }
}
