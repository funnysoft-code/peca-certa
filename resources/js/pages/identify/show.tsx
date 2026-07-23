import { Head, router, useForm } from '@inertiajs/react';
import { EchoListener } from '@/components/echo-listener';
import { AgentSteps } from '@/components/identify/agent-steps';
import { RunResults } from '@/components/identify/run-results';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    Item,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemTitle,
} from '@/components/ui/item';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useSearchRunStream } from '@/hooks/use-search-run-stream';
import {
    isSearchRunCancellable,
    searchRunProgressValue,
    SearchRunStatusBadge,
} from '@/lib/search-run-status';
import { cancel, create, resume } from '@/routes/identify';

type Props = { run: App.Data.SearchRunData };

function failedOperatorHint(run: App.Data.SearchRunData): string | null {
    const failedStep = [...(run.agentSteps ?? [])]
        .reverse()
        .find((step) => step.status === 'failed' && step.detail);

    if (failedStep?.detail) {
        return failedStep.detail;
    }

    return null;
}

function CancelButton({
    runId,
    disabled,
}: {
    runId: string;
    disabled?: boolean;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            disabled={disabled}
            onClick={() => {
                router.post(cancel.url(runId), {}, { preserveScroll: true });
            }}
        >
            Cancelar
        </Button>
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

    const isUnsupportedBrand = run.pendingQuestion.kind === 'unsupported_brand';

    return (
        <Card>
            <CardHeader>
                <CardDescription>
                    {isUnsupportedBrand
                        ? 'Catálogo não configurado / WMI desconhecido'
                        : 'Precisamos de mais detalhe'}
                </CardDescription>
                <CardTitle className="text-base">
                    {run.pendingQuestion.question}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {isUnsupportedBrand && (
                    <Alert>
                        <AlertTitle>
                            Bloqueio de routing, não de peça
                        </AlertTitle>
                        <AlertDescription>
                            O VIN não mapeia para um catálogo PartsLink24
                            conhecido. Escolha a marca (catálogo) correcta — não
                            é necessário modelo nem ano.
                        </AlertDescription>
                    </Alert>
                )}

                {run.pendingQuestion.options.length > 0 && (
                    <ToggleGroup
                        type="single"
                        value={form.data.option}
                        onValueChange={(value) =>
                            form.setData('option', value ?? '')
                        }
                        variant="outline"
                        className="flex w-full flex-wrap justify-start gap-2 shadow-none"
                    >
                        {run.pendingQuestion.options.map((option) => (
                            <ToggleGroupItem
                                key={option}
                                value={option}
                                className="h-auto min-h-9 max-w-full shrink rounded-md border px-3 py-2 text-left whitespace-normal shadow-xs first:rounded-md last:rounded-md data-[variant=outline]:border-l"
                            >
                                {option}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                )}
                {form.errors.option && (
                    <p className="text-sm text-destructive">
                        {form.errors.option}
                    </p>
                )}

                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(resume.url(run.id), {
                            preserveScroll: true,
                        });
                    }}
                >
                    {!isUnsupportedBrand && (
                        <FieldGroup>
                            <Field
                                data-invalid={
                                    form.errors.answer ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="identify-answer">
                                    Resposta (texto livre)
                                </FieldLabel>
                                <Textarea
                                    id="identify-answer"
                                    value={form.data.answer}
                                    onChange={(event) =>
                                        form.setData(
                                            'answer',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Descreva com mais pormenor se as opções não chegarem…"
                                    maxLength={1000}
                                    rows={3}
                                    aria-invalid={
                                        form.errors.answer ? true : undefined
                                    }
                                />
                                <FieldError>{form.errors.answer}</FieldError>
                            </Field>
                        </FieldGroup>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                (isUnsupportedBrand && form.data.option === '')
                            }
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            {isUnsupportedBrand
                                ? 'Continuar com catálogo'
                                : 'Continuar identificação'}
                        </Button>
                        <CancelButton
                            runId={run.id}
                            disabled={form.processing}
                        />
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

export default function IdentifyShow({ run: initialRun }: Props) {
    const { run, handleEvent } = useSearchRunStream(initialRun);

    const isAnalyzing = run.status === 'pending' || run.status === 'running';
    const progress = searchRunProgressValue(
        run.status,
        run.lookups,
        run.oeParts.length,
    );

    return (
        <>
            <Head title={run.requestText ?? 'Identificação de peça'} />
            <EchoListener
                channel={`search-run.${run.id}`}
                events={['.run.advanced', '.lookup.ready', '.agent.step']}
                onEvent={handleEvent}
            />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h1 className="text-lg font-semibold">
                            {run.requestText ?? 'Identificação de peça'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {run.authorName || 'Desconhecido'}
                            {run.vin ? ` · VIN: ${run.vin}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <SearchRunStatusBadge status={run.status} />
                        {isSearchRunCancellable(run.status) &&
                            run.status !== 'needs_input' && (
                                <CancelButton runId={run.id} />
                            )}
                    </div>
                </div>

                {(isAnalyzing || run.status === 'needs_input') && (
                    <div className="flex flex-col gap-2">
                        <Progress value={progress} aria-label="Progresso" />
                        <p className="text-xs text-muted-foreground">
                            {run.status === 'needs_input'
                                ? run.pendingQuestion?.kind ===
                                  'unsupported_brand'
                                    ? 'Aguarda escolha de catálogo (WMI/marca)'
                                    : 'Aguarda resposta do operador'
                                : run.oeParts.length > 0
                                  ? 'A consultar fornecedores…'
                                  : 'A identificar a peça OE…'}
                        </p>
                    </div>
                )}

                {run.status === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTitle>
                            Não foi possível concluir esta identificação.
                        </AlertTitle>
                        <AlertDescription>
                            {failedOperatorHint(run) ??
                                'Tente novamente ou contacte o suporte se o problema persistir.'}
                        </AlertDescription>
                    </Alert>
                )}

                {run.status === 'cancelled' && (
                    <Alert>
                        <AlertTitle>Identificação cancelada.</AlertTitle>
                        <AlertDescription>
                            Pode iniciar uma nova identificação quando quiser.
                        </AlertDescription>
                    </Alert>
                )}

                {(isAnalyzing || (run.agentSteps?.length ?? 0) > 0) && (
                    <AgentSteps
                        steps={run.agentSteps ?? []}
                        live={isAnalyzing}
                    />
                )}

                {run.status === 'needs_input' && (
                    <ClarificationForm run={run} />
                )}

                {run.understanding && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                Categoria identificada
                            </CardDescription>
                            <CardTitle className="text-base">
                                {run.understanding.category}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                )}

                {run.oeParts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Peças OE identificadas
                        </h2>
                        <ItemGroup className="grid gap-3 sm:grid-cols-2">
                            {run.oeParts.map((part) => (
                                <Item
                                    key={part.oeNumber}
                                    variant="outline"
                                    size="sm"
                                    className="items-start"
                                >
                                    <ItemContent className="gap-2">
                                        {part.diagramUrl ? (
                                            <div className="overflow-hidden rounded-md border bg-muted/30">
                                                <img
                                                    src={part.diagramUrl}
                                                    alt={`Esquema PL24 pos ${part.pos ?? '—'} · ${part.oeNumber}`}
                                                    className="max-h-48 w-full object-contain"
                                                    loading="lazy"
                                                />
                                            </div>
                                        ) : null}
                                        <div className="flex flex-wrap items-center gap-2">
                                            <ItemTitle className="font-mono">
                                                {part.oeNumber}
                                            </ItemTitle>
                                            {part.factoryFit === true ? (
                                                <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-medium tracking-wide text-emerald-700 uppercase dark:text-emerald-400">
                                                    Fábrica
                                                </span>
                                            ) : null}
                                            {part.factoryFit === false ? (
                                                <span className="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium tracking-wide text-amber-700 uppercase dark:text-amber-400">
                                                    Opção
                                                </span>
                                            ) : null}
                                            {part.pos ? (
                                                <span className="font-mono text-[11px] text-muted-foreground">
                                                    pos {part.pos}
                                                </span>
                                            ) : null}
                                        </div>
                                        <ItemDescription>
                                            {part.description}
                                            {part.brand
                                                ? ` · ${part.brand}`
                                                : ''}
                                            {part.applicability
                                                ? ` · ${part.applicability}`
                                                : ''}
                                        </ItemDescription>
                                    </ItemContent>
                                </Item>
                            ))}
                        </ItemGroup>
                    </div>
                )}

                {run.lookups.length > 0 && (
                    <div className="flex flex-col gap-2">
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
