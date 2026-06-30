import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { search as searchRoute } from '@/routes/parts';
import { ResultsTable } from './results-table';

type SearchForm = { reference: string };

export function SearchTab() {
    const http = useHttp<SearchForm, App.Data.PartSearchResult>({
        reference: '',
    });
    const [result, setResult] = useState<App.Data.PartSearchResult | null>(
        null,
    );
    const [httpError, setHttpError] = useState<string | null>(null);

    async function run() {
        if (!http.data.reference.trim()) return;
        setHttpError(null);
        try {
            const res = await http.post(searchRoute.url());
            setResult(res);
        } catch {
            setHttpError('Falha na pesquisa. Tente novamente.');
        }
    }

    return (
        <div className="space-y-4">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    void run();
                }}
                className="flex gap-2"
            >
                <Input
                    value={http.data.reference}
                    onChange={(e) => http.setData('reference', e.target.value)}
                    placeholder="Referência da peça"
                    autoFocus
                />
                <Button type="submit" disabled={http.processing}>
                    {http.processing ? 'A pesquisar…' : 'Pesquisar'}
                </Button>
            </form>

            {httpError !== null && (
                <p className="text-sm text-destructive">{httpError}</p>
            )}
            {http.processing && (
                <div className="h-32 animate-pulse rounded-md bg-muted" />
            )}
            {!http.processing && result !== null && (
                <ResultsTable variants={result.variants ?? []} />
            )}
        </div>
    );
}
