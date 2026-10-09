import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { BookOpen, Clapperboard, KeyRound, Package, SlidersHorizontal, Sparkles, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dự án video',
        url: '/projects',
        icon: Clapperboard,
    },
    {
        title: 'Sản phẩm',
        url: '/products',
        icon: Package,
    },
    {
        title: 'Người mẫu',
        url: '/fashion-models',
        icon: Users,
    },
    {
        title: 'Cài đặt video',
        url: '/video-settings',
        icon: SlidersHorizontal,
    },
    {
        title: 'Xác thực Claude',
        url: '/claude',
        icon: Sparkles,
    },
    {
        title: 'Xác thực Muse',
        url: '/muse',
        icon: KeyRound,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Hướng dẫn thiết kế',
        url: 'https://github.com/nguyenthanhthuc2000/video-aff-repo/blob/main/docs/thiet-ke.md',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/products" prefetch>
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
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
