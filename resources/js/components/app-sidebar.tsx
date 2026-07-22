import { Link } from '@inertiajs/react';
import { ChartColumn, ScanSearch, Search } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as analyticsIndex } from '@/routes/analytics';
import { create as identifyCreate } from '@/routes/identify';
import { index as partsIndex } from '@/routes/parts';
import type { NavItem } from '@/types';

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
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
