<?php

declare(strict_types=1);

namespace App\Reasoning;

final class ReasoningResult
{
    public function __construct(
        public readonly string $response,
        public readonly string $model,
    ) {
    }
}
