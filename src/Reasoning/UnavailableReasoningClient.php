<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * Used when no reasoning Model provider is configured. Fails closed with a
 * clear dependency error instead of silently returning a fake result.
 */
final class UnavailableReasoningClient implements ReasoningModelClient
{
    public function reason(string $material): ReasoningResult
    {
        throw new ReasoningException(
            'mind.model_unavailable',
            'dependency',
            'No reasoning Model provider is configured for Mind.',
            503
        );
    }
}
