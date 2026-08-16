<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\InstallationConfirmRequest;
use App\Admin\InstallationOpenRequest;
use App\Admin\InstallationRescheduleRequest;
use App\Admin\InstallationsQuery;
use App\Admin\ReasonRequest;
use App\Installation\InstallationService;
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

/** Evening installation bookings (D08.1–D08.2): every write needs X-CSRF-Token and answers with the booking as it is now. */
#[Route('/api/admin/installations', requirements: ['id' => Requirement::UUID])]
final class AdminInstallationController extends AbstractController
{
    public function __construct(private InstallationService $installations, private CsrfTokenManagerInterface $csrf) {}

    #[Route('', methods: ['GET'])]
    public function list(#[MapQueryString] InstallationsQuery $query): JsonResponse
    {
        return $this->json($this->installations->list($query));
    }

    #[Route('', methods: ['POST'])]
    public function open(#[MapRequestPayload] InstallationOpenRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->installations->open(
            $payload->orderId, $payload->from, $payload->to, $payload->works, $payload->note === null ? null : trim($payload->note), $actor,
        ), 201);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            return $this->json($this->installations->show($id));
        } catch (OrderProblem $problem) {
            return $this->json($problem->toArray(), $problem->status);
        }
    }

    #[Route('/{id}/reschedule', methods: ['POST'])]
    public function reschedule(string $id, #[MapRequestPayload] InstallationRescheduleRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->installations->reschedule($id, $payload->from, $payload->to, $actor));
    }

    #[Route('/{id}/confirm', methods: ['POST'])]
    public function confirm(string $id, #[MapRequestPayload] InstallationConfirmRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->installations->confirm(
            $id, trim($payload->compatibilityNote), $payload->workFrom, $payload->workTo, $actor,
        ));
    }

    #[Route('/{id}/complete', methods: ['POST'])]
    public function complete(string $id, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->installations->complete($id, trim($payload->reason), $actor));
    }

    #[Route('/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): array => $this->installations->cancel($id, trim($payload->reason), $actor));
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
