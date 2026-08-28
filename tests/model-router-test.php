<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Reasoning\ModelRouter;
use App\Reasoning\ModelTier;
use App\Reasoning\OllamaReasoningClient;
use App\Reasoning\OpenAiReasoningClient;
use App\Reasoning\UnavailableReasoningClient;

/**
 * @param array<string, array<string, mixed>> $tiers
 * @return array<string, mixed>
 */
function mind_router_config(array $tiers, bool $openAiCreds = true): array
{
    return [
        'timeout_seconds' => 20,
        'output_tokens' => 600,
        'openai' => [
            'responses_url' => $openAiCreds ? 'https://api.openai.example/v1/responses' : '',
            'api_key' => $openAiCreds ? 'sk-test' : '',
        ],
        'ollama' => ['url' => 'http://127.0.0.1:11434'],
        'tiers' => $tiers,
    ];
}

/**
 * @return array<string, mixed>
 */
function mind_router_tier(string $provider, string $name, bool $enabled): array
{
    return [
        'enabled' => $enabled,
        'provider' => $provider,
        'name' => $name,
        'input_price_per_million' => null,
        'cached_input_price_per_million' => null,
        'output_price_per_million' => null,
    ];
}

function mind_router_frontier_resolves_when_enabled_and_configured(): bool
{
    $router = new ModelRouter(mind_router_config([
        'frontier' => mind_router_tier('openai', 'gpt-5.6-sol', true),
        'cheap' => mind_router_tier('openai', '', false),
        'in_house' => mind_router_tier('ollama', '', false),
    ]));

    return $router->clientFor(ModelTier::Frontier) instanceof OpenAiReasoningClient
        && $router->status() === ['frontier' => 'active', 'cheap' => 'disabled', 'in_house' => 'disabled'];
}

function mind_router_disabled_tier_falls_back_to_frontier(): bool
{
    $router = new ModelRouter(mind_router_config([
        'frontier' => mind_router_tier('openai', 'gpt-5.6-sol', true),
        'cheap' => mind_router_tier('openai', 'gpt-5.6-luna', false),
        'in_house' => mind_router_tier('ollama', '', false),
    ]));

    return $router->clientFor(ModelTier::Cheap) instanceof OpenAiReasoningClient;
}

function mind_router_enabled_in_house_ollama_tier_resolves_to_its_own_client(): bool
{
    $router = new ModelRouter(mind_router_config([
        'frontier' => mind_router_tier('openai', 'gpt-5.6-sol', true),
        'cheap' => mind_router_tier('openai', '', false),
        'in_house' => mind_router_tier('ollama', 'llama3.1', true),
    ]));

    return $router->clientFor(ModelTier::InHouse) instanceof OllamaReasoningClient
        && $router->status() === ['frontier' => 'active', 'cheap' => 'disabled', 'in_house' => 'active'];
}

function mind_router_fails_closed_when_nothing_resolves(): bool
{
    $router = new ModelRouter(mind_router_config([
        'frontier' => mind_router_tier('openai', 'gpt-5.6-sol', true),
        'cheap' => mind_router_tier('openai', '', false),
        'in_house' => mind_router_tier('ollama', '', false),
    ], openAiCreds: false));

    return $router->clientFor(ModelTier::Frontier) instanceof UnavailableReasoningClient
        && $router->status()['frontier'] === 'unconfigured';
}

function mind_router_tier_from_request_defaults_to_frontier(): bool
{
    return ModelTier::fromRequest(null) === ModelTier::Frontier
        && ModelTier::fromRequest('') === ModelTier::Frontier
        && ModelTier::fromRequest('bogus') === ModelTier::Frontier
        && ModelTier::fromRequest('CHEAP') === ModelTier::Cheap
        && ModelTier::fromRequest('in_house') === ModelTier::InHouse;
}

$checks = [
    'frontier resolves when enabled and configured' => mind_router_frontier_resolves_when_enabled_and_configured(),
    'a disabled tier request falls back to the frontier client' => mind_router_disabled_tier_falls_back_to_frontier(),
    'an enabled in_house ollama tier resolves to its own OllamaReasoningClient' => mind_router_enabled_in_house_ollama_tier_resolves_to_its_own_client(),
    'router fails closed to UnavailableReasoningClient when nothing resolves' => mind_router_fails_closed_when_nothing_resolves(),
    'ModelTier::fromRequest defaults unknown/blank to Frontier' => mind_router_tier_from_request_defaults_to_frontier(),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
