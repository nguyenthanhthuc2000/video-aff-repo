import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import ProgressBar from '@/components/progress-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { projectStatusLabels } from '@/lib/project-status';
import { type BreadcrumbItem } from '@/types';
import { type FashionModel, type VideoProject } from '@/types/models';
import { Head, Link, router } from '@inertiajs/react';
import { LoaderCircle, Play, Plus } from 'lucide-react';
import CreateProjectForm from '../products/create-project-form';
import ClaudeWebScript from './claude-web-script';
import MotionClips from './motion-clips';
import MuseWebImages from './muse-web-images';
import RenderPanel from './render-panel';
import SceneImages from './scene-images';
import ScriptEditor from './script-editor';

export default function ProjectsShow({
    project,
    fashionModels,
    autopilotAvailable,
}: {
    project: VideoProject;
    fashionModels: FashionModel[];
    autopilotAvailable: boolean;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dự án video', href: '/projects' },
        { title: project.product?.name ?? `#${project.id}`, href: route('projects.show', project.id) },
    ];

    // Khi có job chạy nền, tự làm mới dữ liệu dự án mỗi 3 giây.
    usePoll(project.is_busy, ['project']);

    const runAutopilot = () => router.post(route('projects.autopilot', project.id), {}, { preserveScroll: true });
    const canAutopilot = !project.is_busy && project.status !== 'done' && !project.autopilot;

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
                    <Heading
                        title={project.product?.name ?? 'Dự án video'}
                        description={`Người mẫu: ${project.fashion_model?.name ?? ''}${project.motion_style_label ? ` · Chuyển động: ${project.motion_style_label}` : ''}`}
                    />
                    <div className="flex items-center gap-2">
                        {project.is_busy && <LoaderCircle className="size-4 animate-spin" />}
                        <Badge variant={project.status === 'failed' ? 'destructive' : 'secondary'}>{projectStatusLabels[project.status]}</Badge>
                        {project.product && (
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button variant="outline">
                                        <Plus /> Tạo thêm video
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Tạo thêm video</DialogTitle>
                                        <DialogDescription>
                                            Dự án mới cho {project.product.name}. Dự án hiện tại được giữ nguyên để so sánh.
                                        </DialogDescription>
                                    </DialogHeader>
                                    {fashionModels.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            Chưa có người mẫu đã xác nhận đồng ý.{' '}
                                            <Link href={route('fashion-models.index')} className="underline">
                                                Thêm người mẫu
                                            </Link>
                                            .
                                        </p>
                                    ) : (
                                        <CreateProjectForm
                                            productId={project.product.id}
                                            fashionModels={fashionModels}
                                            defaultModelId={project.fashion_model?.id}
                                            defaultMotionStyles={project.motion_styles}
                                            autopilotAvailable={autopilotAvailable}
                                        />
                                    )}
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                </div>

                <FlashStatus />

                <div className="space-y-3 rounded-lg border p-4">
                    <ProgressBar percent={project.progress.percent} label={project.progress.label} failed={project.status === 'failed'} />
                    {project.autopilot && (
                        <p className="text-muted-foreground text-sm">Đang chạy ngầm. Bạn có thể rời trang, video sẽ được xuất khi xong.</p>
                    )}
                    {canAutopilot && (
                        <div className="flex flex-wrap items-center gap-3">
                            <Button onClick={runAutopilot} disabled={!!project.autopilot_blocker}>
                                <Play /> Chạy ngầm bằng Claude + Muse
                            </Button>
                            <span className="text-muted-foreground text-xs">
                                {project.autopilot_blocker ??
                                    'Tự viết kịch bản, tạo ảnh, duyệt ảnh mới nhất và xuất video với hook đang chọn. Bước nào đã xong sẽ được bỏ qua.'}
                            </span>
                        </div>
                    )}
                </div>

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
                        {canWriteScript && project.script_mode === 'api' && (
                            <Button variant={project.script ? 'outline' : 'default'} onClick={writeScript}>
                                {project.script ? 'Viết lại kịch bản' : 'Tạo kịch bản'}
                            </Button>
                        )}
                    </div>
                    {project.video_settings && (
                        <p className="text-muted-foreground text-sm">
                            Độ dài khi viết kịch bản: {project.video_settings.scenes} cảnh · {project.video_settings.seconds} giây.{' '}
                            {project.video_settings.reason}{' '}
                            <Link href={route('video-settings.show')} className="underline">
                                Đổi ở Cài đặt video
                            </Link>
                        </p>
                    )}
                    {project.status === 'scripting' && <p className="text-muted-foreground text-sm">Claude đang viết kịch bản...</p>}
                    {!project.script && project.status === 'draft' && project.script_mode === 'api' && (
                        <p className="text-muted-foreground text-sm">
                            Claude sẽ viết 5 hook theo 5 chủ đề (tự chọn hook hợp nhất), {project.video_settings?.scenes ?? 6} cảnh trong khoảng{' '}
                            {project.video_settings?.seconds ?? 25} giây, caption và hashtag từ ảnh và thông tin sản phẩm.
                        </p>
                    )}
                    {canWriteScript &&
                        project.script_mode === 'web' &&
                        (project.script ? (
                            <details className="rounded-lg border p-4">
                                <summary className="cursor-pointer text-sm font-medium">Viết lại kịch bản bằng claude.ai</summary>
                                <div className="pt-4">
                                    <ClaudeWebScript project={project} />
                                </div>
                            </details>
                        ) : (
                            <ClaudeWebScript project={project} />
                        ))}
                    {project.script && <ScriptEditor key={project.updated_at} project={project} disabled={project.is_busy} />}
                </section>

                {project.script && (project.scenes?.length ?? 0) > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">2. Ảnh cảnh</h2>
                        {(project.scene_suggestions?.length ?? 0) > 0 && (
                            <div className="flex flex-wrap items-center gap-1.5 text-sm">
                                <span className="text-muted-foreground">Bối cảnh hợp với sản phẩm:</span>
                                {project.scene_suggestions?.map((setting) => (
                                    <Badge key={setting} variant="outline">
                                        {setting}
                                    </Badge>
                                ))}
                            </div>
                        )}
                        {project.image_mode === 'web' ? <MuseWebImages project={project} /> : <SceneImages project={project} />}
                    </section>
                )}

                {project.motion_styles.length > 0 && (project.scenes?.length ?? 0) > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">3. Clip chuyển động</h2>
                        <MotionClips project={project} />
                    </section>
                )}

                {project.script && (project.scenes?.length ?? 0) > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">{project.motion_styles.length > 0 ? '4' : '3'}. Xuất video</h2>
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
