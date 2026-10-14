import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, ImagePlus, Info, Lightbulb, Loader2, Music, RefreshCw, Sparkles, X } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import SongAnalysisCard, { type Analysis } from './song-analysis';
import SongDrafts, { type Draft, type MusicProvider } from './song-drafts';
import SongPreview, { type TimedLine } from './song-preview';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tạo MV ca nhạc', href: '/music-videos/create' }];

type Idea = { title: string; message: string; story: string; mood: string; singer: string; locations: string[] };

/** Ý tưởng Claude đề xuất → nội dung ô "Ý tưởng" (vẫn sửa được). */
const ideaText = (idea: Idea) => `${idea.title}: ${idea.message}\n${idea.story}\nKhông khí: ${idea.mood}\nBối cảnh: ${idea.locations.join(', ')}`;

const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

type Song = {
    id: number;
    name: string;
    duration_ms: number | null;
    lyrics: string | null;
    url: string | null;
    vocal_status: string | null;
    vocal_error: string | null;
    lines: TimedLine[];
    language: string | null;
    mv_idea: string | null;
    singer: string | null;
    analysis: Analysis | null;
    analysis_status: string | null;
    analysis_error: string | null;
};
type Driver = { label: string; available: boolean; note: string };

type Form = {
    source: 'upload' | 'library' | 'inspire';
    song: File | null;
    audio_track_id: string;
    rights: boolean;
    song_start: string;
    song_end: string;
    lyrics: string;
    idea: string;
    singer_name: string;
    singer: string;
    singer_image: File | null;
    style_preset: string;
    style: string;
    aspect_ratio: string;
    lip_sync: string;
    driver: string;
};

const duration = (ms: number | null) => (ms ? `${Math.floor(ms / 60000)}:${String(Math.round((ms % 60000) / 1000)).padStart(2, '0')}` : '?');

const lipSyncNotes: Record<string, string> = {
    chorus: 'Ca sĩ hát nhép ở điệp khúc, đoạn verse kể chuyện bằng hình. Cân bằng giữa biểu diễn và câu chuyện.',
    full: 'Ca sĩ hát nhép mọi câu hát khi có mặt trong khung. Nhiều cảnh biểu diễn, mỗi clip hát nhép gửi kèm đúng đoạn nhạc.',
    none: 'Không ai hát trên hình, MV kể chuyện và tạo không khí. Dễ làm nhất, ít lỗi khẩu hình.',
};

