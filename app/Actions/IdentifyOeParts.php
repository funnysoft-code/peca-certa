<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

final readonly class IdentifyOeParts
{
    public function __construct(
        private PartsLink24Catalog $catalog,
    ) {}

    /**
     * @param  list<string>  $keywords
     * @return list<OePart>
     */
    public function execute(string $vin, string $category, array $keywords): array
    {
        return $this->catalog->resolveOeParts($vin, $category, $keywords);
    }
}
