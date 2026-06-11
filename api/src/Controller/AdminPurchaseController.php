<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminPurchasesQuery;
use App\Admin\CreatePurchaseRequest;
use App\Admin\ReasonRequest;
use App\Order\OrderProblem;
use App\Purchase\PurchaseQueries;
use App\Purchase\PurchaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Buying from suppliers: what's left to buy, and purchases placed by hand and recorded here. Writes need X-CSRF-Token. */
#[Route('/api/admin', requirements: ['id' => Requirement::UUID])]
final class AdminPurchaseController extends AbstractController
{
    public function __construct(private PurchaseQueries $queries, private PurchaseService $purchases, private CsrfTokenManagerInterface $csrf) {}

    #[Route('/to-purchase', methods: ['GET'])]
    public function toPurchase(): JsonResponse
    {
        return $this->json($this->queries->toPurchase());
    }

    #[Route('/purchases', methods: ['GET'])]
    public function list(#[MapQueryString] AdminPurchasesQuery $query): JsonResponse
    {
        return $this->json($this->queries->list($query));
    }

    #[Route('/purchases', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreatePurchaseRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->purchases->create($payload, $request->headers->get('Idempotency-Key', ''), $actor), 201);
    }

    #[Route('/purchases/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $purchase = $this->queries->detail($id);

        return $purchase === null ? $this->json(['message' => 'Purchase not found'], 404) : $this->json($purchase);
    }

    #[Route('/purchases/{id}/receive', methods: ['POST'])]
    public function receive(string $id, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->purchases->receive($id, $actor));
    }

    #[Route('/purchases/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->purchases->cancel($id, trim($payload->reason), $actor));
    }

    /** @param callable(string): ?array $action gets the admin email for the journal */
    private function write(Request $request, callable $action, int $status = 200): JsonResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('admin-write', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->json(['message' => 'Invalid CSRF token'], 403);
        }
        try {
            $result = $action($this->getUser()?->getUserIdentifier() ?? 'admin');
        } catch (OrderProblem $problem) {
            return $this->json($problem->toArray(), $problem->status);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        } catch (\DomainException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        }

        return $result === null ? $this->json(['message' => 'Purchase not found'], 404) : $this->json($result, $status);
    }
}
