<?php

declare(strict_types=1);

namespace Ladybird\WPT\Command;

use Ladybird\WPT\Metrics\Entity\Product;
use Ladybird\WPT\Metrics\Storage;
use Ladybird\WPT\WPT\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncTotalsCommand extends Command
{
    private ?SymfonyStyle $io = null;

    protected function configure(): void
    {
        $this
            ->setName('app:sync-totals')
            ->setDescription('Bring WPT totals per run in sync with production');
    }

    private function updateRun(Storage $storage, Client $wptClient, Product $product, int $runId): void
    {
        // Do not overwrite existing runs
        if ($storage->findRunById($runId) !== null) {
            $this->io?->writeln(sprintf('Run: %d already exists', $runId));
            return;
        }

        $this->io?->writeln(sprintf('Updating run: %d', $runId));

        $results = $wptClient->getResultsForRuns([$runId]);
        $storage->beginTransaction();

        // Create run
        $runData = $results['runs'][0];
        $runId = $runData['id'];
        $createdAt = $wptClient->dateTimeFromTimestamp($runData['created_at']);
        $timeStart = $wptClient->dateTimeFromTimestamp($runData['time_start']);
        $timeEnd = $wptClient->dateTimeFromTimestamp($runData['time_end']);
        $run = $storage->createRun($product, $runId, $createdAt, $timeStart, $timeEnd, json_encode($runData));
        $subtestResults = $results['results'];
        $this->io?->writeln(sprintf('  Found %d subtest results', count($subtestResults)));

        // Accumulate counts per category
        $perCategoryTotals = [];
        $perCategoryPasses = [];
        foreach ($subtestResults as $subtestResult) {
            $category = explode('/', $subtestResult['test'])[1];
            $legacyStatus = $subtestResult['legacy_status'][0];

            $subtestTotal = max(1, $legacyStatus['total']);
            $subtestPasses = $legacyStatus['status'] === 'P' ? max(1, $legacyStatus['passes']) : $legacyStatus['passes'];

            if (!array_key_exists($category, $perCategoryTotals)) {
                $perCategoryTotals[$category] = 0;
                $perCategoryPasses[$category] = 0;
            }
            $perCategoryTotals[$category] += $subtestTotal;
            $perCategoryPasses[$category] += $subtestPasses;
        }

        // Store all categories
        $categories = [];
        foreach ($perCategoryTotals as $categoryName => $categoryTotal) {
            $categoryPasses = $perCategoryPasses[$categoryName];
            $categories[] = ['category' => $categoryName, 'total' => $categoryTotal, 'passes' => $categoryPasses];
        }
        $storage->createRunCategories($run, $categories);
        $this->io?->writeln(sprintf('  Stored %d records.', count($categories)));

        $storage->commitTransaction();
    }

    private function updateProductRuns(Storage $storage, Client $wptClient, Product $product): int
    {
        $this->io?->writeln(sprintf('Updating runs for product: %s', $product->getName()));

        // Determine time ranges in which to search for runs
        $timeRanges = [];
        [$runMinTime, $runMaxTime] = $storage->getRunsTimeRange($product);
        if ($runMinTime === null || $runMaxTime === null) {
            $timeRanges[] = [new \DateTimeImmutable('-2 weeks'), new \DateTimeImmutable()];
        } else {
            $timeRanges[] = [\DateTimeImmutable::createFromInterface($runMinTime)->modify('-2 weeks'), $runMinTime->modify('-1 second')];
            $timeRanges[] = [$runMaxTime->modify('+1 second'), new \DateTimeImmutable()];
        }

        // FIXME: make these configurable
        $cutoffDate = new \DateTimeImmutable('2024-01-01 00:00:00');
        $maxNumberOfResults = 50;

        // For each time range, determine all the runs inside of it and update them
        $runsStored = 0;
        foreach ($timeRanges as [$from, $to]) {
            $from = max($from, $cutoffDate);
            $to = max($from, $to);

            // Skip if we've reached the cutoff
            if ($from == $to) {
                continue;
            }

            while (true) {
                $this->io?->writeln(sprintf('  Searching for runs between %s and %s', $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')));
                $runs = $wptClient->getRunsInTimeRange(
                    products: [$product->getName()],
                    labels: ['master', 'experimental'],
                    timestampFrom: $from,
                    timestampTo: $to,
                    maxCount: $maxNumberOfResults,
                );
                $this->io?->writeln(sprintf('  Found %d runs', count($runs)));
                if (count($runs) < $maxNumberOfResults) {
                    break;
                }

                // Found too many results; reduce the range by 10%
                if ($runMaxTime !== null && $to < $runMaxTime) {
                    $from = $from->modify(sprintf('+%d seconds', ($to->getTimestamp() - $from->getTimestamp()) / 10));
                } else {
                    $to = $to->modify(sprintf('-%d seconds', ($to->getTimestamp() - $from->getTimestamp()) / 10));
                }
            }

            // Store runs
            foreach ($runs as $run) {
                $this->updateRun($storage, $wptClient, $product, $run['id']);
                ++$runsStored;
                sleep(1);
            }
        }

        return $runsStored;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $storageHost = getenv('STORAGE_HOST');
        $storageDatabase = getenv('STORAGE_DATABASE');
        $storageUsername = getenv('STORAGE_USERNAME');
        $storagePassword = getenv('STORAGE_PASSWORD');
        $storage = new Storage($storageHost, $storageDatabase, $storageUsername, $storagePassword);

        $wptClient = new Client('https://wpt.fyi');
        $everySeconds = 10 * 60;

        while (true) {
            foreach ($storage->getProducts() as $product) {
                do {
                    $runsStored = $this->updateProductRuns($storage, $wptClient, $product);
                    sleep(1);
                } while ($runsStored > 0);
            }

            $this->io->writeln(sprintf('Sleeping for %d seconds', $everySeconds));
            sleep($everySeconds);
            $this->io->newLine();
        }

        return Command::SUCCESS;
    }
}
