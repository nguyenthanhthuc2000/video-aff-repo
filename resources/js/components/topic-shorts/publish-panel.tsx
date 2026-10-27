import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Download, ImageOff, Loader2, Sparkles, Upload } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

export interface PublishPack {
    status: 'writing' | 'ready' | 'failed';
    error?: string | null;
    titles?: string[];
    title?: string;
    cover_text?: string;
    description?: string;
    hashtags?: string[];
}

interface SceneThumb {
    id: number;
    position: number;
    image_url: string | null;
    image_status: string;
}

/** Bước Đăng: ảnh bìa (ảnh cảnh + chữ lớn), tiêu đề (3 gợi ý của Claude), mô tả + hashtag, có nút copy / tải về. */
export function PublishPanel({
    shortId,
    publish,
    cover,
    coverUrl,
    scenes,
}: {
    shortId: number;
    publish: PublishPack | null;
    cover: { scene_id?: number; text?: string; uploaded?: boolean } | null;
    coverUrl: string | null;
    scenes: SceneThumb[];
}) {
    const writing = publish?.status === 'writing';
    const generate = () => router.post(route('topic-shorts.publish', shortId), {}, { preserveScroll: true });

    return (
        <section className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold">Ảnh bìa, tiêu đề, mô tả</h2>
                <Button variant={publish ? 'outline' : 'default'} onClick={generate} disabled={writing}>
                    {writing ? <Loader2 className="animate-spin" /> : <Sparkles />}{' '}
                    {publish?.titles ? 'Claude viết lại' : 'Claude viết tiêu đề + mô tả'}
                </Button>
            </div>
            {writing && <p className="text-muted-foreground text-sm">Claude đang viết tiêu đề, mô tả và ghép ảnh bìa...</p>}
            {publish?.status === 'failed' && <p className="text-sm text-red-600 dark:text-red-400">{publish.error}</p>}
            <div className="grid gap-6 lg:grid-cols-[minmax(0,280px)_1fr]">
                <CoverEditor
                    key={publish?.cover_text ?? ''}
                    shortId={shortId}
                    cover={cover}
                    coverUrl={coverUrl}
                    defaultText={cover?.text ?? publish?.cover_text ?? ''}
                    scenes={scenes}
                />
                {publish?.titles ? (
                    <TextEditor key={`${publish.title}:${publish.description}:${publish.hashtags?.join(' ')}`} shortId={shortId} publish={publish} />
                ) : (
                    !writing && (
                        <p className="text-muted-foreground text-sm">
                            Bấm "Claude viết tiêu đề + mô tả" để có 3 tiêu đề gợi ý, mô tả và hashtag. Dựng video xong Claude cũng tự viết.
                        </p>
                    )
                )}
            </div>
        </section>
    );
}

