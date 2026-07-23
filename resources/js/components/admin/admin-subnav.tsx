import { Link, usePage } from '@inertiajs/react';
import { KeyRound, LayoutDashboard, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminRoles } from '@/routes/admin/roles';
import { index as adminUsers } from '@/routes/admin/users';
import type { Auth } from '@/types';

const items = [
    {
        title: 'Painel',
        href: '/admin',
        match: (url: string) => url === '/admin' || url === '/admin/',
        icon: LayoutDashboard,
        permission: 'admin.access' as const,
    },
    {
        title: 'Utilizadores',
        href: '/admin/users',
        match: (url: string) => url.startsWith('/admin/users'),
        icon: Users,
        permission: 'users.view' as const,
    },
    {
        title: 'Funções',
        href: '/admin/roles',
        match: (url: string) => url.startsWith('/admin/roles'),
        icon: KeyRound,
        permission: 'roles.view' as const,
    },
] as const;

export function AdminSubnav() {
    const page = usePage<{ auth: Auth }>();
    const can = page.props.auth.can ?? {};
    const path = page.url.split('?')[0] ?? page.url;

    const visible = items.filter((item) => {
        if (item.permission === 'admin.access') {
            return can['admin.access'] === true;
        }

        return can[item.permission] === true;
    });

    if (visible.length === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Secções de administração"
            className="flex flex-wrap gap-1 rounded-xl border border-border/70 bg-card/60 p-1"
        >
            {visible.map((item) => {
                const active = item.match(path);
                const Icon = item.icon;
                const href =
                    item.title === 'Painel'
                        ? adminDashboard()
                        : item.title === 'Utilizadores'
                          ? adminUsers()
                          : adminRoles();

                return (
                    <Link
                        key={item.title}
                        href={href}
                        prefetch
                        className={cn(
                            'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                            active
                                ? 'bg-brand/15 text-brand'
                                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4 shrink-0" />
                        {item.title}
                    </Link>
                );
            })}
        </nav>
    );
}
