import { Head } from '@inertiajs/react';
import { EchoListener } from '@/components/echo-listener';
import { RunResults } from '@/components/identify/run-results';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Progress } from '@/components/ui/progress';
import { useSearchRunStream } from '@/hooks/use-search-run-stream';
import {
    searchRunProgressValue,
    SearchRunStatusBadge,
} from '@/lib/search-run-status';
import { index } from '@/routes/parts';

type Props = { run: App.Data.SearchRunData };

export default function PartsShow({ run: initialRun }: Props) {
    const { run, handleEvent } = useSearchRunStream(initialRun);
    const isAnalyzing = run.status === 'pending' || run.status === 'running';
    const progress = searchRunProgressValue(
        run.status,
        run.lookups,
        run.oeParts.length,
    );

    return (
        <>
            <Head title={run.reference ?? 'Pesquisa de peça'} />
            <EchoListener
                channel={`search-run.${run.id}`}
                events={['.run.advanced', '.lookup.ready']}
                onEvent={handleEvent}
            />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h1 className="font-mono text-lg font-semibold">
                            {run.reference ?? 'Pesquisa de peça'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {run.authorName || 'Desconhecido'}
                            {' · '}
                            Preços e disponibilidade nos fornecedores
                        </p>
                    </div>
                    <SearchRunStatusBadge status={run.status} />
                </div>

                {isAnalyzing && (
                    <div className="flex flex-col gap-2">
                        <Progress value={progress} aria-label="Progresso" />
                        <p className="text-xs text-muted-foreground">
                            A consultar fornecedores…
                        </p>
                    </div>
                )}

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
