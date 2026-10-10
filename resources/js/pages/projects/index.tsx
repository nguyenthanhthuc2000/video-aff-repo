import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import ProgressBar from '@/components/progress-bar';
import ProjectCredits, { type ProjectCreditsData } from '@/components/project-credits';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { projectStatusLabels } from '@/lib/project-status';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type VideoProject, type VideoProjectStatus } from '@/types/models';
import { Head, Link, router } from '@inertiajs/react';
import { Download, Play } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dự án video', href: '/projects' }];

export default function ProjectsIndex({
    projects,
    filters,
    statusCounts,
    total,
    credits,
}: {
    projects: VideoProject[];
    filters: { status: VideoProjectStatus | null };
    statusCounts: Partial<Record<VideoProjectStatus, number>>;
    total: number;
    /** Chi phí sản xuất (Credits) theo id dự án. */
    credits: Record<number, ProjectCreditsData>;
}) {
    usePoll(
        projects.some((project) => project.is_busy),
        ['projects', 'statusCounts', 'total'],
    );

    const [preview, setPreview] = useState<VideoProject | null>(null);

    // Bộ lọc nằm trên URL (?status=...) để tải lại trang vẫn giữ.
    const filter = (status: VideoProjectStatus | null) =>
        router.get(route('projects.index'), status ? { status } : {}, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dự án video" />
            <div className="space-y-6 p-4">
                <Heading title="Dự án video" description="Tạo dự án mới từ trang của một sản phẩm." />
                <FlashStatus />

                {total > 0 && (
                    <div className="flex flex-wrap gap-2">
                        <FilterChip active={filters.status === null} label="Tất cả" count={total} onClick={() => filter(null)} />
                        {(Object.keys(projectStatusLabels) as VideoProjectStatus[]).map((status) => (
                            <FilterChip
                                key={status}
                                active={filters.status === status}
                                label={projectStatusLabels[status]}
                                count={statusCounts[status] ?? 0}
                                onClick={() => filter(status)}
                            />
                        ))}
                    </div>
                )}

                {total > 0 && projects.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Không có dự án nào ở trạng thái này.</p>
                ) : projects.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Chưa có dự án nào. Vào{' '}
                        <Link href={route('products.index')} className="underline">
                            Sản phẩm
                        </Link>
                        , chọn một sản phẩm rồi chọn người mẫu để bắt đầu.
                    </p>
                ) : (
                    <div className="divide-y rounded-xl border">
                        {projects.map((project) => (
                            <div key={project.id} className="hover:bg-muted/50 flex items-center gap-4 p-3">
                                <Link href={route('projects.show', project.id)} className="flex min-w-0 flex-1 items-center gap-4">
                                    {project.product?.primary_image_url && (
                                        <img src={project.product.primary_image_url} alt="" className="size-12 rounded-md object-cover" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{project.product?.name}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {project.video_type === 'transformation' && (
                                                <Badge variant="outline" className="mr-1">
                                                    Biến hình
                                                </Badge>
                                            )}
                                            Người mẫu: {project.before_fashion_model ? `${project.before_fashion_model.name} → ` : ''}
                                            {project.fashion_model?.name}
                                        </p>
                                        <ProgressBar
                                            className="mt-2 max-w-md"
                                            percent={project.progress.percent}
                                            label={project.progress.label}
                                            failed={project.status === 'failed'}
                                        />
                                    </div>
                                </Link>
                                <div className="flex shrink-0 flex-col items-end gap-2 sm:flex-row sm:items-center">
                                    <ProjectCredits credits={credits[project.id]} />
                                    {project.latest_render?.url && (
                                        <Button size="sm" variant="outline" onClick={() => setPreview(project)}>
                                            <Play /> Xem nhanh
                                        </Button>
                                    )}
                                    <Badge variant={project.status === 'failed' ? 'destructive' : 'secondary'}>
                                        {projectStatusLabels[project.status]}
                                    </Badge>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
            <Dialog open={preview !== null} onOpenChange={(open) => !open && setPreview(null)}>
                <DialogContent className="max-w-sm sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="line-clamp-2 pr-6">{preview?.product?.name}</DialogTitle>
                        {preview?.latest_render?.hook && <DialogDescription>Hook: {preview.latest_render.hook}</DialogDescription>}
                    </DialogHeader>
                    {preview?.latest_render?.url && (
                        <video
                            key={preview.latest_render.id}
                            src={preview.latest_render.url}
                            controls
                            autoPlay
                            playsInline
                            className="mx-auto max-h-[70vh] w-full rounded-lg border bg-black object-contain"
                        />
                    )}
                    {preview?.latest_render && (
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-muted-foreground text-xs">
                                {preview.latest_render.duration_ms !== null &&
                                    `${(preview.latest_render.duration_ms / 1000).toLocaleString('vi-VN')} giây · `}
                                {new Date(preview.latest_render.created_at).toLocaleString('vi-VN')}
                            </span>
                            <div className="flex gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={route('projects.show', preview.id)}>Mở dự án</Link>
                                </Button>
                                <Button size="sm" asChild>
                                    <a href={preview.latest_render.download_url}>
                                        <Download /> Tải MP4
                                    </a>
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function FilterChip({ active, label, count, onClick }: { active: boolean; label: string; count: number; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={count === 0 && !active}
            className={cn(
                'rounded-full border px-3 py-1 text-sm transition disabled:opacity-40',
                active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
            )}
        >
            {label} <span className={cn('ml-1 text-xs', active ? 'opacity-80' : 'text-muted-foreground')}>{count}</span>
        </button>
    );
}
