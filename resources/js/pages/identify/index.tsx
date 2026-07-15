import { Head } from '@inertiajs/react';
import { IdentifyForm } from '@/components/identify/identify-form';

export default function IdentifyIndex() {
    return (
        <>
            <Head title="Identificar peça" />
            <div className="mx-auto w-full max-w-5xl p-4">
                <h1 className="mb-4 text-lg font-semibold">Identificar peça</h1>
                <IdentifyForm />
            </div>
        </>
    );
}
