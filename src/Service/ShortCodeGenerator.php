<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ShortUrlRepository;

final class ShortCodeGenerator implements ShortCodeGeneratorInterface
{
    private const CODE_LENGTH = 7;
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly ShortUrlRepository $repository)
    {
    }

    public function generate(): string
    {
        // First pass: standard length
        for ($i = 0; $i < self::MAX_ATTEMPTS; ++$i) {
            $code = $this->randomString(self::CODE_LENGTH);

            if (!$this->repository->shortCodeExists($code)) {
                return $code;
            }
        }

        // Second pass: longer codes — still checked for uniqueness
        for ($i = 0; $i < self::MAX_ATTEMPTS; ++$i) {
            $code = $this->randomString(self::CODE_LENGTH + 3);

            if (!$this->repository->shortCodeExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique short code after maximum attempts.');
    }

    private function randomString(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
