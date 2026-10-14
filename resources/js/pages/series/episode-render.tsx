import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/pricing';
import { router } from '@inertiajs/react';
import { Captions, Clapperboard, Download, Image as ImageIcon, Languages, Loader2, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { type Shot } from './episode-shots';
import { type TitleIdea } from './episode-titles';

export interface EpisodeRenderInfo {
    status: 'rendering' | 'done' | 'failed' | null;
    progress: number;
    error: string | null;
    url: string | null;
    rendered_at: string | null;
    options: RenderOptions;
}

export interface EpisodeDubInfo {
    status: 'rendering' | 'done' | 'failed' | null;
    progress: number;
    error: string | null;
    url: string | null;
    rendered_at: string | null;
    /** Bước đang làm (dịch lời, lồng tiếng N/M shot). */
    stage: string | null;
    voice: string;
    vertical: boolean;
    needs_translation: boolean;
    title: string | null;
}

interface RenderOptions {
    title_card: boolean;
    end_card: boolean;
    burn_subtitles: boolean;
    music_track_id: number | null;
    original_score: boolean;
    music_volume: number;
    sound_effects: boolean;
    aspect: string;
    dialogue: boolean;
}

/** Ghép cả tập: thẻ tiêu đề, mọi shot theo thứ tự, thẻ kết, nhạc nền, phụ đề; xem và tải video + .srt. */
export default function EpisodeRender({
    base,
    render,
    shots,
    musicTracks,
    price,
    soundCount,
    soundsReady,
    soundsPlanning,
    soundPrice,
    dub,
    renders,
    dubVoices,
    aspects,
    translatePrice,
    imagePrice = 0,
    titlePrice = 0,
    titleIdeas = [],
    scoreReady,
    silent = false,
}: {
    /** Truyện ngắn không thoại: không có tuỳ chọn lời / phụ đề / bản tiếng Việt; shot chưa có clip là ảnh tĩnh + chuyển động máy. */
    silent?: boolean;
    base: { series: number; episode: number };
    render: EpisodeRenderInfo;
    dub: EpisodeDubInfo;
    renders: EpisodeVersion[];
    dubVoices: Record<string, string>;
    aspects: Record<string, string>;
    translatePrice: number;
    /** Giá Muse vẽ ảnh bìa riêng. */
    imagePrice?: number;
    /** Giá Claude gợi ý tiêu đề (khi vẽ ảnh bìa mà chưa có chữ). */
    titlePrice?: number;
    /** Tiêu đề Claude gợi ý: chữ ngắn để chọn nhanh cho ảnh bìa. */
    titleIdeas?: TitleIdea[];
    shots: Shot[];
    musicTracks: { id: number; name: string }[];
    price: number;
    soundCount: number;
    /** Có kho âm thanh: bật hiệu ứng mà chưa có danh sách thì Claude tự chọn khi ghép. */
    soundsReady: boolean;
    soundsPlanning: boolean;
    soundPrice: number;
    /** Đã có nhạc riêng của tập (chưa có thì Claude sáng tác khi ghép). */
    scoreReady: boolean;
}) {
    const [options, setOptions] = useState<RenderOptions>(render.options);
    const vertical = options.aspect.startsWith('9:16');
    const totalSeconds = Math.round(
        (shots.reduce((sum, shot) => sum + shot.duration_ms, 0) + (options.title_card ? 3500 : 0) + (options.end_card ? 3000 : 0)) / 1000,
    );
    const set = <K extends keyof RenderOptions>(key: K, value: RenderOptions[K]) => setOptions((current) => ({ ...current, [key]: value }));
    const rendering = render.status === 'rendering';
    const withVideo = shots.filter((shot) => shot.video_url).length;
    const stills = shots.filter((shot) => !shot.video_url && shot.image_url).length;
    const blocked = shots.filter((shot) => !shot.video_url && !shot.image_url).length;
    const busy = shots.some((shot) => shot.clip_status === 'pending');
    const withSounds = options.sound_effects && (soundCount > 0 || soundsReady);
    // Chưa có danh sách âm thanh: Claude chọn ngay trong lượt ghép.
    const autoSounds = withSounds && soundCount === 0;
    const autoScore = options.original_score && !scoreReady;
    const total = price + (autoSounds ? soundPrice : 0) + (autoScore ? soundPrice : 0);

    const [dubVoice, setDubVoice] = useState(dub.voice);
    const dubbing = dub.status === 'rendering';
    const dubTotal = total + (dub.needs_translation ? translatePrice : 0);
    const startDub = () => {
        const steps = [
            dub.needs_translation ? 'Claude dịch lời sang tiếng Việt' : null,
            'lồng tiếng Việt từng shot (miễn phí, chạy trên máy)',
            'ghép tập với phụ đề tiếng Anh chèn vào hình',
        ].filter(Boolean);
        const slow = dubVoice === 'character' ? '\nĐổi sang giọng riêng từng nhân vật mất khoảng 30–60 phút.' : '';
        if (
            confirm(
                `Xuất bản tiếng Việt, phụ đề tiếng Anh (${shots.length} shot), khoảng ${formatNumber(dubTotal)} Credits:\n- ${steps.join('\n- ')}${slow}\nTiếp tục?`,
            )
        ) {
            router.post(
                route('series.episodes.render', base),
                { ...options, dialogue: true, sound_effects: withSounds, lang: 'vi', vi_voice: dubVoice },
                { preserveScroll: true },
            );
        }
    };

    const start = () => {
        const note = stills > 0 ? `\n${stills} shot chưa có video sẽ dùng ${silent ? 'ảnh tĩnh + chuyển động máy' : 'khung hình + lời thoại'}.` : '';
        const sounds = autoSounds
            ? '\nClaude chọn âm thanh hiệu ứng cho từng shot rồi ghép luôn.'
            : withSounds
              ? `\nKèm ${soundCount} âm thanh hiệu ứng.`
              : '';
        const score = autoScore
            ? '\nClaude sáng tác nhạc riêng cho tập trước khi ghép.'
            : options.original_score
              ? '\nNhạc nền: nhạc riêng của tập.'
              : '';
        if (
            confirm(
                `Ghép cả tập (${shots.length} shot), khoảng ${formatNumber(total)} Credits.${sounds}${score}${note}\nMỗi bản xuất kèm một ảnh bìa YouTube.\nTiếp tục?`,
            )
        ) {
            router.post(route('series.episodes.render', base), { ...options, sound_effects: withSounds }, { preserveScroll: true });
        }
    };

    if (shots.length === 0) {
        return null;
    }

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <Clapperboard className="size-5" /> Ghép cả tập
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        {withVideo}/{shots.length} shot có video
                        {stills > 0 && ` · ${stills} shot sẽ dùng ${silent ? 'ảnh tĩnh + chuyển động máy' : 'khung hình + lời thoại'}`}
                        {blocked > 0 && ` · ${blocked} shot chưa có khung hình (cần vẽ trước)`}
                    </p>
                </div>
                <Button onClick={start} disabled={rendering || busy || blocked > 0}>
                    {rendering ? <Loader2 className="animate-spin" /> : <Clapperboard />}
                    {rendering
                        ? soundsPlanning
                            ? 'Claude đang chọn âm thanh...'
                            : `Đang ghép ${render.progress}%`
                        : `${render.url ? 'Ghép thêm bản mới' : 'Ghép cả tập'}${withSounds ? ' + âm thanh' : ''}`}
                </Button>
            </div>

            <div className="grid gap-3 text-sm sm:grid-cols-2">
                <label className="flex items-center gap-2">
                    Khung hình
                    <select
                        className="border-input bg-background h-8 min-w-0 flex-1 rounded-md border px-2"
                        value={options.aspect}
                        disabled={rendering}
                        onChange={(e) => set('aspect', e.target.value)}
                    >
                        {Object.entries(aspects).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className={silent ? 'hidden' : 'flex items-center gap-2'}>
                    <input type="checkbox" checked={!options.dialogue} onChange={(e) => set('dialogue', !e.target.checked)} disabled={rendering} />
                    Không lời thoại (chỉ hình, nhạc nền, âm thanh hiệu ứng; không chèn phụ đề)
                </label>
                {vertical && totalSeconds > 180 && (
                    <p className="text-xs text-amber-600 sm:col-span-2 dark:text-amber-400">
                        Tập dài khoảng {Math.round(totalSeconds / 60)} phút: YouTube Shorts tối đa 3 phút, video dài hơn sẽ thành video thường.
                    </p>
                )}
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={options.title_card} onChange={(e) => set('title_card', e.target.checked)} disabled={rendering} />
                    Thẻ tiêu đề đầu tập (tên phim + tên tập){silent && ' (không nên: 3 giây đầu là hook)'}
                </label>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={options.end_card} onChange={(e) => set('end_card', e.target.checked)} disabled={rendering} />
                    Thẻ kết ("Thanks for watching!")
                </label>
                <label className={silent ? 'hidden' : 'flex items-center gap-2'}>
                    <input
                        type="checkbox"
                        checked={options.burn_subtitles && options.dialogue}
                        onChange={(e) => set('burn_subtitles', e.target.checked)}
                        disabled={rendering || !options.dialogue}
                    />
                    Chèn phụ đề vào hình (luôn có file .srt riêng để tải lên YouTube)
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={withSounds}
                        onChange={(e) => set('sound_effects', e.target.checked)}
                        disabled={rendering || (soundCount === 0 && !soundsReady)}
                    />
                    Âm thanh hiệu ứng{' '}
                    {soundCount > 0
                        ? `(${soundCount} âm thanh)`
                        : soundsReady
                          ? `(Claude tự chọn khi ghép, thêm ~${formatNumber(soundPrice)} Credits)`
                          : '(chưa có kho âm thanh)'}
                </label>
                <div className="flex items-center gap-2">
                    <select
                        aria-label="Nhạc nền"
                        className="border-input bg-background h-8 min-w-0 flex-1 rounded-md border px-2"
                        value={options.original_score ? 'score' : (options.music_track_id ?? '')}
                        disabled={rendering}
                        onChange={(e) =>
                            setOptions((current) => ({
                                ...current,
                                original_score: e.target.value === 'score',
                                music_track_id: e.target.value === '' || e.target.value === 'score' ? null : Number(e.target.value),
                            }))
                        }
                    >
                        <option value="">Không nhạc nền</option>
                        <option value="score">
                            Nhạc riêng của tập (Claude sáng tác){scoreReady ? '' : ` · thêm ~${formatNumber(soundPrice)} Credits`}
                        </option>
                        {musicTracks.map((track) => (
                            <option key={track.id} value={track.id}>
                                Nhạc: {track.name}
                            </option>
                        ))}
                    </select>
                    {(options.music_track_id !== null || options.original_score) && (
                        <label className="flex items-center gap-1 text-xs whitespace-nowrap">
                            Âm lượng
                            <input
                                type="range"
                                min={0}
                                max={40}
                                value={options.music_volume}
                                onChange={(e) => set('music_volume', Number(e.target.value))}
                                disabled={rendering}
                            />
                            {options.music_volume}%
                        </label>
                    )}
                </div>
            </div>
            {musicTracks.length === 0 && !options.original_score && (
                <p className="text-muted-foreground text-xs">
                    Chọn "Nhạc riêng của tập" để Claude sáng tác, hoặc thêm nhạc có quyền dùng ở mục Âm thanh.
                </p>
            )}

            {rendering && (
                <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                    <div className="bg-primary h-full transition-all" style={{ width: `${render.progress}%` }} />
                </div>
            )}
            {render.status === 'failed' && render.error && <p className="text-destructive text-sm">Ghép lỗi: {render.error}</p>}

            <div className={silent ? 'hidden' : 'space-y-3 border-t pt-4'}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 className="flex items-center gap-2 font-semibold">
                            <Languages className="size-5" /> Bản tiếng Việt · phụ đề tiếng Anh
                        </h3>
                        <p className="text-muted-foreground text-sm">
                            Claude dịch lời sang tiếng Việt, lồng tiếng từng câu đúng chỗ nhân vật nói, phụ đề tiếng Anh chèn vào hình. Dùng chung thẻ
                            tiêu đề, nhạc nền, âm thanh hiệu ứng ở trên.
                            {dub.title && ` Tên tập: “${dub.title}”.`}
                        </p>
                    </div>
                    <Button onClick={startDub} disabled={dubbing || busy || blocked > 0}>
                        {dubbing ? <Loader2 className="animate-spin" /> : <Languages />}
                        {dubbing ? (dub.stage ?? `Đang ghép ${dub.progress}%`) : dub.url ? 'Xuất thêm bản tiếng Việt' : 'Xuất bản tiếng Việt'}
                    </Button>
                </div>
                <label className="flex flex-wrap items-center gap-2 text-sm">
                    Giọng lồng tiếng
                    <select
                        className="border-input bg-background h-8 rounded-md border px-2"
                        value={dubVoice}
                        disabled={dubbing}
                        onChange={(e) => setDubVoice(e.target.value)}
                    >
                        {Object.entries(dubVoices).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted-foreground text-xs">
                        ~{formatNumber(dubTotal)} Credits{dub.needs_translation ? ' (gồm dịch lời)' : ''}
                    </span>
                </label>
                {dubbing && (
                    <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                        <div className="bg-primary h-full transition-all" style={{ width: `${dub.progress}%` }} />
                    </div>
                )}
                {dub.status === 'failed' && dub.error && <p className="text-destructive text-sm">Xuất bản tiếng Việt lỗi: {dub.error}</p>}
            </div>

            <EpisodeVersions base={base} renders={renders} shots={shots} imagePrice={imagePrice} titlePrice={titlePrice} titleIdeas={titleIdeas} />
        </section>
    );
}

export interface EpisodeVersion {
    id: number;
    lang: 'en' | 'vi';
    label: string;
    vertical: boolean;
    url: string | null;
    has_srt: boolean;
    duration_ms: number;
    created_at: string;
    thumbnail_url: string | null;
    thumbnail_shot_id: number | null;
    thumbnail_text: string | null;
    thumbnail_art: boolean;
    art_url: string | null;
    art_status: 'pending' | 'ready' | 'failed' | null;
    art_error: string | null;
}

/** Chữ trống hoặc chữ tạm ("[Episode Title]", "Untitled"...): AI vẽ ảnh bìa sẽ gợi ý tiêu đề trước. */
const placeholder = (text: string) => /^(\[.*\]|untitled|episode\s*\d*|tập\s*\d*|tbd|title)?$/i.test(text.trim());

/** Ảnh bìa YouTube của một bản xuất: xem, tải, tạo lại với khung hình / chữ khác, hoặc để Muse vẽ riêng ảnh nền (AI). */
function VersionThumbnail({
    base,
    render,
    shots,
    imagePrice,
    titlePrice,
    titleIdeas,
}: {
    base: { series: number; episode: number };
    render: EpisodeVersion;
    shots: Shot[];
    imagePrice: number;
    titlePrice: number;
    titleIdeas: TitleIdea[];
}) {
    const [shot, setShot] = useState<string>(
        render.thumbnail_art && render.art_url ? 'art' : render.thumbnail_shot_id ? String(render.thumbnail_shot_id) : '',
    );
    const [text, setText] = useState(render.thumbnail_text ?? '');
    const [busy, setBusy] = useState(false);
    const drawing = render.art_status === 'pending';
    const suggests = placeholder(text) && titleIdeas.length === 0;
    const remake = () => {
        setBusy(true);
        router.post(
            route('series.episodes.renders.thumbnail', { ...base, render: render.id }),
            { shot_id: shot === '' || shot === 'art' ? null : Number(shot), art: shot === 'art', text },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };
    const draw = () => {
        setBusy(true);
        router.post(
            route('series.episodes.renders.thumbnail-art', { ...base, render: render.id }),
            { text },
            { preserveScroll: true, onSuccess: () => setShot('art'), onFinish: () => setBusy(false) },
        );
    };
    const chips = titleIdeas.map((idea) => idea.thumbnail_text).filter((t, i, all) => t && all.indexOf(t) === i);

    return (
        <div className="flex flex-wrap items-start gap-3">
            {render.thumbnail_url ? (
                <img
                    src={render.thumbnail_url}
                    alt="Ảnh bìa"
                    className={
                        render.vertical ? 'aspect-[9/16] w-28 rounded-md border object-cover' : 'aspect-video w-56 rounded-md border object-cover'
                    }
                />
            ) : (
                <div className="text-muted-foreground flex aspect-video w-56 items-center justify-center rounded-md border border-dashed text-xs">
                    Chưa có ảnh bìa
                </div>
            )}
            <div className="min-w-0 flex-1 space-y-2 text-sm">
                <p className="font-medium">Ảnh bìa YouTube</p>
                <div className="flex flex-wrap gap-2">
                    <select
                        aria-label="Khung hình làm ảnh bìa"
                        className="border-input bg-background h-8 rounded-md border px-2"
                        value={shot}
                        onChange={(e) => setShot(e.target.value)}
                    >
                        <option value="">Tự chọn khung nổi bật</option>
                        {render.art_url && <option value="art">Ảnh AI vẽ riêng</option>}
                        {shots.map((s, i) =>
                            s.image_url ? (
                                <option key={s.id} value={s.id}>
                                    Khung shot {i + 1}
                                </option>
                            ) : null,
                        )}
                    </select>
                    <input
                        aria-label="Chữ trên ảnh bìa"
                        className="border-input bg-background h-8 min-w-40 flex-1 rounded-md border px-2"
                        value={text}
                        maxLength={60}
                        placeholder="Chữ trên ảnh (để trống: tên tập)"
                        onChange={(e) => setText(e.target.value)}
                    />
                </div>
                {chips.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1">
                        <span className="text-muted-foreground text-xs">Chữ AI gợi ý:</span>
                        {chips.map((chip) => (
                            <button
                                key={chip}
                                type="button"
                                onClick={() => setText(chip)}
                                className={`rounded-full border px-2 py-0.5 text-xs ${text === chip ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                            >
                                {chip}
                            </button>
                        ))}
                    </div>
                )}
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" onClick={remake} disabled={busy || drawing}>
                        {busy ? <Loader2 className="animate-spin" /> : <ImageIcon />} {render.thumbnail_url ? 'Tạo lại ảnh bìa' : 'Tạo ảnh bìa'}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={draw}
                        disabled={busy || drawing}
                        title="Muse vẽ riêng một ảnh nền cho ảnh bìa (nhân vật to, biểu cảm rõ, chừa chỗ cho chữ), xong tự in chữ lên"
                    >
                        {drawing ? <Loader2 className="animate-spin" /> : <Sparkles />}{' '}
                        {drawing ? 'Muse đang vẽ ảnh bìa...' : render.art_url ? 'AI vẽ lại ảnh bìa' : 'AI vẽ ảnh bìa'}
                        {!drawing && suggests && ' + gợi ý tiêu đề'}
                        <span className="text-xs opacity-70">~{formatNumber(imagePrice + (suggests ? titlePrice : 0))} Credits</span>
                    </Button>
                    {render.thumbnail_url && (
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('series.episodes.renders.thumbnail.download', { ...base, render: render.id })}>
                                <Download /> Tải ảnh bìa
                            </a>
                        </Button>
                    )}
                </div>
                {render.art_error && render.art_status !== 'pending' && <p className="text-destructive text-xs">{render.art_error}</p>}
            </div>
        </div>
    );
}

/** Các bản đã xuất của tập (mỗi lần ghép thêm một bản): xem, tải video / phụ đề, xoá bản không cần. */
function EpisodeVersions({
    base,
    renders,
    shots,
    imagePrice,
    titlePrice,
    titleIdeas,
}: {
    base: { series: number; episode: number };
    renders: EpisodeVersion[];
    shots: Shot[];
    imagePrice: number;
    titlePrice: number;
    titleIdeas: TitleIdea[];
}) {
    const [open, setOpen] = useState<number | null>(renders[0]?.id ?? null);
    if (renders.length === 0) {
        return null;
    }
    const remove = (render: EpisodeVersion) => {
        if (confirm(`Xoá bản "${render.label}" (${new Date(render.created_at).toLocaleString('vi-VN')})? File video sẽ bị xoá.`)) {
            router.delete(route('series.episodes.renders.destroy', { ...base, render: render.id }), { preserveScroll: true });
        }
    };

    return (
        <div className="space-y-2 border-t pt-4">
            <h3 className="font-semibold">Các bản đã xuất ({renders.length})</h3>
            <div className="divide-y rounded-lg border">
                {renders.map((render) => (
                    <div key={render.id} className="space-y-2 p-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <button type="button" className="min-w-0 flex-1 text-left" onClick={() => setOpen(open === render.id ? null : render.id)}>
                                <p className="text-sm font-medium">{render.label}</p>
                                <p className="text-muted-foreground text-xs">
                                    {new Date(render.created_at).toLocaleString('vi-VN')}
                                    {render.duration_ms > 0 &&
                                        ` · ${Math.floor(render.duration_ms / 60000)}:${String(Math.round((render.duration_ms % 60000) / 1000)).padStart(2, '0')}`}
                                    {open !== render.id && ' · bấm để xem'}
                                </p>
                            </button>
                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <a href={route('series.episodes.renders.download', { ...base, render: render.id })}>
                                        <Download /> Tải .mp4
                                    </a>
                                </Button>
                                {render.has_srt && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={route('series.episodes.renders.subtitles', { ...base, render: render.id })}>
                                            <Captions /> Phụ đề .srt
                                        </a>
                                    </Button>
                                )}
                                <Button variant="ghost" size="sm" onClick={() => remove(render)} aria-label="Xoá bản này">
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                        {open === render.id && (
                            <VersionThumbnail
                                key={`${render.id}-${render.thumbnail_url}`}
                                base={base}
                                render={render}
                                shots={shots}
                                imagePrice={imagePrice}
                                titlePrice={titlePrice}
                                titleIdeas={titleIdeas}
                            />
                        )}
                        {open === render.id && render.url && (
                            <video
                                key={render.url}
                                src={render.url}
                                controls
                                preload="metadata"
                                className={render.vertical ? 'aspect-[9/16] w-72 rounded-lg bg-black' : 'aspect-video w-full rounded-lg bg-black'}
                            />
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
