<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\ApplySuggestionRequest;
use App\Admin\PriceHistoryQuery;
use App\Admin\PricingRuleAdminService;
use App\Admin\PricingRuleWriteRequest;
use App\Admin\RepriceApplyRequest;
use App\Admin\RepricePreviewQuery;
use App\Pricing\PriceHistory;
use App\Pricing\PricingService;
use App\Pricing\StalePriceException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin')]
final class AdminPricingController extends AbstractController
{
    public function __construct(
        private PricingRuleAdminService $rules,
        private PricingService $pricing,
        private PriceHistory $history,
        private CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('/pricing-rules', methods: ['GET'])]
    public function listRules(): JsonResponse
    {
        return $this->json($this->rules->list());
    }

    #[Route('/pricing-rules', methods: ['POST'])]
    public function createRule(#[MapRequestPayload] PricingRuleWriteRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): ?array => $this->rules->create($payload), 201);
    }

    #[Route('/pricing-rules/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function updateRule(string $id, #[MapRequestPayload] PricingRuleWriteRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): ?array => $this->rules->update($id, $payload), 200, 'Pricing rule not found');
    }

    #[Route('/pricing-rules/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function deleteRule(string $id, Request $request): JsonResponse
    {
        $response = $this->write($request, fn (): ?array => $this->rules->delete($id) ? [] : null, 204, 'Pricing rule not found');

        return $response->getStatusCode() === 204 ? new JsonResponse(null, 204) : $response;
    }

    #[Route('/variants/{id}/pricing', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function variantPricing(string $id): JsonResponse
    {
        $pricing = $this->pricing->variant($id);

        return $pricing === null ? $this->json(['message' => 'Variant not found'], 404) : $this->json($pricing);
    }

    #[Route('/variants/{id}/pricing/apply', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function applySuggestion(string $id, #[MapRequestPayload] ApplySuggestionRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): ?array => $this->pricing->applySuggestion($id, $payload->suggestedPriceCzk), 200, 'Variant not found');
    }

    #[Route('/variants/{id}/price-history', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function priceHistory(string $id, #[MapQueryString] PriceHistoryQuery $query): JsonResponse
    {
        $page = $this->history->page($id, $query->page);

        return $page === null ? $this->json(['message' => 'Variant not found'], 404) : $this->json($page);
    }

    #[Route('/pricing/reprice', methods: ['GET'])]
    public function repricePreview(#[MapQueryString] RepricePreviewQuery $query): JsonResponse
    {
        $preview = $this->pricing->preview($query->categoryId);

        return $preview === null ? $this->json(['message' => 'Category not found'], 404) : $this->json($preview);
    }

    #[Route('/pricing/reprice', methods: ['POST'])]
    public function repriceApply(#[MapRequestPayload] RepriceApplyRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): array => $this->pricing->apply($payload->expected()), 200);
    }

    #[Route('/pricing/alerts', methods: ['GET'])]
    public function alerts(): JsonResponse
    {
        return $this->json($this->pricing->alerts());
    }

    /** @param callable(): ?array $action */
    private function write(Request $request, callable $action, int $status, string $notFound = 'Not found'): JsonResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('admin-write', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->json(['message' => 'Invalid CSRF token'], 403);
        }
        try {
            $result = $action();
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        } catch (StalePriceException $error) {
            return $this->json(['message' => $error->getMessage(), 'staleVariantIds' => $error->variantIds], 409);
        } catch (\DomainException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        }

        return $result === null ? $this->json(['message' => $notFound], 404) : $this->json($result, $status);
    }
}
