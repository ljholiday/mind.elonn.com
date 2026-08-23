<?php

declare(strict_types=1);

namespace App;

final class ServiceDescriptor
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'service' => 'mind',
            'description' => 'General-purpose reasoning over material supplied in a Call. Interprets natural language, '
                . 'synthesizes information, and produces a canonical Dataset -- Mind does not orchestrate other Services '
                . 'itself; Conductor decides when Mind participates.',
            'supports' => ['reasoning', 'synthesis', 'interpretation', 'summarization', 'general question answering'],
            'returns' => ['objects' => ['Mind Reasoning Result']],
            'cost' => 'medium',
            'side_effects' => [],
            'operations' => [
                'mind.reason' => [
                    'description' => 'Reason over the material supplied in the Call and return the reasoning result as a canonical Dataset.',
                    'supports' => ['general reasoning', 'synthesis', 'interpretation', 'summarization', 'question answering'],
                    'returns' => ['objects' => ['Mind Reasoning Result']],
                    'cost' => 'medium',
                    'side_effects' => [],
                    'required' => ['material' => 'non_empty_string'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'material' => ['type' => 'string'],
                        ],
                        'required' => ['material'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }
}
