import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MangaPicker } from '@/components/topic-shorts/manga-picker';
import { type PronunciationRow, PronunciationPanel } from '@/components/topic-shorts/pronunciation-panel';
import { type PublishPack, CopyButton, PublishPanel } from '@/components/topic-shorts/publish-panel';
import { type FactCheck, type Research, HookPicker, ResearchPanel, SceneFacts } from '@/components/topic-shorts/research-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { type Panel } from '@/pages/manga-panels/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Clapperboard,
    Download,
    ImageOff,
    LayoutPanelTop,
    Loader2,
    RefreshCw,
    Search,
    ShieldCheck,
    Sparkles,
    TriangleAlert,
    Upload,
    Wand2,
} from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

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
    muse_refusal: string | null;
    muse_fix_prompt: string | null;
    candidates: Candidate[];
    fact_ids: string[];
    fact_check: FactCheck | null;
    mood: string | null;
    manga_panels: Panel[];
    voice_url: string | null;
    clip_url: string | null;
    clip_status: 'none' | 'pending' | 'ready' | 'failed';
    clip_sent: boolean;
    clip_error: string | null;
    voice_ms: number | null;
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
    cover_url: string | null;
    cover: { scene_id?: number; text?: string; uploaded?: boolean } | null;
    publish: PublishPack | null;
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
    clips_busy: number;
    image_stats: { ready: number; failed: number; pending: number; searching: number };
    all_images_ready: boolean;
    scenes: ShortScene[];
}

const sourceLabels = { web: 'Ảnh wiki', muse: 'Muse vẽ', upload: 'Ảnh tải lên', library: 'Ảnh nhân vật', page: 'Trang manga' } as const;

