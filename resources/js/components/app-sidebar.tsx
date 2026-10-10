import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Clapperboard, Coins, Film, History, Image, Music, Package, ShieldCheck, Users, Wallet } from 'lucide-react';
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
        title: 'Video mẫu',
        url: '/sample-videos',
        icon: Film,
    },
    {
        title: 'Bối cảnh',
        url: '/backgrounds',
        icon: Image,
    },
    {
        title: 'Âm thanh',
        url: '/audio-tracks',
        icon: Music,
    },
    {
        title: 'Nạp tiền',
        url: '/billing',
        icon: Wallet,
    },
    {
        title: 'Lịch sử Credits',
        url: '/credits',
        icon: History,
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
    const { auth } = usePage<SharedData>().props;
    // Khu vực quản trị (cài đặt hệ thống, người dùng...) chỉ hiện với admin.
    const items: NavItem[] = auth.user?.is_admin ? [...mainNavItems, { title: 'Quản trị', url: '/admin', icon: ShieldCheck }] : mainNavItems;

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
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                {auth.user && (
                    <Link
                        href={route('billing.index')}
                        className={cn(
                            'flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm group-data-[collapsible=icon]:hidden',
                            (auth.user.credit_balance ?? 0) <= 0
                                ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300'
                                : 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-200',
                        )}
                    >
                        <span className="flex items-center gap-1.5">
                            <Coins className="size-4" /> {(auth.user.credit_balance ?? 0).toLocaleString('vi-VN')} Credits
                        </span>
                        <span className="text-xs font-medium underline">Nạp</span>
                    </Link>
                )}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
