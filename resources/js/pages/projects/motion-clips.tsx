import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type SampleVideo, type Scene, type VideoProject } from '@/types/models';
import { router } from '@inertiajs/react';
import { Check, Copy, Download, LoaderCircle, Sparkles, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

/** Clip chuyển động: muse.ai tạo từ ảnh cảnh đã duyệt + động tác của kiểu đã chọn (hoặc tự tạo ở nơi khác rồi tải lên). */
export default function MotionClips({ project, sampleVideos = [] }: { project: VideoProject; sampleVideos?: SampleVideo[] }) {
    const scenes = project.scenes ?? [];
    const withClip = scenes.filter((scene) => scene.clip_url).length;
    const pending = scenes.filter((scene) => scene.clip_status === 'pending').length;
    const [starting, setStarting] = useState(false);

    const generateAll = () =>
        router.post(
            route('projects.clips.store', project.id),
            {},
            { preserveScroll: true, onStart: () => setStarting(true), onFinish: () => setStarting(false) },
        );

    const setProjectReference = (value: string) =>
        router.put(
            route('projects.motion-reference', project.id),
            { sample_video_id: value === '' ? null : Number(value) },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-4">
            {sampleVideos.length > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded-lg border p-3">
                    {project.sample_video?.thumbnail_url && (
                        <img src={project.sample_video.thumbnail_url} alt="" className="aspect-[9/16] w-10 rounded object-cover" />
                    )}
                    <div className="min-w-0 flex-1 space-y-1">
                        <label htmlFor="project-reference" className="text-sm font-medium">
                            Sao chép chuyển động từ video mẫu
                        </label>
                        <select
                            id="project-reference"
                            className="border-input bg-background h-9 w-full max-w-sm rounded-md border px-3 text-sm"
                            value={project.sample_video?.id ?? ''}
                            onChange={(e) => setProjectReference(e.target.value)}
                            disabled={project.is_busy}
                        >
                            <option value="">
                                Không (dùng kiểu chuyển động{project.motion_style_label ? `: ${project.motion_style_label}` : ''})
                            </option>
                            {sampleVideos.map((video) => (
                                <option key={video.id} value={video.id}>
                                    {video.name}
                                </option>
                            ))}
                        </select>
                        <p className="text-muted-foreground text-xs">
                            AI cho người mẫu trong ảnh cảnh làm đúng động tác của video mẫu (giữ mặt, trang phục, sản phẩm, bối cảnh). Từng cảnh có
                            thể chọn video mẫu riêng bên dưới.
                        </p>
                    </div>
                </div>
            )}
            {project.clips_available && (
                <div className="flex flex-wrap items-center gap-3">
                    <Button onClick={generateAll} disabled={starting || pending > 0 || (project.is_busy && pending === 0)}>
                        {pending > 0 ? <LoaderCircle className="animate-spin" /> : <Sparkles />}
                        {pending > 0 ? `AI đang tạo ${pending} clip...` : 'Tạo clip bằng AI cho mọi cảnh'}
                    </Button>
                    <span className="text-muted-foreground text-xs">
                        Tạo clip từ ảnh cảnh đã duyệt với động tác "{project.motion_style_label}", chờ video xong rồi tự tải về. Mỗi clip mất vài
                        phút.
                    </span>
                </div>
            )}
            <p className="text-muted-foreground text-sm">Hoặc tự tạo ở nơi khác:</p>
            <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                <li>Tải ảnh cảnh đã duyệt về máy.</li>
                <li>
                    Mở{' '}
                    <a href={project.motion_web_url} target="_blank" rel="noreferrer" className="text-foreground underline">
                        công cụ tạo video
                    </a>
                    , đính kèm ảnh, dán prompt chuyển động và tạo video. Kling, Hailuo... cũng được.
                </li>
                <li>Tải clip về (MP4, tối đa 100MB) và bấm "Tải clip lên". Cảnh không có clip sẽ dùng ảnh tĩnh.</li>
            </ol>
            <p className="text-muted-foreground text-xs">
                Kiểu: {project.motion_style_label}. Đã có clip {withClip}/{scenes.length} cảnh. Tiếng trong clip bị bỏ, video dùng nhạc nền đã chọn;
                nhạc trend TikTok thường có bản quyền, nên thêm nhạc khi đăng trên Shopee Video.
            </p>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {scenes.map((scene) => (
                    <ClipCard key={scene.id} scene={scene} canGenerate={project.clips_available} sampleVideos={sampleVideos} />
                ))}
            </div>
        </div>
    );
}

