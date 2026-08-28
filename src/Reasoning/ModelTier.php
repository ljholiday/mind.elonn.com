<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * The model tier a reasoning Call runs on. Heavy lifting uses Frontier; a Call
 * may request Cheap or InHouse via content.tier when an operator enables them.
 */
enum ModelTier: string
{
    case Frontier = 'frontier';
    case Cheap = 'cheap';
    case InHouse = 'in_house';

    public function key(): string
    {
        return $this->value;
    }

    /**
     * Resolve a caller-supplied tier name, defaulting to Frontier for anything
     * missing or unrecognized so a bad hint never fails a Call.
     */
    public static function fromRequest(mixed $tier): self
    {
        return self::tryFrom(strtolower(trim((string) $tier))) ?? self::Frontier;
    }
}
