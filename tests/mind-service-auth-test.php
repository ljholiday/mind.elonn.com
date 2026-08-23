<?php

declare(strict_types=1);

use App\Http\Request;
use App\Security\ServiceAuthenticator;
use App\Security\SignedRequestVerifier;

require dirname(__DIR__) . '/vendor/autoload.php';

$authenticator = new ServiceAuthenticator([
    'admin.elonn' => 'admin-token',
]);
$signedAuthenticator = new ServiceAuthenticator(
    [],
    new SignedRequestVerifier('', ['test-key' => mind_test_public_key()])
);

$fallback = $authenticator->authenticate(Request::testing('GET', '/metrics', [
    'x-elonn-service' => 'admin.elonn',
    'x-elonn-service-token' => 'admin-token',
]));
$wrong = $authenticator->authenticate(Request::testing('GET', '/metrics', [
    'x-elonn-service' => 'admin.elonn',
    'authorization' => 'Bearer wrong',
]));
$unknown = $authenticator->authenticate(Request::testing('GET', '/metrics', [
    'x-elonn-service' => 'unknown.elonn',
    'authorization' => 'Bearer admin-token',
]));
$invalidMember = $authenticator->authenticate(Request::testing('POST', '/mind/call', [
    'x-elonn-service' => 'admin.elonn',
    'authorization' => 'Bearer admin-token',
    'x-elonn-member-id' => 'bad member',
]));

$signedBody = json_encode(['id' => 'call:conductor:mind:test'], JSON_UNESCAPED_SLASHES) ?: '{}';
$conductorSigned = $signedAuthenticator->authenticate(Request::testing('POST', '/mind/call', [
    'x-elonn-service' => 'conductor.elonn',
    'x-elonn-member-id' => '77',
] + mind_signed_headers('POST', '/mind/call', $signedBody), $signedBody));
$conductorBadSignature = $signedAuthenticator->authenticate(Request::testing('POST', '/mind/call', [
    'x-elonn-service' => 'conductor.elonn',
] + mind_signed_headers('POST', '/mind/call', $signedBody), '{"tampered":true}'));
$conductorWithoutVerifier = $authenticator->authenticate(Request::testing('POST', '/mind/call', [
    'x-elonn-service' => 'conductor.elonn',
] + mind_signed_headers('POST', '/mind/call', $signedBody), $signedBody));

$checks = [
    'X-Elonn-Service-Token authenticates first-party caller' => $fallback !== null
        && $fallback->name === 'admin.elonn'
        && $fallback->memberId === null
        && $fallback->owner() === 'service:admin.elonn',
    'Mismatched service token is rejected' => $wrong === null,
    'Unknown service caller is rejected' => $unknown === null,
    'Malformed member context is rejected' => $invalidMember === null,
    'Conductor signed request is authenticated without a static token' => $conductorSigned !== null
        && $conductorSigned->name === 'conductor.elonn'
        && $conductorSigned->memberId === '77',
    'Conductor signature is rejected when the body does not match the digest' => $conductorBadSignature === null,
    'Conductor caller without a configured verifier falls through and is rejected (no static token either)' => $conductorWithoutVerifier === null,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

if ($failed > 0) {
    exit(1);
}

/**
 * @return array<string, string>
 */
function mind_signed_headers(string $method, string $path, string $body): array
{
    $timestamp = (string) time();
    $digest = base64_encode(hash('sha256', $body, true));
    $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $digest]);
    $signature = '';
    if (!openssl_sign($canonical, $signature, mind_test_private_key(), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign Mind test request.');
    }

    return [
        'x-elonn-key-id' => 'test-key',
        'x-elonn-timestamp' => $timestamp,
        'x-elonn-body-sha256' => $digest,
        'x-elonn-signature' => base64_encode($signature),
    ];
}

function mind_test_private_key(): string
{
    static $privateKeyPem = null;
    if ($privateKeyPem === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $privateKeyPem)) {
            throw new RuntimeException('Unable to generate Mind test signing key.');
        }
    }

    return $privateKeyPem;
}

function mind_test_public_key(): string
{
    $key = openssl_pkey_get_private(mind_test_private_key());
    $details = $key === false ? false : openssl_pkey_get_details($key);

    return is_array($details) ? (string) ($details['key'] ?? '') : '';
}
