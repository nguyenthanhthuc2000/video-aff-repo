import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Guitar, Loader2, Music, PenLine, Play, Save, Scissors, Sparkles, Trash2, Wand2 } from 'lucide-react';
import { useState } from 'react';
import SongAnalysisCard, { type Analysis } from '../music-videos/song-analysis';
import { type Draft, MakeMusic, type MusicProvider, SongEditor } from '../music-videos/song-drafts';
import SongPreview, { type TimedLine } from '../music-videos/song-preview';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Nhạc cho Shorts', href: '/music-studio' }];

type Band = { id: number; name: string; genre: string; era: string };
type Take = {
    id: number;
    audio_track_id: number;
    name: string | null;
    url: string | null;
    provider: string | null;
    duration_ms: number | null;
    chosen: boolean;
};
type Track = {
    id: number;
    name: string;
    url: string | null;
    duration_ms: number | null;
    vocal_status: string | null;
    lines: TimedLine[];
    analysis: Analysis | null;
    analysis_status: string | null;
    analysis_error: string | null;
};
type Song = Draft & { topic: string | null; takes: Take[]; track: Track | null; clip_count: number };
type Clip = {
    id: number;
    name: string;
    audio_track_id: number;
    song: string | null;
    start_ms: number;
    end_ms: number;
    seconds: number;
    kind: string;
};
type Suggestion = { start_ms: number; end_ms: number; kind: string; label: string };

const clock = (ms: number) => `${Math.floor(ms / 60000)}:${String(Math.floor((ms % 60000) / 1000)).padStart(2, '0')}`;

const statusLabels: Record<string, string> = {
    fetching: 'Đang đọc link tham khảo',
    profiling: 'Đang phân tích bài tham khảo',
    writing: 'Claude đang viết lời',
    ready: 'Có lời',
    failed: 'Lỗi',
};

/**
 * Nhạc cho Shorts: kho bài hát của từng ban nhạc ảo. Viết nhiều bài một lúc (Claude, bài gốc), tạo nhiều bản nhạc rồi chọn bản hay nhất,
 * nghe với lời chạy theo nhạc, gợi ý / lưu đoạn hook 15–40 giây để làm Shorts nhạc.
 */
