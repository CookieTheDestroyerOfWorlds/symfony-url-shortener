<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly CacheItemPoolInterface $appPool,
    ) {
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'app' => 'symfony-url-shortener',
        ]);
    }

    #[Route('/ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $allOk = !in_array(false, $checks, strict: true);

        return $this->json(
            ['status' => $allOk ? 'ok' : 'degraded', 'checks' => $checks],
            $allOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function checkDatabase(): bool
    {
        try {
            $this->db->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        try {
            $item = $this->appPool->getItem('health_probe');
            $item->set(1);
            $item->expiresAfter(5);

            return $this->appPool->save($item);
        } catch (\Throwable) {
            return false;
        }
    }
}
