import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as identifyStore } from '@/routes/identify';

type IdentifyFormData = { request: string; vin: string };

export function IdentifyForm() {
    const form = useForm<IdentifyFormData>({ request: '', vin: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(identifyStore.url(), { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div className="flex-1 space-y-1.5">
                    <Label htmlFor="request">Pedido do cliente</Label>
                    <Input
                        id="request"
                        value={form.data.request}
                        onChange={(e) =>
                            form.setData('request', e.target.value)
                        }
                        placeholder="Pedido do cliente"
                        autoFocus
                    />
                    <InputError message={form.errors.request} />
                </div>
                <div className="space-y-1.5 sm:w-56">
                    <Label htmlFor="vin">VIN</Label>
                    <Input
                        id="vin"
                        value={form.data.vin}
                        onChange={(e) => form.setData('vin', e.target.value)}
                        placeholder="VIN"
                    />
                    <InputError message={form.errors.vin} />
                </div>
            </div>
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'A identificar…' : 'Identificar'}
            </Button>
        </form>
    );
}
