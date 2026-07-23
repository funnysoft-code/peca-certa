import { Head, Link } from '@inertiajs/react';
import { KeyRound, Shield, UserPlus, Users } from 'lucide-react';
import { AdminSubnav } from '@/components/admin/admin-subnav';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminRoles } from '@/routes/admin/roles';
import { index as adminUsers } from '@/routes/admin/users';

type Props = {
    stats: {
        users: number;
        pending_invites: number;
        roles: number;
    };
};

export default function AdminDashboard({ stats }: Props) {
    return (
        <>
            <Head title="Administração" />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4 md:p-6">
                <AdminSubnav />

                <div className="space-y-1">
                    <p className="text-xs font-medium tracking-[0.14em] text-brand uppercase">
                        Plataforma
                    </p>
                    <h1 className="font-display text-2xl font-semibold tracking-tight">
                        Administração
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Gestão de utilizadores, convites e permissões.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        title="Utilizadores"
                        value={stats.users}
                        description="Contas na plataforma"
                        icon={Users}
                    />
                    <StatCard
                        title="Convites pendentes"
                        value={stats.pending_invites}
                        description="Ainda sem palavra-passe"
                        icon={UserPlus}
                        accent={stats.pending_invites > 0}
                    />
                    <StatCard
                        title="Funções"
                        value={stats.roles}
                        description="Papéis no sistema"
                        icon={Shield}
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Link
                        href={adminUsers()}
                        className="group rounded-xl border border-border/70 bg-card p-5 transition-colors hover:border-brand/40 hover:bg-card/90"
                    >
                        <div className="mb-3 flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand transition-colors group-hover:bg-brand/15">
                            <Users className="size-5" />
                        </div>
                        <h3 className="font-display text-base font-semibold tracking-tight">
                            Utilizadores
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Convidar operadores, reenviar convites e atribuir
                            funções.
                        </p>
                    </Link>

                    <Link
                        href={adminRoles()}
                        className="group rounded-xl border border-border/70 bg-card p-5 transition-colors hover:border-brand/40 hover:bg-card/90"
                    >
                        <div className="mb-3 flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand transition-colors group-hover:bg-brand/15">
                            <KeyRound className="size-5" />
                        </div>
                        <h3 className="font-display text-base font-semibold tracking-tight">
                            Funções e permissões
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Matriz de permissões por função, com gravação em
                            lote.
                        </p>
                    </Link>
                </div>
            </div>
        </>
    );
}

function StatCard({
    title,
    value,
    description,
    icon: Icon,
    accent = false,
}: {
    title: string;
    value: number;
    description: string;
    icon: typeof Users;
    accent?: boolean;
}) {
    return (
        <Card className="border-border/70 bg-card">
            <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
                <div>
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        {title}
                    </CardTitle>
                    <CardDescription className="sr-only">
                        {description}
                    </CardDescription>
                </div>
                <Icon
                    className={
                        accent ? 'size-4 text-amber-300' : 'size-4 text-brand'
                    }
                />
            </CardHeader>
            <CardContent>
                <div
                    className={
                        accent
                            ? 'font-display text-3xl font-semibold tracking-tight text-amber-200'
                            : 'font-display text-3xl font-semibold tracking-tight'
                    }
                >
                    {value}
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    {description}
                </p>
            </CardContent>
        </Card>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: adminDashboard(),
        },
    ],
};