export default function MusicVideoCreate({
    songs,
    sections,
    drafts,
    musicProviders,
    stylePresets,
    lipSyncModes,
    drivers,
    defaultDriver,
    maxMb,
    estimate,
}: {
    songs: Song[];
    sections: string[];
    drafts: Draft[];
    musicProviders: Record<string, MusicProvider>;
    stylePresets: Record<string, { label: string; style: string }>;
    lipSyncModes: Record<string, string>;
    drivers: Record<string, Driver>;
    defaultDriver: string | null;
    maxMb: number;
    estimate: number;
}) {
    const { data, setData, post, processing, errors, transform } = useForm<Form>({
        source: songs.length > 0 ? 'library' : 'upload',
        song: null,
        audio_track_id: songs[0] ? String(songs[0].id) : '',
        rights: false,
        song_start: '',
        song_end: '',
        lyrics: songs[0]?.lyrics ?? '',
        idea: '',
        singer_name: '',
        singer: '',
        singer_image: null,
        style_preset: 'cinematic',
        style: '',
        aspect_ratio: '16:9',
        lip_sync: 'chorus',
        driver: defaultDriver ?? '',
    });
    const errorsOf = errors as Record<string, string | undefined>;
    const selected = songs.find((song) => String(song.id) === data.audio_track_id);
    const library = data.source === 'library';
    // Đang lấy lời theo thời gian: hỏi lại danh sách bài hát vài giây một lần.
    const working = library && (selected?.vocal_status === 'pending' || ['pending', 'waiting'].includes(selected?.analysis_status ?? ''));
    usePoll(working || drafts.some((d) => d.is_busy), ['songs', 'drafts'], 5000);
    // Lời bài hát có sẵn (phụ đề YouTube / Claude phân tích): điền vào ô lời khi ô còn trống.
    useEffect(() => {
        if (library && selected) {
            // Bài mới sáng tác: điền sẵn ý tưởng MV và ca sĩ Claude viết kèm (khi ô còn trống).
            setData((current) => ({
                ...current,
                lyrics: current.lyrics.trim() ? current.lyrics : (selected.lyrics ?? ''),
                idea: current.idea.trim() ? current.idea : (selected.mv_idea ?? ''),
                singer: current.singer.trim() ? current.singer : (selected.singer ?? ''),
            }));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [library, selected?.id, selected?.lyrics]);
    const [seekTo, setSeekTo] = useState<{ at: number; n: number } | null>(null);
    const [saving, setSaving] = useState(false);
    const [songNote, setSongNote] = useState<string | null>(null);
    const seconds = (value: string) => (value.trim() === '' ? null : Number(value));
    const setSeconds = (key: 'song_start' | 'song_end', value: number | null) => setData(key, value === null ? '' : String(value));

    // Lời theo thời gian (dịch vụ audio, miễn phí): bài tải lên thì lưu vào thư viện trước rồi mới phân tích được.
    const analyze = () => {
        if (library && selected) {
            router.post(route('audio-tracks.vocals', selected.id), {}, { preserveScroll: true, preserveState: true });
            return;
        }
        if (!data.song) {
            return;
        }
        if (!data.rights) {
            setSongNote('Tick ô xác nhận quyền dùng bài hát (bên dưới) trước khi lưu bài hát.');
            return;
        }
        setSaving(true);
        setSongNote(null);
        router.post(
            route('audio-tracks.store'),
            { audio: data.song, name: data.song.name.replace(/\.[^.]+$/, ''), rights: 1 },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const saved = (page.props.songs as Song[])[0];
                    setData((current) => ({ ...current, source: 'library', song: null, audio_track_id: String(saved.id) }));
                    router.post(route('audio-tracks.vocals', saved.id), {}, { preserveScroll: true, preserveState: true });
                },
                onError: (errors) => setSongNote(Object.values(errors)[0] ?? 'Chưa lưu được bài hát.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const [ideas, setIdeas] = useState<Idea[]>([]);
    const [seen, setSeen] = useState<string[]>([]);
    const [picked, setPicked] = useState<number | null>(null);
    const [suggesting, setSuggesting] = useState(false);
    const [ideaError, setIdeaError] = useState<string | null>(null);
    const poll = useRef<number | null>(null);
    useEffect(() => () => void (poll.current && window.clearTimeout(poll.current)), []);

    // Claude đề xuất ý tưởng: gửi yêu cầu (chạy nền) rồi hỏi lại vài giây một lần.
    const suggest = async () => {
        setSuggesting(true);
        setIdeaError(null);
        const songName = data.source === 'upload' ? data.song?.name.replace(/\.[^.]+$/, '') : selected?.name;
        try {
            const response = await fetch(route('music-videos.ideas'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({
                    song_name: songName ?? null,
                    lyrics: data.lyrics,
                    style_preset: data.style_preset,
                    style: data.style,
                    aspect_ratio: data.aspect_ratio,
                    singer: data.singer,
                    previous: seen,
                    audio_track_id: library ? selected?.id : null,
                }),
            });
            const body = await response.json();
            if (!response.ok) {
                throw new Error(body.message ?? 'Không gửi được yêu cầu.');
            }
            const check = async () => {
                const result = await (await fetch(route('music-videos.ideas.show', body.token), { headers: { Accept: 'application/json' } })).json();
                if (result.status === 'pending') {
                    poll.current = window.setTimeout(check, 3000);
                    return;
                }
                setSuggesting(false);
                if (result.status === 'done') {
                    setIdeas(result.ideas);
                    setPicked(null);
                    setSeen((current) => [...current, ...result.ideas.map((idea: Idea) => idea.title)]);
                } else {
                    setIdeaError(result.error ?? 'Chưa đề xuất được ý tưởng.');
                }
            };
            await check();
        } catch (error) {
            setSuggesting(false);
            setIdeaError(error instanceof Error ? error.message : 'Chưa đề xuất được ý tưởng.');
        }
    };
    const pick = (index: number) => {
        const idea = ideas[index];
        setPicked(index);
        // Ca sĩ: chỉ điền khi ô còn trống hoặc đang là mô tả của ý tưởng chọn trước.
        setData((current) => ({
            ...current,
            idea: ideaText(idea),
            singer: !current.singer || ideas.some((other) => other.singer === current.singer) ? idea.singer : current.singer,
        }));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        transform(({ source, song, audio_track_id, ...rest }) => ({
            ...rest,
            ...(source === 'upload' ? { song } : { audio_track_id }),
            rights: rest.rights ? 1 : 0,
        }));
        post(route('music-videos.store'), { forceFormData: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tạo MV ca nhạc" />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <Heading
                    title="Tạo MV ca nhạc bằng AI"
                    description="Claude nghe lời bài hát, lên ý tưởng, ca sĩ / nhân vật, bối cảnh rồi cắt shot đúng từng câu hát. Muse vẽ khung hình và làm clip (hát nhép theo đúng đoạn nhạc). App ghép lại với bài hát gốc."
                />

                <section className="space-y-4 rounded-lg border p-4">
                    <Label className="text-base">1. Bài hát</Label>
                    <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <label className="flex items-center gap-2">
                            <input type="radio" checked={data.source === 'inspire'} onChange={() => setData('source', 'inspire')} />
                            Sáng tác bài mới từ link YouTube
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="radio" checked={data.source === 'upload'} onChange={() => setData('source', 'upload')} />
                            Tải bài hát lên
                        </label>
                        <label className={`flex items-center gap-2 ${songs.length === 0 ? 'opacity-50' : ''}`}>
                            <input
                                type="radio"
                                disabled={songs.length === 0}
                                checked={data.source === 'library'}
                                onChange={() => setData('source', 'library')}
                            />
                            Chọn trong thư viện Âm thanh ({songs.length})
                        </label>
                    </div>

                    {data.source === 'inspire' ? (
                        <SongDrafts
                            drafts={drafts}
                            providers={musicProviders}
                            maxMb={maxMb}
                            onUse={(trackId) =>
                                setData((current) => ({ ...current, source: 'library', audio_track_id: String(trackId), lyrics: '' }))
                            }
                        />
                    ) : data.source === 'upload' ? (
                        <div className="space-y-2">
                            <label className="hover:bg-muted/50 flex cursor-pointer items-center gap-3 rounded-md border border-dashed p-4 text-sm">
                                <Music className="text-muted-foreground size-5 shrink-0" />
                                <span className="min-w-0 flex-1 truncate">
                                    {data.song ? data.song.name : `Chọn file mp3, wav hoặc m4a (tối đa ${maxMb}MB)`}
                                </span>
                                <input
                                    type="file"
                                    accept=".mp3,.wav,.m4a,audio/mpeg,audio/wav,audio/mp4"
                                    className="hidden"
                                    onChange={(e) => setData('song', e.target.files?.[0] ?? null)}
                                />
                            </label>
                            <InputError message={errors.song} />
                        </div>
                    ) : (
                        <div className="space-y-2">
                            <select
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={data.audio_track_id}
                                onChange={(e) => {
                                    const song = songs.find((s) => String(s.id) === e.target.value);
                                    setData((current) => ({
                                        ...current,
                                        audio_track_id: e.target.value,
                                        lyrics: current.lyrics || song?.lyrics || '',
                                    }));
                                }}
                            >
                                {songs.map((song) => (
                                    <option key={song.id} value={song.id}>
                                        {song.name} ({duration(song.duration_ms)})
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.audio_track_id} />
                        </div>
                    )}

                    {(library ? selected?.url : data.song) && (
                        <div className="space-y-2">
                            <SongPreview
                                key={library ? `track-${selected?.id}` : `file-${data.song?.name}`}
                                source={(library ? selected?.url : data.song)!}
                                lines={library ? (selected?.lines ?? []) : []}
                                lyrics={data.lyrics}
                                start={seconds(data.song_start)}
                                end={seconds(data.song_end)}
                                onStart={(value) => setSeconds('song_start', value)}
                                onEnd={(value) => setSeconds('song_end', value)}
                                seekTo={seekTo}
                            />
                            <div className="flex flex-wrap items-center gap-3 text-xs">
                                {library && selected?.vocal_status === 'ready' ? (
                                    <span className="text-muted-foreground">
                                        Đã có lời theo thời gian ({selected.lines.length} câu ): câu đang hát sáng lên, bấm một câu để nghe từ đó.
                                    </span>
                                ) : library && selected?.vocal_status === 'pending' ? (
                                    <span className="text-muted-foreground flex items-center gap-1">
                                        <Loader2 className="size-3 animate-spin" /> Dịch vụ audio đang tách giọng và ghi lời theo thời gian (vài
                                        phút)...
                                    </span>
                                ) : (
                                    <>
                                        <Button type="button" variant="outline" size="sm" onClick={analyze} disabled={saving}>
                                            {saving ? <Loader2 className="animate-spin" /> : <Music />} Lấy lời theo thời gian
                                        </Button>
                                        <span className="text-muted-foreground">
                                            {library && selected?.vocal_status === 'failed'
                                                ? `Lần trước lỗi: ${selected.vocal_error ?? 'không rõ'}. Kiểm tra dịch vụ audio (docker compose up -d audio) rồi thử lại.`
                                                : library
                                                  ? 'Miễn phí, chạy trên máy (dịch vụ audio): tách giọng hát rồi ghi lời kèm mốc thời gian để xem lời chạy theo nhạc.'
                                                  : 'Lưu bài hát vào thư viện Âm thanh rồi tách lời kèm mốc thời gian (miễn phí, dịch vụ audio).'}
                                        </span>
                                    </>
                                )}
                                {songNote && <span className="text-destructive">{songNote}</span>}
                            </div>
                            {library && selected && (
                                <SongAnalysisCard
                                    key={`${selected.id}-${JSON.stringify(selected.analysis)}`}
                                    trackId={selected.id}
                                    analysis={selected.analysis}
                                    status={selected.analysis_status}
                                    error={selected.analysis_error}
                                    duration={(selected.duration_ms ?? 0) / 1000}
                                    sections={sections}
                                    onSeek={(at) => setSeekTo({ at, n: Date.now() })}
                                />
                            )}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="song_start">Bắt đầu từ giây (không bắt buộc)</Label>
                            <Input
                                id="song_start"
                                type="number"
                                min={0}
                                step="0.1"
                                value={data.song_start}
                                onChange={(e) => setData('song_start', e.target.value)}
                                placeholder="0"
                            />
                            <InputError message={errors.song_start} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="song_end">Kết thúc ở giây (không bắt buộc)</Label>
                            <Input
                                id="song_end"
                                type="number"
                                min={1}
                                step="0.1"
                                value={data.song_end}
                                onChange={(e) => setData('song_end', e.target.value)}
                                placeholder={selected?.duration_ms ? String(Math.round(selected.duration_ms / 1000)) : 'hết bài'}
                            />
                            <InputError message={errors.song_end} />
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Để trống thì làm MV cả bài. Thử trước với một đoạn 30–60 giây (ví dụ điệp khúc) để xem chất lượng rồi mới làm cả bài.
                    </p>

                    <div className="space-y-2">
                        <Label htmlFor="lyrics">Lời bài hát</Label>
                        <Textarea
                            id="lyrics"
                            rows={10}
                            value={data.lyrics}
                            onChange={(e) => setData('lyrics', e.target.value)}
                            placeholder={'Dán lời chuẩn, mỗi câu một dòng. Ghi [Verse], [Chorus], [Bridge] nếu có để Claude biết đâu là điệp khúc.'}
                        />
                        <p className="text-muted-foreground text-xs">
                            Dịch vụ audio tự tách giọng và ghi lời kèm mốc thời gian (miễn phí). Lời bạn dán dùng để sửa chính tả và nhận ra điệp
                            khúc.
                        </p>
                        <InputError message={errors.lyrics} />
                    </div>

                    <label className="flex items-start gap-2 text-sm">
                        <Checkbox checked={data.rights} onCheckedChange={(checked) => setData('rights', checked === true)} className="mt-0.5" />
                        Tôi là tác giả / có quyền dùng bài hát này để làm và đăng MV.
                    </label>
                    <InputError message={errors.rights} />
                </section>

                <section className="space-y-4 rounded-lg border p-4">
                    <Label className="text-base">2. Ý tưởng và ca sĩ</Label>
                    <div className="space-y-3 rounded-md border border-dashed p-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <Lightbulb className="text-muted-foreground size-5 shrink-0" />
                            <span className="min-w-0 flex-1 text-sm">
                                Chưa có ý tưởng? Claude đọc lời bài hát, tên bài, phong cách và khung hình rồi đề xuất 3 ý tưởng khác nhau.
                            </span>
                            <Button type="button" variant="outline" size="sm" onClick={suggest} disabled={suggesting}>
                                {suggesting ? <Loader2 className="animate-spin" /> : ideas.length ? <RefreshCw /> : <Sparkles />}
                                {suggesting ? 'Claude đang nghĩ...' : ideas.length ? 'Đề xuất lại' : 'Claude đề xuất ý tưởng'}
                            </Button>
                        </div>
                        {ideaError && <p className="text-destructive text-xs">{ideaError}</p>}
                        {ideas.length > 0 && (
                            <div className="grid gap-2 md:grid-cols-3">
                                {ideas.map((idea, i) => (
                                    <button
                                        key={`${idea.title}-${i}`}
                                        type="button"
                                        onClick={() => pick(i)}
                                        className={`space-y-1.5 rounded-lg border p-3 text-left text-xs transition ${picked === i ? 'border-primary bg-primary/5' : 'hover:bg-muted'}`}
                                    >
                                        <span className="flex items-center gap-1 text-sm font-medium">
                                            {picked === i && <Check className="text-primary size-4 shrink-0" />}
                                            {idea.title}
                                        </span>
                                        <span className="block italic">{idea.message}</span>
                                        <span className="text-muted-foreground block">{idea.story}</span>
                                        <span className="block">
                                            <span className="font-medium">Không khí:</span> {idea.mood}
                                        </span>
                                        <span className="block">
                                            <span className="font-medium">Ca sĩ:</span> {idea.singer}
                                        </span>
                                        <span className="block">
                                            <span className="font-medium">Bối cảnh:</span> {idea.locations.join(', ')}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {ideas.length > 0 && (
                            <p className="text-muted-foreground text-xs">Bấm một ý tưởng để điền vào ô bên dưới, sau đó sửa tuỳ ý.</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="idea">Ý tưởng / câu chuyện của MV (không bắt buộc)</Label>
                        <Textarea
                            id="idea"
                            rows={5}
                            value={data.idea}
                            onChange={(e) => setData('idea', e.target.value)}
                            placeholder="Ví dụ: Cô gái đi một mình trong thành phố mưa đêm, nhớ lại mối tình cũ qua những khung cảnh quen. Điệp khúc ánh đèn neon bừng sáng, cuối MV cô đứng trên sân thượng mỉm cười. Màu xanh ngọc và hồng tím."
                        />
                        <p className="text-muted-foreground text-xs">Để trống thì Claude tự chọn ý tưởng hợp nhất với bài hát khi lên kế hoạch.</p>
                        <InputError message={errors.idea} />
                    </div>
                    <div className="flex flex-wrap items-start gap-3">
                        <label className="hover:bg-muted/50 relative flex size-28 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-md border border-dashed">
                            {data.singer_image ? (
                                <img src={URL.createObjectURL(data.singer_image)} alt="" className="size-full object-cover" />
                            ) : (
                                <span className="text-muted-foreground flex flex-col items-center gap-1 text-center text-xs">
                                    <ImagePlus className="size-6" />
                                    Ảnh ca sĩ
                                    <br />
                                    (không bắt buộc)
                                </span>
                            )}
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                className="hidden"
                                onChange={(e) => setData('singer_image', e.target.files?.[0] ?? null)}
                            />
                        </label>
                        <div className="min-w-0 flex-1 space-y-2">
                            <div className="flex gap-2">
                                <Input
                                    placeholder="Tên ca sĩ / nhân vật chính (ví dụ: Lan)"
                                    value={data.singer_name}
                                    onChange={(e) => setData('singer_name', e.target.value)}
                                />
                                {data.singer_image && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Bỏ ảnh"
                                        onClick={() => setData('singer_image', null)}
                                    >
                                        <X />
                                    </Button>
                                )}
                            </div>
                            <Textarea
                                rows={3}
                                value={data.singer}
                                onChange={(e) => setData('singer', e.target.value)}
                                placeholder="Mô tả ca sĩ: tuổi, dáng, tóc, trang phục, phụ kiện... (có ảnh thì Claude tả theo ảnh)"
                            />
                            <InputError message={errors.singer || errors.singer_image || errors.singer_name} />
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Ảnh người thật chỉ dùng khi có sự đồng ý của người trong ảnh. Không dùng người nổi tiếng hay nhân vật có bản quyền.
                    </p>
                </section>

                <section className="space-y-4 rounded-lg border p-4">
                    <Label className="text-base">3. Hình ảnh</Label>
                    <div className="space-y-2">
                        <Label>Phong cách</Label>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {Object.entries(stylePresets).map(([key, preset]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setData('style_preset', key)}
                                    className={`rounded-lg border p-3 text-left text-sm transition ${data.style_preset === key ? 'border-primary bg-primary/5' : 'hover:bg-muted'}`}
                                >
                                    <span className="font-medium">{preset.label}</span>
                                </button>
                            ))}
                        </div>
                        <Input
                            value={data.style}
                            onChange={(e) => setData('style', e.target.value)}
                            placeholder={stylePresets[data.style_preset]?.style}
                        />
                        <p className="text-muted-foreground text-xs">Để trống thì dùng câu phong cách gợi ý ở trên (tiếng Anh).</p>
                        <InputError message={errors.style_preset || errors.style} />
                    </div>
                    <div className="space-y-2">
                        <Label>Khung hình</Label>
                        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                            <label className="flex items-center gap-2">
                                <input type="radio" checked={data.aspect_ratio === '16:9'} onChange={() => setData('aspect_ratio', '16:9')} />
                                Ngang 16:9 (YouTube, mặc định)
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="radio" checked={data.aspect_ratio === '9:16'} onChange={() => setData('aspect_ratio', '9:16')} />
                                Dọc 9:16 (TikTok, Reels, Shorts)
                            </label>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label>Hát nhép</Label>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {Object.entries(lipSyncModes).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setData('lip_sync', key)}
                                    className={`rounded-lg border p-3 text-left text-sm transition ${data.lip_sync === key ? 'border-primary bg-primary/5' : 'hover:bg-muted'}`}
                                >
                                    <span className="font-medium">{label}</span>
                                    <span className="text-muted-foreground mt-1 block text-xs">{lipSyncNotes[key]}</span>
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.lip_sync} />
                    </div>
                </section>

                <section className="space-y-3 rounded-lg border p-4">
                    <Label className="text-base">4. Công cụ tạo ảnh và clip</Label>
                    {Object.entries(drivers).map(([key, driver]) => (
                        <label
                            key={key}
                            className={`flex items-start gap-3 rounded-md border p-3 text-sm ${driver.available ? 'cursor-pointer' : 'opacity-60'} ${data.driver === key ? 'border-primary bg-primary/5' : ''}`}
                        >
                            <input
                                type="radio"
                                className="mt-1"
                                disabled={!driver.available}
                                checked={data.driver === key}
                                onChange={() => setData('driver', key)}
                            />
                            <span>
                                <span className="font-medium">{driver.label}</span>
                                <span className="text-muted-foreground block text-xs">{driver.note}</span>
                            </span>
                        </label>
                    ))}
                    <InputError message={errors.driver} />
                    {!defaultDriver && (
                        <p className="text-destructive text-xs">
                            Chưa có công cụ nào dùng được: bật Muse qua trình duyệt (MUSE_DRIVER=browser) rồi tải lại trang.
                        </p>
                    )}
                </section>

                <div className="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    Sau bước này: duyệt ý tưởng và ca sĩ → vẽ / chốt ảnh ca sĩ → Claude cắt shot theo câu hát → Muse vẽ khung hình → làm clip (hát
                    nhép) → ghép với bài hát gốc. MV 3–4 phút khoảng 35–50 shot; Muse làm mỗi clip vài phút. Bật nhãn nội dung AI khi đăng.
                </div>

                {Object.keys(errorsOf).some((key) => key.startsWith('song') || key === 'audio_track_id') && (
                    <p className="text-destructive text-sm">Kiểm tra lại phần bài hát ở trên.</p>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={processing || !data.driver}>
                        {processing ? <Loader2 className="animate-spin" /> : <Sparkles />} Lên ý tưởng MV
                    </Button>
                    <span className="text-muted-foreground text-xs">Khoảng {formatNumber(estimate)} Credits cho bước này.</span>
                </div>
            </form>
        </AppLayout>
    );
}
