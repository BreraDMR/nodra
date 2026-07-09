<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminService;
use App\Admin\AdminProductsQuery;
use App\Admin\MergeRequest;
use App\Admin\OriginsQuery;
use App\Admin\OriginsQueryRequest;
use App\Admin\ProductWriteRequest;
use App\Admin\SettingsView;
use App\Admin\StockAdjustmentRequest;
use App\Admin\VariantWriteRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin')]
final class AdminController extends AbstractController
{
    public function __construct(private AdminService $admin, private SettingsView $settings, private CsrfTokenManagerInterface $csrf, private \App\Import\ProductMerge $merges, private OriginsQuery $origins) {}

    #[Route('/dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse { return $this->json($this->admin->dashboard()); }

    #[Route('/queues', methods: ['GET'])]
    public function queues(): JsonResponse { return $this->json($this->admin->queues()); }

    #[Route('/settings', methods: ['GET'])]
    public function settings(): JsonResponse { return $this->json($this->settings->toArray()); }

    #[Route('/products', methods: ['GET'])]
    public function products(#[MapQueryString] AdminProductsQuery $query): JsonResponse { return $this->json($this->admin->products($query)); }

    #[Route('/products', methods: ['POST'])]
    public function createProduct(#[MapRequestPayload] ProductWriteRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try { return $this->json($this->admin->createProduct($payload), 201); }
        catch (\InvalidArgumentException $error) { return $this->json(['message' => $error->getMessage()], 422); }
        catch (\DomainException $error) { return $this->json(['message' => $error->getMessage()], 409); }
    }

    #[Route('/products/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function updateProduct(string $id, #[MapRequestPayload] ProductWriteRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try {
            $result = $this->admin->updateProduct($id, $payload);
            return $result === null ? $this->json(['message' => 'Product not found'], 404) : $this->json($result);
        } catch (\InvalidArgumentException $error) { return $this->json(['message' => $error->getMessage()], 422); }
        catch (\DomainException $error) { return $this->json(['message' => $error->getMessage()], 409); }
    }

    #[Route('/stock-adjustments', methods: ['POST'])]
    public function adjustStock(#[MapRequestPayload] StockAdjustmentRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try { return $this->json($this->admin->adjustStock($payload), 201); }
        catch (\InvalidArgumentException|\DomainException $error) { return $this->json(['message' => $error->getMessage()], 422); }
    }

    #[Route('/products/{id}/variants', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function createVariant(string $id, #[MapRequestPayload] VariantWriteRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try {
            $result = $this->admin->createVariant($id, $payload);
            return $result === null ? $this->json(['message' => 'Product not found'], 404) : $this->json($result, 201);
        } catch (\InvalidArgumentException $error) { return $this->json(['message' => $error->getMessage()], 422); }
        catch (\DomainException $error) { return $this->json(['message' => $error->getMessage()], 409); }
    }

    #[Route('/variants/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function updateVariant(string $id, #[MapRequestPayload] VariantWriteRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try {
            $result = $this->admin->updateVariant($id, $payload);
            return $result === null ? $this->json(['message' => 'Variant not found'], 404) : $this->json($result);
        } catch (\InvalidArgumentException $error) { return $this->json(['message' => $error->getMessage()], 422); }
        catch (\DomainException $error) { return $this->json(['message' => $error->getMessage()], 409); }
    }

    /** The manual merge of two products (D03.3): the source ends archived, nothing is deleted. */
    #[Route('/products/{id}/merge', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function mergeProduct(string $id, #[MapRequestPayload] MergeRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try {
            $result = $this->merges->merge($id, $payload->targetId);
            return $result === null ? $this->json(['message' => 'Product not found'], 404) : $this->json($result);
        } catch (\DomainException $error) { return $this->json(['message' => $error->getMessage()], 409); }
    }

    /** The latest origin of every written field, for the product and variant cards (D03.3). */
    #[Route('/origins', methods: ['GET'])]
    public function origins(#[MapQueryString] OriginsQueryRequest $query): JsonResponse
    {
        try {
            return $this->json($this->origins->latest($query->type, $query->ids()));
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }
    }

    private function validCsrf(Request $request): bool
    {
        return $this->csrf->isTokenValid(new CsrfToken('admin-write', $request->headers->get('X-CSRF-Token', '')));
    }
}
