<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_variant')]
#[ORM\Index(columns: ['product_id', 'active'], name: 'idx_variant_product')]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 80, unique: true)]
    private string $sku;

    /** @var array{cs: string, de: string, en: string} */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $label;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $color;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $size;

    #[ORM\Column]
    private int $priceCzk;

    #[ORM\Column]
    private int $priceEur;

    #[ORM\Column]
    private int $stock;

    #[ORM\Column]
    private bool $active = true;

    public function __construct(Product $product, string $sku, array $label, int $priceCzk, int $priceEur, int $stock, ?string $color = null, ?string $size = null)
    {
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->sku = $sku;
        $this->label = $label;
        $this->priceCzk = $priceCzk;
        $this->priceEur = $priceEur;
        $this->stock = $stock;
        $this->color = $color;
        $this->size = $size;
    }
}
