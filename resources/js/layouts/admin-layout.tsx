import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/react';
import {
    BadgePercent,
    CircleDollarSign,
    Clapperboard,
    Cpu,
    KeyRound,
    LayoutDashboard,
    ListChecks,
    type LucideIcon,
    SlidersHorizontal,
    Sparkles,
    Tags,
    Users,
} from 'lucide-react';
import { type ReactNode } from 'react';

const groups: { title: string; items: { title: string; url: string; icon: LucideIcon }[] }[] = [
    {
        title: 'Quản lý',
        items: [
            { title: 'Tổng quan', url: '/admin', icon: LayoutDashboard },
            { title: 'Người dùng', url: '/admin/users', icon: Users },
            { title: 'Dự án video', url: '/admin/projects', icon: Clapperboard },
            { title: 'Chi phí AI', url: '/admin/ai-costs', icon: CircleDollarSign },
            { title: 'Hàng đợi', url: '/admin/jobs', icon: ListChecks },
            { title: 'Loại sản phẩm', url: '/admin/product-categories', icon: Tags },
        ],
    },
    {
        title: 'Cài đặt hệ thống',
        items: [
            { title: 'Cài đặt video', url: '/admin/settings/video', icon: SlidersHorizontal },
            { title: 'Model AI', url: '/admin/settings/ai-models', icon: Cpu },
            { title: 'Giá bán Credits', url: '/admin/settings/credit-pricing', icon: BadgePercent },
            { title: 'Xác thực Claude', url: '/admin/settings/claude', icon: Sparkles },
            { title: 'Xác thực Muse', url: '/admin/settings/muse', icon: KeyRound },
        ],
    },
];

/** Khu vực quản trị: menu riêng bên trái (trên điện thoại là thanh cuộn ngang) và nội dung bên phải. */
export default function AdminLayout({ title, children }: { title: string; children: ReactNode }) {
    const path = window.location.pathname.replace(/\/$/, '') || '/';
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Quản trị', href: '/admin' }, ...(path === '/admin' ? [] : [{ title, href: path }])];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex flex-col gap-6 p-4 lg:flex-row">
                <aside className="lg:w-52 lg:shrink-0">
                    <nav className="flex gap-4 overflow-x-auto lg:flex-col lg:gap-6">
                        {groups.map((group) => (
                            <div key={group.title} className="flex gap-1 lg:flex-col">
                                <p className="text-muted-foreground hidden px-3 pb-1 text-xs font-medium tracking-wide uppercase lg:block">
                                    {group.title}
                                </p>
                                {group.items.map((item) => (
                                    <Link
                                        key={item.url}
                                        href={item.url}
                                        className={cn(
                                            'flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm whitespace-nowrap transition',
                                            path === item.url
                                                ? 'bg-muted font-medium'
                                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                        )}
                                    >
                                        <item.icon className="size-4" /> {item.title}
                                    </Link>
                                ))}
                            </div>
                        ))}
                    </nav>
                </aside>
                <div className="min-w-0 flex-1">{children}</div>
            </div>
        </AppLayout>
    );
}
