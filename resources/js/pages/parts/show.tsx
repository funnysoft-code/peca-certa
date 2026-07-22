import { Head } from '@inertiajs/react';
import { EchoListener } from '@/components/echo-listener';
import { RunResults } from '@/components/identify/run-results';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { useSearchRunStream } from '@/hooks/use-search-run-stream';
import { index } from '@/routes/parts';

type Props = { run: App.Data.SearchRunData };

const STATUS_LABELS: Record<App.Enums.SearchRunStatus, string> = {
    pending: 'Pendente',
    running: 'A processar…',
    needs_input: 'Aguarda resposta',
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

export default function PartsShow({ run: initialRun }: Props) {
    const { run, handleEvent } = useSearchRunStream(initialRun);

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

                <RunResults run={run} />
            </div>
        </>
    );
}

PartsShow.layout = {
    breadcrumbs: [{ title: 'Peças', href: index() }],
};
