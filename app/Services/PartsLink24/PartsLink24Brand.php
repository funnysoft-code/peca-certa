<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

final readonly class PartsLink24Brand
{
    public function __construct(
        public string $key,
        public string $service,
        public string $group,
    ) {}
}
