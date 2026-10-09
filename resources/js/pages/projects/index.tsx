import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import ProgressBar from '@/components/progress-bar';
import { Badge } from '@/components/ui/badge';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { projectStatusLabels } from '@/lib/project-status';
import { type BreadcrumbItem } from '@/types';
import { type VideoProject } from '@/types/models';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dự án video', href: '/projects' }];

export default function ProjectsIndex({ projects }: { projects: VideoProject[] }) {
    usePoll(
        projects.some((project) => project.is_busy),
        ['projects'],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dự án video" />
            <div className="space-y-6 p-4">
                <Heading title="Dự án video" description="Tạo dự án mới từ trang của một sản phẩm." />
                <FlashStatus />

                {projects.length === 0 ? (
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
                            <Link
                                key={project.id}
                                href={route('projects.show', project.id)}
                                className="hover:bg-muted/50 flex items-center gap-4 p-3"
                            >
                                {project.product?.primary_image_url && (
                                    <img src={project.product.primary_image_url} alt="" className="size-12 rounded-md object-cover" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{project.product?.name}</p>
                                    <p className="text-muted-foreground text-xs">Người mẫu: {project.fashion_model?.name}</p>
                                    <ProgressBar
                                        className="mt-2 max-w-md"
                                        percent={project.progress.percent}
                                        label={project.progress.label}
                                        failed={project.status === 'failed'}
                                    />
                                </div>
                                <Badge variant={project.status === 'failed' ? 'destructive' : 'secondary'}>
                                    {projectStatusLabels[project.status]}
                                </Badge>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
