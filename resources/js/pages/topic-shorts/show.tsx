import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download, ImageOff, Loader2, RefreshCw, Search, Sparkles, TriangleAlert, Upload, Wand2 } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

interface Candidate {
    url: string;
    page: string;
    title: string;
    width: number;
    height: number;
}

interface ShortScene {
    id: number;
    position: number;
    narration: string;
    on_screen_text: string | null;
    image_query: string | null;
    image_prompt: string | null;
    characters: string[];
    image_source: 'web' | 'muse' | 'upload' | null;
    image_status: 'none' | 'searching' | 'pending' | 'ready' | 'failed';
    image_url: string | null;
    image_source_url: string | null;
    image_error: string | null;
    candidates: Candidate[];
}

interface TopicShort {
    id: number;
    topic: string;
    title: string | null;
    language: 'vi' | 'en';
    style: string;
    target_seconds: number;
    status: 'writing' | 'ready' | 'failed';
    error: string | null;
    caption: string;
    hashtags: string[];
    art_style: string | null;
    characters: { name: string; look: string; image_url: string | null }[];
    video_status: 'rendering' | 'done' | 'failed' | null;
    video_error: string | null;
    video_url: string | null;
    video_ms: number | null;
    estimated_ms: number;
    images_busy: boolean;
    all_images_ready: boolean;
    scenes: ShortScene[];
}

const sourceLabels = { web: 'Ảnh wiki', muse: 'Muse vẽ', upload: 'Ảnh tải lên' } as const;

/** Chi tiết short: sửa lời kể, chọn ảnh từng cảnh (wiki / Muse / tải lên), dựng video có giọng AI. */
export default function TopicShortShow({
    short,
    music,
    prices,
}: {
    short: TopicShort;
    music: { id: number; name: string }[];
    prices: { image: number; render: number };
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shorts chủ đề', href: '/topic-shorts' },
        { title: short.title || short.topic, href: route('topic-shorts.show', short.id) },
    ];
    usePoll(short.status === 'writing' || short.images_busy || short.video_status === 'rendering', ['short'], 3000);

    const rewrite = () => {
        if (confirm('Claude viết lại từ đầu, các cảnh và ảnh hiện tại sẽ bị xoá. Tiếp tục?')) {
            router.post(route('topic-shorts.rewrite', short.id), {}, { preserveScroll: true });
        }
    };
    const destroy = () => {
        if (confirm('Xoá short này?')) {
            router.delete(route('topic-shorts.destroy', short.id));
        }
    };
    const museAll = () => {
        const count = short.scenes.filter((s) => s.image_source !== 'upload').length;
        if (confirm(`Muse vẽ lại ${count} cảnh (trừ ảnh bạn tự tải lên), khoảng ${formatNumber(count * prices.image)} Credits. Tiếp tục?`)) {
            router.post(route('topic-shorts.muse-all', short.id), {}, { preserveScroll: true });
        }
    };
    const textKey = short.scenes.map((s) => `${s.id}:${s.narration}:${s.on_screen_text}:${s.image_query}:${s.image_prompt}`).join('|') + short.title;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={short.title || short.topic} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={short.title || short.topic} description={`Chủ đề: ${short.topic}`} />
                    <div className="flex items-center gap-2">
                        {short.status === 'writing' && <Loader2 className="size-4 animate-spin" />}
                        <Badge variant="outline">{short.style}</Badge>
                        <Badge variant="secondary">{short.language === 'en' ? 'English' : 'Tiếng Việt'}</Badge>
                        <Button variant="outline" size="sm" onClick={rewrite} disabled={short.status === 'writing'}>
                            <RefreshCw /> Viết lại
                        </Button>
                    </div>
                </div>

                <FlashStatus />

                <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    Ảnh tìm trên wiki là ảnh phim / truyện có bản quyền (Toei, Shueisha...). Video có thể bị gỡ hoặc không bật kiếm tiền được. Ảnh
                    Muse vẽ hoặc ảnh tự làm ít rủi ro hơn.
                </div>

                {short.status === 'writing' && <p className="text-muted-foreground text-sm">Claude đang viết lời kể và chia cảnh...</p>}
                {short.error && <p className="text-sm text-red-600 dark:text-red-400">{short.error}</p>}

                {short.scenes.length > 0 && <ScriptForm key={textKey} short={short} />}

                {short.characters.length > 0 && <CharactersPanel short={short} />}

                {short.scenes.length > 0 && (
                    <section className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold">3. Ảnh từng cảnh</h2>
                            <Button variant="outline" size="sm" onClick={museAll} disabled={short.images_busy}>
                                <Wand2 /> Muse vẽ lại tất cả
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-sm">
                            Mỗi cảnh chọn một ảnh: ảnh tìm trên wiki (bấm ảnh nhỏ để đổi), Muse vẽ ({formatNumber(prices.image)} Credits / ảnh) hoặc
                            ảnh bạn tải lên.
                        </p>
                        <div className="grid gap-4 lg:grid-cols-2">
                            {short.scenes.map((scene) => (
                                <SceneImageCard key={scene.id} scene={scene} />
                            ))}
                        </div>
                    </section>
                )}

                {short.scenes.length > 0 && <RenderPanel short={short} music={music} price={prices.render} />}

                <Button variant="ghost" className="text-destructive" onClick={destroy}>
                    Xoá short
                </Button>
            </div>
        </AppLayout>
    );
}

