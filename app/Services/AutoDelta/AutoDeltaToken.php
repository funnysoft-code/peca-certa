<?php

declare(strict_types=1);

namespace App\Services\AutoDelta;

use Carbon\CarbonInterface;

final readonly class AutoDeltaToken
{
    public function __construct(
        public string $apiKey,
        public string $catalogUserId,
        public CarbonInterface $expiresOn,
    ) {}

    public function isValid(): bool
    {
        return $this->expiresOn->isFuture();
    }
}
