<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\CategoryAdminService;
use App\Admin\CategoryWriteRequest;
use App\Admin\SupplierOfferAdminService;
use App\Admin\SupplierOfferWriteRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin')]
final class AdminCatalogController extends AbstractController
{
    public function __construct(
        private CategoryAdminService $categories,
        private SupplierOfferAdminService $offers,
        private CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('/categories', methods: ['GET'])]
    public function listCategories(): JsonResponse
    {
        return $this->json($this->categories->list());
    }

    #[Route('/categories', methods: ['POST'])]
    public function createCategory(#[MapRequestPayload] CategoryWriteRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): ?array => $this->categories->create($payload), 201);
    }

    #[Route('/categories/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function updateCategory(string $id, #[MapRequestPayload] CategoryWriteRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): ?array => $this->categories->update($id, $payload), 200, 'Category not found');
    }

    #[Route('/products/{id}/supplier-offers', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function createOffer(string $id, #[MapRequestPayload] SupplierOfferWriteRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): ?array => $this->offers->create($id, $payload), 201, 'Product not found');
    }

    #[Route('/supplier-offers/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function updateOffer(string $id, #[MapRequestPayload] SupplierOfferWriteRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): ?array => $this->offers->update($id, $payload), 200, 'Offer not found');
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
        } catch (\DomainException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        }

        return $result === null ? $this->json(['message' => $notFound], 404) : $this->json($result, $status);
    }
}
