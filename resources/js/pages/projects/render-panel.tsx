import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type VideoProject } from '@/types/models';
import { useForm } from '@inertiajs/react';
import { Download, TriangleAlert } from 'lucide-react';
import { FormEventHandler } from 'react';

type RenderFormData = {
    hook: number;
    music: File | null;
    remove_music: boolean;
};

const renderStatusLabels = { pending: 'Đang xuất', done: 'Xong', failed: 'Lỗi' } as const;

export default function RenderPanel({ project }: { project: VideoProject }) {
    const hooks = project.script?.hooks ?? [];
    const { data, setData, post, processing, errors, reset } = useForm<RenderFormData>({
        hook: project.selected_hook ?? 0,
        music: null,
        remove_music: false,
    });

    const canRender = project.all_approved && ['reviewing', 'done', 'failed'].includes(project.status);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.renders.store', project.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset('music', 'remove_music'),
        });
    };

    return (
        <div className="space-y-6">
            {!project.all_approved && <p className="text-muted-foreground text-sm">Duyệt ảnh cho mọi cảnh để xuất video.</p>}

            <form onSubmit={submit} className="space-y-4">
                <fieldset disabled={!canRender} className="space-y-4">
                    <div className="space-y-2">
                        <Label>Hook mở đầu</Label>
                        {hooks.map((hook, i) => (
                            <label key={i} className="flex items-center gap-2 text-sm">
                                <input type="radio" name="hook" checked={data.hook === i} onChange={() => setData('hook', i)} />
                                {hook}
                            </label>
                        ))}
                        <InputError message={errors.hook} />
                    </div>

                    <div className="grid max-w-md gap-2">
                        <Label htmlFor="music">Nhạc nền (mp3/m4a/wav, có bản quyền dùng thương mại)</Label>
                        <Input
                            id="music"
                            type="file"
                            accept=".mp3,.m4a,.aac,.wav,audio/*"
                            onChange={(e) => setData('music', e.target.files?.[0] ?? null)}
                        />
                        {project.has_music && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.remove_music} onCheckedChange={(checked) => setData('remove_music', checked === true)} />
                                Bỏ nhạc nền đang dùng
                            </label>
                        )}
                        <InputError message={errors.music} />
                    </div>

                    <Button type="submit" disabled={processing || !canRender}>
                        Xuất video
                    </Button>
                </fieldset>
            </form>

            {(project.renders?.length ?? 0) > 0 && (
                <div className="space-y-3">
                    <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        Khi đăng lên Shopee Video, nhớ bật nhãn nội dung do AI tạo. Nên chèn thêm 1–2 đoạn quay sản phẩm thật.
                    </div>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {project.renders?.map((render) => (
                            <div key={render.id} className="space-y-2">
                                {render.status === 'done' && render.url ? (
                                    <video src={render.url} controls playsInline className="aspect-[9/16] w-full rounded-lg border bg-black" />
                                ) : (
                                    <div className="bg-muted text-muted-foreground flex aspect-[9/16] items-center justify-center rounded-lg border p-4 text-center text-sm">
                                        {render.status === 'failed' ? render.error : 'Đang ghép video...'}
                                    </div>
                                )}
                                <div className="flex items-center justify-between gap-2">
                                    <Badge variant={render.status === 'failed' ? 'destructive' : 'secondary'}>
                                        {render.status === 'pending'
                                            ? `${renderStatusLabels.pending} ${render.progress}%`
                                            : renderStatusLabels[render.status]}
                                    </Badge>
                                    {render.download_url && (
                                        <Button size="sm" variant="outline" asChild>
                                            <a href={render.download_url}>
                                                <Download /> Tải MP4
                                            </a>
                                        </Button>
                                    )}
                                </div>
                                <p className="text-muted-foreground line-clamp-2 text-xs">Hook: {hooks[render.hook]}</p>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
