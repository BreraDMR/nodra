<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountService;
use App\Checkout\CheckoutRequest;
use App\Checkout\CheckoutService;
use App\Checkout\QuoteRequest;
use App\Order\OrderProblem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class CheckoutController extends AbstractController
{
    public function __construct(private CheckoutService $checkout, private AccountService $accounts) {}

    #[Route('/checkout/quote', methods: ['POST'])]
    public function quote(#[MapRequestPayload] QuoteRequest $payload): JsonResponse
    {
        return $this->answer(fn (): array => $this->checkout->quote($payload), 200);
    }

    #[Route('/checkout', methods: ['POST'])]
    public function place(#[MapRequestPayload] CheckoutRequest $payload, Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->checkout->place($payload, $request->headers->get('Idempotency-Key', ''), $this->accounts->current($request)), 201);
    }

    #[Route('/orders/{reference}', methods: ['GET'])]
    public function lookup(string $reference, Request $request): JsonResponse
    {
        $receipt = $this->checkout->lookup($reference, $request->query->getString('token'));

        return $receipt === null ? $this->json(['message' => 'Order not found'], 404) : $this->json($receipt);
    }

    /** @param callable(): array $action */
    private function answer(callable $action, int $status): JsonResponse
    {
        try {
            return $this->json($action(), $status);
        } catch (OrderProblem $problem) {
            return $this->json($problem->toArray(), $problem->status);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        } catch (\DomainException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        }
    }
}
