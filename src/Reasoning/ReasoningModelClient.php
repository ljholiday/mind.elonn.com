<?php

declare(strict_types=1);

namespace App\Reasoning;

/**
 * Reasons over material and returns free-form text. Mind's own choice of model,
 * provider, and prompting is an implementation detail behind this interface --
 * consumers of Mind's Contract never see it.
 */
interface ReasoningModelClient
{
    /**
     * @throws ReasoningException
     */
    public function reason(string $material): ReasoningResult;
}
