<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Carbon\CarbonInterface;

final readonly class PartsLink24Token
{
    public function __construct(
        public string $accessToken,
        public CarbonInterface $expiresAt,
    ) {}

    public function isValid(): bool
    {
        return $this->expiresAt->isFuture();
    }
}
