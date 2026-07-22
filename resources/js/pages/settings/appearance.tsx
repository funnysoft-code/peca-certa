import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Aparência" />

            <h1 className="sr-only">Aparência</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Aparência"
                    description="O R2CZ Auto Finder usa exclusivamente o tema escuro."
                />
                <div className="rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground">
                    <p>
                        O tema está fixo em modo escuro para manter a identidade
                        visual R2CZ Auto e o contraste adequado em ambiente de
                        oficina.
                    </p>
                </div>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Aparência',
            href: editAppearance(),
        },
    ],
};
