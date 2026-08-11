<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\ExternalRatingWriteRequest;
use App\Catalog\ExternalRatingService;
use App\Entity\Product;
use App\Order\OrderProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** D07.3: the outside ratings of a product, typed in by an admin; no public exposure. */
#[Route('/api/admin', requirements: ['productId' => Requirement::UUID, 'id' => Requirement::UUID])]
final class AdminExternalRatingController extends AbstractController
{
    public function __construct(
        private ExternalRatingService $ratings,
        private EntityManagerInterface $em,
        private CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('/products/{productId}/external-ratings', methods: ['GET'])]
    public function list(#[MapEntity(mapping: ['productId' => 'id'])] ?Product $product): JsonResponse
    {
        if ($product === null) {
            return $this->json(['message' => 'Product not found'], 404);
        }

        return $this->json($this->ratings->allOf($product));
    }

    #[Route('/products/{productId}/external-ratings', methods: ['POST'])]
    public function create(
        #[MapEntity(mapping: ['productId' => 'id'])] ?Product $product,
        #[MapRequestPayload] ExternalRatingWriteRequest $payload,
        Request $request,
    ): JsonResponse {
        return $this->write($request, fn (): array => $this->ratings->create($this->product($product), $payload), 201);
    }

    #[Route('/external-ratings/{id}', methods: ['PUT'])]
    public function update(string $id, #[MapRequestPayload] ExternalRatingWriteRequest $payload, Request $request): JsonResponse
    {
        return $this->write($request, fn (): array => $this->ratings->update($id, $payload));
    }

    #[Route('/external-ratings/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        return $this->write($request, function () use ($id): void {
            $this->ratings->delete($id);
        }, 204);
    }

    private function product(?Product $product): Product
    {
        if ($product === null) {
            throw OrderProblem::notFound('Product not found');
        }

        return $product;
    }

    /** @param callable(): mixed $action */
    private function write(Request $request, callable $action, int $status = 200): JsonResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('admin-write', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->json(['message' => 'Invalid CSRF token'], 403);
        }
        try {
            $result = $action();
        } catch (OrderProblem $problem) {
            return $this->json($problem->toArray(), $problem->status);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }

        return $status === 204 ? new JsonResponse(null, 204) : $this->json($result, $status);
    }
}
