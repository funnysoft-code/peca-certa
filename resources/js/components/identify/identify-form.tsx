import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { ResultsTable, type ResultRow } from '@/components/parts/results-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store as identifyStore } from '@/routes/identify';

type IdentifyForm = { request: string; vin: string };

const SUPPLIER_LABELS: Record<App.Enums.Supplier, string> = {
    autodelta: 'Auto Delta',
    autozitania: 'Auto Zitânia',
};

export function IdentifyForm() {
    const http = useHttp<IdentifyForm, App.Data.IdentifyResult>({
        request: '',
        vin: '',
    });
    const [result, setResult] = useState<App.Data.IdentifyResult | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function run() {
        if (!http.data.request.trim() || !http.data.vin.trim()) return;
        setError(null);
        try {
            setResult(await http.post(identifyStore.url()));
        } catch {
            setResult(null);
            setError('Falha na identificação. Tente novamente.');
        }
    }

    const rows: ResultRow[] = [
        ...(result?.autoDeltaResults ?? []).flatMap((r) =>
            r.variants.map((variant) => ({
                variant,
                supplier: SUPPLIER_LABELS.autodelta,
                stockMode: 'quantity' as const,
                price: variant.purchasePrice,
            })),
        ),
        ...(result?.autoZitaniaResults ?? []).flatMap((r) =>
            r.variants.map((variant) => ({
                variant,
                supplier: SUPPLIER_LABELS.autozitania,
                stockMode: 'availability' as const,
                price: variant.retailPrice,
            })),
        ),
    ];

    return (
        <div className="space-y-4">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    void run();
                }}
                className="flex flex-col gap-2 sm:flex-row"
            >
                <Input
                    value={http.data.request}
                    onChange={(e) => http.setData('request', e.target.value)}
                    placeholder="Pedido do cliente"
                    autoFocus
                />
                <Input
                    value={http.data.vin}
                    onChange={(e) => http.setData('vin', e.target.value)}
                    placeholder="VIN"
                    className="sm:max-w-56"
                />
                <Button type="submit" disabled={http.processing}>
                    {http.processing ? 'A identificar…' : 'Identificar'}
                </Button>
            </form>

            {error !== null && (
                <p className="text-sm text-destructive">{error}</p>
            )}

            {result?.understanding.clarifyingQuestion != null && (
                <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    {result.understanding.clarifyingQuestion}
                </p>
            )}

            {rows.length > 0 && <ResultsTable rows={rows} />}
        </div>
    );
}
