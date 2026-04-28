<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PrimeSetRankingService;
use App\Service\PrimeSetSnapshotStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:warm-prime-set-cache', description: 'Builds the local snapshot for Warframe Market prime set ranking data.')]
final class WarmPrimeSetCacheCommand extends Command
{
    public function __construct(
        private readonly PrimeSetRankingService $rankingService,
        private readonly PrimeSetSnapshotStore $snapshotStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Refreshing local Prime set snapshot');

        $this->snapshotStore->markRefreshing();
        $this->snapshotStore->saveRefreshStatus([
            'status' => 'running',
            'phase' => 'fetching',
            'message' => 'Fetching live market data and rebuilding the local snapshot.',
            'logPath' => $this->snapshotStore->getRefreshLogPath(),
        ]);

        try {
            $progress = function (string $phase, int $current, ?int $total, string $message): void {
                $this->snapshotStore->saveRefreshStatus([
                    'status' => 'running',
                    'phase' => $phase,
                    'current' => $current,
                    'total' => $total,
                    'message' => $message,
                    'logPath' => $this->snapshotStore->getRefreshLogPath(),
                ]);
            };

            $result = $this->rankingService->getRankedPrimeSets(forceRefresh: true, progress: $progress);

            $this->snapshotStore->saveRefreshStatus([
                'status' => 'running',
                'phase' => 'saving',
                'message' => 'Saving the refreshed snapshot to disk.',
                'logPath' => $this->snapshotStore->getRefreshLogPath(),
            ]);

            $result['generatedAt'] = $result['generatedAt']->format(DATE_ATOM);
            $this->snapshotStore->saveSnapshot($result);

            $this->snapshotStore->saveRefreshStatus([
                'status' => 'completed',
                'phase' => 'done',
                'message' => sprintf('Snapshot updated with %d Prime sets.', count($result['sets'])),
                'logPath' => $this->snapshotStore->getRefreshLogPath(),
            ]);
        } catch (\Throwable $exception) {
            $this->snapshotStore->saveRefreshStatus([
                'status' => 'failed',
                'phase' => 'error',
                'message' => 'Refresh failed while building the snapshot.',
                'error' => $exception->getMessage(),
                'logPath' => $this->snapshotStore->getRefreshLogPath(),
            ]);

            throw $exception;
        } finally {
            $this->snapshotStore->clearRefreshing();
        }

        $io->success('Snapshot updated successfully.');

        return Command::SUCCESS;
    }
}
