import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { type FactCheck, type Research, HookPicker, ResearchPanel, SceneFacts } from '@/components/topic-shorts/research-panel';
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
import { Download, ImageOff, LayoutPanelTop, Loader2, RefreshCw, Search, ShieldCheck, Sparkles, TriangleAlert, Upload, Wand2 } from 'lucide-react';
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
    image_source: 'web' | 'muse' | 'upload' | 'library' | 'page' | null;
    expression: string | null;
    library: { id: number; name: string; expression: string; url: string }[];
    image_status: 'none' | 'searching' | 'pending' | 'ready' | 'failed';
    image_url: string | null;
    image_source_url: string | null;
    image_error: string | null;
    candidates: Candidate[];
    fact_ids: string[];
    fact_check: FactCheck | null;
    mood: string | null;
    panels: { character: string; pose: string; camera: string; name: string; label: string; url: string | null; exact: boolean }[];
}

interface TopicShort {
    id: number;
    topic: string;
    title: string | null;
    language: 'vi' | 'en';
    style: string;
    image_style: string;
    target_seconds: number;
    status: 'researching' | 'research_ready' | 'writing' | 'ready' | 'failed';
    error: string | null;
    use_research: boolean;
    research: Research | null;
    research_notes: string | null;
    hooks: { style: string; text: string }[];
    hook_index: number | null;
    series_parts: number;
    part: number | null;
    series: { id: number; parts: { id: number; part: number; title: string | null; status: string; focus: string }[] } | null;
    unsupported_count: number;
    missing_panels: { character: string; pose: string; name: string; label: string; status: 'none' | 'failed' | 'pending' }[];
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
    image_stats: { ready: number; failed: number; pending: number; searching: number };
    all_images_ready: boolean;
    scenes: ShortScene[];
}

const sourceLabels = { web: 'Ảnh wiki', muse: 'Muse vẽ', upload: 'Ảnh tải lên', library: 'Ảnh nhân vật', page: 'Trang manga' } as const;

