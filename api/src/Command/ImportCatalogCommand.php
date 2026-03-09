<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\CatalogSeed;
use App\Catalog\SupplierOfferSeed;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:catalog:import', description: 'Add catalog demo products without resetting orders or edited stock')]
final class ImportCatalogCommand extends Command
{
    public function __construct(private EntityManagerInterface $manager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = CatalogSeed::items();
        $catalog = $this->manager->getRepository(Product::class);
        $added = 0;

        foreach ($items as $rank => $item) {
            $existing = $catalog->findOneBy(['slug' => $item['slug']]);
            if ($existing !== null) {
                $source = $item['source'] ?? null;
                if (is_array($source) && isset($source['url']) && $this->manager->getRepository(SupplierOffer::class)->findOneBy(['product' => $existing, 'url' => $source['url']]) === null) {
                    $offer = SupplierOfferSeed::fromItem($existing, $item);
                    if ($offer !== null) {
                        $this->manager->persist($offer);
                    }
                }
                continue;
            }

            $copy = [];
            foreach (['cs', 'de', 'en'] as $locale) {
                $copy[$locale] = [
                    'name' => $item['name'][$locale],
                    'short' => $item['short'][$locale],
                    'description' => $item['description'][$locale] ?? $item['short'][$locale],
                    'details' => $item['details'][$locale],
                ];
            }

            $image = '/images/'.$item['image'];
            $product = new Product($item['slug'], $item['category'], $copy, $image);
            $product->update($item['slug'], $item['category'], $copy, $image, [$image], $item['badge'], $item['featuredRank'] ?? $rank + 1, 'published');
            $this->manager->persist($product);
            $offer = SupplierOfferSeed::fromItem($product, $item);
            if ($offer !== null) {
                $this->manager->persist($offer);
            }

            foreach ($item['variants'] as $index => $option) {
                $this->manager->persist(new ProductVariant(
                    $product,
                    'ND-'.strtoupper(substr(hash('sha256', $item['slug']), 0, 12)).'-'.($index + 1),
                    $option['label'],
                    $item['priceCzk'] + ($option['priceDeltaCzk'] ?? 0),
                    $item['priceEur'] + ($option['priceDeltaEur'] ?? 0),
                    max(0, $item['stock'] - ($index * 3)),
                    $option['color'] ?? null,
                    $option['size'] ?? null,
                ));
            }
            ++$added;
        }

        $this->manager->flush();
        $output->writeln(sprintf('Added %d products; existing products and editorial changes preserved.', $added));

        return Command::SUCCESS;
    }
}
