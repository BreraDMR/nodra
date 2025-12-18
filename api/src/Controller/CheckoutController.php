<?php

declare(strict_types=1);

namespace App\Controller;

use App\Checkout\CheckoutRequest;
use App\Checkout\CheckoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class CheckoutController extends AbstractController
{
    public function __construct(private CheckoutService $checkout) {}

    #[Route('/checkout', methods: ['POST'])]
    public function place(#[MapRequestPayload] CheckoutRequest $payload, Request $request): JsonResponse
    {
        try {
            return $this->json($this->checkout->place($payload, $request->headers->get('Idempotency-Key', '')), 201);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        } catch (\DomainException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        }
    }

    #[Route('/orders/{reference}', methods: ['GET'])]
    public function lookup(string $reference, Request $request): JsonResponse
    {
        $receipt = $this->checkout->lookup($reference, $request->query->getString('token'));

        return $receipt === null ? $this->json(['message' => 'Order not found'], 404) : $this->json($receipt);
    }
}
