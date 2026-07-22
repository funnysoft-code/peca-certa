import { Head, Link } from '@inertiajs/react';
import { PartsSearchForm } from '@/components/parts/parts-search-form';
import { Badge } from '@/components/ui/badge';
import { formatRelativeTime } from '@/lib/utils';
import { index, show } from '@/routes/parts';

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

export default function PartsIndex({ recentRuns }: Props) {
    return (
        <>
            <Head title="Peças" />
            <div className="mx-auto w-full max-w-3xl space-y-8 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="font-display text-xl font-semibold tracking-tight">
                        Pesquisa de peças
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Consulte preços e disponibilidade por referência OE ou
                        aftermarket.
                    </p>
                </div>

                <div className="rounded-xl border border-border bg-card p-4 shadow-sm md:p-6">
                    <PartsSearchForm />
                </div>

                {recentRuns.length > 0 ? (
                    <div className="space-y-3">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Pesquisas recentes
                        </h2>
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                            {recentRuns.map((run) => (
                                <li key={run.id}>
                                    <Link
                                        href={show(run.id)}
                                        className="flex items-center justify-between gap-4 px-4 py-3 text-sm transition-colors hover:bg-muted/50"
                                    >
                                        <span className="truncate font-mono">
                                            {run.reference ?? 'Sem referência'}
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
                ) : (
                    <div className="rounded-xl border border-dashed border-border bg-card/50 px-4 py-8 text-center text-sm text-muted-foreground">
                        Ainda não há pesquisas. Submeta uma referência para
                        começar.
                    </div>
                )}
            </div>
        </>
    );
}

PartsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Peças',
            href: index(),
        },
    ],
};
