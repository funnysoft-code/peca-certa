<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Finding;
use Illuminate\Pagination\LengthAwarePaginator;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Laravel Resource-style paginator envelope (data + links + meta).
 *
 * @phpstan-type PaginatorLink array{url: string|null, label: string, active: bool}
 * @phpstan-type PaginatorLinks array{first: string|null, last: string|null, prev: string|null, next: string|null}
 * @phpstan-type PaginatorMeta array{
 *     current_page: int,
 *     from: int|null,
 *     last_page: int,
 *     path: string|null,
 *     per_page: int,
 *     to: int|null,
 *     total: int,
 *     links: list<PaginatorLink>
 * }
 */
#[TypeScript]
final readonly class PaginatedFindingsData implements JsonSerializable
{
    /**
     * @param  list<FindingData>  $data
     * @param  PaginatorLinks  $links
     * @param  PaginatorMeta  $meta
     */
    public function __construct(
        public array $data,
        public array $links,
        public array $meta,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Finding>  $paginator
     */
    public static function fromPaginator(LengthAwarePaginator $paginator): self
    {
        /** @var list<Finding> $rawItems */
        $rawItems = array_values($paginator->items());

        $items = array_map(
            FindingData::fromModel(...),
            $rawItems,
        );

        /** @var list<PaginatorLink> $pageLinks */
        $pageLinks = [];

        foreach ($paginator->linkCollection()->all() as $link) {
            /** @var array{url?: mixed, label?: mixed, active?: mixed} $link */
            $url = $link['url'] ?? null;
            $label = $link['label'] ?? '';

            $pageLinks[] = [
                'url' => is_string($url) ? $url : null,
                'label' => is_string($label) || is_numeric($label) ? (string) $label : '',
                'active' => (bool) ($link['active'] ?? false),
            ];
        }

        return new self(
            data: $items,
            links: [
                'first' => $paginator->url(1),
                'last' => $paginator->url(max(1, $paginator->lastPage())),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            meta: [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
                'links' => $pageLinks,
            ],
        );
    }

    /**
     * @return array{data: list<FindingData>, links: PaginatorLinks, meta: PaginatorMeta}
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->data,
            'links' => $this->links,
            'meta' => $this->meta,
        ];
    }
}
