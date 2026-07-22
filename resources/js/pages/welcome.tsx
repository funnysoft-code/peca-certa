import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

/**
 * Public gate for unauthenticated visitors.
 * Authenticated users never reach this page (server redirects to Identify).
 */
export default function Welcome() {
    return (
        <>
            <Head title="Entrar" />
            <div className="flex min-h-svh flex-col items-center justify-center bg-background px-6 py-12 text-foreground">
                <main className="flex w-full max-w-md flex-col items-center gap-8 text-center">
                    <AppLogoIcon
                        variant="full"
                        className="h-14 w-auto sm:h-16"
                        alt="R2CZ Auto"
                    />

                    <div className="space-y-3">
                        <h1 className="font-display text-2xl font-semibold tracking-tight sm:text-3xl">
                            R2CZ Auto Finder
                        </h1>
                        <p className="text-sm leading-relaxed text-muted-foreground sm:text-base">
                            Identifique peças OE e compare preços nos
                            fornecedores — para a oficina.
                        </p>
                    </div>

                    <Button asChild size="lg" className="min-w-40 px-8">
                        <Link href={login()} data-test="entrar-button">
                            Entrar
                        </Link>
                    </Button>

                    <a
                        href="https://r2czauto.pt"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-sm text-muted-foreground transition-colors hover:text-primary"
                    >
                        r2czauto.pt
                    </a>
                </main>
            </div>
        </>
    );
}
