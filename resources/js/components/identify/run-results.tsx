import { ExternalLink } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { ResultsTable } from '@/components/parts/results-table';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useSearchRunFindings } from '@/hooks/use-search-run-findings';

const SUPPLIER_LABELS: Record<App.Enums.Supplier, string> = {
    autodelta: 'Auto Delta',
    autozitania: 'Auto Zitânia',
};

type ProviderLink = {
    supplier: App.Enums.Supplier;
    query: string;
    url: string;
};

// One link per (supplier, OE number) lookup that has a searchUrl, so up to 5
// OE candidates each get their own Auto Delta link instead of collapsing to
// one. Lookups that share the identical (supplier, searchUrl), e.g. Auto
// Zitânia's single branded entry URL repeated across every OE part, collapse
// to one link.
function toProviderLinks(
    lookups: App.Data.SupplierLookupData[],
): ProviderLink[] {
    const byOeNumber = new Map<string, ProviderLink>();

    for (const lookup of lookups) {
        const url = lookup.result?.searchUrl;

        if (!url) {
            continue;
        }

        byOeNumber.set(`${lookup.supplier}:${lookup.query}`, {
            supplier: lookup.supplier,
            query: lookup.query,
            url,
        });
    }

    const byUrl = new Map<string, ProviderLink>();

    for (const link of byOeNumber.values()) {
        byUrl.set(`${link.supplier}:${link.url}`, link);
    }

    return Array.from(byUrl.values());
}

export function RunResults({ run }: { run: App.Data.SearchRunData }) {
    const {
        findings,
        query,
        loading,
        error,
        setSearch,
        setInStockOnly,
        setSort,
        setPage,
        setPerPage,
        refetch,
    } = useSearchRunFindings(run.id);

    // When Echo/poll reloads the run prop (lookup.ready), refetch the current
    // findings query so new rows appear without resetting filters/sort/page.
    const lookupsFingerprint = useMemo(
        () =>
            run.lookups
                .map((lookup) => `${lookup.id}:${lookup.status}`)
                .join('|'),
        [run.lookups],
    );

    useEffect(() => {
        refetch();
        // Only re-run when lookup statuses change (broadcast/poll). Query changes
        // already fetch inside useSearchRunFindings.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lookupsFingerprint]);

    const lookups = run.lookups;

    if (lookups.length === 0) {
        return null;
    }

    const pending = lookups.filter(
        (lookup) => lookup.status === 'pending' || lookup.status === 'running',
    );
    // Broadcast-only failures can leave status=failed while result is populated.
    // Only surface the error when there is nothing useful to show for that lookup.
    const failed = lookups.filter(
        (lookup) =>
            lookup.status === 'failed' &&
            (lookup.result?.variants.length ?? 0) === 0,
    );

    const total = findings?.meta.total ?? 0;
    const showInitialSkeleton = pending.length > 0 && total === 0 && !loading;
    const providerLinks = toProviderLinks(lookups);

    return (
        <div className="space-y-4">
            {pending.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        A pesquisar em{' '}
                        {pending
                            .map((lookup) => SUPPLIER_LABELS[lookup.supplier])
                            .join(', ')}
                        …
                    </p>
                    {showInitialSkeleton && (
                        <div className="space-y-2">
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                        </div>
                    )}
                </div>
            )}

            {failed.length > 0 && (
                <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                    Falha ao consultar{' '}
                    {[
                        ...new Set(
                            failed.map(
                                (lookup) => SUPPLIER_LABELS[lookup.supplier],
                            ),
                        ),
                    ].join(', ')}
                    .
                </div>
            )}

            {providerLinks.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {providerLinks.map((link) => (
                        <Button
                            key={`${link.supplier}:${link.url}`}
                            asChild
                            variant="outline"
                            size="sm"
                        >
                            <a
                                href={link.url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink className="size-4" />
                                Abrir {link.query} em{' '}
                                {SUPPLIER_LABELS[link.supplier]}
                            </a>
                        </Button>
                    ))}
                </div>
            )}

            {!showInitialSkeleton && (
                <ResultsTable
                    findings={findings}
                    loading={loading}
                    error={error}
                    search={query.search}
                    inStockOnly={query.inStockOnly}
                    sort={query.sort}
                    page={query.page}
                    perPage={query.perPage}
                    onSearchChange={setSearch}
                    onInStockOnlyChange={setInStockOnly}
                    onSortChange={setSort}
                    onPageChange={setPage}
                    onPerPageChange={setPerPage}
                />
            )}
        </div>
    );
}
