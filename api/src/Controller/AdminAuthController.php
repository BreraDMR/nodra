<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin')]
final class AdminAuthController extends AbstractController
{
    public function __construct(private CsrfTokenManagerInterface $csrf) {}
    #[Route('/login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->me();
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall handles this route');
    }

    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        return $this->json(['email' => $user->getEmail(), 'name' => $user->getName(), 'csrfToken' => $this->csrf->getToken('admin-write')->getValue()]);
    }
}
