<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateShortUrlRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'URL is required.')]
        #[Assert\Url(message: 'The value "{{ value }}" is not a valid URL.')]
        #[Assert\Length(max: 2048, maxMessage: 'URL must not exceed 2048 characters.')]
        public readonly string $url,

        #[Assert\Length(
            min: 3,
            max: 16,
            minMessage: 'Custom alias must be at least {{ limit }} characters.',
            maxMessage: 'Custom alias must not exceed {{ limit }} characters.',
        )]
        #[Assert\Regex(
            pattern: '/^[a-zA-Z0-9_-]+$/',
            message: 'Custom alias may only contain letters, numbers, hyphens, and underscores.',
        )]
        public readonly ?string $customAlias = null,

        public readonly ?string $expiresAt = null,
    ) {
    }
}
