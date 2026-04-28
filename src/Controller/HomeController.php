<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PartIconResolver;
use App\Service\PrimeSetSnapshotStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        Request $request,
        PrimeSetSnapshotStore $snapshotStore,
        PartIconResolver $partIconResolver,
    ): Response {
        $snapshot = $snapshotStore->loadSnapshot();
        $refreshStatus = $snapshotStore->loadRefreshStatus();
        $sets = $snapshot['sets'] ?? [];

        foreach ($sets as &$set) {
            if (!is_array($set)) {
                continue;
            }

            $category = (string) ($set['category'] ?? 'other');
            $parts = $set['parts'] ?? [];

            if (!is_array($parts)) {
                continue;
            }

            foreach ($parts as &$part) {
                if (!is_array($part)) {
                    continue;
                }

                $part['imageUrl'] = $partIconResolver->resolve(
                    $category,
                    (string) ($part['slug'] ?? ''),
                    isset($part['imageUrl']) && is_string($part['imageUrl']) ? $part['imageUrl'] : null,
                );
            }

            unset($part);
            $set['parts'] = $parts;
        }

        unset($set);

        return $this->render('home/index.html.twig', [
            'generatedAt' => isset($snapshot['generatedAt']) ? new \DateTimeImmutable((string) $snapshot['generatedAt']) : null,
            'sets' => $sets,
            'stats' => $snapshot['stats'] ?? ['setCount' => 0, 'cheaperAsSet' => 0, 'cheaperAsParts' => 0],
            'refreshing' => $snapshotStore->isRefreshing(),
            'refreshState' => $request->query->get('refresh'),
            'refreshStatusData' => $refreshStatus,
            'hasSnapshot' => $snapshot !== null,
        ]);
    }

    #[Route('/refresh-status', name: 'app_refresh_status', methods: ['GET'])]
    public function refreshStatus(PrimeSetSnapshotStore $snapshotStore): JsonResponse
    {
        $status = $snapshotStore->loadRefreshStatus() ?? ['status' => 'idle'];
        $status['refreshing'] = $snapshotStore->isRefreshing();

        return $this->json($status);
    }
}
