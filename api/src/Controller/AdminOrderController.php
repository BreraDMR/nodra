<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminOrdersQuery;
use App\Admin\AdminService;
use App\Admin\ConfirmOrderRequest;
use App\Admin\LineTermsRequest;
use App\Admin\MarkOrderedRequest;
use App\Admin\ReasonRequest;
use App\Admin\RecordPaymentRequest;
use App\Admin\ReplacementRequest;
use App\Admin\ScheduleShipmentRequest;
use App\Order\OrderActions;
use App\Order\OrderPresenter;
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

/** Order handling in the admin: every write needs X-CSRF-Token and answers with the order as it is now. */
#[Route('/api/admin/orders', requirements: ['id' => Requirement::UUID, 'itemId' => Requirement::UUID, 'shipmentId' => Requirement::UUID])]
final class AdminOrderController extends AbstractController
{
    public function __construct(private AdminService $admin, private OrderActions $actions, private OrderPresenter $presenter, private CsrfTokenManagerInterface $csrf) {}

    #[Route('', methods: ['GET'])]
    public function list(#[MapQueryString] AdminOrdersQuery $query): JsonResponse
    {
        return $this->json($this->admin->orders($query));
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $order = $this->presenter->admin($id);

        return $order === null ? $this->json(['message' => 'Order not found'], 404) : $this->json($order);
    }

    #[Route('/{id}/confirm', methods: ['POST'])]
    public function confirm(string $id, #[MapRequestPayload] ConfirmOrderRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->confirm($id, $payload->customerAgreedVia, $actor));
    }

    #[Route('/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->cancel($id, trim($payload->reason), $actor));
    }

    #[Route('/{id}/items/{itemId}/terms', methods: ['POST'])]
    public function terms(string $id, string $itemId, #[MapRequestPayload] LineTermsRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->changeTerms(
            $id, $itemId, $payload->unitPriceMinor, $payload->leadTimeMinDays, $payload->leadTimeMaxDays, $payload->customerAgreedVia, $actor,
        ));
    }

    #[Route('/{id}/items/{itemId}/ordered', methods: ['POST'])]
    public function ordered(string $id, string $itemId, #[MapRequestPayload] MarkOrderedRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->markOrdered($id, $itemId, trim($payload->supplierReference), $actor));
    }

    #[Route('/{id}/items/{itemId}/received', methods: ['POST'])]
    public function received(string $id, string $itemId, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->markReceived($id, $itemId, $actor));
    }

    #[Route('/{id}/items/{itemId}/failed', methods: ['POST'])]
    public function failed(string $id, string $itemId, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->markFailed($id, $itemId, trim($payload->reason), $actor));
    }

    #[Route('/{id}/items/{itemId}/cancel', methods: ['POST'])]
    public function cancelItem(string $id, string $itemId, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->cancelItem($id, $itemId, trim($payload->reason), $actor));
    }

    #[Route('/{id}/items/{itemId}/replacement', methods: ['POST'])]
    public function replacement(string $id, string $itemId, #[MapRequestPayload] ReplacementRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->replace(
            $id, $itemId, $payload->variantId, $payload->quantity, $payload->unitPriceMinor, $payload->customerAgreedVia, $actor,
        ));
    }

    #[Route('/{id}/items/{itemId}/return', methods: ['POST'])]
    public function returnItem(string $id, string $itemId, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->returnItem($id, $itemId, trim($payload->reason), $actor));
    }

    #[Route('/{id}/shipments/{shipmentId}/schedule', methods: ['POST'])]
    public function schedule(string $id, string $shipmentId, #[MapRequestPayload] ScheduleShipmentRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->schedule($id, $shipmentId, $payload->from, $payload->to, $actor));
    }

    #[Route('/{id}/shipments/{shipmentId}/hand-over', methods: ['POST'])]
    public function handOver(string $id, string $shipmentId, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->handOver($id, $shipmentId, $actor));
    }

    #[Route('/{id}/shipments/{shipmentId}/refused', methods: ['POST'])]
    public function refused(string $id, string $shipmentId, #[MapRequestPayload] ReasonRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (string $actor): ?array => $this->actions->refuse($id, $shipmentId, trim($payload->reason), $actor));
    }

    #[Route('/{id}/payments', methods: ['POST'])]
    public function payment(string $id, #[MapRequestPayload] RecordPaymentRequest $payload, Request $request): JsonResponse
    {
        $note = trim((string) $payload->note);

        return $this->write($request, fn (string $actor): ?array => $this->actions->recordPayment(
            $id, $request->headers->get('Idempotency-Key', ''), $payload->kind, $payload->method, $payload->amountMinor,
            $payload->shipmentId, $note === '' ? null : $note, $actor,
        ), 201);
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

        return $result === null ? $this->json(['message' => 'Order not found'], 404) : $this->json($result, $status);
    }
}
