<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Rules\KeysIn;

trait Filterable
{
    /**
     * @param  list<string>  $filters
     * @return array<string, list<KeysIn|string>>
     */
    private function allowedFilters(array $filters): array
    {
        return [
            'filter' => ['array', new KeysIn($filters)],
            'filter.*' => ['nullable', 'string'],
        ];
    }
}