function ClipCard({ scene, canGenerate, sampleVideos }: { scene: Scene; canGenerate: boolean; sampleVideos: SampleVideo[] }) {
    const setReference = (value: string) =>
        router.put(route('scenes.motion-reference', scene.id), { sample_video_id: value === '' ? null : Number(value) }, { preserveScroll: true });

    const input = useRef<HTMLInputElement>(null);
    const [copied, setCopied] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const image = scene.image?.status === 'approved' ? scene.image : null;

    const copy = async () => {
        await navigator.clipboard.writeText(scene.motion_prompt ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        router.post(
            route('scenes.clip.store', scene.id),
            { clip: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => {
                    setUploading(true);
                    setError(null);
                },
                onProgress: (event) => setProgress(event?.percentage ?? null),
                onError: (errors) => setError(errors.clip ?? 'Tải clip thất bại.'),
                onFinish: () => {
                    setUploading(false);
                    setProgress(null);
                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    const remove = () => {
        if (confirm('Bỏ clip của cảnh này?')) {
            router.delete(route('scenes.clip.destroy', scene.id), { preserveScroll: true });
        }
    };

    return (
        <div className="flex gap-4 rounded-lg border p-3">
            <div className="bg-muted relative aspect-[9/16] w-28 shrink-0 overflow-hidden rounded-md border">
                {scene.clip_url ? (
                    <video src={scene.clip_url} className="h-full w-full object-cover" muted loop autoPlay playsInline />
                ) : (
                    image?.url && <img src={image.url} alt={`Cảnh ${scene.position}`} className="h-full w-full object-cover" />
                )}
                {scene.clip_url && <Badge className="absolute top-1 left-1">Clip</Badge>}
                {scene.clip_status === 'pending' && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/50 text-xs text-white">
                        <LoaderCircle className="size-5 animate-spin" /> AI đang tạo
                    </div>
                )}
            </div>
            <div className="min-w-0 flex-1 space-y-2">
                <div className="text-sm font-medium">
                    Cảnh {scene.position}: {scene.on_screen_text}
                </div>
                {scene.motion_reference ? (
                    <p className="text-muted-foreground text-xs">Sao chép chuyển động: {scene.motion_reference.name}</p>
                ) : (
                    scene.motion_style_label && <p className="text-muted-foreground text-xs">Kiểu: {scene.motion_style_label}</p>
                )}
                {sampleVideos.length > 0 && (
                    <select
                        aria-label={`Video mẫu cảnh ${scene.position}`}
                        className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                        value={scene.sample_video_id ?? ''}
                        onChange={(e) => setReference(e.target.value)}
                    >
                        <option value="">Theo dự án</option>
                        {sampleVideos.map((video) => (
                            <option key={video.id} value={video.id}>
                                Video mẫu: {video.name}
                            </option>
                        ))}
                    </select>
                )}
                {!image ? (
                    <p className="text-muted-foreground text-xs">Cần ảnh cảnh đã duyệt trước.</p>
                ) : (
                    <>
                        {scene.motion_prompt && (
                            <pre className="bg-muted max-h-28 overflow-auto rounded-md p-2 text-xs whitespace-pre-wrap">{scene.motion_prompt}</pre>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" asChild>
                                <a href={image.url ?? '#'} download>
                                    <Download /> Ảnh
                                </a>
                            </Button>
                            {canGenerate && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={scene.clip_status === 'pending'}
                                    onClick={() => router.post(route('scenes.clip.generate', scene.id), {}, { preserveScroll: true })}
                                >
                                    <Sparkles /> {scene.clip_url ? 'Tạo lại bằng AI' : 'Tạo bằng AI'}
                                </Button>
                            )}
                            {scene.motion_prompt && (
                                <Button size="sm" variant="outline" onClick={copy}>
                                    {copied ? <Check /> : <Copy />} {copied ? 'Đã copy' : 'Copy prompt'}
                                </Button>
                            )}
                            <Button size="sm" onClick={() => input.current?.click()} disabled={uploading}>
                                <Upload />{' '}
                                {uploading && progress !== null ? `${Math.round(progress)}%` : scene.clip_url ? 'Thay clip' : 'Tải clip lên'}
                            </Button>
                            {scene.clip_url && (
                                <Button size="sm" variant="ghost" onClick={remove} aria-label="Bỏ clip">
                                    <Trash2 />
                                </Button>
                            )}
                            <input
                                ref={input}
                                type="file"
                                accept="video/mp4,video/quicktime,video/webm"
                                className="hidden"
                                aria-label={`Clip cảnh ${scene.position}`}
                                onChange={(e) => upload(e.target.files?.[0])}
                            />
                        </div>
                    </>
                )}
                {scene.clip_status === 'failed' && scene.clip_error && (
                    <p className="text-xs text-red-600 dark:text-red-400">Tạo clip lỗi: {scene.clip_error} Cảnh này dùng ảnh tĩnh.</p>
                )}
                {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
            </div>
        </div>
    );
}
