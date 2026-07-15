import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { search as searchRoute } from '@/routes/parts';
import { ResultsTable, type StockMode } from './results-table';

type SearchForm = { reference: string; supplier: App.Enums.Supplier };

type SupplierSearch = {
    run: (reference: string) => Promise<void>;
    result: App.Data.PartSearchResult | null;
    error: string | null;
    processing: boolean;
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
            setError('Falha na pesquisa. Tente novamente.');
        }
    }

    return { run, result, error, processing: http.processing };
}

function SupplierSection({
    label,
    search,
    stockMode,
}: {
    label: string;
    search: SupplierSearch;
    stockMode: StockMode;
}) {
    return (
        <section className="space-y-2">
            <h3 className="text-sm font-semibold text-muted-foreground">
                {label}
            </h3>
            {search.error !== null && (
                <p className="text-sm text-destructive">{search.error}</p>
            )}
            {search.processing && (
                <div className="h-24 animate-pulse rounded-md bg-muted" />
            )}
            {!search.processing && search.result !== null && (
                <ResultsTable
                    variants={search.result.variants ?? []}
                    stockMode={stockMode}
                />
            )}
        </section>
    );
}

export function SearchTab() {
    const [reference, setReference] = useState('');
    const autoDelta = useSupplierSearch('autodelta');
    const autoZitania = useSupplierSearch('autozitania');
    const processing = autoDelta.processing || autoZitania.processing;
    const searched =
        processing ||
        autoDelta.result !== null ||
        autoZitania.result !== null ||
        autoDelta.error !== null ||
        autoZitania.error !== null;

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

            {searched && (
                <div className="space-y-6">
                    <SupplierSection
                        label="Auto Delta"
                        search={autoDelta}
                        stockMode="quantity"
                    />
                    <SupplierSection
                        label="Auto Zitânia"
                        search={autoZitania}
                        stockMode="availability"
                    />
                </div>
            )}
        </div>
    );
}
