<?php

declare(strict_types=1);

namespace App\Reasoning;

use RuntimeException;

/**
 * Represents a reasoning-model dependency failure without exposing credentials.
 */
final class ReasoningException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $errorClass,
        string $message,
        public readonly int $httpStatus = 503,
    ) {
        parent::__construct($message);
    }
}
