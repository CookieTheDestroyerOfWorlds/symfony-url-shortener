<?php

declare(strict_types=1);

namespace App\Service;

interface ShortCodeGeneratorInterface
{
    public function generate(): string;
}
