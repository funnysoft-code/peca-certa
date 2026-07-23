import { Link, usePage } from '@inertiajs/react';
import {
    ChartColumn,
    KeyRound,
    ScanSearch,
    Search,
    Shield,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminRoles } from '@/routes/admin/roles';
import { index as adminUsers } from '@/routes/admin/users';
import { index as analyticsIndex } from '@/routes/analytics';
import { create as identifyCreate } from '@/routes/identify';
import { index as partsIndex } from '@/routes/parts';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Identificar',
        href: identifyCreate(),
        icon: ScanSearch,
    },
    {
        title: 'Peças',
        href: partsIndex(),
        icon: Search,
    },
    {
        title: 'Análises',
        href: analyticsIndex(),
        icon: ChartColumn,
    },
];

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const can = auth.can ?? {};
    const showAdmin = can['admin.access'] === true;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={identifyCreate()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />

                {showAdmin && (
                    <SidebarGroup className="mt-2">
                        <SidebarGroupLabel>Admin</SidebarGroupLabel>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton asChild tooltip="Admin">
                                    <Link href={adminDashboard()} prefetch>
                                        <Shield />
                                        <span>Painel</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                            {can['users.view'] && (
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        tooltip="Utilizadores"
                                    >
                                        <Link href={adminUsers()} prefetch>
                                            <Users />
                                            <span>Utilizadores</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            )}
                            {can['roles.view'] && (
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        tooltip="Funções"
                                    >
                                        <Link href={adminRoles()} prefetch>
                                            <KeyRound />
                                            <span>Funções</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            )}
                        </SidebarMenu>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
