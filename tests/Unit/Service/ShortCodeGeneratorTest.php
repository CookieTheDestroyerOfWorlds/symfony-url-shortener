<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\ShortUrlRepository;
use App\Service\ShortCodeGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ShortCodeGeneratorTest extends TestCase
{
    private ShortUrlRepository&Stub $repository;
    private ShortCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(ShortUrlRepository::class);
        $this->generator = new ShortCodeGenerator($this->repository);
    }

    #[Test]
    public function generatesSevenCharCode(): void
    {
        $this->repository->method('shortCodeExists')->willReturn(false);

        $code = $this->generator->generate();

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{7}$/', $code);
    }

    #[Test]
    public function retriesOnCollisionAndReturnsUniqueCode(): void
    {
        // First 5 calls return "exists", 6th returns "free"
        $this->repository
            ->method('shortCodeExists')
            ->willReturnOnConsecutiveCalls(true, true, true, true, true, false);

        $code = $this->generator->generate();

        $this->assertNotEmpty($code);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $code);
    }

    #[Test]
    public function fallsBackToLongerCodeWhenFirstPassExhausted(): void
    {
        // First 10 standard-length attempts all collide; 11th (longer) is free
        $returns = array_merge(array_fill(0, 10, true), [false]);
        $this->repository->method('shortCodeExists')->willReturnOnConsecutiveCalls(...$returns);

        $code = $this->generator->generate();

        $this->assertGreaterThan(7, strlen($code));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $code);
    }

    #[Test]
    public function throwsWhenAllAttemptsExhausted(): void
    {
        // All 20 attempts (10 standard + 10 longer) collide
        $this->repository->method('shortCodeExists')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unique short code');

        $this->generator->generate();
    }
}