function CoverEditor({
    shortId,
    cover,
    coverUrl,
    defaultText,
    scenes,
}: {
    shortId: number;
    cover: { scene_id?: number; text?: string; uploaded?: boolean } | null;
    coverUrl: string | null;
    defaultText: string;
    scenes: SceneThumb[];
}) {
    const ready = scenes.filter((s) => s.image_status === 'ready' && s.image_url);
    const { data, setData, post, processing } = useForm({ scene_id: cover?.scene_id ?? ready[0]?.id ?? 0, text: defaultText });
    const { errors } = usePage().props as { errors: Record<string, string> };
    // Báo ngay trong khung ảnh bìa (thông báo đầu trang bị khuất khi đang cuộn xuống), ảnh xem trước luôn tải lại.
    const [madeAt, setMadeAt] = useState<number | null>(null);
    const file = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const upload = (image: File | undefined) =>
        image &&
        router.post(
            route('topic-shorts.cover.upload', shortId),
            { image },
            {
                preserveScroll: true,
                forceFormData: true,
                onStart: () => setUploading(true),
                onFinish: () => setUploading(false),
                onSuccess: () => setMadeAt(Date.now()),
            },
        );
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('topic-shorts.cover', shortId), { preserveScroll: true, onSuccess: () => setMadeAt(Date.now()) });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="bg-muted flex aspect-[9/16] w-full items-center justify-center overflow-hidden rounded-lg border">
                {coverUrl ? (
                    <img
                        src={madeAt ? `${coverUrl}?v=${madeAt}` : coverUrl}
                        alt="Ảnh bìa"
                        className={`h-full w-full object-cover ${processing ? 'opacity-50' : ''}`}
                    />
                ) : (
                    <ImageOff className="text-muted-foreground size-6" />
                )}
            </div>
            <div className="grid gap-1">
                <Label htmlFor="cover_text">Chữ trên ảnh bìa</Label>
                <Input id="cover_text" value={data.text} onChange={(e) => setData('text', e.target.value)} placeholder="VEGETA ĐÃ KHÓC?" />
            </div>
            {ready.length > 0 ? (
                <div className="space-y-1">
                    <Label>Ảnh nền (bấm để chọn cảnh)</Label>
                    <div className="flex flex-wrap gap-1">
                        {ready.map((scene) => (
                            <button
                                key={scene.id}
                                type="button"
                                title={`Cảnh ${scene.position}`}
                                onClick={() => setData('scene_id', scene.id)}
                                className={`h-16 w-9 overflow-hidden rounded border-2 ${data.scene_id === scene.id ? 'border-primary' : 'border-transparent'}`}
                            >
                                <img src={scene.image_url!} alt={`Cảnh ${scene.position}`} className="h-full w-full object-cover" loading="lazy" />
                            </button>
                        ))}
                    </div>
                    {data.scene_id > 0 && (
                        <Button type="button" size="sm" variant="outline" asChild>
                            <a href={route('topic-short-scenes.image.download', data.scene_id)}>
                                <Download /> Tải ảnh nền cảnh {ready.find((s) => s.id === data.scene_id)?.position} (ảnh gốc, không chữ)
                            </a>
                        </Button>
                    )}
                </div>
            ) : (
                <p className="text-muted-foreground text-xs">Cần có ảnh cảnh trước khi tạo ảnh bìa.</p>
            )}
            <InputError message={errors.cover ?? errors.scene_id ?? errors.text ?? errors.image} />
            {cover?.uploaded && <p className="text-muted-foreground text-xs">Đang dùng ảnh bìa bạn tải lên (giữ nguyên, không ghép chữ).</p>}
            {madeAt && !processing && (
                <p className="flex items-center gap-1 text-sm text-green-600 dark:text-green-400">
                    <Check className="size-4" /> Đã tạo ảnh bìa lúc {new Date(madeAt).toLocaleTimeString('vi-VN')}.
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button size="sm" disabled={processing || ready.length === 0}>
                    {processing && <Loader2 className="animate-spin" />}
                    {processing ? 'Đang tạo...' : coverUrl ? 'Tạo lại ảnh bìa' : 'Tạo ảnh bìa'}
                </Button>
                <Button type="button" size="sm" variant="outline" disabled={uploading} onClick={() => file.current?.click()}>
                    {uploading ? <Loader2 className="animate-spin" /> : <Upload />} Tải ảnh bìa của bạn lên
                </Button>
                <input
                    ref={file}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    aria-label="Ảnh bìa của bạn"
                    onChange={(e) => {
                        upload(e.target.files?.[0]);
                        e.target.value = '';
                    }}
                />
                {coverUrl && (
                    <Button size="sm" variant="outline" asChild>
                        <a href={route('topic-shorts.cover.download', shortId)}>
                            <Download /> Tải ảnh bìa
                        </a>
                    </Button>
                )}
            </div>
        </form>
    );
}

function TextEditor({ shortId, publish }: { shortId: number; publish: PublishPack }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        title: publish.title ?? publish.titles?.[0] ?? '',
        description: publish.description ?? '',
        hashtags: (publish.hashtags ?? []).join(' '),
    });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('topic-shorts.publish.save', shortId), { preserveScroll: true });
    };
    const full = [data.description, data.hashtags].filter(Boolean).join('\n\n');

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="space-y-2">
                <Label>Tiêu đề (bấm một gợi ý để dùng)</Label>
                <div className="grid gap-2">
                    {(publish.titles ?? []).map((title) => (
                        <button
                            key={title}
                            type="button"
                            onClick={() => setData('title', title)}
                            aria-pressed={data.title === title}
                            className={`rounded-md border p-2 text-left text-sm ${data.title === title ? 'border-primary bg-primary/5' : 'hover:bg-muted/50'}`}
                        >
                            {title}
                        </button>
                    ))}
                </div>
                <div className="flex gap-2">
                    <Input value={data.title} onChange={(e) => setData('title', e.target.value)} aria-label="Tiêu đề" />
                    <CopyButton text={data.title} />
                </div>
                <p className="text-muted-foreground text-xs">{data.title.length}/100 ký tự (YouTube Shorts nên dưới 70)</p>
                <InputError message={errors.title} />
            </div>
            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label htmlFor="description">Mô tả</Label>
                    <CopyButton text={full} label="Copy mô tả + hashtag" />
                </div>
                <Textarea id="description" rows={7} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                <InputError message={errors.description} />
            </div>
            <div className="space-y-2">
                <Label htmlFor="hashtags">Hashtag</Label>
                <Input id="hashtags" value={data.hashtags} onChange={(e) => setData('hashtags', e.target.value)} />
            </div>
            <Button disabled={processing || !isDirty}>Lưu</Button>
        </form>
    );
}

export function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
    const [done, setDone] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // Trình duyệt chặn clipboard (http không bảo mật): copy kiểu cũ qua ô ẩn.
            const area = Object.assign(document.createElement('textarea'), { value: text });
            document.body.appendChild(area);
            area.select();
            const ok = document.execCommand('copy');
            area.remove();
            if (!ok) return;
        }
        setDone(true);
        setTimeout(() => setDone(false), 1500);
    };

    return (
        <Button type="button" size="sm" variant="outline" onClick={copy} disabled={text.trim() === ''}>
            {done ? <Check /> : <Copy />} {done ? 'Đã copy' : label}
        </Button>
    );
}
