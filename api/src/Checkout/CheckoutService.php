<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Entity\OrderItem;
use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CheckoutService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db) {}
}
