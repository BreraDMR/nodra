<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\CatalogSeeder;
use App\Entity\ImportRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:catalog:import', description: 'Add missing catalog categories and products without resetting orders, stock or admin edits')]
final class ImportCatalogCommand extends Command
{
    public function __construct(private CatalogSeeder $seeder, private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $run = new ImportRun(ImportRun::SOURCE_SEED, new \DateTimeImmutable());
        $this->em->persist($run);
        $categories = $this->seeder->seedCategories();
        foreach ($categories['warnings'] as $warning) {
            $output->writeln('<comment>Warning: '.$warning.'</comment>');
        }
        $products = $this->seeder->seedProducts();
        foreach ($products['errors'] as $error) {
            $output->writeln('<comment>Row error: '.$error['message'].'</comment>');
        }
        $output->writeln(sprintf(
            'Categories: %d added, %d filled. Products: %d added, %d filled, %d supplier offers added. Existing edits preserved.',
            $categories['added'], $categories['filled'], $products['added'], $products['filled'], $products['offers'],
        ));

        // the seed run lands in the same journal as the feed imports, row errors included
        $total = $products['added'] + $products['filled'] + count($products['errors']);
        $counts = [
            'totalRows' => $total, 'newProducts' => $products['added'], 'updates' => $products['filled'],
            'conflicts' => 0, 'unknowns' => 0, 'errors' => count($products['errors']),
            'rowsWithCost' => 0, 'suggestionsMarginTooLow' => 0,
        ];
        $report = [
            'source' => ImportRun::SOURCE_SEED, 'fileName' => null, 'sha256' => null, 'totalRows' => $total,
            'counts' => $counts, 'newProducts' => [], 'updates' => [], 'conflicts' => [], 'unknowns' => [], 'errors' => [],
            'cost' => ['rows' => [], 'marginTooLow' => 0],
        ];
        $run->finish(ImportRun::STATUS_APPLIED, $counts, $report, $products['errors'], new \DateTimeImmutable());
        $this->em->flush();

        return Command::SUCCESS;
    }
}
