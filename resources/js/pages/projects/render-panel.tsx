import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type AudioTrack, type VideoProject } from '@/types/models';
import { Link, useForm } from '@inertiajs/react';
import { Download, TriangleAlert } from 'lucide-react';
import { FormEventHandler } from 'react';

type RenderFormData = {
    hook: number;
    /** "" = không nhạc, "legacy" = file tải riêng cho dự án (bản cũ), "sample" = nhạc như video mẫu, còn lại = id nhạc thư viện. */
    music_choice: string;
    /** false = video không có hook, chữ trên cảnh, phụ đề và cảnh kết. */
    with_text: boolean;
};

const renderStatusLabels = { pending: 'Đang xuất', done: 'Xong', failed: 'Lỗi' } as const;

export default function RenderPanel({ project, audioTracks }: { project: VideoProject; audioTracks: AudioTrack[] }) {
    const hooks = project.script?.hooks ?? [];
    const { data, setData, post, processing, errors, transform } = useForm<RenderFormData>({
        hook: project.selected_hook ?? 0,
        music_choice: project.audio_track ? String(project.audio_track.id) : project.has_music ? 'legacy' : '',
        with_text: project.render_text ?? true,
    });

    const canRender = project.all_approved && ['reviewing', 'done', 'failed'].includes(project.status);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => ({
            hook: form.hook,
            audio_track_id: ['', 'legacy', 'sample'].includes(form.music_choice) ? null : Number(form.music_choice),
            use_sample_audio: form.music_choice === 'sample',
            // "Không có nhạc" thì bỏ cả file nhạc tải riêng cho dự án (bản cũ).
            remove_music: form.music_choice === '',
            with_text: form.with_text,
        }));
        post(route('projects.renders.store', project.id), { preserveScroll: true });
    };

    return (
        <div className="space-y-6">
            {!project.all_approved && <p className="text-muted-foreground text-sm">Duyệt ảnh cho mọi cảnh để xuất video.</p>}

            <form onSubmit={submit} className="space-y-4">
                <fieldset disabled={!canRender} className="space-y-4">
                    <div className="space-y-2">
                        <Label>Hook mở đầu ({hooks.length} chủ đề, đã chọn sẵn hook AI đề xuất)</Label>
                        {hooks.map((hook, i) => (
                            <label key={i} className="flex items-center gap-2 text-sm">
                                <input type="radio" name="hook" checked={data.hook === i} onChange={() => setData('hook', i)} />
                                {project.script?.hook_themes?.[i] && <Badge variant="outline">{project.script.hook_themes[i]}</Badge>}
                                <span>{hook}</span>
                                {project.script?.recommended_hook === i && <Badge>Đề xuất</Badge>}
                            </label>
                        ))}
                        <InputError message={errors.hook} />
                    </div>

                    <div className="grid max-w-md gap-2">
                        <Label htmlFor="music_choice">Nhạc nền</Label>
                        <select
                            id="music_choice"
                            className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            value={data.music_choice}
                            onChange={(e) => setData('music_choice', e.target.value)}
                        >
                            <option value="">Không có nhạc nền</option>
                            {project.has_music && <option value="legacy">Nhạc đã tải lên riêng cho dự án này</option>}
                            {project.sample_video && <option value="sample">Nhạc như video mẫu: {project.sample_video.name}</option>}
                            {audioTracks.map((track) => (
                                <option key={track.id} value={String(track.id)}>
                                    {track.name}
                                </option>
                            ))}
                        </select>
                        <p className="text-muted-foreground text-xs">
                            Nhạc lấy từ thư viện{' '}
                            <Link href={route('audio-tracks.index')} className="underline">
                                Âm thanh
                            </Link>
                            . Dùng nhạc có bản quyền thương mại; nhạc trend TikTok nên thêm khi đăng trên Shopee Video.
                        </p>
                        <InputError message={(errors as Record<string, string>).audio_track_id} />
                    </div>

                    <div className="space-y-1">
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <input type="checkbox" checked={data.with_text} onChange={(e) => setData('with_text', e.target.checked)} />
                            Chèn chữ lên video
                        </label>
                        <p className="text-muted-foreground text-xs">
                            {data.with_text
                                ? 'Có hook ở cảnh đầu, chữ trên từng cảnh, phụ đề lời đọc và cảnh kết kêu gọi mua.'
                                : 'Video chỉ có hình và nhạc: không hook, không chữ trên cảnh, không phụ đề, không cảnh kết. Hợp khi muốn tự thêm chữ trên TikTok / Shopee Video.'}
                        </p>
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
                                <p className="text-muted-foreground line-clamp-2 text-xs">
                                    {render.with_text === false ? 'Không chèn chữ' : `Hook: ${hooks[render.hook]}`}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
