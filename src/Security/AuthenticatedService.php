<?php

declare(strict_types=1);

namespace App\Security;

/**
 * First-party service caller accepted at Mind's internal boundary.
 */
final class AuthenticatedService
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $memberId,
    ) {
    }

    public function owner(): string
    {
        return $this->memberId === null ? 'service:' . $this->name : 'member:' . $this->memberId;
    }
}
