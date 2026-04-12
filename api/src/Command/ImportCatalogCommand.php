<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\CatalogSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:catalog:import', description: 'Add missing catalog categories and products without resetting orders, stock or admin edits')]
final class ImportCatalogCommand extends Command
{
    public function __construct(private CatalogSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $categories = $this->seeder->seedCategories();
        foreach ($categories['warnings'] as $warning) {
            $output->writeln('<comment>Warning: '.$warning.'</comment>');
        }
        $products = $this->seeder->seedProducts();
        $output->writeln(sprintf(
            'Categories: %d added, %d filled. Products: %d added, %d filled, %d supplier offers added. Existing edits preserved.',
            $categories['added'], $categories['filled'], $products['added'], $products['filled'], $products['offers'],
        ));

        return Command::SUCCESS;
    }
}
