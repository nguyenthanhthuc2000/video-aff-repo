import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type SceneImage, type VideoProject } from '@/types/models';
import { router } from '@inertiajs/react';
import { Check, ImageOff, LoaderCircle, RefreshCw } from 'lucide-react';

const imageStatusLabels: Record<SceneImage['status'], string> = {
    pending: 'Đang tạo',
    ready: 'Chờ duyệt',
    approved: 'Đã duyệt',
    rejected: 'Đã bỏ',
    failed: 'Lỗi',
};

export default function SceneImages({ project }: { project: VideoProject }) {
    const scenes = project.scenes ?? [];
    const canGenerate = ['script_ready', 'imaging', 'reviewing', 'done', 'failed'].includes(project.status) && scenes.length > 0;
    const missing = scenes.filter((scene) => !scene.image || ['rejected', 'failed'].includes(scene.image.status)).length;

    const generateAll = () => router.post(route('projects.images.store', project.id), {}, { preserveScroll: true });

    const regenerate = (sceneId: number, currentPrompt: string) => {
        const prompt = window.prompt('Prompt ảnh (có thể sửa trước khi tạo lại):', currentPrompt);
        if (prompt === null) {
            return;
        }

        router.post(route('scenes.image.regenerate', sceneId), { image_prompt: prompt }, { preserveScroll: true });
    };

    const approve = (imageId: number) => router.post(route('scene-images.approve', imageId), {}, { preserveScroll: true });

    return (
        <div className="space-y-4">
            {canGenerate && missing > 0 && (
                <Button onClick={generateAll} disabled={project.status === 'imaging'}>
                    Tạo ảnh cho {missing} cảnh
                </Button>
            )}
            <p className="text-muted-foreground text-sm">
                Muse mặc sản phẩm lên ảnh người mẫu cho từng cảnh. Chỉ duyệt ảnh giữ đúng màu, hoạ tiết và kiểu dáng sản phẩm.
                {(project.image_cost_usd ?? 0) > 0 && ` Chi phí ảnh đến nay: ${project.image_cost_usd} USD.`}
            </p>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {scenes.map((scene) => {
                    const image = scene.image;

                    return (
                        <div key={scene.id} className="space-y-2">
                            <div className="bg-muted relative flex aspect-[9/16] items-center justify-center overflow-hidden rounded-lg border">
                                {image?.status === 'pending' && <LoaderCircle className="text-muted-foreground size-6 animate-spin" />}
                                {image?.url && image.status !== 'pending' && (
                                    <img src={image.url} alt={`Cảnh ${scene.position}`} className="h-full w-full object-cover" />
                                )}
                                {(!image || (!image.url && image.status !== 'pending')) && <ImageOff className="text-muted-foreground size-6" />}
                                {image && (
                                    <Badge
                                        className="absolute top-2 left-2"
                                        variant={image.status === 'failed' ? 'destructive' : image.status === 'approved' ? 'default' : 'secondary'}
                                    >
                                        {imageStatusLabels[image.status]}
                                    </Badge>
                                )}
                            </div>
                            <div className="text-sm font-medium">
                                Cảnh {scene.position}: {scene.on_screen_text}
                            </div>
                            {image?.error && <p className="text-xs text-red-600 dark:text-red-400">{image.error}</p>}
                            {image && image.status !== 'pending' && canGenerate && (
                                <div className="flex gap-2">
                                    {image.status === 'ready' && (
                                        <Button size="sm" onClick={() => approve(image.id)}>
                                            <Check /> Duyệt
                                        </Button>
                                    )}
                                    <Button size="sm" variant="outline" onClick={() => regenerate(scene.id, scene.image_prompt)}>
                                        <RefreshCw /> Tạo lại
                                    </Button>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
