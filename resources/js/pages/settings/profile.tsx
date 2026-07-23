import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
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
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <>
            <Head title="Perfil" />

            <h1 className="sr-only">Perfil</h1>

            <div className="flex flex-col gap-6">
                <Heading
                    variant="small"
                    title="Perfil"
                    description="Atualize o nome e o email"
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Dados da conta</CardTitle>
                        <CardDescription>
                            Nome e email usados para identificação e contacto.
                        </CardDescription>
                    </CardHeader>

                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        className="flex flex-col"
                    >
                        {({ processing, errors }) => (
                            <>
                                <CardContent>
                                    <FieldGroup>
                                        <Field
                                            data-invalid={
                                                errors.name ? true : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="name">
                                                Nome
                                            </FieldLabel>
                                            <Input
                                                id="name"
                                                className="w-full"
                                                defaultValue={user.name}
                                                name="name"
                                                required
                                                autoComplete="name"
                                                placeholder="Nome completo"
                                                aria-invalid={
                                                    errors.name
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <FieldError>
                                                {errors.name}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                errors.email ? true : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="email">
                                                Email
                                            </FieldLabel>
                                            <Input
                                                id="email"
                                                type="email"
                                                className="w-full"
                                                defaultValue={user.email}
                                                name="email"
                                                required
                                                autoComplete="username"
                                                placeholder="Email"
                                                aria-invalid={
                                                    errors.email
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <FieldError>
                                                {errors.email}
                                            </FieldError>
                                        </Field>

                                        {mustVerifyEmail &&
                                            user.email_verified_at === null && (
                                                <Field>
                                                    <FieldDescription>
                                                        O email ainda não está
                                                        verificado.{' '}
                                                        <Link
                                                            href={send()}
                                                            as="button"
                                                            className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                                        >
                                                            Reenviar email de
                                                            verificação.
                                                        </Link>
                                                    </FieldDescription>

                                                    {status ===
                                                        'verification-link-sent' && (
                                                        <FieldDescription className="font-medium text-primary">
                                                            Foi enviada uma nova
                                                            ligação de
                                                            verificação para o
                                                            seu email.
                                                        </FieldDescription>
                                                    )}
                                                </Field>
                                            )}
                                    </FieldGroup>
                                </CardContent>

                                <CardFooter className="border-t pt-6">
                                    <Button
                                        disabled={processing}
                                        data-test="update-profile-button"
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Guardar
                                    </Button>
                                </CardFooter>
                            </>
                        )}
                    </Form>
                </Card>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Perfil',
            href: edit(),
        },
    ],
};
