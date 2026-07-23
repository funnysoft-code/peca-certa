import { useHttp } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import { ResultsTable } from '@/components/parts/results-table';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useSearchRunFindings } from '@/hooks/use-search-run-findings';
import { unavailable as expandUnavailable } from '@/routes/search-runs/findings';

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

function isLookupPending(lookup: App.Data.SupplierLookupData): boolean {
    return lookup.status === 'pending' || lookup.status === 'running';
}

export function RunResults({ run }: { run: App.Data.SearchRunData }) {
    const { submit } = useHttp();
    const expandRequested = useRef(false);
    // Skip the mount pass: useSearchRunFindings already fetches on mount.
    // Only refetch when lookup statuses change after that (Echo/poll).
    const skipFingerprintRefetch = useRef(true);
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
    // Include result presence so a settled lookup always bumps the fingerprint
    // even if status labels were already terminal on first paint.
    const lookupsFingerprint = useMemo(
        () =>
            run.lookups
                .map((lookup) => {
                    const variantCount = lookup.result?.variants.length ?? 0;

                    return `${lookup.id}:${lookup.status}:${variantCount}`;
                })
                .join('|'),
        [run.lookups],
    );

    useEffect(() => {
        if (skipFingerprintRefetch.current) {
            skipFingerprintRefetch.current = false;

            return;
        }

        refetch();
        // Only re-run when lookup statuses/results change (broadcast/poll).
        // Query changes already fetch inside useSearchRunFindings.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lookupsFingerprint]);

    useEffect(() => {
        if (run.unavailableIncluded) {
            expandRequested.current = true;
        }
    }, [run.unavailableIncluded]);

    const handleInStockOnlyChange = useCallback(
        (inStockOnly: boolean): void => {
            setInStockOnly(inStockOnly);

            // Showing OOS: if this run never fetched them, kick a re-price
            // with includeUnavailable=true. Hide path is filter-only.
            if (
                !inStockOnly &&
                !run.unavailableIncluded &&
                !expandRequested.current
            ) {
                expandRequested.current = true;
                void submit(expandUnavailable(run.id)).catch(() => {
                    expandRequested.current = false;
                });
            }
        },
        [run.id, run.unavailableIncluded, setInStockOnly, submit],
    );

    const lookups = run.lookups;

    if (lookups.length === 0) {
        return null;
    }

    const pending = lookups.filter(isLookupPending);
    const hasSettledLookup = lookups.some((lookup) => !isLookupPending(lookup));
    // Broadcast-only failures can leave status=failed while result is populated.
    // Only surface the error when there is nothing useful to show for that lookup.
    const failed = lookups.filter(
        (lookup) =>
            lookup.status === 'failed' &&
            (lookup.result?.variants.length ?? 0) === 0,
    );

    const total = findings?.meta.total ?? 0;
    // Skeleton only while every supplier is still in flight and we have nothing
    // to show. As soon as one lookup settles (or findings land), show the table
    // so progressive results appear without waiting on the slow supplier.
    const showInitialSkeleton =
        !hasSettledLookup && total === 0 && !loading && findings === null;
    const providerLinks = toProviderLinks(lookups);
    const pendingSupplierLabels = [
        ...new Set(pending.map((lookup) => SUPPLIER_LABELS[lookup.supplier])),
    ];

    return (
        <div className="flex flex-col gap-4">
            {pending.length > 0 && (
                <div className="flex flex-col gap-2">
                    <p className="text-sm text-muted-foreground">
                        A pesquisar em {pendingSupplierLabels.join(', ')}…
                    </p>
                    {showInitialSkeleton && (
                        <div className="flex flex-col gap-2">
                            <Skeleton className="h-10 w-full" />
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
                    onInStockOnlyChange={handleInStockOnlyChange}
                    onSortChange={setSort}
                    onPageChange={setPage}
                    onPerPageChange={setPerPage}
                />
            )}
        </div>
    );
}
