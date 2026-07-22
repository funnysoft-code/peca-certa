import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store as identifyStore } from '@/routes/identify';

type IdentifyFormData = { request: string; vin: string };

export function IdentifyForm() {
    const form = useForm<IdentifyFormData>({ request: '', vin: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(identifyStore.url(), { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Novo pedido</CardTitle>
                <CardDescription>
                    Descreva o pedido do cliente e o VIN do veículo.
                </CardDescription>
            </CardHeader>
            <form onSubmit={submit} className="flex flex-col">
                <CardContent>
                    <FieldGroup>
                        <Field
                            data-invalid={
                                form.errors.request ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="request">
                                Pedido do cliente
                            </FieldLabel>
                            <Textarea
                                id="request"
                                value={form.data.request}
                                onChange={(e) =>
                                    form.setData('request', e.target.value)
                                }
                                placeholder="Ex.: filtro de óleo do Mini Cooper S 2013…"
                                autoFocus
                                rows={3}
                                aria-invalid={
                                    form.errors.request ? true : undefined
                                }
                            />
                            <FieldError>{form.errors.request}</FieldError>
                        </Field>

                        <Field
                            data-invalid={form.errors.vin ? true : undefined}
                        >
                            <FieldLabel htmlFor="vin">VIN</FieldLabel>
                            <Input
                                id="vin"
                                value={form.data.vin}
                                onChange={(e) =>
                                    form.setData('vin', e.target.value)
                                }
                                placeholder="VIN do veículo"
                                autoComplete="off"
                                aria-invalid={
                                    form.errors.vin ? true : undefined
                                }
                            />
                            <FieldError>{form.errors.vin}</FieldError>
                        </Field>
                    </FieldGroup>
                </CardContent>
                <CardFooter className="border-t pt-6">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="identify-submit"
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        {form.processing ? 'A identificar…' : 'Identificar'}
                    </Button>
                </CardFooter>
            </form>
        </Card>
    );
}
