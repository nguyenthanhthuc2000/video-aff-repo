import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/admin-layout';
import { projectStatusLabels } from '@/lib/project-status';
import { cn } from '@/lib/utils';
import { type VideoProjectStatus } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CircleDollarSign, Clapperboard, Film, ListChecks, type LucideIcon, Music, Package, Users } from 'lucide-react';

type Stats = Record<
    | 'users'
    | 'users_week'
    | 'admins'
    | 'blocked'
    | 'products'
    | 'projects'
    | 'projects_week'
    | 'renders_done'
    | 'renders_week'
    | 'sample_videos'
    | 'audio_tracks'
    | 'jobs_pending'
    | 'jobs_failed',
    number
>;

interface Props {
    stats: Stats;
    aiCost: { month_usd: number; month_calls: number; usd_vnd: number };
    projectsByStatus: Record<VideoProjectStatus, number>;
    services: { script_driver: string; image_driver: string; workflow: 'auto' | 'manual'; muse: { state: string; message: string } };
    recentErrors: { id: number; product: string | null; user: string | null; error: string; updated_at: string }[];
}

const scriptDrivers: Record<string, string> = {
    claude_cli: 'Gói Claude (đã đăng nhập)',
    claude: 'Claude API',
    web: 'Chưa kết nối (dán tay claude.ai)',
    fake: 'Dữ liệu mẫu',
};

export default function AdminDashboard({ stats, aiCost, projectsByStatus, services, recentErrors }: Props) {
    const cards: { label: string; value: number | string; sub?: string; icon: LucideIcon; href?: string }[] = [
        {
            label: 'Người dùng',
            value: stats.users,
            sub: `+${stats.users_week} trong 7 ngày · ${stats.admins} admin · ${stats.blocked} bị khoá`,
            icon: Users,
            href: '/admin/users',
        },
        { label: 'Sản phẩm', value: stats.products, icon: Package },
        { label: 'Dự án video', value: stats.projects, sub: `+${stats.projects_week} trong 7 ngày`, icon: Clapperboard, href: '/admin/projects' },
        { label: 'Video đã xuất', value: stats.renders_done, sub: `+${stats.renders_week} trong 7 ngày`, icon: Film },
        {
            label: 'Video mẫu · Nhạc',
            value: stats.sample_videos + stats.audio_tracks,
            sub: `${stats.sample_videos} video mẫu · ${stats.audio_tracks} bài nhạc`,
            icon: Music,
        },
        {
            label: 'Chi phí AI tháng này',
            value: `$${aiCost.month_usd.toLocaleString('en-US', { maximumFractionDigits: aiCost.month_usd < 1 ? 4 : 2 })}`,
            sub: `≈ ${Math.round(aiCost.month_usd * aiCost.usd_vnd).toLocaleString('vi-VN')}đ · ${aiCost.month_calls} lần gọi`,
            icon: CircleDollarSign,
            href: '/admin/ai-costs',
        },
        {
            label: 'Hàng đợi',
            value: stats.jobs_pending,
            sub: stats.jobs_failed > 0 ? `${stats.jobs_failed} job lỗi` : 'Không có job lỗi',
            icon: ListChecks,
            href: '/admin/jobs',
        },
    ];
    const maxStatus = Math.max(1, ...Object.values(projectsByStatus));
    const museOk = services.muse.state === 'logged_in';
    const claudeOk = ['claude_cli', 'claude'].includes(services.script_driver);

    return (
        <AdminLayout title="Tổng quan">
            <Head title="Quản trị" />
            <div className="space-y-6">
                <Heading title="Tổng quan" description="Tình hình hệ thống và các dịch vụ." />
                <FlashStatus />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {cards.map((card) => {
                        const body = (
                            <>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground text-sm">{card.label}</span>
                                    <card.icon className="text-muted-foreground size-4" />
                                </div>
                                <p className="mt-2 text-3xl font-bold">
                                    {typeof card.value === 'number' ? card.value.toLocaleString('vi-VN') : card.value}
                                </p>
                                {card.sub && <p className="text-muted-foreground mt-1 text-xs">{card.sub}</p>}
                            </>
                        );
                        return card.href ? (
                            <Link key={card.label} href={card.href} className="hover:bg-muted/40 rounded-xl border p-4 transition">
                                {body}
                            </Link>
                        ) : (
                            <div key={card.label} className="rounded-xl border p-4">
                                {body}
                            </div>
                        );
                    })}
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">Dự án theo trạng thái</h2>
                        <div className="space-y-2">
                            {(Object.keys(projectStatusLabels) as VideoProjectStatus[]).map((status) => (
                                <Link key={status} href={`/admin/projects?status=${status}`} className="block space-y-1">
                                    <div className="flex justify-between text-sm">
                                        <span>{projectStatusLabels[status]}</span>
                                        <span className="font-medium">{projectsByStatus[status] ?? 0}</span>
                                    </div>
                                    <div className="bg-muted h-1.5 overflow-hidden rounded">
                                        <div
                                            className={cn(
                                                'h-full rounded',
                                                status === 'failed' ? 'bg-red-500' : 'bg-gradient-to-r from-orange-500 to-rose-500',
                                            )}
                                            style={{ width: `${((projectsByStatus[status] ?? 0) / maxStatus) * 100}%` }}
                                        />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>

                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">Dịch vụ</h2>
                        <ServiceRow
                            ok={claudeOk}
                            label="Viết kịch bản, phân tích"
                            value={scriptDrivers[services.script_driver] ?? services.script_driver}
                            href="/admin/settings/claude"
                        />
                        <ServiceRow
                            ok={museOk}
                            label="Tạo ảnh, clip (muse.ai)"
                            value={services.muse.message || services.muse.state}
                            href="/admin/settings/muse"
                        />
                        <ServiceRow
                            ok
                            label="Chế độ xử lý"
                            value={services.workflow === 'auto' ? 'Tự động tất cả các bước' : 'Duyệt thủ công từng bước'}
                            href="/admin/settings/video"
                        />
                    </section>
                </div>

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="flex items-center gap-2 font-medium">
                        <AlertTriangle className="size-4 text-amber-500" /> Lỗi gần đây
                    </h2>
                    {recentErrors.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Không có dự án nào báo lỗi.</p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {recentErrors.map((item) => (
                                <li key={item.id} className="space-y-0.5 py-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{item.product ?? 'Dự án #' + item.id}</span>
                                        <span className="text-muted-foreground text-xs">{item.user}</span>
                                        <span className="text-muted-foreground ml-auto text-xs">
                                            {new Date(item.updated_at).toLocaleString('vi-VN')}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground line-clamp-2 text-xs">{item.error}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}

function ServiceRow({ ok, label, value, href }: { ok: boolean; label: string; value: string; href: string }) {
    return (
        <Link href={href} className="hover:bg-muted/40 flex items-center justify-between gap-3 rounded-lg border p-3 transition">
            <div className="min-w-0">
                <p className="text-sm font-medium">{label}</p>
                <p className="text-muted-foreground truncate text-xs">{value}</p>
            </div>
            <Badge variant={ok ? 'secondary' : 'destructive'}>{ok ? 'Hoạt động' : 'Cần xử lý'}</Badge>
        </Link>
    );
}
