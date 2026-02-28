<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\CatalogSeed;
use App\Entity\Product;
use App\Entity\ProductVariant;
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
        $slugs = array_column($items, 'slug');
        $added = 0;

        foreach ($items as $rank => $item) {
            $existing = $catalog->findOneBy(['slug' => $item['slug']]);
            if ($existing !== null) {
                $image = '/images/'.$item['image'];
                $featuredRank = $item['featuredRank'] ?? $rank + 1;
                if ($existing->getImage() !== $image || $existing->getFeaturedRank() !== $featuredRank) {
                    $existing->update($existing->getSlug(), $existing->getCategory(), $existing->getCopy(), $image, [$image], $existing->getBadge(), $featuredRank, $existing->getStatus());
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
        $archived = 0;
        foreach ($catalog->findBy(['status' => 'published']) as $product) {
            if (in_array($product->getSlug(), $slugs, true)) {
                continue;
            }
            $product->update($product->getSlug(), $product->getCategory(), $product->getCopy(), $product->getImage(), $product->getImages(), $product->getBadge(), $product->getFeaturedRank(), 'archived');
            ++$archived;
        }
        $this->manager->flush();
        $output->writeln(sprintf('Added %d products; archived %d superseded demo products.', $added, $archived));

        return Command::SUCCESS;
    }
}
