import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { EchoListener } from '@/components/echo-listener';
import { RunResults } from '@/components/identify/run-results';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/parts';

type Props = { run: App.Data.SearchRunData };

type RunAdvancedEvent = { run: App.Data.SearchRunData };
type LookupReadyEvent = { lookup: App.Data.SupplierLookupData };

const STATUS_LABELS: Record<App.Enums.SearchRunStatus, string> = {
    pending: 'Pendente',
    running: 'A processar…',
    done: 'Concluído',
    failed: 'Falhou',
};

function isRunAdvancedEvent(event: unknown): event is RunAdvancedEvent {
    return (
        typeof event === 'object' &&
        event !== null &&
        'run' in event &&
        event.run !== null
    );
}

function isLookupReadyEvent(event: unknown): event is LookupReadyEvent {
    return (
        typeof event === 'object' &&
        event !== null &&
        'lookup' in event &&
        event.lookup !== null
    );
}

function mergeLookups(
    current: App.Data.SupplierLookupData[],
    incoming: App.Data.SupplierLookupData[],
): App.Data.SupplierLookupData[] {
    const byId = new Map(current.map((lookup) => [lookup.id, lookup]));

    for (const lookup of incoming) {
        byId.set(lookup.id, lookup);
    }

    return Array.from(byId.values());
}

function upsertLookup(
    current: App.Data.SupplierLookupData[],
    lookup: App.Data.SupplierLookupData,
): App.Data.SupplierLookupData[] {
    const exists = current.some((l) => l.id === lookup.id);

    return exists
        ? current.map((l) => (l.id === lookup.id ? lookup : l))
        : [...current, lookup];
}

function StatusIndicator({ status }: { status: App.Enums.SearchRunStatus }) {
    if (status === 'pending' || status === 'running') {
        return (
            <Badge variant="secondary" className="gap-1.5">
                <Spinner className="size-3" />
                {STATUS_LABELS[status]}
            </Badge>
        );
    }

    return (
        <Badge variant={status === 'failed' ? 'destructive' : 'default'}>
            {STATUS_LABELS[status]}
        </Badge>
    );
}

export default function PartsShow({ run: initialRun }: Props) {
    const [run, setRun] = useState(initialRun);

    function handleEvent(event: unknown) {
        if (isRunAdvancedEvent(event)) {
            setRun((prev) => ({
                ...prev,
                status: event.run.status,
                lookups: mergeLookups(prev.lookups, event.run.lookups),
            }));

            return;
        }

        if (isLookupReadyEvent(event)) {
            setRun((prev) => ({
                ...prev,
                lookups: upsertLookup(prev.lookups, event.lookup),
            }));
        }
    }

    return (
        <>
            <Head title={run.reference ?? 'Pesquisa de peça'} />
            <EchoListener
                channel={`search-run.${run.id}`}
                events={['.run.advanced', '.lookup.ready']}
                onEvent={handleEvent}
            />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="font-mono text-lg font-semibold">
                            {run.reference ?? 'Pesquisa de peça'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Preços e disponibilidade nos fornecedores
                        </p>
                    </div>
                    <StatusIndicator status={run.status} />
                </div>

                {run.status === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTitle>
                            Não foi possível concluir esta pesquisa.
                        </AlertTitle>
                        <AlertDescription>
                            Tente novamente ou contacte o suporte se o problema
                            persistir.
                        </AlertDescription>
                    </Alert>
                )}

                <RunResults lookups={run.lookups} />
            </div>
        </>
    );
}

PartsShow.layout = {
    breadcrumbs: [{ title: 'Peças', href: index() }],
};
