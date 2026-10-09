import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type Scene, type VideoProject } from '@/types/models';
import { router } from '@inertiajs/react';
import { Check, Copy, Download, ExternalLink, ImageOff, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

/** Chế độ Muse bản web: người dùng tạo ảnh trên meta.ai rồi tải lên từng cảnh. */
export default function MuseWebImages({ project }: { project: VideoProject }) {
    const scenes = project.scenes ?? [];
    const references = project.references ?? [];

    return (
        <div className="space-y-6">
            <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                <li>Tải các ảnh tham chiếu bên dưới về máy (giữ đúng thứ tự: người mẫu trước, sản phẩm sau).</li>
                <li>
                    Mở{' '}
                    <a href={project.muse_web_url} target="_blank" rel="noreferrer" className="text-foreground underline">
                        meta.ai
                    </a>
                    , đính kèm các ảnh tham chiếu, dán prompt của cảnh rồi tạo ảnh (khung dọc 9:16).
                </li>
                <li>Chọn ảnh giữ đúng màu, hoạ tiết, kiểu dáng sản phẩm, tải về và bấm "Tải ảnh lên" ở cảnh tương ứng.</li>
            </ol>

            <div className="flex flex-wrap items-end gap-4">
                {references.map((ref) => (
                    <a key={ref.url} href={ref.url} download className="group space-y-1 text-center text-xs">
                        <img src={ref.url} alt={ref.label} className="h-28 rounded-md border object-cover" />
                        <span className="text-muted-foreground group-hover:text-foreground inline-flex items-center gap-1">
                            <Download className="size-3" /> {ref.label}
                        </span>
                    </a>
                ))}
                <Button variant="outline" asChild>
                    <a href={project.muse_web_url} target="_blank" rel="noreferrer">
                        <ExternalLink /> Mở meta.ai
                    </a>
                </Button>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {scenes.map((scene) => (
                    <SceneCard key={scene.id} scene={scene} />
                ))}
            </div>
        </div>
    );
}

function SceneCard({ scene }: { scene: Scene }) {
    const input = useRef<HTMLInputElement>(null);
    const [copied, setCopied] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const image = scene.image?.status === 'approved' ? scene.image : null;

    const copy = async () => {
        await navigator.clipboard.writeText(scene.full_prompt);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        router.post(
            route('scenes.image.upload', scene.id),
            { image: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => {
                    setUploading(true);
                    setError(null);
                },
                onError: (errors) => setError(errors.image ?? 'Tải ảnh thất bại.'),
                onFinish: () => {
                    setUploading(false);
                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <div className="flex gap-4 rounded-lg border p-3">
            <div className="bg-muted relative flex aspect-[9/16] w-28 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                {image?.url ? (
                    <img src={image.url} alt={`Cảnh ${scene.position}`} className="h-full w-full object-cover" />
                ) : (
                    <ImageOff className="text-muted-foreground size-5" />
                )}
                {image && <Badge className="absolute top-1 left-1">Đã có</Badge>}
            </div>
            <div className="min-w-0 flex-1 space-y-2">
                <div className="text-sm font-medium">
                    Cảnh {scene.position}: {scene.on_screen_text}
                </div>
                <pre className="bg-muted max-h-28 overflow-auto rounded-md p-2 text-xs whitespace-pre-wrap">{scene.full_prompt}</pre>
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={copy}>
                        {copied ? <Check /> : <Copy />} {copied ? 'Đã copy' : 'Copy prompt'}
                    </Button>
                    <Button size="sm" onClick={() => input.current?.click()} disabled={uploading}>
                        <Upload /> {image ? 'Thay ảnh' : 'Tải ảnh lên'}
                    </Button>
                    <input
                        ref={input}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        aria-label={`Ảnh cảnh ${scene.position}`}
                        onChange={(e) => upload(e.target.files?.[0])}
                    />
                </div>
                {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
            </div>
        </div>
    );
}
