import { Head } from '@inertiajs/react';
import { EchoListener } from '@/components/echo-listener';
import { RunResults } from '@/components/identify/run-results';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { useSearchRunStream } from '@/hooks/use-search-run-stream';
import { create } from '@/routes/identify';

type Props = { run: App.Data.SearchRunData };

const STATUS_LABELS: Record<App.Enums.SearchRunStatus, string> = {
    pending: 'Pendente',
    running: 'A processar…',
    done: 'Concluído',
    failed: 'Falhou',
};

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

export default function IdentifyShow({ run: initialRun }: Props) {
    const { run, handleEvent } = useSearchRunStream(initialRun);

    const isAnalyzing =
        run.understanding === null &&
        run.status !== 'done' &&
        run.status !== 'failed';

    return (
        <>
            <Head title={run.requestText ?? 'Identificação de peça'} />
            <EchoListener
                channel={`search-run.${run.id}`}
                events={['.run.advanced', '.lookup.ready']}
                onEvent={handleEvent}
            />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {run.requestText ?? 'Identificação de peça'}
                        </h1>
                        {run.vin && (
                            <p className="text-sm text-muted-foreground">
                                VIN: {run.vin}
                            </p>
                        )}
                    </div>
                    <StatusIndicator status={run.status} />
                </div>

                {run.status === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTitle>
                            Não foi possível concluir esta identificação.
                        </AlertTitle>
                        <AlertDescription>
                            Tente novamente ou contacte o suporte se o problema
                            persistir.
                        </AlertDescription>
                    </Alert>
                )}

                {isAnalyzing && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner className="size-4" />A analisar o pedido…
                    </div>
                )}

                {run.understanding && (
                    <div className="rounded-md border p-4">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Categoria identificada
                        </p>
                        <p className="mt-1 text-sm font-medium">
                            {run.understanding.category}
                        </p>
                    </div>
                )}

                {run.understanding?.clarifyingQuestion && (
                    <Alert>
                        <AlertTitle>É necessária mais informação</AlertTitle>
                        <AlertDescription>
                            {run.understanding.clarifyingQuestion}
                        </AlertDescription>
                    </Alert>
                )}

                {run.oeParts.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Peças OE identificadas
                        </h2>
                        <ul className="grid gap-2 sm:grid-cols-2">
                            {run.oeParts.map((part) => (
                                <li
                                    key={part.oeNumber}
                                    className="rounded-md border p-3 text-sm"
                                >
                                    <p className="font-mono font-medium">
                                        {part.oeNumber}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {part.description}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {part.brand}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {run.lookups.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Resultados
                        </h2>
                        <RunResults lookups={run.lookups} />
                    </div>
                )}
            </div>
        </>
    );
}

IdentifyShow.layout = {
    breadcrumbs: [{ title: 'Identificar peça', href: create() }],
};
