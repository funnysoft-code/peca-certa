import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store as partsStore } from '@/routes/parts';

type PartsSearchFormData = { reference: string };

export function PartsSearchForm() {
    const form = useForm<PartsSearchFormData>({ reference: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(partsStore.url());
    }

    return (
        <form onSubmit={submit} className="flex gap-2">
            <div className="flex-1 space-y-1.5">
                <Input
                    id="reference"
                    value={form.data.reference}
                    onChange={(e) => form.setData('reference', e.target.value)}
                    placeholder="Referência da peça"
                    autoFocus
                />
                <InputError message={form.errors.reference} />
            </div>
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'A pesquisar…' : 'Pesquisar'}
            </Button>
        </form>
    );
}
