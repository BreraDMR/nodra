<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/** json_login answers {"error": ...} by default; the admin API speaks Problem ({message, code}) everywhere. */
final class AdminLoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // one message for an unknown email and a wrong password, so the answer doesn't tell which accounts exist
        return new JsonResponse(['message' => 'Invalid email or password', 'code' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
    }
}
