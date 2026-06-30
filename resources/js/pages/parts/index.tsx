import { Head } from '@inertiajs/react';
import { index } from '@/routes/parts';

export default function PartsIndex() {
    return (
        <>
            <Head title="Pesquisa de Peças" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Pesquisa de Peças</h1>
            </div>
        </>
    );
}

PartsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Pesquisa de Peças',
            href: index(),
        },
    ],
};