function ScriptForm({ short }: { short: TopicShort }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        title: short.title ?? '',
        caption: short.caption,
        scenes: short.scenes.map((s) => ({
            id: s.id,
            narration: s.narration,
            on_screen_text: s.on_screen_text ?? '',
            image_query: s.image_query ?? '',
            image_prompt: s.image_prompt ?? '',
        })),
    });
    const fieldErrors = errors as Record<string, string | undefined>;
    const setScene = (index: number, key: 'narration' | 'on_screen_text' | 'image_query' | 'image_prompt', value: string) =>
        setData(
            'scenes',
            data.scenes.map((scene, i) => (i === index ? { ...scene, [key]: value } : scene)),
        );
    const seconds = Math.round(short.estimated_ms / 1000);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('topic-shorts.update', short.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="flex items-baseline justify-between gap-4">
                <h2 className="text-lg font-semibold">1. Lời kể</h2>
                <span className="text-muted-foreground text-sm">
                    Ước tính {seconds} giây (mục tiêu {short.target_seconds} giây)
                </span>
            </div>
            <div className="grid gap-2">
                <Label htmlFor="title">Tiêu đề (hiện trên cảnh đầu)</Label>
                <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
            </div>
            {data.scenes.map((scene, i) => (
                <div key={scene.id} className="grid gap-3 rounded-lg border p-3 md:grid-cols-2">
                    <div className="grid gap-1 md:col-span-2">
                        <Label className="text-sm">Cảnh {i + 1}: lời kể</Label>
                        <Textarea rows={2} value={scene.narration} onChange={(e) => setScene(i, 'narration', e.target.value)} />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-sm font-normal">Chữ trên màn</Label>
                        <Input value={scene.on_screen_text} onChange={(e) => setScene(i, 'on_screen_text', e.target.value)} />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-sm font-normal">Từ khoá tìm ảnh (tiếng Anh)</Label>
                        <Input value={scene.image_query} onChange={(e) => setScene(i, 'image_query', e.target.value)} />
                    </div>
                    <div className="grid gap-1 md:col-span-2">
                        <Label className="text-sm font-normal">Prompt Muse vẽ (tiếng Anh)</Label>
                        <Textarea rows={2} value={scene.image_prompt} onChange={(e) => setScene(i, 'image_prompt', e.target.value)} />
                    </div>
                    <InputError className="md:col-span-2" message={Object.entries(fieldErrors).find(([k]) => k.startsWith(`scenes.${i}.`))?.[1]} />
                </div>
            ))}
            <div className="grid gap-2">
                <Label htmlFor="caption">Caption đăng bài</Label>
                <Textarea id="caption" rows={2} value={data.caption} onChange={(e) => setData('caption', e.target.value)} />
                {short.hashtags.length > 0 && <p className="text-muted-foreground text-sm">{short.hashtags.join(' ')}</p>}
            </div>
            <Button type="submit" disabled={processing || !isDirty}>
                Lưu lời kể
            </Button>
        </form>
    );
}

