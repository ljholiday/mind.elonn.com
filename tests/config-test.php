<?php

declare(strict_types=1);

// Regression coverage for a real production incident elsewhere on this platform: a peer service
// URL default must resolve to the correct environment automatically -- derived via mind_peer_url(),
// not a hand-typed literal that can be left stale.

$root = dirname(__DIR__);
$localKeysUrl = config_value($root, '$_SERVER["APP_ENV"]="local";', 'service_auth.conductor_keys_url');
$productionKeysUrl = config_value($root, '$_SERVER["APP_ENV"]="production";', 'service_auth.conductor_keys_url');
// Legacy MODEL_PROVIDER / MODEL_NAME still feed the frontier tier so a pre-tier .env keeps working.
$legacyFrontierProvider = config_value($root, '$_SERVER["APP_ENV"]="local";$_SERVER["MODEL_PROVIDER"]="OpenAI";', 'model.tiers.frontier.provider');
$cheapEnabledDefault = config_value($root, '$_SERVER["APP_ENV"]="local";', 'model.tiers.cheap.enabled');
$cheapPrice = config_value($root, '$_SERVER["APP_ENV"]="local";$_SERVER["MODEL_CHEAP_INPUT_PRICE_PER_MILLION"]="0.20";', 'model.tiers.cheap.input_price_per_million');

$checks = [
    'Local Mind defaults to local Conductor keys URL' =>
        $localKeysUrl === 'https://conductor.elonn.local/.well-known/elonn-service-keys.json',
    'Production Mind defaults to production Conductor keys URL' =>
        $productionKeysUrl === 'https://conductor.elonn.com/.well-known/elonn-service-keys.json',
    'Legacy MODEL_PROVIDER feeds the frontier tier, lowercased' => $legacyFrontierProvider === 'openai',
    'cheap tier is disabled by default' => $cheapEnabledDefault === '',
    'MODEL_CHEAP_INPUT_PRICE_PER_MILLION is read as a plain env key' => $cheapPrice === '0.2',
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);

function config_value(string $root, string $setup, string $path): string
{
    $code = $setup . '$config=require ' . var_export($root . '/config/config.php', true) . ';'
        . '$value=$config; foreach (explode(".", ' . var_export($path, true) . ') as $key) { $value=$value[$key]; } echo $value;';
    $command = 'php -r ' . escapeshellarg($code);
    exec($command, $output, $status);
    if ($status !== 0) {
        return '';
    }

    return trim(implode("\n", $output));
}
