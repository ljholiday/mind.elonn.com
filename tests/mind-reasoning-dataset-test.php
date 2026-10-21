<?php

declare(strict_types=1);

use App\Application;
use App\Reasoning\ReasoningResult;
use App\Security\AuthenticatedService;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array<string, mixed> */
function mind_reasoning_dataset_test_config(): array
{
    return [
        'app' => ['environment' => 'testing', 'debug' => false, 'url' => 'https://mind.elonn.local'],
        'model' => [
            'timeout_seconds' => 20,
            'output_tokens' => 600,
            'openai' => ['responses_url' => '', 'api_key' => ''],
            'ollama' => ['url' => 'http://127.0.0.1:11434'],
            'tiers' => [
                'frontier' => ['enabled' => true, 'provider' => 'openai', 'name' => 'gpt-5.6-sol', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
                'cheap' => ['enabled' => false, 'provider' => 'openai', 'name' => 'gpt-5.6-luna', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
                'in_house' => ['enabled' => false, 'provider' => 'ollama', 'name' => '', 'input_price_per_million' => null, 'cached_input_price_per_million' => null, 'output_price_per_million' => null],
            ],
        ],
        'ai_usage' => ['path' => '', 'max_records' => 5000],
        'service_auth' => [
            'admin.elonn' => 'admin-token',
            'conductor_keys_url' => '',
        ],
    ];
}

$app = new Application(mind_reasoning_dataset_test_config());

$reflection = new ReflectionMethod(Application::class, 'reasoningDataset');
$reflection->setAccessible(true);

$dataset = $reflection->invoke(
    $app,
    'What is the capital of France?',
    new ReasoningResult('Paris is the capital of France.', 'gpt-5.6-sol'),
    new AuthenticatedService('conductor.elonn', null)
);

$object = $dataset['objects'][0] ?? [];
$body = $object['content']['body'] ?? null;

$checks = [
    'Mind reasoning Object carries a canonical content.body' => is_array($body),
    'content.body has exactly one node' => is_array($body) && count($body) === 1,
    'The node is a paragraph node' => ($body[0]['node'] ?? '') === 'paragraph',
    'The paragraph text is the model response verbatim' =>
        ($body[0]['text'] ?? '') === 'Paris is the capital of France.',
    'The legacy content.response field is preserved alongside body' =>
        ($object['content']['response'] ?? '') === 'Paris is the capital of France.',
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
