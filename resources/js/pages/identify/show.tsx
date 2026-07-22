import { Head, useForm } from '@inertiajs/react';
import { EchoListener } from '@/components/echo-listener';
import { RunResults } from '@/components/identify/run-results';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useSearchRunStream } from '@/hooks/use-search-run-stream';
import { create, resume } from '@/routes/identify';

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

    if (status === 'needs_input') {
        return <Badge variant="outline">{STATUS_LABELS[status]}</Badge>;
    }

    return (
        <Badge variant={status === 'failed' ? 'destructive' : 'default'}>
            {STATUS_LABELS[status]}
        </Badge>
    );
}

function ClarificationForm({ run }: { run: App.Data.SearchRunData }) {
    const form = useForm<{ answer: string; option: string }>({
        answer: '',
        option: '',
    });

    if (!run.pendingQuestion) {
        return null;
    }

    return (
        <div className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm">
            <div>
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Precisamos de mais detalhe
                </p>
                <p className="mt-1 text-sm font-medium">
                    {run.pendingQuestion.question}
                </p>
            </div>

            {run.pendingQuestion.options.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {run.pendingQuestion.options.map((option) => (
                        <Button
                            key={option}
                            type="button"
                            size="sm"
                            variant={
                                form.data.option === option
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => form.setData('option', option)}
                        >
                            {option}
                        </Button>
                    ))}
                </div>
            )}

            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(resume.url(run.id), {
                        preserveScroll: true,
                    });
                }}
            >
                <div className="space-y-1.5">
                    <Label htmlFor="identify-answer">
                        Resposta (texto livre)
                    </Label>
                    <Input
                        id="identify-answer"
                        value={form.data.answer}
                        onChange={(event) =>
                            form.setData('answer', event.target.value)
                        }
                        placeholder="Descreva com mais pormenor se as opções não chegarem…"
                        maxLength={1000}
                    />
                    {form.errors.answer && (
                        <p className="text-sm text-destructive">
                            {form.errors.answer}
                        </p>
                    )}
                </div>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? (
                        <>
                            <Spinner className="size-4" />A enviar…
                        </>
                    ) : (
                        'Continuar identificação'
                    )}
                </Button>
            </form>
        </div>
    );
}

export default function IdentifyShow({ run: initialRun }: Props) {
    const { run, handleEvent } = useSearchRunStream(initialRun);

    const isAnalyzing = run.status === 'pending' || run.status === 'running';

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
                        <p className="text-sm text-muted-foreground">
                            {run.authorName || 'Desconhecido'}
                            {run.vin ? ` · VIN: ${run.vin}` : ''}
                        </p>
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
                        <Spinner className="size-4" />A identificar peça no
                        catálogo OE…
                    </div>
                )}

                {run.status === 'needs_input' && (
                    <ClarificationForm run={run} />
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
                        <RunResults run={run} />
                    </div>
                )}
            </div>
        </>
    );
}

IdentifyShow.layout = {
    breadcrumbs: [{ title: 'Identificar peça', href: create() }],
};
