<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;

/**
 * Verifies first-party service callers at Mind's internal boundary.
 */
final class ServiceAuthenticator
{
    /** @param array<string, string> $tokensByService */
    public function __construct(
        private readonly array $tokensByService,
        private readonly ?SignedRequestVerifier $signedRequestVerifier = null
    ) {
    }

    public function authenticate(Request $request): ?AuthenticatedService
    {
        $service = trim($request->header('x-elonn-service'));
        if ($service === '') {
            return null;
        }

        $memberId = trim($request->header('x-elonn-member-id'));
        if ($memberId !== '' && !$this->validMemberId($memberId)) {
            return null;
        }

        if ($service === 'conductor.elonn' && $this->signedRequestVerifier instanceof SignedRequestVerifier) {
            $signatureHeaders = [
                'x-elonn-key-id' => $request->header('x-elonn-key-id'),
                'x-elonn-timestamp' => $request->header('x-elonn-timestamp'),
                'x-elonn-body-sha256' => $request->header('x-elonn-body-sha256'),
                'x-elonn-signature' => $request->header('x-elonn-signature'),
            ];
            if ($this->signedRequestVerifier->verify($signatureHeaders, $request->method(), $request->path(), $request->body())) {
                return new AuthenticatedService($service, $memberId === '' ? null : $memberId);
            }
        }

        $token = $this->bearerToken($request->header('authorization')) ?? trim($request->header('x-elonn-service-token'));
        if ($token === null) {
            return null;
        }

        $expected = $this->tokensByService[$service] ?? '';
        if ($expected === '' || !hash_equals($expected, $token)) {
            return null;
        }

        return new AuthenticatedService($service, $memberId === '' ? null : $memberId);
    }

    private function bearerToken(string $authorization): ?string
    {
        $authorization = trim($authorization);
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));
        return $token === '' ? null : $token;
    }

    private function validMemberId(string $memberId): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $memberId) === 1;
    }
}