/** Chi tiết short: sửa lời kể, chọn ảnh từng cảnh (wiki / Muse / tải lên), dựng video có giọng AI. */
export default function TopicShortShow({
    short,
    music,
    builtin_music,
    music_suggestion,
    prices,
    image_styles,
    pronunciations,
    render_defaults,
    logo_url,
}: {
    short: TopicShort;
    music: { id: number; name: string }[];
    builtin_music: { file: string; name: string }[];
    music_suggestion: { mood: string | null; track_id: number | null; file: string | null };
    image_styles: Record<string, string>;
    prices: { image: number; clip: number; render: number };
    pronunciations: PronunciationRow[];
    render_defaults: { style: 'fast' | 'page'; watermark: string; logo: boolean; logo_size: number };
    logo_url: string | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        short.use_research ? { title: 'Video truyện', href: '/story-videos' } : { title: 'Shorts chủ đề', href: '/topic-shorts' },
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
            short.clips_busy > 0 ||
            short.video_status === 'rendering' ||
            short.publish?.status === 'writing',
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
    const failedCount = short.scenes.filter((s) => s.image_status === 'failed' || (s.muse_fix_prompt && s.image_status !== 'pending')).length;
    const retryFailed = () => {
        if (
            confirm(
                `Tạo lại ${failedCount} ảnh lỗi bằng prompt Claude sửa theo góp ý của Muse, khoảng ${formatNumber(failedCount * prices.image)} Credits. Tiếp tục?`,
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
    const steps = stepsOf(short);
    const [step, setStep] = useState<StepKey>(() => {
        const asked = new URLSearchParams(window.location.search).get('step') as StepKey | null;
        return asked && steps.some((st) => st.key === asked) ? asked : currentStep(short);
    });
    // Trạng thái đổi (tìm xong tư liệu, viết xong lời kể, đủ ảnh...): chuyển sang bước tiếp theo cần làm.
    const lastStatus = useRef(`${short.status}:${short.all_images_ready}`);
    useEffect(() => {
        const now = `${short.status}:${short.all_images_ready}`;
        if (lastStatus.current !== now) {
            lastStatus.current = now;
            setStep(currentStep(short));
        }
    }, [short]);
    const go = (key: StepKey) => {
        setStep(key);
        window.history.replaceState(null, '', `?step=${key}`);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const textKey = short.scenes.map((s) => `${s.id}:${s.narration}:${s.on_screen_text}:${s.image_query}:${s.image_prompt}`).join('|') + short.title;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={short.title || short.topic} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={short.title || short.topic} description={`Chủ đề: ${short.topic}`} />
                    <div className="flex items-center gap-2">
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

                <StepBar steps={steps} current={step} onChange={go} />

                {step === 'research' && (
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

                {step === 'script' && (
                    <div className="space-y-6">
                        {short.status === 'writing' && (
                            <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                <Loader2 className="size-4 animate-spin" /> Claude đang viết lời kể và chia cảnh...
                            </p>
                        )}
                        {short.scenes.length > 0 && <HookPicker shortId={short.id} hooks={short.hooks} index={short.hook_index} />}
                        {short.scenes.length > 0 && <ScriptForm key={textKey} short={short} />}
                        {short.characters.length > 0 && <CharactersPanel short={short} />}
                    </div>
                )}

                {step === 'images' && short.scenes.length > 0 && (
                    <div className="space-y-4">
                        {short.scenes.some((s) => s.image_source === 'web') && (
                            <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                Ảnh tìm trên wiki là ảnh phim / truyện có bản quyền (Toei, Shueisha...). Video có thể bị gỡ hoặc không bật kiếm tiền
                                được. Ảnh Muse vẽ hoặc ảnh tự làm ít rủi ro hơn.
                            </div>
                        )}

                        <section className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-lg font-semibold">Ảnh từng cảnh</h2>
                                <div className="flex items-center gap-2">
                                    <select
                                        aria-label="Kiểu ảnh"
                                        className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                        value={imageStyle}
                                        onChange={(e) => {
                                            // Lưu ngay: nút Muse vẽ ảnh từng cảnh cũng vẽ theo kiểu này.
                                            setImageStyle(e.target.value);
                                            router.put(
                                                route('topic-shorts.image-style', short.id),
                                                { image_style: e.target.value },
                                                { preserveScroll: true },
                                            );
                                        }}
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
                                    <Button
                                        size="sm"
                                        onClick={() => router.post(route('topic-shorts.find-images', short.id), {}, { preserveScroll: true })}
                                        disabled={short.images_busy}
                                    >
                                        <Search /> Claude tìm ảnh cho mọi cảnh
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={museAll} disabled={short.images_busy}>
                                        <Wand2 /> Muse vẽ lại tất cả
                                    </Button>
                                </div>
                            </div>
                            <p className="text-muted-foreground text-sm">
                                Mỗi cảnh là một trang manga ghép từ khung truyện thật trong Kho khung manga (Claude tự chọn khi viết lời kể, bấm "Chọn
                                khung manga" để đổi), cảnh không có khung thật thì ghép từ thư viện Nhân vật Ngọc Rồng. Thư viện thiếu ảnh thì bấm "Vẽ
                                khung còn thiếu", vẽ xong trang tự ghép lại. Có thể thay bằng ảnh tải lên hoặc Muse vẽ riêng (
                                {formatNumber(prices.image)} Credits / ảnh).
                            </p>
                            <p className="text-sm">
                                {short.scenes.length} cảnh:{' '}
                                <span className="text-green-600 dark:text-green-400">{short.image_stats.ready} có ảnh</span>
                                {short.image_stats.failed > 0 && (
                                    <span className="text-red-600 dark:text-red-400"> · {short.image_stats.failed} lỗi</span>
                                )}
                                {short.image_stats.pending + short.image_stats.searching > 0 &&
                                    ` · ${short.image_stats.pending + short.image_stats.searching} đang tạo`}
                            </p>
                            {short.clips_busy > 0 && (
                                <div className="flex items-center gap-2 rounded-md border border-violet-500/40 bg-violet-500/10 px-3 py-2 text-sm">
                                    <Loader2 className="size-4 animate-spin text-violet-600" />
                                    Muse đang tạo {short.clips_busy} video ngắn, mỗi video vài phút. Trang tự cập nhật khi xong.
                                </div>
                            )}
                            <MangaPanelsBar short={short} price={prices.image} />
                            <div className="grid gap-4 lg:grid-cols-2">
                                {short.scenes.map((scene) => (
                                    <SceneImageCard key={scene.id} scene={scene} clipPrice={prices.clip} color={short.image_style !== 'manga'} />
                                ))}
                            </div>
                        </section>
                    </div>
                )}

                {step === 'video' && short.scenes.length > 0 && (
                    <>
                        <RenderPanel
                            short={short}
                            music={music}
                            builtin={builtin_music}
                            suggestion={music_suggestion}
                            price={prices.render}
                            defaults={render_defaults}
                            logoUrl={logo_url}
                        />
                        <VoicesPanel short={short} />
                        {short.language !== 'en' && <PronunciationPanel rows={pronunciations} />}
                    </>
                )}

                {step === 'publish' && short.scenes.length > 0 && (
                    <PublishPanel shortId={short.id} publish={short.publish} cover={short.cover} coverUrl={short.cover_url} scenes={short.scenes} />
                )}

                <NextStep steps={steps} current={step} onChange={go} />

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
                <h2 className="text-lg font-semibold">Lời kể</h2>
                <span className="text-muted-foreground flex items-center gap-2 text-sm">
                    Ước tính {seconds} giây (mục tiêu {short.target_seconds} giây)
                    <CopyButton
                        text={data.scenes
                            .map((scene) => scene.narration.trim())
                            .filter(Boolean)
                            .join('\n\n')}
                        label="Copy toàn bộ lời kể"
                    />
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

/** Giọng từng cảnh: tải mp3 tự thu cho cảnh nào thì cảnh đó dùng giọng của bạn, còn lại máy đọc. */
function VoicesPanel({ short }: { short: TopicShort }) {
    const own = short.scenes.filter((scene) => scene.voice_url).length;

    return (
        <section className="space-y-3 rounded-lg border p-4">
            <div>
                <h2 className="font-semibold">Giọng từng cảnh</h2>
                <p className="text-muted-foreground text-sm">
                    Tải file mp3 bạn tự thu cho cảnh nào thì cảnh đó dùng giọng của bạn, cảnh còn lại máy đọc. Phụ đề tự khớp theo độ dài file. Tải
                    xong bấm dựng lại video. {own > 0 && `Đang có ${own}/${short.scenes.length} cảnh dùng giọng tự thu.`}
                </p>
            </div>
            <div className="divide-y">
                {short.scenes.map((scene) => (
                    <div key={scene.id} className="space-y-1 py-2">
                        <p className="text-sm">
                            <span className="font-medium">Cảnh {scene.position}:</span> {scene.narration}
                        </p>
                        <SceneVoice scene={scene} />
                    </div>
                ))}
            </div>
        </section>
    );
}

/** Giọng tự thu cho một cảnh: tải mp3 lên thì khi dựng video dùng file đó thay giọng máy. */
function SceneVoice({ scene }: { scene: ShortScene }) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const upload = (file: File | undefined) =>
        file &&
        router.post(
            route('topic-short-scenes.voice', scene.id),
            { voice: file },
            {
                preserveScroll: true,
                forceFormData: true,
                onStart: () => {
                    setUploading(true);
                    setError(null);
                },
                onError: (errors) => setError(errors.voice ?? null),
                onFinish: () => setUploading(false),
            },
        );
    const remove = () => router.delete(route('topic-short-scenes.voice.destroy', scene.id), { preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            {scene.voice_url ? (
                <>
                    <span className="text-green-700 dark:text-green-400">Giọng tự thu ({((scene.voice_ms ?? 0) / 1000).toFixed(1)} giây)</span>
                    <audio controls preload="none" src={scene.voice_url} className="h-8 max-w-64" />
                    <Button type="button" size="sm" variant="ghost" className="text-destructive h-7" onClick={remove}>
                        Bỏ, dùng giọng máy
                    </Button>
                </>
            ) : (
                <span className="text-muted-foreground">Giọng máy đọc</span>
            )}
            <label className="text-primary inline-flex cursor-pointer items-center gap-1 font-medium">
                {uploading ? <Loader2 className="size-3.5 animate-spin" /> : <Upload className="size-3.5" />}
                {scene.voice_url ? 'Đổi file giọng' : 'Tải giọng của bạn (mp3)'}
                <input
                    type="file"
                    accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/ogg,.mp3,.m4a,.wav,.ogg"
                    className="hidden"
                    aria-label={`Giọng cảnh ${scene.position}`}
                    onChange={(e) => {
                        upload(e.target.files?.[0]);
                        e.target.value = '';
                    }}
                />
            </label>
            {error && <span className="text-red-600 dark:text-red-400">{error}</span>}
        </div>
    );
}

function CharactersPanel({ short }: { short: TopicShort }) {
    const upload = (index: number, file: File | undefined) =>
        file && router.post(route('topic-shorts.character-image', [short.id, index]), { image: file }, { preserveScroll: true, forceFormData: true });

    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold">Nhân vật</h2>
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

function SceneImageCard({ scene, clipPrice, color }: { scene: ShortScene; clipPrice: number; color: boolean }) {
    const upload = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState(scene.image_query ?? '');
    const { errors } = usePage().props as { errors: Record<string, string> };
    const busy = scene.image_status === 'searching' || scene.image_status === 'pending';
    const options = { preserveScroll: true };

    const search = () => router.post(route('topic-short-scenes.search', scene.id), { query }, options);
    const pick = (url: string) => router.post(route('topic-short-scenes.pick', scene.id), { url }, options);
    const muse = () => router.post(route('topic-short-scenes.muse', scene.id), {}, options);
    const clipBusy = scene.clip_status === 'pending';
    const makeClip = () =>
        confirm(`Muse làm video ngắn từ ảnh hiện tại của cảnh ${scene.position}, khoảng ${formatNumber(clipPrice)} Credits. Tiếp tục?`) &&
        router.post(route('topic-short-scenes.clip', scene.id), {}, options);
    const dropClip = () => router.delete(route('topic-short-scenes.clip.destroy', scene.id), options);
    const compose = () => router.post(route('topic-short-scenes.compose', scene.id), {}, options);
    const pickLibrary = (portraitId: number) => router.post(route('topic-short-scenes.library', scene.id), { portrait_id: portraitId }, options);
    const more = useRef<HTMLInputElement>(null);
    const findImages = () => router.post(route('topic-short-scenes.find-images', scene.id), {}, options);
    const addImages = (files: File[]) =>
        files.length > 0 &&
        router.post(route('topic-short-scenes.add-images', scene.id), { images: files.slice(0, 3) }, { ...options, forceFormData: true });
    const send = (file: File | undefined) =>
        file && router.post(route('topic-short-scenes.upload', scene.id), { image: file }, { ...options, forceFormData: true });

    return (
        <div className="flex gap-3 rounded-lg border p-3">
            <div className="bg-muted relative flex aspect-[9/16] w-32 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                {busy && <Loader2 className="text-muted-foreground size-5 animate-spin" />}
                {scene.clip_status === 'ready' && scene.clip_url && !clipBusy && (
                    <Badge className="absolute top-1 right-1 bg-violet-600 text-white">Video</Badge>
                )}
                {clipBusy && (
                    <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-1 bg-black/55 text-center text-xs font-medium text-white">
                        <Loader2 className="size-6 animate-spin" />
                        Đang tạo video
                    </div>
                )}
                {!busy && scene.image_url && <img src={scene.image_url} alt={`Cảnh ${scene.position}`} className="h-full w-full object-contain" />}
                {!busy && !scene.image_url && <ImageOff className="text-muted-foreground size-5" />}
                {/* Muse từ chối nên cảnh còn trang ghép từ thư viện nhân vật (manga đen trắng), không phải ảnh màu đã chọn. */}
                {color && scene.image_source === 'page' && scene.image_status === 'ready' && (
                    <Badge className="absolute right-1 bottom-1 left-1 justify-center bg-amber-500 text-[10px] text-black">
                        Dự phòng · trắng đen
                    </Badge>
                )}
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
                <MangaPicker
                    key={scene.manga_panels.map((p) => p.id).join(',')}
                    sceneId={scene.id}
                    current={scene.manga_panels}
                    hint={scene.characters[0] ?? ''}
                />
                {scene.panels.length > 0 && scene.manga_panels.length === 0 && (
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
                {scene.image_status === 'searching' && <p className="text-muted-foreground text-xs">Claude đang tìm ảnh trên mạng (vài phút)...</p>}
                {scene.image_status === 'pending' && <p className="text-muted-foreground text-xs">Muse đang vẽ...</p>}
                {scene.image_error && <p className="text-xs text-red-600 dark:text-red-400">{scene.image_error}</p>}
                {scene.muse_fix_prompt && !busy && <MuseFix key={scene.muse_fix_prompt} scene={scene} />}
                {clipBusy && (
                    <p className="flex items-center gap-1 text-xs text-violet-600 dark:text-violet-400">
                        <Loader2 className="size-3 animate-spin" />
                        {scene.clip_sent ? 'Muse đang làm video từ ảnh này (thường 2–5 phút)...' : 'Đang gửi ảnh cho Muse (chờ lượt nếu Muse bận)...'}
                    </p>
                )}
                {scene.clip_error && <p className="text-xs text-red-600 dark:text-red-400">{scene.clip_error}</p>}
                {scene.clip_status === 'ready' && scene.clip_url && (
                    <div className="flex items-end gap-2">
                        <video
                            src={scene.clip_url}
                            className="aspect-[9/16] w-24 rounded-md border bg-black object-cover"
                            controls
                            muted
                            loop
                            playsInline
                        />
                        <div className="space-y-1 text-xs">
                            <p className="text-muted-foreground">Khi dựng, cảnh này dùng video thay ảnh động.</p>
                            <Button size="sm" variant="ghost" onClick={dropClip}>
                                Bỏ video, dùng ảnh động
                            </Button>
                        </div>
                    </div>
                )}
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
                <details className="text-xs" open={scene.image_source === 'web'}>
                    <summary className="text-muted-foreground cursor-pointer">Tìm ảnh trên wiki (ảnh phim có bản quyền)</summary>
                    <div className="mt-2 space-y-2">
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
                    </div>
                </details>
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={findImages} disabled={busy}>
                        <Search /> Claude tìm ảnh
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => more.current?.click()}
                        disabled={busy}
                        title="Mỗi ảnh là một cảnh quay, tối đa 3"
                    >
                        <Upload /> Thêm ảnh bạn tìm
                    </Button>
                    <input
                        ref={more}
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        aria-label={`Thêm ảnh cảnh ${scene.position}`}
                        onChange={(e) => {
                            addImages(Array.from(e.target.files ?? []));
                            e.target.value = '';
                        }}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={muse}
                        disabled={busy}
                        title="Claude viết prompt theo đúng lời kể của cảnh rồi Muse vẽ"
                    >
                        <Wand2 /> Muse vẽ ảnh
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={makeClip}
                        disabled={busy || clipBusy || !scene.image_url}
                        title="Muse làm video ngắn từ ảnh hiện tại của cảnh; khi dựng, cảnh dùng video thay ảnh động"
                    >
                        {clipBusy ? <Loader2 className="animate-spin" /> : <Clapperboard />}
                        {clipBusy ? 'Đang tạo video...' : scene.clip_status === 'ready' ? 'Tạo lại video' : 'Muse tạo video ngắn'}
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => upload.current?.click()} disabled={busy}>
                        <Upload /> Thay bằng 1 ảnh
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
                {scene.image_prompt && (
                    <details className="text-xs">
                        <summary className="text-muted-foreground cursor-pointer">
                            Prompt Muse (Claude viết lại theo lời kể mỗi lần bấm Muse vẽ)
                        </summary>
                        <p className="mt-1">{scene.image_prompt}</p>
                    </details>
                )}
            </div>
        </div>
    );
}

/** Muse từ chối: hiện góp ý của Muse và prompt Claude đã sửa theo đó (sửa được), bấm để vẽ lại bằng prompt này. */
function MuseFix({ scene }: { scene: ShortScene }) {
    const [prompt, setPrompt] = useState(scene.muse_fix_prompt ?? '');
    const redraw = () => router.post(route('topic-short-scenes.muse', scene.id), { prompt, fix: true }, { preserveScroll: true });

    return (
        <div className="space-y-2 rounded-md border border-amber-500/50 bg-amber-500/5 p-2 text-xs">
            {scene.muse_refusal && (
                <p>
                    <span className="font-medium">Muse góp ý:</span> {scene.muse_refusal}
                </p>
            )}
            <Label className="text-xs">Prompt Claude đã sửa theo góp ý (sửa thêm nếu muốn)</Label>
            <Textarea rows={3} value={prompt} onChange={(e) => setPrompt(e.target.value)} className="text-xs" />
            <Button size="sm" onClick={redraw} disabled={prompt.trim().length < 5}>
                <RefreshCw /> Tạo lại ảnh với prompt này
            </Button>
        </div>
    );
}

type StepKey = 'research' | 'script' | 'images' | 'video' | 'publish';

interface Step {
    key: StepKey;
    title: string;
    summary: string;
    state: 'done' | 'busy' | 'todo' | 'warn' | 'locked';
}

/** Các bước làm short: tư liệu (nếu có) → lời kể → ảnh → video, kèm tình trạng từng bước. */
function stepsOf(short: TopicShort): Step[] {
    const hasScenes = short.scenes.length > 0;
    const busyImages = short.image_stats.pending + short.image_stats.searching;
    const steps: Step[] = [];
    if (short.use_research) {
        const kept = short.research?.facts.filter((f) => f.enabled).length ?? 0;
        steps.push({
            key: 'research',
            title: 'Tư liệu',
            summary:
                short.status === 'researching'
                    ? 'Claude đang tìm...'
                    : short.status === 'research_ready'
                      ? `${short.research?.facts.length ?? 0} chi tiết chờ duyệt`
                      : short.research
                        ? `${kept} chi tiết đã duyệt`
                        : 'Chưa có',
            state: short.status === 'researching' ? 'busy' : short.status === 'research_ready' ? 'todo' : short.research ? 'done' : 'todo',
        });
    }
    steps.push({
        key: 'script',
        title: 'Lời kể',
        summary:
            short.status === 'writing'
                ? 'Claude đang viết...'
                : hasScenes
                  ? `${short.scenes.length} cảnh${short.unsupported_count > 0 ? ` · ${short.unsupported_count} cảnh thiếu nguồn` : ''}`
                  : 'Chưa viết',
        state:
            short.status === 'writing'
                ? 'busy'
                : !hasScenes
                  ? short.use_research
                      ? 'locked'
                      : 'todo'
                  : short.unsupported_count > 0
                    ? 'warn'
                    : 'done',
    });
    steps.push({
        key: 'images',
        title: 'Ảnh',
        summary: hasScenes
            ? `${short.image_stats.ready}/${short.scenes.length} có ảnh${short.image_stats.failed > 0 ? ` · ${short.image_stats.failed} lỗi` : ''}${busyImages > 0 ? ` · ${busyImages} đang tạo` : ''}`
            : 'Chờ lời kể',
        state: !hasScenes ? 'locked' : short.all_images_ready ? 'done' : busyImages > 0 ? 'busy' : short.image_stats.failed > 0 ? 'warn' : 'todo',
    });
    steps.push({
        key: 'video',
        title: 'Video',
        summary:
            short.video_status === 'rendering'
                ? 'Đang dựng...'
                : short.video_url
                  ? 'Đã có video'
                  : short.all_images_ready
                    ? 'Sẵn sàng dựng'
                    : 'Chờ đủ ảnh',
        state: short.video_status === 'rendering' ? 'busy' : short.video_url ? 'done' : short.all_images_ready ? 'todo' : 'locked',
    });
    steps.push({
        key: 'publish',
        title: 'Đăng',
        summary:
            short.publish?.status === 'writing'
                ? 'Claude đang viết...'
                : short.publish?.title
                  ? `Có tiêu đề${short.cover_url ? ' + ảnh bìa' : ''}`
                  : 'Ảnh bìa, tiêu đề, mô tả',
        state:
            short.publish?.status === 'writing'
                ? 'busy'
                : short.publish?.status === 'failed'
                  ? 'warn'
                  : short.publish?.title && short.cover_url
                    ? 'done'
                    : hasScenes
                      ? 'todo'
                      : 'locked',
    });

    return steps;
}

/** Bước nên mở khi vào trang: bước đầu tiên chưa xong. */
function currentStep(short: TopicShort): StepKey {
    if (short.use_research && ['researching', 'research_ready'].includes(short.status)) {
        return 'research';
    }
    if (short.status !== 'ready' || short.scenes.length === 0) {
        return short.use_research && short.status === 'failed' && !short.research ? 'research' : 'script';
    }

    if (short.video_url && short.video_status !== 'rendering') {
        return 'publish';
    }

    return short.all_images_ready ? 'video' : 'images';
}

const stepStyles: Record<Step['state'], string> = {
    done: 'border-green-500 bg-green-50 dark:bg-green-950',
    busy: 'border-blue-400 bg-blue-50 dark:bg-blue-950',
    todo: 'border-primary',
    warn: 'border-amber-500 bg-amber-50 dark:bg-amber-950',
    locked: 'opacity-60',
};

function StepBar({ steps, current, onChange }: { steps: Step[]; current: StepKey; onChange: (key: StepKey) => void }) {
    return (
        <nav aria-label="Các bước" className={`grid grid-cols-2 gap-2 ${steps.length === 5 ? 'md:grid-cols-5' : 'md:grid-cols-4'}`}>
            {steps.map((st, i) => (
                <button
                    key={st.key}
                    type="button"
                    onClick={() => onChange(st.key)}
                    aria-current={current === st.key ? 'step' : undefined}
                    className={`rounded-lg border-2 p-3 text-left transition ${stepStyles[st.state]} ${current === st.key ? 'ring-primary ring-2 ring-offset-2' : 'hover:shadow-sm'}`}
                >
                    <span className="flex items-center gap-2 text-sm font-semibold">
                        <span className="bg-background flex size-6 shrink-0 items-center justify-center rounded-full border text-xs">
                            {st.state === 'done' ? (
                                <Check className="size-3.5 text-green-600" />
                            ) : st.state === 'busy' ? (
                                <Loader2 className="size-3.5 animate-spin" />
                            ) : (
                                i + 1
                            )}
                        </span>
                        {st.title}
                    </span>
                    <span className="text-muted-foreground mt-1 block text-xs">{st.summary}</span>
                </button>
            ))}
        </nav>
    );
}

function NextStep({ steps, current, onChange }: { steps: Step[]; current: StepKey; onChange: (key: StepKey) => void }) {
    const index = steps.findIndex((st) => st.key === current);
    const prev = steps[index - 1];
    const next = steps[index + 1];

    return (
        <div className="flex justify-between gap-2 border-t pt-4">
            {prev ? (
                <Button variant="outline" onClick={() => onChange(prev.key)}>
                    <ChevronLeft /> {prev.title}
                </Button>
            ) : (
                <span />
            )}
            {next && (
                <Button onClick={() => onChange(next.key)} disabled={next.state === 'locked'}>
                    Tiếp: {next.title} <ChevronRight />
                </Button>
            )}
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

const moodLabels: Record<string, string> = { tense: 'căng thẳng', sad: 'buồn', epic: 'hoành tráng', funny: 'vui', calm: 'nhẹ nhàng' };

function RenderPanel({
    short,
    music,
    builtin,
    suggestion,
    price,
    defaults,
    logoUrl,
}: {
    short: TopicShort;
    music: { id: number; name: string }[];
    builtin: { file: string; name: string }[];
    suggestion: { mood: string | null; track_id: number | null; file: string | null };
    price: number;
    defaults: { style: 'fast' | 'page'; watermark: string; logo: boolean; logo_size: number };
    logoUrl: string | null;
}) {
    // Giá trị ô nhạc: "track:<id>" (nhạc của bạn) hoặc "file:<tên file>" (nhạc có sẵn trong public/nhac).
    const { data, setData, post, processing, transform } = useForm<{
        music: string;
        music_volume: number;
        sfx: boolean;
        style: 'fast' | 'page';
        watermark: string;
        logo: boolean;
        logo_size: number;
    }>({
        music: suggestion.track_id
            ? `track:${suggestion.track_id}`
            : suggestion.file
              ? `file:${suggestion.file}`
              : builtin[0]
                ? `file:${builtin[0].file}`
                : '',
        music_volume: 12,
        sfx: true,
        style: defaults.style,
        watermark: defaults.watermark,
        logo: defaults.logo,
        logo_size: defaults.logo_size,
    });
    const logoFile = useRef<HTMLInputElement>(null);
    const uploadLogo = (logo: File | undefined) =>
        logo &&
        router.post(
            route('channel-logo.store'),
            { logo },
            { preserveScroll: true, forceFormData: true, onError: (errors) => errors.logo && alert(errors.logo) },
        );
    const removeLogo = () => confirm('Xoá logo?') && router.delete(route('channel-logo.destroy'), { preserveScroll: true });
    const rendering = short.video_status === 'rendering';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (short.unsupported_count > 0 && !confirm(`${short.unsupported_count} cảnh có câu không có trong tư liệu (đánh dấu đỏ). Vẫn dựng video?`)) {
            return;
        }
        transform(({ music, logo, ...rest }) => ({
            ...rest,
            logo: logo && !!logoUrl,
            music_track_id: music.startsWith('track:') ? Number(music.slice(6)) : null,
            music_file: music.startsWith('file:') ? music.slice(5) : null,
        }));
        post(route('topic-shorts.render', short.id), { preserveScroll: true });
    };

    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">Dựng video</h2>
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
                        value={data.music}
                        onChange={(e) => setData('music', e.target.value)}
                    >
                        <option value="">Không có nhạc</option>
                        {builtin.length > 0 && (
                            <optgroup label="Nhạc có sẵn (miễn phí bản quyền)">
                                {builtin.map((track) => (
                                    <option key={track.file} value={`file:${track.file}`}>
                                        {track.name}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        {music.length > 0 && (
                            <optgroup label="Nhạc của bạn">
                                {music.map((track) => (
                                    <option key={track.id} value={`track:${track.id}`}>
                                        {track.name}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                    </select>
                    {data.music.startsWith('file:') && (
                        <audio
                            key={data.music}
                            controls
                            preload="none"
                            className="h-8 w-56"
                            src={`/nhac/${encodeURIComponent(data.music.slice(5))}`}
                        />
                    )}
                </div>
                {data.music && (
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
                <div className="grid gap-2">
                    <Label htmlFor="style">Kiểu dựng</Label>
                    <select
                        id="style"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={data.style}
                        onChange={(e) => setData('style', e.target.value as 'fast' | 'page')}
                    >
                        <option value="fast">Cắt nhanh: mỗi khung một cảnh, chữ từng từ giữa màn hình</option>
                        <option value="page">Trang manga: cả trang lia chậm, phụ đề dưới</option>
                    </select>
                </div>
                {data.style === 'fast' && (
                    <div className="grid gap-2">
                        <Label htmlFor="watermark">Tên kênh (chữ mờ)</Label>
                        <Input
                            id="watermark"
                            className="w-44"
                            maxLength={30}
                            value={data.watermark}
                            onChange={(e) => setData('watermark', e.target.value)}
                            placeholder="WOW ANIME"
                        />
                    </div>
                )}
                <div className="grid gap-2">
                    <Label>Logo kênh (PNG, góc trên phải)</Label>
                    <div className="flex items-center gap-2">
                        {logoUrl && (
                            <img
                                src={logoUrl}
                                alt="Logo"
                                className="h-9 w-9 rounded border bg-[repeating-conic-gradient(#ddd_0_25%,#fff_0_50%)] bg-[length:10px_10px] object-contain"
                            />
                        )}
                        {logoUrl && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" className="size-4" checked={data.logo} onChange={(e) => setData('logo', e.target.checked)} />
                                Gắn logo
                            </label>
                        )}
                        <Button type="button" size="sm" variant="outline" onClick={() => logoFile.current?.click()}>
                            <Upload /> {logoUrl ? 'Đổi logo' : 'Tải logo lên'}
                        </Button>
                        {logoUrl && (
                            <Button type="button" size="sm" variant="ghost" onClick={removeLogo}>
                                Xoá
                            </Button>
                        )}
                        <input
                            ref={logoFile}
                            type="file"
                            accept="image/png"
                            className="hidden"
                            aria-label="Logo kênh"
                            onChange={(e) => {
                                uploadLogo(e.target.files?.[0]);
                                e.target.value = '';
                            }}
                        />
                    </div>
                    {logoUrl && data.logo && (
                        <div className="flex items-center gap-2 text-sm">
                            <label htmlFor="logo_size">Cỡ logo: {data.logo_size}% bề ngang</label>
                            <input
                                id="logo_size"
                                type="range"
                                min={8}
                                max={50}
                                value={data.logo_size}
                                onChange={(e) => setData('logo_size', Number(e.target.value))}
                            />
                            {/* Xem trước vị trí và cỡ logo trên khung 9:16. */}
                            <div className="relative aspect-[9/16] w-16 overflow-hidden rounded border bg-neutral-700">
                                <img
                                    src={logoUrl}
                                    alt=""
                                    className="absolute top-[2.1%] right-[3.7%] h-auto"
                                    style={{ width: `${data.logo_size}%` }}
                                />
                            </div>
                        </div>
                    )}
                </div>
                <label className="flex h-9 items-center gap-2 text-sm">
                    <input type="checkbox" className="size-4" checked={data.sfx} onChange={(e) => setData('sfx', e.target.checked)} />
                    Hiệu ứng âm thanh (đấm, lao tới, gom khí)
                </label>
                <Button disabled={processing || rendering || !short.all_images_ready || short.status !== 'ready'}>
                    {rendering ? <Loader2 className="animate-spin" /> : <Sparkles />} Đọc giọng + dựng video ({formatNumber(price)} Credits)
                </Button>
            </form>
            {suggestion.mood && (
                <p className="text-muted-foreground text-sm">
                    Cảm xúc chính: {moodLabels[suggestion.mood] ?? suggestion.mood}.{' '}
                    {suggestion.track_id || suggestion.file
                        ? 'Đã chọn sẵn bài nhạc hợp.'
                        : `Chưa có bài nhạc nào tên chứa từ "${suggestion.mood}", đặt tên bài nhạc theo cảm xúc để được gợi ý.`}
                </p>
            )}
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
