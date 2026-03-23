<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ShortUrl;
use Psr\Cache\CacheItemPoolInterface;

final class ShortUrlCache implements ShortUrlCacheInterface
{
    private const TTL = 3600;
    private const KEY_PREFIX = 'short_url.';

    public function __construct(private readonly CacheItemPoolInterface $shortUrlPool)
    {
    }

    /** @return array{id: int|null, shortCode: string, originalUrl: string, expiresAt: int|null, isActive: bool}|null */
    public function get(string $shortCode): ?array
    {
        $item = $this->shortUrlPool->getItem($this->key($shortCode));

        return $item->isHit() ? $item->get() : null;
    }

    public function set(ShortUrl $shortUrl): void
    {
        $item = $this->shortUrlPool->getItem($this->key($shortUrl->getShortCode()));
        $item->set($this->serialize($shortUrl));
        $item->expiresAfter(self::TTL);
        $this->shortUrlPool->save($item);
    }

    public function delete(string $shortCode): void
    {
        $this->shortUrlPool->deleteItem($this->key($shortCode));
    }

    private function key(string $shortCode): string
    {
        return self::KEY_PREFIX.$shortCode;
    }

    /** @return array{id: int|null, shortCode: string, originalUrl: string, expiresAt: int|null, isActive: bool} */
    private function serialize(ShortUrl $shortUrl): array
    {
        return [
            'id' => $shortUrl->getId(),
            'shortCode' => $shortUrl->getShortCode(),
            'originalUrl' => $shortUrl->getOriginalUrl(),
            'expiresAt' => $shortUrl->getExpiresAt()?->getTimestamp(),
            'isActive' => $shortUrl->isActive(),
        ];
    }
}