function CharactersPanel({ short }: { short: TopicShort }) {
    const upload = (index: number, file: File | undefined) =>
        file && router.post(route('topic-shorts.character-image', [short.id, index]), { image: file }, { preserveScroll: true, forceFormData: true });

    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold">2. Nhân vật</h2>
            <p className="text-muted-foreground text-sm">
                Muse vẽ theo tên và mô tả từng nhân vật{short.art_style ? `, phong cách: ${short.art_style}` : ''}. Tải lên một ảnh mẫu rõ mặt mỗi
                nhân vật (chụp từ phim) để Muse vẽ giống hơn, rồi bấm "Muse vẽ lại tất cả".
            </p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {short.characters.map((character, i) => (
                    <div key={character.name} className="flex gap-3 rounded-lg border p-3">
                        <div className="bg-muted flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                            {character.image_url ? (
                                <img src={character.image_url} alt={character.name} className="h-full w-full object-cover" />
                            ) : (
                                <ImageOff className="text-muted-foreground size-5" />
                            )}
                        </div>
                        <div className="min-w-0 flex-1 space-y-1">
                            <p className="text-sm font-medium">{character.name}</p>
                            <p className="text-muted-foreground line-clamp-3 text-xs">{character.look}</p>
                            <label className="text-primary inline-flex cursor-pointer items-center gap-1 text-xs font-medium">
                                <Upload className="size-3.5" /> {character.image_url ? 'Đổi ảnh mẫu' : 'Tải ảnh mẫu'}
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    className="hidden"
                                    aria-label={`Ảnh mẫu ${character.name}`}
                                    onChange={(e) => upload(i, e.target.files?.[0])}
                                />
                            </label>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function SceneImageCard({ scene }: { scene: ShortScene }) {
    const upload = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState(scene.image_query ?? '');
    const { errors } = usePage().props as { errors: Record<string, string> };
    const busy = scene.image_status === 'searching' || scene.image_status === 'pending';
    const options = { preserveScroll: true };

    const search = () => router.post(route('topic-short-scenes.search', scene.id), { query }, options);
    const pick = (url: string) => router.post(route('topic-short-scenes.pick', scene.id), { url }, options);
    const muse = () => router.post(route('topic-short-scenes.muse', scene.id), {}, options);
    const send = (file: File | undefined) =>
        file && router.post(route('topic-short-scenes.upload', scene.id), { image: file }, { ...options, forceFormData: true });

    return (
        <div className="flex gap-3 rounded-lg border p-3">
            <div className="bg-muted relative flex aspect-[9/16] w-32 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                {busy && <Loader2 className="text-muted-foreground size-5 animate-spin" />}
                {!busy && scene.image_url && <img src={scene.image_url} alt={`Cảnh ${scene.position}`} className="h-full w-full object-contain" />}
                {!busy && !scene.image_url && <ImageOff className="text-muted-foreground size-5" />}
                {scene.image_source && scene.image_status === 'ready' && (
                    <Badge className="absolute top-1 left-1" variant="secondary">
                        {sourceLabels[scene.image_source]}
                    </Badge>
                )}
            </div>
            <div className="min-w-0 flex-1 space-y-2">
                <div className="text-sm font-medium">
                    Cảnh {scene.position}: {scene.on_screen_text}
                </div>
                {scene.characters.length > 0 && <p className="text-muted-foreground text-xs">Nhân vật: {scene.characters.join(', ')}</p>}
                {scene.image_status === 'searching' && <p className="text-muted-foreground text-xs">Đang tìm ảnh trên wiki...</p>}
                {scene.image_status === 'pending' && <p className="text-muted-foreground text-xs">Muse đang vẽ...</p>}
                {scene.image_error && <p className="text-xs text-red-600 dark:text-red-400">{scene.image_error}</p>}
                <InputError message={errors[`scene_${scene.id}`]} />

                {scene.candidates.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {scene.candidates.map((candidate) => (
                            <button
                                key={candidate.url}
                                type="button"
                                title={candidate.title}
                                disabled={busy}
                                onClick={() => pick(candidate.url)}
                                className={`h-14 w-14 overflow-hidden rounded border-2 ${scene.image_source_url === candidate.url ? 'border-primary' : 'border-transparent'}`}
                            >
                                <img src={candidate.url} alt={candidate.title} className="h-full w-full object-cover" loading="lazy" />
                            </button>
                        ))}
                    </div>
                )}

                <div className="flex gap-1">
                    <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Từ khoá tìm ảnh" className="h-8 text-sm" />
                    <Button size="sm" variant="outline" onClick={search} disabled={busy || query.trim() === ''}>
                        <Search /> Tìm
                    </Button>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={muse} disabled={busy}>
                        <Wand2 /> Muse vẽ
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => upload.current?.click()} disabled={busy}>
                        <Upload /> Tải ảnh lên
                    </Button>
                    <input
                        ref={upload}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        aria-label={`Ảnh cảnh ${scene.position}`}
                        onChange={(e) => send(e.target.files?.[0])}
                    />
                </div>
            </div>
        </div>
    );
}

