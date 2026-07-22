import { Form, Head } from '@inertiajs/react';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Entrar" />

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <FieldGroup>
                        <Field data-invalid={errors.email ? true : undefined}>
                            <FieldLabel htmlFor="email">Email</FieldLabel>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="email@exemplo.pt"
                                aria-invalid={errors.email ? true : undefined}
                            />
                            <FieldError>{errors.email}</FieldError>
                        </Field>

                        <Field
                            data-invalid={errors.password ? true : undefined}
                        >
                            <div className="flex items-center gap-2">
                                <FieldLabel htmlFor="password">
                                    Palavra-passe
                                </FieldLabel>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="ml-auto text-sm"
                                        tabIndex={5}
                                    >
                                        Esqueceu-se da palavra-passe?
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Palavra-passe"
                                aria-invalid={
                                    errors.password ? true : undefined
                                }
                            />
                            <FieldError>{errors.password}</FieldError>
                        </Field>

                        <Field orientation="horizontal">
                            <Checkbox
                                id="remember"
                                name="remember"
                                tabIndex={3}
                            />
                            <FieldLabel
                                htmlFor="remember"
                                className="font-normal"
                            >
                                Manter sessão
                            </FieldLabel>
                        </Field>

                        <Button
                            type="submit"
                            className="w-full"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Entrar
                        </Button>
                    </FieldGroup>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-primary">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Entrar',
    description: 'Introduza o email e a palavra-passe para aceder.',
};
