<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminClaimsQuery;
use App\Admin\ClaimAcceptRequest;
use App\Admin\ClaimOpenRequest;
use App\Admin\ClaimResolveRequest;
use App\Admin\ClaimWaitRequest;
use App\Admin\ReasonRequest;
use App\Order\ClaimService;
use App\Order\OrderProblem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** The after-sale claims registry (D08.4): every write needs X-CSRF-Token and answers with the claim as it is now. */
#[Route('/api/admin/claims', requirements: ['id' => Requirement::UUID])]
final class AdminClaimController extends AbstractController
{
    public function __construct(private ClaimService $claims, private CsrfTokenManagerInterface $csrf) {}

    #[Route('', methods: ['GET'])]
    public function list(#[MapQueryString] AdminClaimsQuery $query): JsonResponse
    {
        return $this->json($this->claims->list($query));
    }

    #[Route('', methods: ['POST'])]
    public function open(#[MapRequestPayload] ClaimOpenRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->claims->open(
            $payload->orderId, $payload->itemId, $payload->kind, $payload->note === null ? null : trim($payload->note), $payload->contactedOn, $actor,
        ), 201);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            return $this->json($this->claims->show($id));
        } catch (OrderProblem $problem) {
            return $this->json($problem->toArray(), $problem->status);
        }
    }

    #[Route('/{id}/wait', methods: ['POST'])]
    public function wait(string $id, #[MapRequestPayload] ClaimWaitRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->claims->wait($id, $payload->note === null ? null : trim($payload->note), $actor));
    }

    #[Route('/{id}/accept', methods: ['POST'])]
    public function accept(string $id, #[MapRequestPayload] ClaimAcceptRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->claims->accept($id, $payload->refundAmountMinor, $payload->note === null ? null : trim($payload->note), $actor));
    }

    #[Route('/{id}/reject', methods: ['POST'])]
    public function reject(string $id, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->claims->reject($id, trim($payload->reason), $actor));
    }

    #[Route('/{id}/resolve', methods: ['POST'])]
    public function resolve(string $id, #[MapRequestPayload] ClaimResolveRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->claims->resolve($id, $payload->resolution, $payload->note === null ? null : trim($payload->note), $actor));
    }

    /** @param callable(string): array $action gets the admin email for the journal */
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

        return $this->json($result, $status);
    }
}
