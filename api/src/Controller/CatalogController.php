<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogQuery;
use App\Catalog\CatalogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class CatalogController extends AbstractController
{
    public function __construct(private CatalogService $catalog) {}

    #[Route('/products', methods: ['GET'])]
    public function browse(#[MapQueryString] CatalogQuery $query): JsonResponse
    {
        return $this->json($this->catalog->browse($query));
    }

    #[Route('/products/{slug}', methods: ['GET'])]
    public function product(string $slug, #[MapQueryString] CatalogQuery $query): JsonResponse
    {
        $product = $this->catalog->product($slug, $query->locale);

        return $product === null ? $this->json(['message' => 'Product not found'], 404) : $this->json($product);
    }

    #[Route('/categories', methods: ['GET'])]
    public function categories(#[MapQueryString] CatalogQuery $query): JsonResponse
    {
        return $this->json($this->catalog->categories($query->locale));
    }
}
