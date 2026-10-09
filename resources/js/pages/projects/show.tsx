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
import RenderPanel from './render-panel';
import SceneImages from './scene-images';
import ScriptEditor from './script-editor';

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

    const canWriteScript = ['draft', 'script_ready', 'failed'].includes(project.status);

    const writeScript = () => {
        if (project.script && !confirm('Viết lại sẽ thay toàn bộ cảnh hiện tại. Tiếp tục?')) {
            return;
        }

        router.post(route('projects.script.store', project.id), {}, { preserveScroll: true });
    };

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

                <section className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <h2 className="text-lg font-semibold">1. Kịch bản</h2>
                        {canWriteScript && (
                            <Button variant={project.script ? 'outline' : 'default'} onClick={writeScript}>
                                {project.script ? 'Viết lại kịch bản' : 'Tạo kịch bản'}
                            </Button>
                        )}
                    </div>
                    {project.status === 'scripting' && <p className="text-muted-foreground text-sm">Claude đang viết kịch bản...</p>}
                    {!project.script && project.status === 'draft' && (
                        <p className="text-muted-foreground text-sm">
                            Claude sẽ viết 3 hook, 5–7 cảnh, caption và hashtag từ ảnh và thông tin sản phẩm.
                        </p>
                    )}
                    {project.script && <ScriptEditor key={project.updated_at} project={project} disabled={project.is_busy} />}
                </section>

                {project.script && (project.scenes?.length ?? 0) > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">2. Ảnh cảnh</h2>
                        <SceneImages project={project} />
                    </section>
                )}

                {project.script && (project.scenes?.length ?? 0) > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">3. Xuất video</h2>
                        <RenderPanel key={`${project.selected_hook}-${project.updated_at}`} project={project} />
                    </section>
                )}

                <Button variant="ghost" className="text-destructive" onClick={destroy}>
                    Xoá dự án
                </Button>
            </div>
        </AppLayout>
    );
}
