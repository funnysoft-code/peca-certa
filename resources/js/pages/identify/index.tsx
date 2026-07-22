import { Head } from '@inertiajs/react';
import { IdentifyForm } from '@/components/identify/identify-form';
import { SearchRunHistory } from '@/components/search-run-history';
import { create, show } from '@/routes/identify';

type PaginatorLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

type PaginatorMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string | null;
    per_page: number;
    to: number | null;
    total: number;
};

type Props = {
    runs: {
        data: App.Data.SearchRunData[];
        links: PaginatorLinks;
        meta: PaginatorMeta;
    };
    filters: {
        scope: 'everyone' | 'mine';
        q: string;
    };
};

export default function IdentifyIndex({ runs, filters }: Props) {
    return (
        <>
            <Head title="Identificar" />
            <div className="mx-auto w-full max-w-3xl space-y-8 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="font-display text-xl font-semibold tracking-tight">
                        Identificar peça
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Descreva o pedido do cliente e o VIN do veículo para
                        identificar a peça OE e procurar preços nos
                        fornecedores.
                    </p>
                </div>

                <div className="rounded-xl border border-border bg-card p-4 shadow-sm md:p-6">
                    <IdentifyForm />
                </div>

                <SearchRunHistory
                    runs={runs}
                    filters={filters}
                    indexUrl={create.url()}
                    showUrl={(id) => show.url(id)}
                    primaryLabel={(run) => run.requestText ?? 'Sem descrição'}
                    emptyNoRuns="Ainda não há pesquisas. Submeta um pedido para começar."
                    emptyNoMatches="Nenhum resultado para esta pesquisa."
                />
            </div>
        </>
    );
}

IdentifyIndex.layout = {
    breadcrumbs: [{ title: 'Identificar', href: create() }],
};
