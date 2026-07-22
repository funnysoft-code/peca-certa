<?php

declare(strict_types=1);

namespace App\Concerns;

trait HandlesPagination
{
    public function getPage(): int
    {
        $page = $this->query('page', 1);

        return max(1, is_numeric($page) ? (int) $page : 1);
    }

    public function getLimit(): int
    {
        $defaultPaginationSize = config('peca.pagination_size');
        $maxPaginationSize = config('peca.max_pagination_size');

        // Non-numeric config falls back to safe defaults (defensive for misconfig).
        $default = is_numeric($defaultPaginationSize) ? (int) $defaultPaginationSize : 25;
        $max = is_numeric($maxPaginationSize) ? (int) $maxPaginationSize : 50;

        $requested = $this->query('per_page');
        $requestedPaginationSize = is_numeric($requested) ? (int) $requested : 0;

        if (
            $requestedPaginationSize >= 1
            && $requestedPaginationSize <= $max
        ) {
            return $requestedPaginationSize;
        }

        return $default;
    }

    /**
     * @return array<string, list<string>>
     */
    private function paginationRules(): array
    {
        return [
            'per_page' => ['integer'],
            'page' => ['integer'],
        ];
    }
}
