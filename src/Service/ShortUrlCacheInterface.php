<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ShortUrl;

interface ShortUrlCacheInterface
{
    /** @return array{id: int|null, shortCode: string, originalUrl: string, expiresAt: int|null, isActive: bool}|null */
    public function get(string $shortCode): ?array;

    public function set(ShortUrl $shortUrl): void;

    public function delete(string $shortCode): void;
}
