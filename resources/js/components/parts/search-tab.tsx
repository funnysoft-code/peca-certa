import { useHttp } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { search as searchRoute } from '@/routes/parts';
import { ResultsTable, type ResultRow, type StockMode } from './results-table';

type SearchForm = { reference: string; supplier: App.Enums.Supplier };

type SupplierSearch = {
    run: (reference: string) => Promise<void>;
    result: App.Data.PartSearchResult | null;
    error: string | null;
    processing: boolean;
};

const SUPPLIER_LABELS: Record<App.Enums.Supplier, string> = {
    autodelta: 'Auto Delta',
    autozitania: 'Auto Zitânia',
};

const SUPPLIER_STOCK_MODES: Record<App.Enums.Supplier, StockMode> = {
    autodelta: 'quantity',
    autozitania: 'availability',
};

function useSupplierSearch(supplier: App.Enums.Supplier): SupplierSearch {
    const http = useHttp<SearchForm, App.Data.PartSearchResult>({
        reference: '',
        supplier,
    });
    const [result, setResult] = useState<App.Data.PartSearchResult | null>(
        null,
    );
    const [error, setError] = useState<string | null>(null);

    async function run(reference: string) {
        setError(null);
        try {
            http.transform((data) => ({ ...data, reference }));
            setResult(await http.post(searchRoute.url()));
        } catch {
            setResult(null);
            setError(
                `${SUPPLIER_LABELS[supplier]}: falha na pesquisa. Tente novamente.`,
            );
        }
    }

    return { run, result, error, processing: http.processing };
}

function toRows(
    search: SupplierSearch,
    supplier: App.Enums.Supplier,
): ResultRow[] {
    return (search.result?.variants ?? []).map((variant) => ({
        variant,
        supplier: SUPPLIER_LABELS[supplier],
        stockMode: SUPPLIER_STOCK_MODES[supplier],
    }));
}

function byBrandThenSupplier(a: ResultRow, b: ResultRow): number {
    return (
        a.variant.brandName.localeCompare(b.variant.brandName) ||
        a.supplier.localeCompare(b.supplier)
    );
}

export function SearchTab() {
    const [reference, setReference] = useState('');
    const autoDelta = useSupplierSearch('autodelta');
    const autoZitania = useSupplierSearch('autozitania');
    const processing = autoDelta.processing || autoZitania.processing;
    const searches: [App.Enums.Supplier, SupplierSearch][] = [
        ['autodelta', autoDelta],
        ['autozitania', autoZitania],
    ];

    const rows = searches
        .flatMap(([supplier, search]) => toRows(search, supplier))
        .sort(byBrandThenSupplier);
    const available = rows.filter((row) => row.variant.inStock);
    const unavailable = rows.filter((row) => !row.variant.inStock);
    const pending = searches.filter(([, search]) => search.processing);
    const hasResults = searches.some(([, search]) => search.result !== null);

    function run() {
        if (!reference.trim()) return;
        void autoDelta.run(reference);
        void autoZitania.run(reference);
    }

    return (
        <div className="space-y-4">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    run();
                }}
                className="flex gap-2"
            >
                <Input
                    value={reference}
                    onChange={(e) => setReference(e.target.value)}
                    placeholder="Referência da peça"
                    autoFocus
                />
                <Button type="submit" disabled={processing}>
                    {processing ? 'A pesquisar…' : 'Pesquisar'}
                </Button>
            </form>

            {searches.map(
                ([supplier, search]) =>
                    search.error !== null && (
                        <p key={supplier} className="text-sm text-destructive">
                            {search.error}
                        </p>
                    ),
            )}

            {pending.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        A pesquisar em{' '}
                        {pending
                            .map(([supplier]) => SUPPLIER_LABELS[supplier])
                            .join(', ')}
                        …
                    </p>
                    {!hasResults && (
                        <div className="h-24 animate-pulse rounded-md bg-muted" />
                    )}
                </div>
            )}

            {hasResults && (
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
            )}
        </div>
    );
}
