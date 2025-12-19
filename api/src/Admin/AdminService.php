<?php

declare(strict_types=1);

namespace App\Admin;

use App\Checkout\CheckoutService;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use App\Entity\StockMovement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db, private CheckoutService $checkout) {}
}
