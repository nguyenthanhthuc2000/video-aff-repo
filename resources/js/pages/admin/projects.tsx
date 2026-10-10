import ProjectCostDialog, { type ProjectCost, usd } from '@/components/admin/project-cost-dialog';
import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import Pagination, { type Paginated } from '@/components/pagination';
import ProgressBar from '@/components/progress-bar';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { formatDuration } from '@/lib/duration';
import { projectStatusLabels } from '@/lib/project-status';
import { cn } from '@/lib/utils';
import { type VideoProjectStatus } from '@/types/models';
import { Head, Link, router } from '@inertiajs/react';
import { ChartPie, CircleDollarSign, Film, Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface AdminProject {
    id: number;
    product: string | null;
    user: { name: string | null; email: string | null };
    status: VideoProjectStatus;
    video_type: 'standard' | 'transformation';
    progress: { percent: number; label: string };
    error: string | null;
    has_video: boolean;
    created_at: string;
    updated_at: string;
    cost: ProjectCost;
    total_ms: number | null;
}

interface TimeStats {
    count: number;
    avg_ms: number | null;
    median_ms: number | null;
    min_ms: number | null;
    max_ms: number | null;
    steps: Record<string, { label: string; count: number; avg_ms: number | null; median_ms: number | null }>;
    by_type: Partial<Record<'standard' | 'transformation', { count: number; avg_ms: number | null }>>;
}

export default function AdminProjects({
    projects,
    filters,
    usdVnd,
    timeStats,
}: {
    projects: Paginated<AdminProject>;
    filters: { status: VideoProjectStatus | null; q: string; sort: 'latest' | 'cost' };
    usdVnd: number;
    timeStats: TimeStats;
}) {
    const [q, setQ] = useState(filters.q);
    const [costOf, setCostOf] = useState<AdminProject | null>(null);
    const vnd = (value: number) => `${Math.round(value * usdVnd).toLocaleString('vi-VN')}đ`;

    const go = (params: Record<string, string | null>) =>
        router.get(
            route('admin.projects.index'),
            { q: filters.q || undefined, status: filters.status || undefined, sort: filters.sort === 'cost' ? 'cost' : undefined, ...params },
            { preserveState: true, replace: true },
        );

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        go({ q: q || null });
    };

    return (
        <AdminLayout title="Dự án video">
            <Head title="Dự án video" />
            <div className="space-y-5">
                <Heading title="Dự án video" description="Dự án của mọi người dùng: theo dõi tiến độ, lỗi và chi phí xử lý từng video." />
                <FlashStatus />

                <TimeStatsPanel stats={timeStats} />

                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={search} className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tên sản phẩm hoặc email" className="w-64 pl-9" />
                    </form>
                    <div className="flex flex-wrap gap-1">
                        <Chip active={!filters.status} onClick={() => go({ status: null })}>
                            Tất cả
                        </Chip>
                        {(Object.keys(projectStatusLabels) as VideoProjectStatus[]).map((status) => (
                            <Chip key={status} active={filters.status === status} onClick={() => go({ status })}>
                                {projectStatusLabels[status]}
                            </Chip>
                        ))}
                    </div>
                    <div className="flex gap-1 md:ml-auto">
                        <Chip active={filters.sort !== 'cost'} onClick={() => go({ sort: null })}>
                            Mới nhất
                        </Chip>
                        <Chip active={filters.sort === 'cost'} onClick={() => go({ sort: 'cost' })}>
                            Tốn nhất
                        </Chip>
                    </div>
                </div>

                <div className="divide-y rounded-xl border">
                    {projects.data.length === 0 && <p className="text-muted-foreground p-6 text-center text-sm">Không có dự án nào.</p>}
                    {projects.data.map((project) => (
                        <div key={project.id} className="p-4">
                            <div className="grid gap-3 md:grid-cols-[1fr_200px_150px_auto] md:items-center">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span className="text-muted-foreground text-xs">#{project.id}</span>
                                        <Link href={route('admin.projects.show', project.id)} className="truncate font-medium hover:underline">
                                            {project.product ?? '—'}
                                        </Link>
                                        {project.video_type === 'transformation' && <Badge variant="outline">Biến hình</Badge>}
                                        {project.has_video && (
                                            <Badge variant="secondary">
                                                <Film className="size-3" /> Có video
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        {project.user.name} · {project.user.email} · {new Date(project.created_at).toLocaleString('vi-VN')}
                                        {project.total_ms !== null && ` · xử lý ${formatDuration(project.total_ms)}`}
                                    </p>
                                    {project.error && <p className="mt-1 line-clamp-2 text-xs text-red-600 dark:text-red-400">{project.error}</p>}
                                </div>
                                <ProgressBar percent={project.progress.percent} label={project.progress.label} failed={project.status === 'failed'} />
                                <button
                                    type="button"
                                    onClick={() => setCostOf(project)}
                                    className="hover:bg-muted flex items-center justify-between gap-2 rounded-lg border px-3 py-1.5 text-left"
                                    title="Bấm để xem chi phí từng bước"
                                >
                                    <span>
                                        <span className="flex items-center gap-1 text-sm font-semibold">
                                            <CircleDollarSign className="size-3.5 text-orange-500" /> {usd(project.cost.total_usd)}
                                        </span>
                                        <span className="text-muted-foreground block text-xs">{vnd(project.cost.total_usd)}</span>
                                    </span>
                                    <ChartPie className="text-muted-foreground size-4" />
                                </button>
                                <Badge
                                    variant={project.status === 'failed' ? 'destructive' : 'secondary'}
                                    className="justify-self-start md:justify-self-end"
                                >
                                    {projectStatusLabels[project.status]}
                                </Badge>
                            </div>
                        </div>
                    ))}
                </div>
                <Pagination page={projects} />
            </div>
            <ProjectCostDialog project={costOf} usdVnd={usdVnd} onClose={() => setCostOf(null)} />
        </AdminLayout>
    );
}

function TimeStatsPanel({ stats }: { stats: TimeStats }) {
    const cards = [
        { label: 'Trung bình mỗi video', value: formatDuration(stats.avg_ms) },
        { label: 'Trung vị', value: formatDuration(stats.median_ms) },
        { label: 'Nhanh nhất', value: formatDuration(stats.min_ms) },
        { label: 'Chậm nhất', value: formatDuration(stats.max_ms) },
    ];
    const maxStep = Math.max(1, ...Object.values(stats.steps).map((step) => step.avg_ms ?? 0));

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-medium">Thời gian xử lý video</h2>
                <span className="text-muted-foreground text-xs">
                    {stats.count} video đã xuất xong (200 dự án gần nhất) · từ lúc tạo dự án đến lúc xuất xong
                    {stats.by_type.standard?.avg_ms ? ` · thường TB ${formatDuration(stats.by_type.standard.avg_ms)}` : ''}
                    {stats.by_type.transformation?.avg_ms ? ` · biến hình TB ${formatDuration(stats.by_type.transformation.avg_ms)}` : ''}
                </span>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {cards.map((card) => (
                    <div key={card.label} className="bg-muted/40 rounded-lg p-3">
                        <p className="text-muted-foreground text-xs">{card.label}</p>
                        <p className="text-xl font-bold">{card.value}</p>
                    </div>
                ))}
            </div>
            <div className="space-y-1.5">
                {Object.entries(stats.steps).map(([key, step]) => (
                    <div key={key} className="grid items-center gap-2 text-sm sm:grid-cols-[180px_1fr_170px]">
                        <span>{step.label}</span>
                        <div className="bg-muted h-2 overflow-hidden rounded">
                            <div
                                className="h-full rounded bg-gradient-to-r from-orange-500 to-rose-500"
                                style={{ width: `${((step.avg_ms ?? 0) / maxStep) * 100}%` }}
                            />
                        </div>
                        <span className="text-right text-xs">
                            TB <b>{formatDuration(step.avg_ms)}</b> · trung vị {formatDuration(step.median_ms)}
                            {step.count > 0 && <span className="text-muted-foreground"> · {step.count}</span>}
                        </span>
                    </div>
                ))}
            </div>
        </section>
    );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('rounded-full border px-3 py-1 text-sm', active ? 'bg-foreground text-background' : 'hover:bg-muted')}
        >
            {children}
        </button>
    );
}
