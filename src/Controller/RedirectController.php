<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ShortUrlRepository;
use App\Service\ShortUrlCacheInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RedirectController extends AbstractController
{
    public function __construct(
        private readonly ShortUrlRepository $repository,
        private readonly ShortUrlCacheInterface $cache,
    ) {
    }

    #[Route('/{shortCode}', methods: ['GET'], requirements: ['shortCode' => '[a-zA-Z0-9_-]+'])]
    public function resolve(string $shortCode): Response
    {
        $cached = $this->cache->get($shortCode);

        if (null !== $cached) {
            if (!$cached['isActive']) {
                return $this->json(['error' => 'Short URL not found.'], Response::HTTP_NOT_FOUND);
            }

            if (null !== $cached['expiresAt'] && $cached['expiresAt'] < time()) {
                return $this->json(['error' => 'This short URL has expired.'], Response::HTTP_GONE);
            }

            // Cache hit: single UPDATE, no SELECT needed — all redirect data came from Redis.
            // A message queue would be more appropriate at high volume, but is out of scope here.
            $this->repository->incrementClickCount($shortCode);

            return new RedirectResponse($cached['originalUrl'], Response::HTTP_MOVED_PERMANENTLY);
        }

        // Cache miss — query the DB, warm the cache, then track the click.
        $shortUrl = $this->repository->findActiveByShortCode($shortCode);

        if (null === $shortUrl) {
            return $this->json(['error' => 'Short URL not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($shortUrl->isExpired()) {
            return $this->json(['error' => 'This short URL has expired.'], Response::HTTP_GONE);
        }

        $this->cache->set($shortUrl);
        $this->repository->incrementClickCount($shortCode);

        return new RedirectResponse($shortUrl->getOriginalUrl(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
