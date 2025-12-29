<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminService;
use App\Admin\OrderStatusRequest;
use App\Admin\ProductWriteRequest;
use App\Admin\StockAdjustmentRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin')]
final class AdminController extends AbstractController
{
    public function __construct(private AdminService $admin, private CsrfTokenManagerInterface $csrf) {}

    #[Route('/dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse { return $this->json($this->admin->dashboard()); }

    #[Route('/products', methods: ['GET'])]
    public function products(): JsonResponse { return $this->json(['items' => $this->admin->products()]); }

    #[Route('/products', methods: ['POST'])]
    public function createProduct(#[MapRequestPayload] ProductWriteRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try { return $this->json($this->admin->createProduct($payload), 201); }
        catch (\InvalidArgumentException $error) { return $this->json(['message' => $error->getMessage()], 422); }
    }

    #[Route('/products/{id}', methods: ['PUT'])]
    public function updateProduct(string $id, #[MapRequestPayload] ProductWriteRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try {
            $result = $this->admin->updateProduct($id, $payload);
            return $result === null ? $this->json(['message' => 'Product not found'], 404) : $this->json($result);
        } catch (\InvalidArgumentException $error) { return $this->json(['message' => $error->getMessage()], 422); }
    }

    #[Route('/stock-adjustments', methods: ['POST'])]
    public function adjustStock(#[MapRequestPayload] StockAdjustmentRequest $payload, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['message' => 'Invalid CSRF token'], 403);
        try { return $this->json($this->admin->adjustStock($payload), 201); }
        catch (\InvalidArgumentException|\DomainException $error) { return $this->json(['message' => $error->getMessage()], 422); }
    }

    #[Route('/orders', methods: ['GET'])]
    public function orders(): JsonResponse { return $this->json(['items' => $this->admin->orders()]); }

    #[Route('/orders/{id}', methods: ['GET'])]
    public function order(string $id): JsonResponse
    {
        $order = $this->admin->order($id);
        return $order === null ? $this->json(['message' => 'Order not found'], 404) : $this->json($order);
    }
}
