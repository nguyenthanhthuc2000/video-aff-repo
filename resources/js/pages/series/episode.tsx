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
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, Loader2, Lock, Save, UserRound } from 'lucide-react';
import { type FormEvent } from 'react';
import EpisodePackagePanel, { type EpisodePackage } from './episode-package';
import EpisodeRender, { type EpisodeDubInfo, type EpisodeRenderInfo, type EpisodeVersion } from './episode-render';
import EpisodeScorePanel, { type EpisodeScoreInfo } from './episode-score';
import EpisodeShots, { type Sequence, type Shot } from './episode-shots';
import EpisodeSoundsPanel, { type EpisodeSounds } from './episode-sounds';
import EpisodeTitlesPanel, { type EpisodeTitles } from './episode-titles';

interface EpisodeDetail {
    id: number;
    number: number;
    title: string;
    synopsis: string;
    lesson: string | null;
    kind: string | null;
    brief: string | null;
    package: EpisodePackage | null;
    character_ids: number[];
    status: string;
    voice_engine: string;
    render: EpisodeRenderInfo;
    render_vi: EpisodeDubInfo;
    renders: EpisodeVersion[];
    score: EpisodeScoreInfo;
    sounds: EpisodeSounds;
    titles: EpisodeTitles;
    status_label: string;
    error: string | null;
    summary: string | null;
    duration_ms: number;
    sequences: Sequence[];
    written: number;
    can_resume: boolean;
    shots: Shot[];
}

interface CharacterItem {
    id: number;
    name: string;
    role_label: string;
    image_url: string | null;
    locked: boolean;
}

type EpisodeNav = { id: number; number: number; title: string } | null;

