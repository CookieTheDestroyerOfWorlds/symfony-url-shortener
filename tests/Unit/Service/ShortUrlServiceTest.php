<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\CreateShortUrlRequest;
use App\Entity\ShortUrl;
use App\Repository\ShortUrlRepository;
use App\Service\ShortCodeGeneratorInterface;
use App\Service\ShortUrlCacheInterface;
use App\Service\ShortUrlService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ShortUrlServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ShortUrlRepository&MockObject $repository;
    private ShortCodeGeneratorInterface&MockObject $generator;
    private ShortUrlCacheInterface&MockObject $cache;
    private ShortUrlService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(ShortUrlRepository::class);
        $this->generator = $this->createMock(ShortCodeGeneratorInterface::class);
        $this->cache = $this->createMock(ShortUrlCacheInterface::class);

        $this->service = new ShortUrlService(
            $this->em,
            $this->repository,
            $this->generator,
            $this->cache,
        );
    }

    #[Test]
    public function createPersistsAndCachesShortUrl(): void
    {
        $this->generator->method('generate')->willReturn('abc1234');
        $this->repository->method('shortCodeExists')->willReturn(false);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');
        $this->cache->expects($this->once())->method('set');

        $request = new CreateShortUrlRequest(url: 'https://example.com');
        $result = $this->service->create($request);

        $this->assertSame('abc1234', $result->getShortCode());
        $this->assertSame('https://example.com', $result->getOriginalUrl());
        $this->assertTrue($result->isActive());
        $this->assertSame(0, $result->getClickCount());
    }

    #[Test]
    public function createUsesCustomAlias(): void
    {
        $this->repository->method('shortCodeExists')->willReturn(false);
        $this->generator->expects($this->never())->method('generate');
        $this->em->method('persist');
        $this->em->method('flush');
        $this->cache->method('set');

        $request = new CreateShortUrlRequest(url: 'https://example.com', customAlias: 'my-link');
        $result = $this->service->create($request);

        $this->assertSame('my-link', $result->getShortCode());
    }

    #[Test]
    public function createThrowsOnDuplicateCustomAlias(): void
    {
        $this->repository->method('shortCodeExists')->willReturn(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"taken-alias" is already taken');

        $this->service->create(new CreateShortUrlRequest(url: 'https://example.com', customAlias: 'taken-alias'));
    }

    #[Test]
    public function createSetsExpiryFromIsoDate(): void
    {
        $this->generator->method('generate')->willReturn('xyz');
        $this->repository->method('shortCodeExists')->willReturn(false);
        $this->em->method('persist');
        $this->em->method('flush');
        $this->cache->method('set');

        $future = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        $result = $this->service->create(new CreateShortUrlRequest(url: 'https://example.com', expiresAt: $future));

        $this->assertNotNull($result->getExpiresAt());
        $this->assertFalse($result->isExpired());
    }

    #[Test]
    public function createThrowsOnPastExpiryDate(): void
    {
        $this->generator->method('generate')->willReturn('xyz');
        $this->repository->method('shortCodeExists')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('future date');

        $this->service->create(new CreateShortUrlRequest(url: 'https://example.com', expiresAt: '2000-01-01'));
    }

    #[Test]
    public function createRetriesOnUniqueConstraintViolationForGeneratedCode(): void
    {
        $this->repository->method('shortCodeExists')->willReturn(false);
        $this->generator->method('generate')->willReturn('aaa1111', 'bbb2222');
        $this->cache->method('set');

        $uniqueEx = $this->createMock(UniqueConstraintViolationException::class);

        // First flush throws, second succeeds
        $this->em->expects($this->exactly(2))->method('flush')
            ->willReturnOnConsecutiveCalls($this->throwException($uniqueEx), null);
        $this->em->expects($this->once())->method('clear');

        $result = $this->service->create(new CreateShortUrlRequest(url: 'https://example.com'));

        $this->assertSame('bbb2222', $result->getShortCode());
    }

    #[Test]
    public function createThrowsInvalidArgumentOnUniqueConstraintViolationForCustomAlias(): void
    {
        $this->repository->method('shortCodeExists')->willReturn(false);
        $this->cache->method('set');

        $uniqueEx = $this->createMock(UniqueConstraintViolationException::class);

        $this->em->method('flush')->willThrowException($uniqueEx);
        $this->em->method('clear');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"my-alias" is already taken');

        $this->service->create(new CreateShortUrlRequest(url: 'https://example.com', customAlias: 'my-alias'));
    }

    #[Test]
    public function deactivateSetsInactiveAndInvalidatesCache(): void
    {
        $shortUrl = new ShortUrl('abc', 'https://example.com');

        $this->em->expects($this->once())->method('flush');
        $this->cache->expects($this->once())->method('delete')->with('abc');

        $this->service->deactivate($shortUrl);

        $this->assertFalse($shortUrl->isActive());
    }
}
