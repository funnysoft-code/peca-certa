import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
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

const STATUS_LABELS: Record<App.Enums.SearchRunStatus, string> = {
    pending: 'Pendente',
    running: 'Em curso',
    needs_input: 'Aguarda resposta',
    done: 'Concluído',
    failed: 'Falhou',
};

const STATUS_VARIANTS: Record<
    App.Enums.SearchRunStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    running: 'secondary',
    needs_input: 'outline',
    done: 'default',
    failed: 'destructive',
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
        <div className="space-y-3">
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
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Pesquisar pedido, VIN, ref., autor…"
                            aria-label="Pesquisar no histórico"
                            className="h-8"
                        />
                        <Button type="submit" size="sm" variant="secondary">
                            Filtrar
                        </Button>
                    </form>
                </div>
            </div>

            {isEmpty ? (
                <div className="rounded-xl border border-dashed border-border bg-card/50 px-4 py-8 text-center text-sm text-muted-foreground">
                    {hasQuery ? emptyNoMatches : emptyNoRuns}
                </div>
            ) : (
                <>
                    <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                        {runs.data.map((run) => (
                            <li key={run.id}>
                                <Link
                                    href={showUrl(run.id)}
                                    className="flex items-center justify-between gap-4 px-4 py-3 text-sm transition-colors hover:bg-muted/50"
                                >
                                    <span className="min-w-0 flex-1 space-y-0.5">
                                        <span className="block truncate font-medium">
                                            {primaryLabel(run)}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {run.authorName || 'Desconhecido'}
                                        </span>
                                    </span>
                                    <span className="flex shrink-0 items-center gap-3 text-muted-foreground">
                                        <Badge
                                            variant={
                                                STATUS_VARIANTS[run.status]
                                            }
                                        >
                                            {STATUS_LABELS[run.status]}
                                        </Badge>
                                        <span>
                                            {formatRelativeTime(run.createdAt)}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>

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