/** Chi tiết short: sửa lời kể, chọn ảnh từng cảnh (wiki / Muse / tải lên), dựng video có giọng AI. */
export default function TopicShortShow({
    short,
    music,
    prices,
    image_styles,
}: {
    short: TopicShort;
    music: { id: number; name: string }[];
    image_styles: Record<string, string>;
    prices: { image: number; render: number };
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shorts chủ đề', href: '/topic-shorts' },
        { title: short.title || short.topic, href: route('topic-shorts.show', short.id) },
    ];
    const checking = short.use_research && short.status === 'ready' && short.scenes.some((s) => s.fact_check === null);
    const drawing = short.missing_panels.some((p) => p.status === 'pending');
    usePoll(
        short.status === 'writing' ||
            short.status === 'researching' ||
            checking ||
            drawing ||
            short.images_busy ||
            short.video_status === 'rendering',
        ['short'],
        3000,
    );

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
    const [imageStyle, setImageStyle] = useState(short.image_style);
    const failedCount = short.scenes.filter((s) => s.image_status === 'failed').length;
    const retryFailed = () => {
        if (
            confirm(
                `Claude viết lại prompt theo lời từ chối của Muse rồi tạo lại ${failedCount} ảnh lỗi, khoảng ${formatNumber(failedCount * prices.image)} Credits. Tiếp tục?`,
            )
        ) {
            router.post(route('topic-shorts.retry-failed', short.id), {}, { preserveScroll: true });
        }
    };
    const museAll = () => {
        const count = short.scenes.filter((s) => s.image_source !== 'upload').length;
        if (confirm(`Muse vẽ lại ${count} cảnh (trừ ảnh bạn tự tải lên), khoảng ${formatNumber(count * prices.image)} Credits. Tiếp tục?`)) {
            router.post(route('topic-shorts.muse-all', short.id), { image_style: imageStyle }, { preserveScroll: true });
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
                        {short.part && (
                            <Badge>
                                Phần {short.part}/{short.series_parts}
                            </Badge>
                        )}
                        <Button variant="outline" size="sm" onClick={rewrite} disabled={short.status === 'writing' || short.status === 'researching'}>
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

                {short.series && (
                    <div className="flex flex-wrap gap-2 text-sm">
                        <span className="text-muted-foreground">Series:</span>
                        {short.series.parts.map((p) => (
                            <a
                                key={p.id}
                                href={route('topic-shorts.show', p.id)}
                                className={`rounded border px-2 py-0.5 ${p.id === short.id ? 'border-primary font-medium' : 'hover:bg-muted/50'}`}
                                title={p.focus}
                            >
                                Phần {p.part}
                                {p.status === 'writing' ? ' (đang viết)' : ''}
                            </a>
                        ))}
                    </div>
                )}

                {short.use_research && (
                    <ResearchPanel
                        key={`${short.status}:${short.research?.facts.map((f) => `${f.id}${f.enabled}${f.text}`).join('|')}`}
                        shortId={short.id}
                        status={short.status}
                        research={short.research}
                        notes={short.research_notes}
                        seriesParts={short.part ? 1 : short.series_parts}
                        hasScript={short.scenes.length > 0}
                    />
                )}

                {short.scenes.length > 0 && <HookPicker shortId={short.id} hooks={short.hooks} index={short.hook_index} />}

                {short.scenes.length > 0 && <ScriptForm key={textKey} short={short} />}

                {short.characters.length > 0 && <CharactersPanel short={short} />}

                {short.scenes.length > 0 && (
                    <section className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold">3. Ảnh từng cảnh</h2>
                            <div className="flex items-center gap-2">
                                <select
                                    aria-label="Kiểu ảnh"
                                    className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                    value={imageStyle}
                                    onChange={(e) => setImageStyle(e.target.value)}
                                >
                                    {Object.entries(image_styles).map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                                {failedCount > 0 && (
                                    <Button size="sm" onClick={retryFailed}>
                                        <RefreshCw /> Tạo lại {failedCount} ảnh lỗi
                                    </Button>
                                )}
                                <Button variant="outline" size="sm" onClick={museAll} disabled={short.images_busy}>
                                    <Wand2 /> Muse vẽ lại tất cả
                                </Button>
                            </div>
                        </div>
                        <p className="text-muted-foreground text-sm">
                            Mỗi cảnh chọn một ảnh: ảnh tìm trên wiki (bấm ảnh nhỏ để đổi), Muse vẽ ({formatNumber(prices.image)} Credits / ảnh) hoặc
                            ảnh bạn tải lên.
                        </p>
                        <p className="text-sm">
                            {short.scenes.length} cảnh: <span className="text-green-600 dark:text-green-400">{short.image_stats.ready} có ảnh</span>
                            {short.image_stats.failed > 0 && (
                                <span className="text-red-600 dark:text-red-400"> · {short.image_stats.failed} lỗi</span>
                            )}
                            {short.image_stats.pending + short.image_stats.searching > 0 &&
                                ` · ${short.image_stats.pending + short.image_stats.searching} đang tạo`}
                        </p>
                        <MangaPanelsBar short={short} price={prices.image} />
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
                <span className="text-muted-foreground flex items-center gap-2 text-sm">
                    Ước tính {seconds} giây (mục tiêu {short.target_seconds} giây)
                    {short.use_research && short.status === 'ready' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={isDirty}
                            title={isDirty ? 'Lưu lời kể trước' : undefined}
                            onClick={() => router.post(route('topic-shorts.fact-check', short.id), {}, { preserveScroll: true })}
                        >
                            <ShieldCheck /> Kiểm chứng lại
                        </Button>
                    )}
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
                    <SceneFacts
                        check={short.scenes[i]?.fact_check ?? null}
                        factIds={short.scenes[i]?.fact_ids ?? []}
                        facts={short.research?.facts ?? []}
                    />
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
    const compose = () => router.post(route('topic-short-scenes.compose', scene.id), {}, options);
    const pickLibrary = (portraitId: number) => router.post(route('topic-short-scenes.library', scene.id), { portrait_id: portraitId }, options);
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
                {scene.panels.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1">
                        <span className="text-muted-foreground text-xs">Khung manga:</span>
                        {scene.panels.map((panel, i) => (
                            <span
                                key={i}
                                title={`${panel.name}: ${panel.label}${panel.camera ? ` (${panel.camera})` : ''}${panel.exact ? '' : ' · chưa có ảnh đúng tư thế'}`}
                                className={`flex h-14 w-10 items-center justify-center overflow-hidden rounded border-2 ${panel.exact ? 'border-transparent' : 'border-dashed border-amber-500'}`}
                            >
                                {panel.url ? (
                                    <img src={panel.url} alt={panel.name} className="h-full w-full object-cover" loading="lazy" />
                                ) : (
                                    <ImageOff className="text-muted-foreground size-4" />
                                )}
                            </span>
                        ))}
                        <Button size="sm" variant="outline" disabled={busy} onClick={compose}>
                            <LayoutPanelTop /> Ghép trang
                        </Button>
                    </div>
                )}
                {scene.image_status === 'searching' && <p className="text-muted-foreground text-xs">Đang tìm ảnh trên wiki...</p>}
                {scene.image_status === 'pending' && <p className="text-muted-foreground text-xs">Muse đang vẽ...</p>}
                {scene.image_error && <p className="text-xs text-red-600 dark:text-red-400">{scene.image_error}</p>}
                <InputError message={errors[`scene_${scene.id}`]} />

                {scene.library.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1">
                        <span className="text-muted-foreground text-xs">Thư viện:</span>
                        {scene.library.map((portrait) => (
                            <button
                                key={portrait.id}
                                type="button"
                                title={`${portrait.name} (${portrait.expression})`}
                                disabled={busy}
                                onClick={() => pickLibrary(portrait.id)}
                                className="hover:border-primary h-14 w-10 overflow-hidden rounded border-2 border-transparent"
                            >
                                <img src={portrait.url} alt={portrait.name} className="h-full w-full object-cover" loading="lazy" />
                            </button>
                        ))}
                    </div>
                )}
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

/** Khung manga các cảnh cần mà thư viện chưa có ảnh đúng tư thế: vẽ vào thư viện (dùng lại cho video sau), xong tự ghép trang. */
function MangaPanelsBar({ short, price }: { short: TopicShort; price: number }) {
    if (!short.scenes.some((s) => s.panels.length > 0)) {
        return null;
    }
    const drawable = short.missing_panels.filter((p) => p.status !== 'pending');
    const pending = short.missing_panels.length - drawable.length;
    const draw = () => {
        if (confirm(`Muse vẽ ${drawable.length} khung vào thư viện nhân vật, khoảng ${formatNumber(drawable.length * price)} Credits. Tiếp tục?`)) {
            router.post(route('topic-shorts.draw-missing', short.id), {}, { preserveScroll: true });
        }
    };

    return (
        <div className="space-y-2 rounded-lg border p-3 text-sm">
            {short.missing_panels.length === 0 ? (
                <p>Thư viện đã có đủ ảnh cho mọi khung manga.</p>
            ) : (
                <>
                    <p>
                        Thiếu {short.missing_panels.length} khung trong thư viện (đang tạm dùng mặt thường, viền vàng):{' '}
                        <span className="text-muted-foreground">
                            {short.missing_panels.map((p) => `${p.name} ${p.label}${p.status === 'failed' ? ' (lỗi)' : ''}`).join(', ')}
                        </span>
                    </p>
                    {pending > 0 && <p className="text-muted-foreground">Muse đang vẽ {pending} khung...</p>}
                </>
            )}
            <div className="flex flex-wrap gap-2">
                {drawable.length > 0 && (
                    <Button size="sm" onClick={draw}>
                        <Wand2 /> Vẽ {drawable.length} khung còn thiếu ({formatNumber(drawable.length * price)} Credits)
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => router.post(route('topic-shorts.compose-all', short.id), {}, { preserveScroll: true })}
                >
                    <LayoutPanelTop /> Ghép lại tất cả trang
                </Button>
                <a href={route('character-library.index')} className="text-primary self-center">
                    Thư viện nhân vật
                </a>
            </div>
        </div>
    );
}

function RenderPanel({ short, music, price }: { short: TopicShort; music: { id: number; name: string }[]; price: number }) {
    const { data, setData, post, processing } = useForm<{ music_track_id: string; music_volume: number }>({ music_track_id: '', music_volume: 12 });
    const rendering = short.video_status === 'rendering';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (short.unsupported_count > 0 && !confirm(`${short.unsupported_count} cảnh có câu không có trong tư liệu (đánh dấu đỏ). Vẫn dựng video?`)) {
            return;
        }
        post(route('topic-shorts.render', short.id), { preserveScroll: true });
    };

    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">4. Dựng video</h2>
            {!short.all_images_ready && <p className="text-muted-foreground text-sm">Mọi cảnh cần có ảnh trước khi dựng video.</p>}
            {short.unsupported_count > 0 && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    {short.unsupported_count} cảnh có câu không có trong tư liệu. Sửa lời kể rồi bấm Kiểm chứng lại.
                </p>
            )}
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
