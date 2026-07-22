import { CheckIcon, LoaderCircleIcon } from 'lucide-react';

type AgentStep = App.Data.AgentStep;

export function AgentSteps({
    steps,
    live,
}: {
    steps: AgentStep[];
    live: boolean;
}) {
    if (steps.length === 0 && !live) {
        return null;
    }

    return (
        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Progresso do agente
            </p>
            {steps.length === 0 ? (
                <p className="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                    <LoaderCircleIcon className="size-3.5 animate-spin" />A
                    planear pesquisa no catálogo OE…
                </p>
            ) : (
                <ol className="mt-3 space-y-2">
                    {steps.map((step) => (
                        <li
                            key={step.id}
                            className="flex items-start gap-2 text-sm"
                        >
                            <span className="mt-0.5 shrink-0 text-muted-foreground">
                                {step.status === 'done' ? (
                                    <CheckIcon className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                ) : (
                                    <LoaderCircleIcon className="size-3.5 animate-spin" />
                                )}
                            </span>
                            <span className="min-w-0">
                                <span
                                    className={
                                        step.status === 'done'
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    }
                                >
                                    {step.label}
                                </span>
                                {step.detail ? (
                                    <span className="mt-0.5 block truncate font-mono text-xs text-muted-foreground">
                                        {step.detail}
                                    </span>
                                ) : null}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