function RenderPanel({ short, music, price }: { short: TopicShort; music: { id: number; name: string }[]; price: number }) {
    const { data, setData, post, processing } = useForm<{ music_track_id: string; music_volume: number }>({ music_track_id: '', music_volume: 12 });
    const rendering = short.video_status === 'rendering';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('topic-shorts.render', short.id), { preserveScroll: true });
    };

    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">4. Dựng video</h2>
            {!short.all_images_ready && <p className="text-muted-foreground text-sm">Mọi cảnh cần có ảnh trước khi dựng video.</p>}
            <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="music">Nhạc nền</Label>
                    <select
                        id="music"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={data.music_track_id}
                        onChange={(e) => setData('music_track_id', e.target.value)}
                    >
                        <option value="">Không có nhạc</option>
                        {music.map((track) => (
                            <option key={track.id} value={track.id}>
                                {track.name}
                            </option>
                        ))}
                    </select>
                </div>
                {data.music_track_id && (
                    <div className="grid gap-2">
                        <Label htmlFor="music_volume">Âm lượng nhạc: {data.music_volume}%</Label>
                        <input
                            id="music_volume"
                            type="range"
                            min={0}
                            max={40}
                            value={data.music_volume}
                            onChange={(e) => setData('music_volume', Number(e.target.value))}
                        />
                    </div>
                )}
                <Button disabled={processing || rendering || !short.all_images_ready || short.status !== 'ready'}>
                    {rendering ? <Loader2 className="animate-spin" /> : <Sparkles />} Đọc giọng + dựng video ({formatNumber(price)} Credits)
                </Button>
            </form>
            {rendering && <p className="text-muted-foreground text-sm">Đang đọc giọng AI và ghép video...</p>}
            {short.video_error && <p className="text-sm text-red-600 dark:text-red-400">{short.video_error}</p>}
            {short.video_url && short.video_status !== 'rendering' && (
                <div className="flex flex-wrap items-end gap-4">
                    <video
                        key={short.video_url}
                        src={short.video_url}
                        controls
                        playsInline
                        className="aspect-[9/16] w-64 rounded-lg border bg-black"
                    />
                    <Button variant="outline" asChild>
                        <a href={route('topic-shorts.download', short.id)}>
                            <Download /> Tải MP4
                        </a>
                    </Button>
                </div>
            )}
        </section>
    );
}
