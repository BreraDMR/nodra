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
}
