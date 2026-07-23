import { useForm } from '@inertiajs/react';
import { SearchIcon } from 'lucide-react';
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
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { store as partsStore } from '@/routes/parts';

type PartsSearchFormData = { reference: string };

export function PartsSearchForm() {
    const form = useForm<PartsSearchFormData>({ reference: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(partsStore.url());
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Nova pesquisa</CardTitle>
                <CardDescription>
                    Referência OE ou aftermarket para consultar preços.
                </CardDescription>
            </CardHeader>
            <form onSubmit={submit} className="flex flex-col gap-6">
                <CardContent>
                    <FieldGroup>
                        <Field
                            data-invalid={
                                form.errors.reference ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="reference">
                                Referência
                            </FieldLabel>
                            <InputGroup>
                                <InputGroupAddon align="inline-start">
                                    <SearchIcon />
                                </InputGroupAddon>
                                <InputGroupInput
                                    id="reference"
                                    value={form.data.reference}
                                    onChange={(e) =>
                                        form.setData(
                                            'reference',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Ex.: 11427622446"
                                    autoFocus
                                    aria-invalid={
                                        form.errors.reference ? true : undefined
                                    }
                                />
                            </InputGroup>
                            <FieldError>{form.errors.reference}</FieldError>
                        </Field>
                    </FieldGroup>
                </CardContent>
                <CardFooter className="border-t pt-6">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="parts-submit"
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        {form.processing ? 'A pesquisar…' : 'Pesquisar'}
                    </Button>
                </CardFooter>
            </form>
        </Card>
    );
}
