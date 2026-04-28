<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PrimeSetSnapshotStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RefreshController extends AbstractController
{
    #[Route('/refresh', name: 'app_refresh', methods: ['POST'])]
    public function refresh(PrimeSetSnapshotStore $snapshotStore): Response
    {
        if ($snapshotStore->isRefreshing()) {
            return $this->redirectToRoute('app_home', ['refresh' => 'running']);
        }

        $projectDir = $snapshotStore->getProjectDir();
        $refreshLogPath = $snapshotStore->getRefreshLogPath();

        exec('sh -lc "command -v php >/dev/null 2>&1"', $phpCheckOutput, $phpCheckCode);

        if ($phpCheckCode !== 0) {
            $snapshotStore->saveRefreshStatus([
                'status' => 'failed',
                'message' => 'PHP CLI is not available in the app container, so the refresh cannot be started.',
                'logPath' => $refreshLogPath,
            ]);

            return $this->redirectToRoute('app_home', ['refresh' => 'failed']);
        }

        $snapshotStore->markRefreshing();
        $snapshotStore->saveRefreshStatus([
            'status' => 'queued',
            'message' => 'Refresh has been queued and will start shortly.',
            'logPath' => $refreshLogPath,
        ]);

        $command = sprintf(
            'cd %s && nohup php bin/console app:warm-prime-set-cache > %s 2>&1 < /dev/null &',
            escapeshellarg($projectDir),
            escapeshellarg($refreshLogPath)
        );

        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {
            $snapshotStore->clearRefreshing();
            $snapshotStore->saveRefreshStatus([
                'status' => 'failed',
                'message' => 'The refresh process could not be started.',
                'logPath' => $refreshLogPath,
            ]);

            return $this->redirectToRoute('app_home', ['refresh' => 'failed']);
        }

        return $this->redirectToRoute('app_home', ['refresh' => 'started']);
    }
}
