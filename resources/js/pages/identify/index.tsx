import { Head, Link } from '@inertiajs/react';
import { IdentifyForm } from '@/components/identify/identify-form';
import { Badge } from '@/components/ui/badge';
import { formatRelativeTime } from '@/lib/utils';
import { create, show } from '@/routes/identify';

type Props = { recentRuns: App.Data.SearchRunData[] };

const STATUS_LABELS: Record<App.Enums.SearchRunStatus, string> = {
    pending: 'Pendente',
    running: 'Em curso',
    done: 'Concluído',
    failed: 'Falhou',
};

const STATUS_VARIANTS: Record<
    App.Enums.SearchRunStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    running: 'secondary',
    done: 'default',
    failed: 'destructive',
};

export default function IdentifyIndex({ recentRuns }: Props) {
    return (
        <>
            <Head title="Identificar peça" />
            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <div className="space-y-1">
                    <h1 className="text-lg font-semibold">Identificar peça</h1>
                    <p className="text-sm text-muted-foreground">
                        Descreva o pedido do cliente e o VIN do veículo para
                        identificar a peça OE e procurar preços nos
                        fornecedores.
                    </p>
                </div>

                <IdentifyForm />

                {recentRuns.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Pesquisas recentes
                        </h2>
                        <ul className="divide-y rounded-md border">
                            {recentRuns.map((run) => (
                                <li key={run.id}>
                                    <Link
                                        href={show(run.id)}
                                        className="flex items-center justify-between gap-4 px-4 py-3 text-sm transition-colors hover:bg-muted/50"
                                    >
                                        <span className="truncate">
                                            {run.requestText ?? 'Sem descrição'}
                                        </span>
                                        <span className="flex shrink-0 items-center gap-3 text-muted-foreground">
                                            <Badge
                                                variant={
                                                    STATUS_VARIANTS[run.status]
                                                }
                                            >
                                                {STATUS_LABELS[run.status]}
                                            </Badge>
                                            <span>
                                                {formatRelativeTime(
                                                    run.createdAt,
                                                )}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </>
    );
}

IdentifyIndex.layout = {
    breadcrumbs: [{ title: 'Identificar peça', href: create() }],
};
