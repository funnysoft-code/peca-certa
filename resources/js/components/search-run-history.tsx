import { Link, router } from '@inertiajs/react';
import { HistoryIcon, SearchIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemTitle,
} from '@/components/ui/item';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    SEARCH_RUN_STATUS_LABELS,
    SEARCH_RUN_STATUS_VARIANTS,
} from '@/lib/search-run-status';
import { formatRelativeTime } from '@/lib/utils';

type Scope = 'everyone' | 'mine';

type Filters = {
    scope: Scope;
    q: string;
};

type PaginatorLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

type PaginatorMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string | null;
    per_page: number;
    to: number | null;
    total: number;
};

type PaginatedRuns = {
    data: App.Data.SearchRunData[];
    links: PaginatorLinks;
    meta: PaginatorMeta;
};

type Props = {
    // Generated TS keeps links/meta as unknown; shape matches PaginatedSearchRunsData.
    runs: PaginatedRuns;
    filters: Filters;
    indexUrl: string;
    showUrl: (id: string) => string;
    primaryLabel: (run: App.Data.SearchRunData) => string;
    emptyNoRuns: string;
    emptyNoMatches: string;
};

function visitFilters(indexUrl: string, filters: Filters, page?: number): void {
    const params: Record<string, string> = {
        scope: filters.scope,
    };

    if (filters.q.trim() !== '') {
        params.q = filters.q.trim();
    }

    if (page !== undefined && page > 1) {
        params.page = String(page);
    }

    router.get(indexUrl, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

export function SearchRunHistory({
    runs,
    filters,
    indexUrl,
    showUrl,
    primaryLabel,
    emptyNoRuns,
    emptyNoMatches,
}: Props) {
    const [query, setQuery] = useState(filters.q);

    useEffect(() => {
        setQuery(filters.q);
    }, [filters.q]);

    const hasQuery = filters.q.trim() !== '';
    const isEmpty = runs.data.length === 0;

    function applyScope(scope: Scope): void {
        visitFilters(indexUrl, { scope, q: filters.q });
    }

    function submitSearch(event: React.FormEvent): void {
        event.preventDefault();
        visitFilters(indexUrl, { scope: filters.scope, q: query });
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 className="text-sm font-medium text-muted-foreground">
                    Histórico de pesquisas
                </h2>
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <ToggleGroup
                        type="single"
                        value={filters.scope}
                        onValueChange={(value) => {
                            if (value === 'everyone' || value === 'mine') {
                                applyScope(value);
                            }
                        }}
                        variant="outline"
                        size="sm"
                        className="justify-start"
                    >
                        <ToggleGroupItem value="everyone" aria-label="Todos">
                            Todos
                        </ToggleGroupItem>
                        <ToggleGroupItem value="mine" aria-label="Meus">
                            Meus
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <form
                        onSubmit={submitSearch}
                        className="flex min-w-0 flex-1 gap-2 sm:max-w-xs"
                    >
                        <InputGroup className="h-8">
                            <InputGroupAddon align="inline-start">
                                <SearchIcon />
                            </InputGroupAddon>
                            <InputGroupInput
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Pesquisar pedido, VIN, ref., autor…"
                                aria-label="Pesquisar no histórico"
                                className="h-8"
                            />
                        </InputGroup>
                        <Button type="submit" size="sm" variant="secondary">
                            Filtrar
                        </Button>
                    </form>
                </div>
            </div>

            {isEmpty ? (
                <Empty className="border border-dashed">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <HistoryIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            {hasQuery
                                ? 'Sem correspondências'
                                : 'Sem pesquisas ainda'}
                        </EmptyTitle>
                        <EmptyDescription>
                            {hasQuery ? emptyNoMatches : emptyNoRuns}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <>
                    <ItemGroup className="gap-2">
                        {runs.data.map((run) => (
                            <Item
                                key={run.id}
                                variant="outline"
                                size="sm"
                                asChild
                            >
                                <Link href={showUrl(run.id)}>
                                    <ItemContent>
                                        <ItemTitle className="truncate">
                                            {primaryLabel(run)}
                                        </ItemTitle>
                                        <ItemDescription>
                                            {run.authorName || 'Desconhecido'}
                                            {' · '}
                                            {formatRelativeTime(run.createdAt)}
                                        </ItemDescription>
                                    </ItemContent>
                                    <ItemActions>
                                        <Badge
                                            variant={
                                                SEARCH_RUN_STATUS_VARIANTS[
                                                    run.status
                                                ]
                                            }
                                        >
                                            {
                                                SEARCH_RUN_STATUS_LABELS[
                                                    run.status
                                                ]
                                            }
                                        </Badge>
                                    </ItemActions>
                                </Link>
                            </Item>
                        ))}
                    </ItemGroup>

                    {runs.meta.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <p className="text-muted-foreground">
                                Página {runs.meta.current_page} de{' '}
                                {runs.meta.last_page}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={runs.links.prev === null}
                                    onClick={() =>
                                        visitFilters(
                                            indexUrl,
                                            filters,
                                            runs.meta.current_page - 1,
                                        )
                                    }
                                >
                                    Anterior
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={runs.links.next === null}
                                    onClick={() =>
                                        visitFilters(
                                            indexUrl,
                                            filters,
                                            runs.meta.current_page + 1,
                                        )
                                    }
                                >
                                    Seguinte
                                </Button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