export default function SeriesEpisode({
    series,
    episode,
    characters,
    cameras,
    cameraMoves,
    kinds,
    voiceEngines,
    dubVoices,
    aspects,
    musicTracks,
    pricing,
    previous,
    next,
}: {
    series: {
        id: number;
        title: string;
        silent: boolean;
        music_video: boolean;
        vertical: boolean;
        clip_budget: number | null;
        episode_seconds: number;
        episode_count: number;
    };
    episode: EpisodeDetail;
    characters: CharacterItem[];
    cameras: string[];
    cameraMoves: Record<string, string>;
    kinds: Record<string, string>;
    voiceEngines: Record<string, string>;
    musicTracks: { id: number; name: string }[];
    dubVoices: Record<string, string>;
    aspects: Record<string, string>;
    pricing: { write: number; image: number; rewrite: number; clip: number; render: number; sounds: number; translate: number; titles: number };
    previous: EpisodeNav;
    next: EpisodeNav;
}) {
    usePoll(
        episode.status === 'writing' ||
            episode.render.status === 'rendering' ||
            episode.render_vi.status === 'rendering' ||
            episode.sounds.status === 'planning' ||
            episode.score.status === 'composing' ||
            episode.titles.status === 'suggesting' ||
            episode.renders.some((render) => render.art_status === 'pending') ||
            episode.shots.some((shot) => shot.image_status === 'pending' || shot.clip_status === 'pending' || shot.audio_pending),
        ['episode'],
        5000,
    );
    const breadcrumbs: BreadcrumbItem[] = [
        series.music_video ? { title: 'MV ca nhạc', href: '/music-videos' } : { title: 'Phim hoạt hình', href: '/series' },
        { title: series.title, href: route('series.show', series.id) },
        { title: `Tập ${episode.number}`, href: route('series.episodes.show', { series: series.id, episode: episode.id }) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Tập ${episode.number}: ${episode.title}`} />
            <div className="max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Heading
                            title={`Tập ${episode.number}: ${episode.title}`}
                            description={`${series.title} · khoảng ${series.episode_seconds < 60 ? `${series.episode_seconds} giây` : `${Math.round(series.episode_seconds / 60)} phút`}`}
                        />
                        <Badge variant={episode.status === 'failed' ? 'destructive' : 'secondary'}>{episode.status_label}</Badge>
                    </div>
                    <div className="flex gap-2">
                        <EpisodeLink series={series.id} episode={previous} direction="previous" />
                        <EpisodeLink series={series.id} episode={next} direction="next" />
                    </div>
                </div>
                <FlashStatus />
                {episode.error && (
                    <p className="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border px-4 py-3 text-sm">{episode.error}</p>
                )}

                <EpisodeForm
                    key={`${episode.id}-${episode.title}`}
                    seriesId={series.id}
                    episode={episode}
                    characters={characters}
                    silent={series.silent}
                    kinds={kinds}
                />

                <EpisodeShots
                    base={{ series: series.id, episode: episode.id }}
                    status={episode.status}
                    sequences={episode.sequences}
                    written={episode.written}
                    canResume={episode.can_resume}
                    shots={episode.shots}
                    characters={characters}
                    cameras={cameras}
                    cameraMoves={cameraMoves}
                    silent={series.silent}
                    vertical={series.vertical}
                    clipBudget={series.clip_budget}
                    voiceEngine={episode.voice_engine}
                    voiceEngines={voiceEngines}
                    pricing={pricing}
                    episodeSeconds={series.episode_seconds}
                />

                <EpisodeTitlesPanel
                    base={{ series: series.id, episode: episode.id }}
                    titles={episode.titles}
                    current={episode.title}
                    hasShots={episode.shots.length > 0}
                    price={pricing.titles}
                />

                {episode.package && <EpisodePackagePanel pack={episode.package} />}

                <EpisodeSoundsPanel
                    base={{ series: series.id, episode: episode.id }}
                    sounds={episode.sounds}
                    hasShots={episode.shots.length > 0}
                    price={pricing.sounds}
                />

                <EpisodeScorePanel
                    base={{ series: series.id, episode: episode.id }}
                    score={episode.score}
                    hasShots={episode.shots.length > 0}
                    price={pricing.sounds}
                />

                <EpisodeRender
                    base={{ series: series.id, episode: episode.id }}
                    render={episode.render}
                    dub={episode.render_vi}
                    renders={episode.renders}
                    dubVoices={dubVoices}
                    aspects={aspects}
                    translatePrice={pricing.translate}
                    imagePrice={pricing.image}
                    titlePrice={pricing.titles}
                    titleIdeas={episode.titles.ideas}
                    shots={episode.shots}
                    musicTracks={musicTracks}
                    price={pricing.render}
                    soundCount={episode.sounds.items.length}
                    soundsReady={episode.sounds.library_ready}
                    soundsPlanning={episode.sounds.status === 'planning'}
                    soundPrice={pricing.sounds}
                    scoreReady={episode.score.cues.length > 0}
                    silent={series.silent}
                />

                {episode.summary && (
                    <section className="space-y-2">
                        <h2 className="text-lg font-semibold">Tóm tắt sau tập (sổ liên tục)</h2>
                        <p className="text-sm whitespace-pre-line">{episode.summary}</p>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function EpisodeLink({ series, episode, direction }: { series: number; episode: EpisodeNav; direction: 'previous' | 'next' }) {
    const label = direction === 'previous' ? 'Tập trước' : 'Tập sau';
    if (!episode) {
        return (
            <Button variant="outline" size="sm" disabled>
                {direction === 'previous' && <ChevronLeft />} {label} {direction === 'next' && <ChevronRight />}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild title={episode.title}>
            <Link href={route('series.episodes.show', { series, episode: episode.id })}>
                {direction === 'previous' && <ChevronLeft />} {label} {episode.number} {direction === 'next' && <ChevronRight />}
            </Link>
        </Button>
    );
}

function EpisodeForm({
    seriesId,
    episode,
    characters,
    silent,
    kinds,
}: {
    seriesId: number;
    episode: EpisodeDetail;
    characters: CharacterItem[];
    silent: boolean;
    kinds: Record<string, string>;
}) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        title: episode.title,
        synopsis: episode.synopsis,
        lesson: episode.lesson ?? '',
        character_ids: episode.character_ids,
        ...(silent ? { kind: episode.kind ?? '', brief: episode.brief ?? '' } : {}),
    });
    const form = data as typeof data & { kind?: string; brief?: string };

    const toggle = (id: number) =>
        setData('character_ids', data.character_ids.includes(id) ? data.character_ids.filter((item) => item !== id) : [...data.character_ids, id]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('series.episodes.update', { series: seriesId, episode: episode.id }), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-5 rounded-xl border p-4">
            <div className="space-y-2">
                <Label htmlFor="title">Tên tập</Label>
                <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                <InputError message={errors.title} />
            </div>
            <div className="space-y-2">
                <Label htmlFor="synopsis">Tóm tắt</Label>
                <Textarea id="synopsis" rows={4} value={data.synopsis} onChange={(e) => setData('synopsis', e.target.value)} />
                <InputError message={errors.synopsis} />
            </div>
            <div className={silent ? 'grid gap-4 sm:grid-cols-[1fr_200px]' : 'space-y-2'}>
                <div className="space-y-2">
                    <Label htmlFor="lesson">{silent ? 'Cảm xúc / thông điệp của tập' : 'Bài học / kiến thức của tập'}</Label>
                    <Input id="lesson" value={data.lesson} onChange={(e) => setData('lesson', e.target.value)} />
                    <InputError message={errors.lesson} />
                </div>
                {silent && (
                    <div className="space-y-2">
                        <Label htmlFor="kind">Thể loại</Label>
                        <select
                            id="kind"
                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                            value={form.kind ?? ''}
                            onChange={(e) => setData((current) => ({ ...current, kind: e.target.value }))}
                        >
                            <option value="">Chưa chọn</option>
                            {Object.entries(kinds).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
            </div>
            {silent && (
                <div className="space-y-2">
                    <Label htmlFor="brief">Kịch bản có sẵn (không bắt buộc)</Label>
                    <Textarea
                        id="brief"
                        rows={form.brief ? 8 : 3}
                        value={form.brief ?? ''}
                        onChange={(e) => setData((current) => ({ ...current, brief: e.target.value }))}
                        placeholder="Dán kịch bản của tập (bảng cảnh, thời gian, hành động, âm thanh, prompt ảnh / video). Claude viết shot bám theo kịch bản này."
                        className="font-mono text-xs"
                    />
                    <InputError message={(errors as Record<string, string>).brief} />
                </div>
            )}

            <div className="space-y-2">
                <Label>Nhân vật xuất hiện trong tập</Label>
                <p className="text-muted-foreground text-xs">Bấm để chọn. Để trống thì Claude tự chọn nhân vật theo tóm tắt khi viết tập.</p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    {characters.map((character) => {
                        const selected = data.character_ids.includes(character.id);
                        return (
                            <button
                                key={character.id}
                                type="button"
                                onClick={() => toggle(character.id)}
                                className={cn(
                                    'flex items-center gap-2 rounded-lg border p-2 text-left text-sm transition',
                                    selected ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                                )}
                            >
                                {character.image_url ? (
                                    <img src={character.image_url} alt="" className="size-10 shrink-0 rounded-md border object-cover" />
                                ) : (
                                    <span className="bg-muted flex size-10 shrink-0 items-center justify-center rounded-md">
                                        <UserRound className="text-muted-foreground size-4" />
                                    </span>
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-center gap-1 truncate font-medium">
                                        {character.name}
                                        {character.locked && <Lock className="text-primary size-3" />}
                                    </span>
                                    <span className="text-muted-foreground block truncate text-xs">{character.role_label}</span>
                                </span>
                                {selected && <Check className="text-primary size-4 shrink-0" />}
                            </button>
                        );
                    })}
                </div>
                <InputError message={(errors as Record<string, string>)['character_ids.0']} />
            </div>

            <Button type="submit" disabled={processing || !isDirty}>
                {processing ? <Loader2 className="animate-spin" /> : <Save />} Lưu tập
            </Button>
        </form>
    );
}
