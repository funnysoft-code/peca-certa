import { Head, Link } from '@inertiajs/react';

interface ErrorPageProps {
    status: number;
}

const STATUS_MESSAGES: Record<number, string> = {
    403: 'Acesso negado',
    404: 'Página não encontrada',
    405: 'Método não permitido',
    429: 'Demasiados pedidos',
    500: 'Erro do servidor',
    503: 'Serviço indisponível',
};

export default function Error({ status }: ErrorPageProps) {
    const title = STATUS_MESSAGES[status] ?? 'Algo correu mal';

    return (
        <>
            <Head title={`${status} — ${title}`} />
            <main className="flex min-h-screen flex-col items-center justify-center gap-3 bg-background p-6 text-center text-foreground">
                <p className="font-display text-6xl font-bold tracking-tight text-primary">
                    {status}
                </p>
                <h1 className="text-xl font-medium text-muted-foreground">
                    {title}
                </h1>
                <Link
                    href="/"
                    className="mt-2 text-sm text-primary underline underline-offset-4 hover:text-[var(--brand-hover)]"
                >
                    Voltar ao início
                </Link>
            </main>
        </>
    );
}
