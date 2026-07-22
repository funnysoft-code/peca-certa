import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';

export const SEARCH_RUN_STATUS_LABELS: Record<
    App.Enums.SearchRunStatus,
    string
> = {
    pending: 'Pendente',
    running: 'A processar…',
    needs_input: 'Aguarda resposta',
    done: 'Concluído',
    failed: 'Falhou',
    cancelled: 'Cancelada',
};

export const SEARCH_RUN_STATUS_VARIANTS: Record<
    App.Enums.SearchRunStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    running: 'secondary',
    needs_input: 'outline',
    done: 'default',
    failed: 'destructive',
    cancelled: 'outline',
};

export function isSearchRunCancellable(
    status: App.Enums.SearchRunStatus,
): boolean {
    return (
        status === 'pending' || status === 'running' || status === 'needs_input'
    );
}

export function SearchRunStatusBadge({
    status,
}: {
    status: App.Enums.SearchRunStatus;
}) {
    const label = SEARCH_RUN_STATUS_LABELS[status];

    if (status === 'pending' || status === 'running') {
        return (
            <Badge variant="secondary" className="gap-1.5">
                <Spinner className="size-3" />
                {label}
            </Badge>
        );
    }

    return <Badge variant={SEARCH_RUN_STATUS_VARIANTS[status]}>{label}</Badge>;
}

/** Pipeline progress 0–100 for identify / parts runs. */
export function searchRunProgressValue(
    status: App.Enums.SearchRunStatus,
    lookups: App.Data.SupplierLookupData[],
    oePartsCount: number,
): number {
    if (status === 'done') {
        return 100;
    }

    if (status === 'failed' || status === 'cancelled') {
        return 0;
    }

    if (status === 'pending') {
        return 8;
    }

    if (status === 'needs_input') {
        return 35;
    }

    if (status === 'running') {
        if (lookups.length === 0) {
            return oePartsCount > 0 ? 45 : 25;
        }

        const terminal = lookups.filter(
            (lookup) =>
                lookup.status === 'done' ||
                lookup.status === 'failed' ||
                lookup.status === 'empty',
        ).length;

        return Math.min(95, 50 + Math.round((terminal / lookups.length) * 45));
    }

    return 0;
}
