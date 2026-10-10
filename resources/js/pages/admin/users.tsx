import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import Pagination, { type Paginated } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Lock, LockOpen, Search, Shield, ShieldOff } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    blocked_at: string | null;
    products_count: number;
    video_projects_count: number;
    credit_balance: number;
    created_at: string;
}

const filterOptions = [
    { value: '', label: 'Tất cả' },
    { value: 'admin', label: 'Admin' },
    { value: 'blocked', label: 'Bị khoá' },
];

export default function AdminUsers({ users, filters }: { users: Paginated<AdminUser>; filters: { q: string; filter: string | null } }) {
    const { auth } = usePage<SharedData>().props;
    const [q, setQ] = useState(filters.q);

    const go = (params: Record<string, string | null>) =>
        router.get(
            route('admin.users.index'),
            { q: filters.q || undefined, filter: filters.filter || undefined, ...params },
            { preserveState: true, replace: true },
        );

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        go({ q: q || null });
    };

    const update = (user: AdminUser, data: Record<string, boolean>, question: string) => {
        if (confirm(question)) {
            router.put(route('admin.users.update', user.id), data, { preserveScroll: true });
        }
    };

    return (
        <AdminLayout title="Người dùng">
            <Head title="Người dùng" />
            <div className="space-y-5">
                <Heading title="Người dùng" description="Cấp quyền quản trị, khoá tài khoản vi phạm." />
                <FlashStatus />

                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={search} className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tìm tên hoặc email" className="w-64 pl-9" />
                    </form>
                    <div className="flex gap-1">
                        {filterOptions.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => go({ filter: option.value || null })}
                                className={cn(
                                    'rounded-full border px-3 py-1 text-sm',
                                    (filters.filter ?? '') === option.value ? 'bg-foreground text-background' : 'hover:bg-muted',
                                )}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">Người dùng</th>
                                <th className="px-4 py-2.5 font-medium">Credits</th>
                                <th className="px-4 py-2.5 font-medium">Sản phẩm</th>
                                <th className="px-4 py-2.5 font-medium">Dự án</th>
                                <th className="px-4 py-2.5 font-medium">Ngày tạo</th>
                                <th className="px-4 py-2.5 text-right font-medium">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
                                        Không có người dùng nào.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => {
                                const isMe = user.id === auth.user.id;
                                return (
                                    <tr key={user.id} className={cn(user.blocked_at && 'bg-red-50/50 dark:bg-red-950/20')}>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <Link href={route('admin.users.show', user.id)} className="font-medium hover:underline">
                                                    {user.name}
                                                </Link>
                                                {user.is_admin && <Badge>Admin</Badge>}
                                                {user.blocked_at && <Badge variant="destructive">Bị khoá</Badge>}
                                                {isMe && <Badge variant="outline">Bạn</Badge>}
                                            </div>
                                            <span className="text-muted-foreground text-xs">{user.email}</span>
                                        </td>
                                        <td className="px-4 py-3 font-medium">{user.credit_balance.toLocaleString('vi-VN')}</td>
                                        <td className="px-4 py-3">{user.products_count}</td>
                                        <td className="px-4 py-3">{user.video_projects_count}</td>
                                        <td className="text-muted-foreground px-4 py-3 text-xs">
                                            {new Date(user.created_at).toLocaleDateString('vi-VN')}
                                        </td>
                                        <td className="px-4 py-3">
                                            {!isMe && (
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            update(
                                                                user,
                                                                { is_admin: !user.is_admin },
                                                                user.is_admin
                                                                    ? `Bỏ quyền admin của ${user.email}?`
                                                                    : `Cấp quyền admin cho ${user.email}?`,
                                                            )
                                                        }
                                                    >
                                                        {user.is_admin ? <ShieldOff /> : <Shield />} {user.is_admin ? 'Bỏ admin' : 'Cấp admin'}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className={cn(!user.blocked_at && 'text-destructive')}
                                                        onClick={() =>
                                                            update(
                                                                user,
                                                                { blocked: !user.blocked_at },
                                                                user.blocked_at
                                                                    ? `Mở khoá ${user.email}?`
                                                                    : `Khoá ${user.email}? Người này sẽ bị đăng xuất.`,
                                                            )
                                                        }
                                                    >
                                                        {user.blocked_at ? <LockOpen /> : <Lock />} {user.blocked_at ? 'Mở khoá' : 'Khoá'}
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <Pagination page={users} />
            </div>
        </AdminLayout>
    );
}
