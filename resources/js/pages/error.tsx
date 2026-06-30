import { Head, Link } from '@inertiajs/react';

interface ErrorPageProps {
    status: number;
}

const STATUS_MESSAGES: Record<number, string> = {
    403: 'Forbidden',
    404: 'Page Not Found',
    405: 'Method Not Allowed',
    429: 'Too Many Requests',
    500: 'Server Error',
    503: 'Service Unavailable',
};

export default function Error({ status }: ErrorPageProps) {
    const title = STATUS_MESSAGES[status] ?? 'Something Went Wrong';

    return (
        <>
            <Head title={`${status} — ${title}`} />
            <main className="flex min-h-screen flex-col items-center justify-center gap-3 p-6 text-center">
                <p className="text-6xl font-bold tracking-tight">{status}</p>
                <h1 className="text-xl font-medium text-muted-foreground">
                    {title}
                </h1>
                <Link
                    href="/"
                    className="mt-2 text-sm underline underline-offset-4 hover:text-foreground"
                >
                    Go back home
                </Link>
            </main>
        </>
    );
}
