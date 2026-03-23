<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateShortUrlRequest;
use App\Entity\ShortUrl;
use App\Repository\ShortUrlRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class ShortUrlService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShortUrlRepository $repository,
        private readonly ShortCodeGeneratorInterface $codeGenerator,
        private readonly ShortUrlCacheInterface $cache,
    ) {
    }

    private const MAX_INSERT_ATTEMPTS = 3;

    public function create(CreateShortUrlRequest $request): ShortUrl
    {
        if (null !== $request->customAlias && $this->repository->shortCodeExists($request->customAlias)) {
            throw new \InvalidArgumentException(sprintf('The alias "%s" is already taken.', $request->customAlias));
        }

        $expiresAt = $this->resolveExpiresAt($request->expiresAt);

        for ($attempt = 1; $attempt <= self::MAX_INSERT_ATTEMPTS; ++$attempt) {
            $shortCode = $request->customAlias ?? $this->codeGenerator->generate();
            $shortUrl = new ShortUrl($shortCode, $request->url);

            if (null !== $expiresAt) {
                $shortUrl->setExpiresAt($expiresAt);
            }

            try {
                $this->em->persist($shortUrl);
                $this->em->flush();
                $this->cache->set($shortUrl);

                return $shortUrl;
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent request inserted the same code between our check and insert.
                // Clear the unit of work so the EM is usable for the next attempt.
                // (Doctrine 3 does not auto-close the EM on exceptions.)
                $this->em->clear();

                if (null !== $request->customAlias) {
                    // Custom aliases can't be regenerated — surface the conflict.
                    throw new \InvalidArgumentException(sprintf('The alias "%s" is already taken.', $request->customAlias), previous: $e);
                }

                if (self::MAX_INSERT_ATTEMPTS === $attempt) {
                    throw new \RuntimeException('Failed to persist a unique short code after retries.', previous: $e);
                }
            }
        }

        // Unreachable, but satisfies the return type analyser.
        throw new \LogicException('Unreachable.');
    }

    private function resolveExpiresAt(?string $raw): ?\DateTimeImmutable
    {
        if (null === $raw) {
            return null;
        }

        $expiresAt = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $raw)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        if (!$expiresAt) {
            throw new \InvalidArgumentException('Invalid expiresAt date. Use ISO 8601 format (e.g. 2026-12-31 or 2026-12-31T23:59:59+00:00).');
        }

        if ($expiresAt <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('expiresAt must be a future date.');
        }

        return $expiresAt;
    }

    public function deactivate(ShortUrl $shortUrl): void
    {
        $shortUrl->setIsActive(false);
        $this->em->flush();
        $this->cache->delete($shortUrl->getShortCode());
    }
}
