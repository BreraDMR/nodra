<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\FeedRefresh;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The whole scheduler of the D03.4 feed refresh: hang it on cron, nothing is scheduled inside the
 * app and no worker daemon runs. Without an argument it walks every supplier with a stored feed.
 */
#[AsCommand(name: 'app:import:feed', description: 'Refresh stored supplier feeds in row batches: download, plan, write batch by batch with retries')]
final class ImportFeedCommand extends Command
{
    public function __construct(private FeedRefresh $refresh)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('supplier', InputArgument::OPTIONAL, 'One supplier, e.g. bike_components; defaults to every supplier with a feed URL')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Refresh even when the frequency limit has not elapsed yet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->refresh->refresh($input->getArgument('supplier'), (bool) $input->getOption('force'));
        if ($results === []) {
            $output->writeln('No supplier has a feed URL stored; set one in the admin import screen.');

            return Command::SUCCESS;
        }
        foreach ($results as $result) {
            $line = sprintf('%s', $result['supplier']);
            if ($result['runId'] !== null) {
                $line .= sprintf(': run %s %s', $result['runId'], $result['status']);
            }
            if ($result['batchesDone'] > 0 || $result['batchesFailed'] > 0) {
                $line .= sprintf(', batches %d done / %d failed', $result['batchesDone'], $result['batchesFailed']);
            }
            if ($result['note'] !== null) {
                $line .= ' — '.$result['note'];
            }
            $output->writeln($result['status'] === 'failed' || $result['batchesFailed'] > 0 ? '<error>'.$line.'</error>' : $line);
        }

        return Command::SUCCESS;
    }
}
