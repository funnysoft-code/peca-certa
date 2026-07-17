import { ChevronDown, ExternalLink } from 'lucide-react';
import {
    ResultsTable,
    type ResultRow,
    type StockMode,
} from '@/components/parts/results-table';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Skeleton } from '@/components/ui/skeleton';

const SUPPLIER_LABELS: Record<App.Enums.Supplier, string> = {
    autodelta: 'Auto Delta',
    autozitania: 'Auto Zitânia',
};

const SUPPLIER_STOCK_MODES: Record<App.Enums.Supplier, StockMode> = {
    autodelta: 'quantity',
    autozitania: 'availability',
};

// Auto Delta is where R2CZ buys, so its price column shows the purchase price;
// Auto Zitânia only exposes the retail (PVP) price.
const SUPPLIER_PRICE: Record<
    App.Enums.Supplier,
    (variant: App.Data.PartVariant) => number | null
> = {
    autodelta: (variant) => variant.purchasePrice,
    autozitania: (variant) => variant.retailPrice,
};

function toRows(lookup: App.Data.SupplierLookupData): ResultRow[] {
    if (lookup.result === null) {
        return [];
    }

    return lookup.result.variants.map((variant) => ({
        variant,
        supplier: SUPPLIER_LABELS[lookup.supplier],
        stockMode: SUPPLIER_STOCK_MODES[lookup.supplier],
        price: SUPPLIER_PRICE[lookup.supplier](variant),
    }));
}

function byBrandThenSupplier(a: ResultRow, b: ResultRow): number {
    return (
        a.variant.brandName.localeCompare(b.variant.brandName) ||
        a.supplier.localeCompare(b.supplier)
    );
}

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

export function RunResults({
    lookups,
}: {
    lookups: App.Data.SupplierLookupData[];
}) {
    if (lookups.length === 0) {
        return null;
    }

    const pending = lookups.filter(
        (lookup) => lookup.status === 'pending' || lookup.status === 'running',
    );
    const failed = lookups.filter((lookup) => lookup.status === 'failed');

    const rows = lookups.flatMap(toRows).sort(byBrandThenSupplier);
    const available = rows.filter((row) => row.variant.inStock);
    const unavailable = rows.filter((row) => !row.variant.inStock);
    const hasResults = rows.length > 0;

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
                    {!hasResults && (
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
                    {failed
                        .map((lookup) => SUPPLIER_LABELS[lookup.supplier])
                        .join(', ')}
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

            {hasResults ? (
                <div className="space-y-4">
                    <ResultsTable rows={available} />

                    {unavailable.length > 0 && (
                        <Collapsible>
                            <CollapsibleTrigger className="group flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                                <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                                Indisponíveis ({unavailable.length})
                            </CollapsibleTrigger>
                            <CollapsibleContent className="pt-2">
                                <ResultsTable rows={unavailable} />
                            </CollapsibleContent>
                        </Collapsible>
                    )}
                </div>
            ) : (
                pending.length === 0 && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Sem resultados.
                    </p>
                )
            )}
        </div>
    );
}
