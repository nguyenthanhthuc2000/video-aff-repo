import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { projectStatusLabels } from '@/lib/project-status';
import { type BreadcrumbItem } from '@/types';
import { type VideoProject } from '@/types/models';
import { Head, router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect } from 'react';

export default function ProjectsShow({ project }: { project: VideoProject }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dự án video', href: '/projects' },
        { title: project.product?.name ?? `#${project.id}`, href: route('projects.show', project.id) },
    ];

    // Khi có job chạy nền, tự làm mới dữ liệu dự án mỗi 3 giây.
    useEffect(() => {
        if (!project.is_busy) {
            return;
        }

        const timer = setInterval(() => router.reload({ only: ['project'] }), 3000);

        return () => clearInterval(timer);
    }, [project.is_busy]);

    const destroy = () => {
        if (confirm('Xoá dự án video này?')) {
            router.delete(route('projects.destroy', project.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Video: ${project.product?.name ?? ''}`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={project.product?.name ?? 'Dự án video'} description={`Người mẫu: ${project.fashion_model?.name ?? ''}`} />
                    <div className="flex items-center gap-2">
                        {project.is_busy && <LoaderCircle className="size-4 animate-spin" />}
                        <Badge variant={project.status === 'failed' ? 'destructive' : 'secondary'}>{projectStatusLabels[project.status]}</Badge>
                    </div>
                </div>

                <FlashStatus />

                {project.error && (
                    <div className="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        {project.error}
                    </div>
                )}

                <div className="flex gap-4">
                    {project.product?.primary_image_url && (
                        <img src={project.product.primary_image_url} alt="Sản phẩm" className="h-40 rounded-lg border object-cover" />
                    )}
                    {project.fashion_model?.photo_url && (
                        <img src={project.fashion_model.photo_url} alt="Người mẫu" className="h-40 rounded-lg border object-cover" />
                    )}
                </div>

                <Button variant="ghost" className="text-destructive" onClick={destroy}>
                    Xoá dự án
                </Button>
            </div>
        </AppLayout>
    );
}