export default function MusicStudio({
    bands,
    band,
    songs,
    clips,
    providers,
    musicDefault,
    creditsPerSong,
    credits,
    sections,
    maxMb,
}: {
    bands: Band[];
    band: Band | null;
    songs: Song[];
    clips: Clip[];
    providers: Record<string, MusicProvider>;
    musicDefault: string;
    creditsPerSong: number;
    credits: number;
    sections: string[];
    maxMb: number;
}) {
    const [selected, setSelected] = useState<number | null>(songs[0]?.id ?? null);
    const song = songs.find((s) => s.id === selected) ?? songs[0] ?? null;
    usePoll(
        songs.some((s) => s.is_busy || s.track?.vocal_status === 'pending' || ['pending', 'waiting'].includes(s.track?.analysis_status ?? '')),
        ['songs', 'clips'],
        5000,
    );

    if (!band) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Nhạc cho Shorts" />
                <div className="space-y-4 p-4">
                    <Heading title="Nhạc cho Shorts" description="Kho bài hát của các ban nhạc ảo." />
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        Chưa có ban nhạc ảo nào.{' '}
                        <Link href={route('shorts.index')} className="text-primary underline">
                            Tạo ban nhạc ở Shorts nhạc
                        </Link>{' '}
                        (hoặc thêm 3 ban nhạc mẫu) rồi quay lại đây.
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nhạc cho Shorts" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Nhạc cho Shorts"
                    description="Kho bài hát gốc của từng ban nhạc ảo: Claude viết lời, AI tạo nhạc (nhiều bản để chọn), cắt đoạn hook 15–40 giây để làm Shorts."
                />
                <FlashStatus />
                <div className="flex flex-wrap items-center gap-2">
                    {bands.map((b) => (
                        <Button key={b.id} variant={b.id === band.id ? 'default' : 'outline'} size="sm" asChild>
                            <Link href={route('music-studio.index', { band: b.id })}>
                                <Guitar /> {b.name}
                            </Link>
                        </Button>
                    ))}
                    <span className="text-muted-foreground ml-auto text-xs">
                        {band.genre} · {band.era} · {songs.length} bài · {clips.length} đoạn hook
                    </span>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <NewSongs band={band} creditsPerSong={creditsPerSong} credits={credits} />
                    <GenerateAll band={band} songs={songs} providers={providers} musicDefault={musicDefault} />
                </div>

                {songs.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                        Ban nhạc chưa có bài nào: viết bài mới ở trên.
                    </p>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-[280px_1fr]">
                        <div className="divide-y self-start rounded-xl border">
                            {songs.map((s) => (
                                <button
                                    key={s.id}
                                    type="button"
                                    onClick={() => setSelected(s.id)}
                                    className={`block w-full p-3 text-left text-sm ${song?.id === s.id ? 'bg-muted' : 'hover:bg-muted/50'}`}
                                >
                                    <span className="block truncate font-medium">{s.song?.title ?? s.topic ?? 'Bài mới'}</span>
                                    <span className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-xs">
                                        {s.is_busy && <Loader2 className="size-3 animate-spin" />}
                                        {s.music_status === 'pending'
                                            ? 'Đang tạo nhạc'
                                            : s.track
                                              ? `${s.takes.length} bản nhạc`
                                              : (statusLabels[s.status] ?? s.status)}
                                        {s.clip_count > 0 && ` · ${s.clip_count} hook`}
                                        {s.overlap?.too_similar && <Badge variant="destructive">giống tham khảo</Badge>}
                                    </span>
                                </button>
                            ))}
                        </div>
                        {song && (
                            <SongDetail
                                key={song.id}
                                song={song}
                                clips={clips.filter((c) => c.audio_track_id === song.track?.id)}
                                providers={providers}
                                musicDefault={musicDefault}
                                sections={sections}
                                maxMb={maxMb}
                            />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function NewSongs({ band, creditsPerSong, credits }: { band: Band; creditsPerSong: number; credits: number }) {
    const { data, setData, post, processing, errors, transform } = useForm({ band_id: String(band.id), topics: '', notes: '', url: '' });
    const count = Math.min(10, data.topics.split('\n').filter((l) => l.trim()).length);
    const cost = count * creditsPerSong * (data.url.trim() ? 2 : 1);
    const submit = () => {
        if (confirm(`Claude viết ${count} bài hát mới cho ${band.name}? Khoảng ${cost} Credits.`)) {
            transform((d) => ({ ...d, confirm: 1 }));
            post(route('music-studio.songs.store'), { preserveScroll: true, onSuccess: () => setData('topics', '') });
        }
    };
    return (
        <div className="space-y-2 rounded-lg border p-4 text-sm">
            <p className="flex items-center gap-2 font-medium">
                <PenLine className="size-4" /> Viết bài mới
            </p>
            <Textarea
                rows={4}
                value={data.topics}
                onChange={(e) => setData('topics', e.target.value)}
                placeholder={
                    'Mỗi dòng một bài (chủ đề), tối đa 10:\nĐêm cuối cùng ở bến cảng\nChuyến tàu đêm không trở lại\nBài ca cho những người lạc lối'
                }
            />
            <InputError message={errors.topics} />
            <Input
                value={data.notes}
                onChange={(e) => setData('notes', e.target.value)}
                placeholder="Hướng chung (không bắt buộc): lời tiếng Anh, điệp khúc hô vang, nhịp nhanh..."
            />
            <Input
                value={data.url}
                onChange={(e) => setData('url', e.target.value)}
                placeholder="Link YouTube tham khảo (không bắt buộc, chỉ để lấy cảm hứng)"
            />
            <InputError message={errors.url} />
            <div className="flex items-center gap-3">
                <Button type="button" size="sm" onClick={submit} disabled={processing || count === 0}>
                    {processing ? <Loader2 className="animate-spin" /> : <Sparkles />} Viết {count || ''} bài
                </Button>
                <span className={`text-xs ${cost > credits ? 'text-destructive' : 'text-muted-foreground'}`}>
                    Khoảng {cost} Credits (còn {credits})
                </span>
            </div>
        </div>
    );
}

function GenerateAll({
    band,
    songs,
    providers,
    musicDefault,
}: {
    band: Band;
    songs: Song[];
    providers: Record<string, MusicProvider>;
    musicDefault: string;
}) {
    const api = Object.entries(providers).filter(([, p]) => p.api && p.available);
    const [provider, setProvider] = useState(api.some(([k]) => k === musicDefault) ? musicDefault : (api[0]?.[0] ?? ''));
    const waiting = songs.filter((s) => s.status === 'ready' && !s.audio_track_id && s.music_status !== 'pending');
    const total = waiting.reduce((sum, s) => sum + (s.estimates[provider] ?? 0), 0);
    const run = () => {
        if (
            confirm(
                `Tạo nhạc cho ${waiting.length} bài bằng ${providers[provider]?.label}? Chi phí ước tính ~${total.toFixed(2)} USD (trả cho nhà cung cấp).`,
            )
        ) {
            router.post(route('music-studio.generate-all', band.id), { provider, confirm_cost: 1 }, { preserveScroll: true });
        }
    };
    return (
        <div className="space-y-2 rounded-lg border p-4 text-sm">
            <p className="flex items-center gap-2 font-medium">
                <Music className="size-4" /> Tạo nhạc hàng loạt
            </p>
            {api.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    Chưa có công cụ tạo nhạc qua API (cần khoá fal hoặc MiniMax ở Quản trị → Kết nối AI). Vẫn tạo được từng bài bằng Suno / Udio rồi
                    tải lên.
                </p>
            ) : (
                <>
                    <select
                        className="border-input bg-background h-9 w-full rounded-md border px-3"
                        value={provider}
                        onChange={(e) => setProvider(e.target.value)}
                    >
                        {api.map(([k, p]) => (
                            <option key={k} value={k}>
                                {p.label}
                            </option>
                        ))}
                    </select>
                    <p className="text-muted-foreground text-xs">{waiting.length} bài đã có lời nhưng chưa có nhạc.</p>
                    <Button type="button" size="sm" onClick={run} disabled={waiting.length === 0}>
                        <Wand2 /> Tạo nhạc {waiting.length} bài (~{total.toFixed(2)} USD)
                    </Button>
                </>
            )}
        </div>
    );
}

function SongDetail({
    song,
    clips,
    providers,
    musicDefault,
    sections,
    maxMb,
}: {
    song: Song;
    clips: Clip[];
    providers: Record<string, MusicProvider>;
    musicDefault: string;
    sections: string[];
    maxMb: number;
}) {
    const [start, setStart] = useState<number | null>(null);
    const [end, setEnd] = useState<number | null>(null);
    const [seekTo, setSeekTo] = useState<{ at: number; n: number } | null>(null);
    const [seconds, setSeconds] = useState('25');
    const [suggestions, setSuggestions] = useState<Suggestion[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [clipName, setClipName] = useState('');
    const track = song.track;

    const suggest = async () => {
        if (!track) return;
        setLoading(true);
        const response = await fetch(route('music-studio.clips.suggest', { audioTrack: track.id, seconds }), {
            headers: { Accept: 'application/json' },
        });
        setSuggestions(response.ok ? (await response.json()).suggestions : []);
        setLoading(false);
    };
    const saveClip = (name: string, startMs: number, endMs: number, kind = 'custom') =>
        track &&
        router.post(
            route('music-studio.clips.store', track.id),
            { name, start_ms: startMs, end_ms: endMs, kind },
            { preserveScroll: true, onSuccess: () => setClipName('') },
        );
    const pick = (s: Suggestion) => {
        setStart(s.start_ms / 1000);
        setEnd(s.end_ms / 1000);
        setSeekTo({ at: s.start_ms / 1000, n: Date.now() });
    };

    return (
        <div className="min-w-0 space-y-4 rounded-xl border p-4 text-sm">
            <div>
                <p className="text-base font-medium">{song.song?.title ?? song.topic ?? 'Bài mới'}</p>
                <p className="text-muted-foreground text-xs">
                    Chủ đề: {song.topic ?? '—'}
                    {song.reference && ` · Tham khảo: ${song.reference.title}`}
                </p>
            </div>
            {song.is_busy && song.music_status !== 'pending' && (
                <p className="text-muted-foreground flex items-center gap-2 text-xs">
                    <Loader2 className="size-4 animate-spin" /> {statusLabels[song.status] ?? 'Đang xử lý'}...
                </p>
            )}
            {song.error && <p className="text-destructive text-xs">{song.error}</p>}

            {track && (
                <section className="space-y-3">
                    <SongPreview
                        key={track.id}
                        source={track.url!}
                        lines={track.lines}
                        lyrics={song.song?.lyrics ?? ''}
                        start={start}
                        end={end}
                        onStart={setStart}
                        onEnd={setEnd}
                        seekTo={seekTo}
                    />
                    {track.vocal_status !== 'ready' && (
                        <p className="text-muted-foreground flex items-center gap-2 text-xs">
                            {track.vocal_status === 'pending' ? (
                                <>
                                    <Loader2 className="size-3 animate-spin" /> Đang lấy lời theo thời gian (dịch vụ audio)...
                                </>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post(route('audio-tracks.vocals', track.id), {}, { preserveScroll: true })}
                                >
                                    <Music /> Lấy lời theo thời gian
                                </Button>
                            )}
                        </p>
                    )}

                    <div className="space-y-2 rounded-md border p-3">
                        <p className="flex items-center gap-2 font-medium">
                            <Scissors className="size-4" /> Đoạn hook cho Shorts
                        </p>
                        <div className="flex flex-wrap items-center gap-2 text-xs">
                            Dài
                            <Input
                                type="number"
                                min={15}
                                max={40}
                                className="h-8 w-20"
                                value={seconds}
                                onChange={(e) => setSeconds(e.target.value)}
                            />
                            giây
                            <Button type="button" variant="outline" size="sm" onClick={suggest} disabled={loading}>
                                {loading ? <Loader2 className="animate-spin" /> : <Sparkles />} Gợi ý đoạn hook
                            </Button>
                        </div>
                        {suggestions?.length === 0 && (
                            <p className="text-muted-foreground text-xs">
                                Chưa gợi ý được: lấy lời theo thời gian hoặc phân tích bài trước, hoặc chọn tay trên trình nghe.
                            </p>
                        )}
                        {suggestions?.map((s, i) => (
                            <div key={i} className="flex flex-wrap items-center gap-2 text-xs">
                                <span className="font-mono">
                                    {clock(s.start_ms)}–{clock(s.end_ms)}
                                </span>
                                <span className="min-w-0 flex-1 truncate">{s.label}</span>
                                <Button type="button" variant="ghost" size="sm" onClick={() => pick(s)}>
                                    <Play /> Nghe
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => saveClip(s.label.split(':')[0], s.start_ms, s.end_ms, s.kind)}
                                >
                                    <Save /> Lưu
                                </Button>
                            </div>
                        ))}
                        <div className="flex flex-wrap items-center gap-2 text-xs">
                            <Input
                                className="h-8 max-w-56"
                                value={clipName}
                                onChange={(e) => setClipName(e.target.value)}
                                placeholder="Tên đoạn (ví dụ Điệp khúc chính)"
                            />
                            <Button
                                type="button"
                                size="sm"
                                disabled={start === null || end === null || !clipName.trim()}
                                onClick={() => saveClip(clipName.trim(), Math.round((start ?? 0) * 1000), Math.round((end ?? 0) * 1000))}
                            >
                                <Save /> Lưu đoạn đang chọn {start !== null && end !== null && `(${Math.round(end - start)}s)`}
                            </Button>
                            <span className="text-muted-foreground">Đặt điểm bắt đầu / kết thúc trên trình nghe ở trên.</span>
                        </div>
                        {clips.length > 0 && (
                            <div className="divide-y rounded-md border">
                                {clips.map((c) => (
                                    <div key={c.id} className="flex items-center gap-2 p-2 text-xs">
                                        <Check className="size-3 text-green-600" />
                                        <span className="font-medium">{c.name}</span>
                                        <span className="text-muted-foreground font-mono">
                                            {clock(c.start_ms)}–{clock(c.end_ms)} ({c.seconds}s)
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="ml-auto"
                                            aria-label="Nghe"
                                            onClick={() => setSeekTo({ at: c.start_ms / 1000, n: Date.now() })}
                                        >
                                            <Play />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Xoá"
                                            className="text-destructive"
                                            onClick={() => router.delete(route('music-studio.clips.destroy', c.id), { preserveScroll: true })}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                        {clips.length > 0 && (
                            <p className="text-muted-foreground text-xs">
                                Đoạn đã lưu hiện ở{' '}
                                <Link href={route('shorts.index')} className="text-primary underline">
                                    Shorts nhạc
                                </Link>{' '}
                                → Tạo hàng loạt → "Đoạn hook đã lưu".
                            </p>
                        )}
                    </div>

                    <SongAnalysisCard
                        key={`${track.id}-${JSON.stringify(track.analysis)}`}
                        trackId={track.id}
                        analysis={track.analysis}
                        status={track.analysis_status}
                        error={track.analysis_error}
                        duration={(track.duration_ms ?? 0) / 1000}
                        sections={sections}
                        onSeek={(at) => setSeekTo({ at, n: Date.now() })}
                    />
                </section>
            )}

            {song.takes.length > 0 && (
                <section className="space-y-2">
                    <p className="font-medium">Các bản nhạc ({song.takes.length})</p>
                    {song.takes.map((t) => (
                        <div
                            key={t.id}
                            className={`flex flex-wrap items-center gap-2 rounded-md border p-2 text-xs ${t.chosen ? 'border-primary' : ''}`}
                        >
                            <span className="min-w-0 flex-1 truncate font-medium">
                                {t.name} <span className="text-muted-foreground">· {t.provider}</span>
                            </span>
                            {t.url && <audio controls preload="none" src={t.url} className="h-8 max-w-64" />}
                            {t.chosen ? (
                                <Badge>Đang dùng</Badge>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            route('music-studio.takes.choose', { songDraft: song.id, take: t.id }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Chọn bản này
                                </Button>
                            )}
                        </div>
                    ))}
                </section>
            )}

            {song.status === 'ready' && <MakeMusic draft={song} providers={providers} maxMb={maxMb} defaultProvider={musicDefault} allowMore />}
            {song.song && song.status !== 'writing' && <SongEditor key={JSON.stringify(song.song)} draft={song} />}
        </div>
    );
}
