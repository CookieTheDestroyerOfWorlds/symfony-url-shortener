<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShortUrlRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShortUrlRepository::class)]
#[ORM\Table(name: 'short_urls')]
#[ORM\Index(columns: ['short_code'], name: 'idx_short_code')]
class ShortUrl
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, unique: true)]
    private string $shortCode;

    #[ORM\Column(type: 'text')]
    private string $originalUrl;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'integer')]
    private int $clickCount = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    public function __construct(string $shortCode, string $originalUrl)
    {
        $this->shortCode = $shortCode;
        $this->originalUrl = $originalUrl;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function getOriginalUrl(): string
    {
        return $this->originalUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getClickCount(): int
    {
        return $this->clickCount;
    }

    public function incrementClickCount(): void
    {
        ++$this->clickCount;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < new \DateTimeImmutable();
    }
}
