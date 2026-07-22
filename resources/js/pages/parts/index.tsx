import { Head } from '@inertiajs/react';
import { PartsSearchForm } from '@/components/parts/parts-search-form';
import { SearchRunHistory } from '@/components/search-run-history';
import { index, show } from '@/routes/parts';

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

export default function PartsIndex({ runs, filters }: Props) {
    return (
        <>
            <Head title="Peças" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="font-display text-xl font-semibold tracking-tight">
                        Pesquisa de peças
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Consulte preços e disponibilidade por referência OE ou
                        aftermarket.
                    </p>
                </div>

                <PartsSearchForm />

                <SearchRunHistory
                    runs={runs}
                    filters={filters}
                    indexUrl={index.url()}
                    showUrl={(id) => show.url(id)}
                    primaryLabel={(run) => run.reference ?? 'Sem referência'}
                    emptyNoRuns="Ainda não há pesquisas. Submeta uma referência para começar."
                    emptyNoMatches="Nenhum resultado para esta pesquisa."
                />
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
