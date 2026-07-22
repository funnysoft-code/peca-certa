<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

trait Sortable
{
    /**
     * @param  list<string>  $sorts
     * @return array<string, list<In|string>>
     */
    private function allowedSorts(array $sorts): array
    {
        return [
            'sort' => ['string', Rule::in($this->parseSorts($sorts))],
        ];
    }

    /**
     * @param  list<string>  $sorts
     * @return list<string>
     */
    private function parseSorts(array $sorts): array
    {
        $parsedSorts = [];

        foreach ($sorts as $sort) {
            $parsedSorts[] = $sort;
            $parsedSorts[] = '-'.$sort;
        }

        return $parsedSorts;
    }
}
