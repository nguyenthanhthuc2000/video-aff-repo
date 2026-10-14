import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Check, Copy, Loader2, Music, PenLine, RefreshCw, Save, Sparkles, Upload } from 'lucide-react';
import { useState } from 'react';

export type MusicProvider = { label: string; available: boolean; api: boolean; note: string };

type Profile = {
    genre: string;
    tempo_bpm: number | null;
    tempo_feel: string;
    mood: string;
    vocal: string;
    instruments: string[];
    structure: { section: string; seconds: number }[];
    themes: string[];
    hook: string;
    rhyme: string;
    summary: string;
};

type NewSong = {
    title: string;
    language: string;
    concept: string;
    genre: string;
    tempo_bpm: number | null;
    vocal: string;
    style_prompt: string;
    lyrics: string;
    mv_idea: string;
    singer: string;
};

export type Draft = {
    id: number;
    source_url: string;
    notes: string | null;
    status: string;
    error: string | null;
    is_busy: boolean;
    reference: { title: string; artist: string; duration: number; language: string | null; captions_kind: string | null; lines: number } | null;
    profile: Profile | null;
    song: NewSong | null;
    overlap: { score: number; lines: string[]; too_similar: boolean } | null;
    music_provider: string | null;
    music_status: string | null;
    music_error: string | null;
    audio_track_id: number | null;
};

const busyLabels: Record<string, string> = {
    fetching: 'Đang đọc link YouTube (thông tin bài, phụ đề; không tải nhạc)...',
    profiling: 'Claude đang phân tích bài tham khảo...',
    writing: 'Claude đang sáng tác bài mới...',
};

/**
 * Sáng tác bài mới lấy cảm hứng từ link YouTube: bài tham khảo chỉ để phân tích (không tải nhạc, không dùng trong MV).
 * Claude viết lời + phong cách nhạc mới (sửa, viết lại được, có kiểm tra trùng lời), rồi tạo nhạc: tự làm ở công cụ ngoài và tải lên, hoặc API.
 */
export default function SongDrafts({
    drafts,
    providers,
    maxMb,
    onUse,
    initialOpen,
}: {
    drafts: Draft[];
    initialOpen?: number | null;
    providers: Record<string, MusicProvider>;
    maxMb: number;
    onUse: (trackId: number) => void;
}) {
    const form = useForm({ url: '', notes: '' });
    const [open, setOpen] = useState<number | null>(initialOpen ?? drafts[0]?.id ?? null);
    const start = () =>
        form.post(route('song-drafts.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                form.reset();
                setOpen((page.props.drafts as Draft[])[0]?.id ?? null);
            },
        });

    return (
        <div className="space-y-4">
            <div className="space-y-2 rounded-md border border-dashed p-3">
                <p className="text-muted-foreground text-xs">
                    Dán link một bài bạn thích. Claude đọc tên bài, mô tả và lời (phụ đề) để hiểu thể loại, nhịp, cấu trúc, cách viết hook, rồi sáng
                    tác <span className="text-foreground font-medium">bài hoàn toàn mới</span>: lời mới, nhạc mới, ý tưởng MV mới. Bài tham khảo không
                    bị tải về và không dùng trong MV.
                </p>
                <Input
                    type="url"
                    value={form.data.url}
                    onChange={(e) => form.setData('url', e.target.value)}
                    placeholder="https://www.youtube.com/watch?v=..."
                />
                <InputError message={form.errors.url} />
                <Textarea
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="Hướng sáng tác (không bắt buộc): chủ đề mới, ngôn ngữ lời, giọng nam / nữ, nhanh hơn / chậm hơn..."
                />
                <Button type="button" onClick={start} disabled={form.processing || !form.data.url.trim()}>
                    {form.processing ? <Loader2 className="animate-spin" /> : <Sparkles />} Phân tích & sáng tác bài mới
                </Button>
            </div>

            {drafts.map((draft) => (
                <DraftCard
                    key={draft.id}
                    draft={draft}
                    open={open === draft.id}
                    onToggle={() => setOpen(open === draft.id ? null : draft.id)}
                    providers={providers}
                    maxMb={maxMb}
                    onUse={onUse}
                />
            ))}
        </div>
    );
}

function DraftCard({
    draft,
    open,
    onToggle,
    providers,
    maxMb,
    onUse,
}: {
    draft: Draft;
    open: boolean;
    onToggle: () => void;
    providers: Record<string, MusicProvider>;
    maxMb: number;
    onUse: (trackId: number) => void;
}) {
    return (
        <div className="space-y-3 rounded-md border p-3 text-sm">
            <button type="button" onClick={onToggle} className="flex w-full flex-wrap items-center gap-2 text-left">
                <span className="font-medium">{draft.song?.title || draft.reference?.title || draft.source_url}</span>
                {draft.reference && draft.song && <span className="text-muted-foreground text-xs">lấy cảm hứng từ "{draft.reference.title}"</span>}
                <span className="ml-auto">
                    {draft.is_busy ? (
                        <Badge variant="secondary">
                            <Loader2 className="animate-spin" /> Đang xử lý
                        </Badge>
                    ) : draft.status === 'failed' ? (
                        <Badge variant="destructive">Lỗi</Badge>
                    ) : draft.audio_track_id ? (
                        <Badge>Đã có nhạc</Badge>
                    ) : (
                        <Badge variant="outline">Có lời mới</Badge>
                    )}
                </span>
            </button>
            {open && (
                <>
                    {busyLabels[draft.status] && (
                        <p className="text-muted-foreground flex items-center gap-2 text-xs">
                            <Loader2 className="size-4 animate-spin" /> {busyLabels[draft.status]}
                        </p>
                    )}
                    {draft.error && <p className="text-destructive text-xs">{draft.error}</p>}
                    {draft.profile && <ProfileView draft={draft} />}
                    {draft.song && !['writing'].includes(draft.status) && <SongEditor key={JSON.stringify(draft.song)} draft={draft} />}
                    {draft.song && draft.status === 'ready' && <MakeMusic draft={draft} providers={providers} maxMb={maxMb} onUse={onUse} />}
                </>
            )}
        </div>
    );
}

function ProfileView({ draft }: { draft: Draft }) {
    const profile = draft.profile!;
    return (
        <details className="bg-muted/30 rounded-md p-3 text-xs">
            <summary className="cursor-pointer font-medium">
                Phân tích bài tham khảo{draft.reference && `: ${draft.reference.title}`}
                {draft.reference?.artist && ` · ${draft.reference.artist}`}
            </summary>
            <div className="mt-2 space-y-1.5">
                <div className="flex flex-wrap gap-1.5">
                    {profile.genre && <Badge variant="secondary">{profile.genre}</Badge>}
                    {(profile.tempo_feel || profile.tempo_bpm) && (
                        <Badge variant="secondary">
                            {profile.tempo_feel}
                            {profile.tempo_bpm ? ` · ~${profile.tempo_bpm} BPM` : ''}
                        </Badge>
                    )}
                    {profile.vocal && <Badge variant="outline">{profile.vocal}</Badge>}
                    {profile.instruments.map((i) => (
                        <Badge key={i} variant="outline">
                            {i}
                        </Badge>
                    ))}
                </div>
                {profile.mood && <p>Không khí: {profile.mood}</p>}
                {profile.structure.length > 0 && <p>Cấu trúc: {profile.structure.map((s) => `${s.section} ~${s.seconds}s`).join(' → ')}</p>}
                {profile.themes.length > 0 && <p>Chủ đề: {profile.themes.join(', ')}</p>}
                {profile.hook && <p>Hook: {profile.hook}</p>}
                {profile.rhyme && <p>Vần: {profile.rhyme}</p>}
                {profile.summary && <p className="text-muted-foreground">{profile.summary}</p>}
                {draft.reference && (
                    <p className="text-muted-foreground">
                        Lời tham khảo:{' '}
                        {draft.reference.lines > 0
                            ? `${draft.reference.lines} câu từ phụ đề ${draft.reference.captions_kind === 'manual' ? 'của video' : 'tự động'}`
                            : 'không có phụ đề, Claude phân tích theo tên bài và mô tả'}
                        .
                    </p>
                )}
            </div>
        </details>
    );
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => {
                void navigator.clipboard.writeText(text);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }}
        >
            {copied ? <Check /> : <Copy />} {copied ? 'Đã copy' : 'Copy'}
        </Button>
    );
}

function SongEditor({ draft }: { draft: Draft }) {
    const song = draft.song!;
    const { data, setData, put, processing, errors } = useForm({ title: song.title, style_prompt: song.style_prompt, lyrics: song.lyrics });
    const [notes, setNotes] = useState(draft.notes ?? '');
    const [rewriting, setRewriting] = useState(false);
    const save = () => put(route('song-drafts.update', draft.id), { preserveScroll: true, preserveState: true });
    const rewrite = () =>
        router.post(
            route('song-drafts.rewrite', draft.id),
            { notes },
            { preserveScroll: true, preserveState: true, onStart: () => setRewriting(true), onFinish: () => setRewriting(false) },
        );

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2">
                <PenLine className="text-primary size-4" />
                <span className="font-medium">Bài mới</span>
                <span className="text-muted-foreground text-xs">
                    {song.genre}
                    {song.tempo_bpm ? ` · ~${song.tempo_bpm} BPM` : ''}
                    {song.vocal ? ` · ${song.vocal}` : ''}
                </span>
            </div>
            {song.concept && <p className="text-muted-foreground text-xs">{song.concept}</p>}
            {draft.overlap?.too_similar && (
                <div className="border-destructive/40 bg-destructive/5 text-destructive space-y-1 rounded-md border p-2 text-xs">
                    <p className="flex items-center gap-1 font-medium">
                        <AlertTriangle className="size-4" /> Lời còn giống bài tham khảo ({Math.round(draft.overlap.score * 100)}% cụm từ trùng). Sửa
                        hoặc viết lại:
                    </p>
                    {draft.overlap.lines.map((line) => (
                        <p key={line}>• {line}</p>
                    ))}
                </div>
            )}
            {draft.overlap && !draft.overlap.too_similar && (
                <p className="text-muted-foreground flex items-center gap-1 text-xs">
                    <Check className="size-3" /> Lời khác bài tham khảo (trùng {Math.round(draft.overlap.score * 100)}% cụm 4 chữ).
                </p>
            )}
            <div className="space-y-1">
                <Label>Tên bài</Label>
                <Input value={data.title} onChange={(e) => setData('title', e.target.value)} />
            </div>
            <div className="space-y-1">
                <div className="flex items-center justify-between">
                    <Label>Phong cách nhạc (dán vào ô Style của công cụ tạo nhạc)</Label>
                    <CopyButton text={data.style_prompt} />
                </div>
                <Textarea rows={2} value={data.style_prompt} onChange={(e) => setData('style_prompt', e.target.value)} />
            </div>
            <div className="space-y-1">
                <div className="flex items-center justify-between">
                    <Label>Lời bài hát</Label>
                    <CopyButton text={data.lyrics} />
                </div>
                <Textarea rows={14} value={data.lyrics} onChange={(e) => setData('lyrics', e.target.value)} className="font-mono text-xs" />
            </div>
            <InputError message={errors.title || errors.style_prompt || errors.lyrics} />
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" size="sm" onClick={save} disabled={processing}>
                    {processing ? <Loader2 className="animate-spin" /> : <Save />} Lưu
                </Button>
                <Input
                    className="h-8 min-w-48 flex-1"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="Muốn khác đi thế nào? (ví dụ: vui hơn, lời tiếng Anh)"
                />
                <Button type="button" variant="outline" size="sm" onClick={rewrite} disabled={rewriting}>
                    {rewriting ? <Loader2 className="animate-spin" /> : <RefreshCw />} Viết lại
                </Button>
            </div>
        </div>
    );
}

function MakeMusic({
    draft,
    providers,
    maxMb,
    onUse,
}: {
    draft: Draft;
    providers: Record<string, MusicProvider>;
    maxMb: number;
    onUse: (trackId: number) => void;
}) {
    const [provider, setProvider] = useState(draft.music_provider ?? 'manual');
    const upload = useForm<{ song: File | null; rights: boolean }>({ song: null, rights: false });
    const send = () => {
        upload.transform((data) => ({ ...data, rights: data.rights ? 1 : 0 }));
        upload.post(route('song-drafts.upload', draft.id), { forceFormData: true, preserveScroll: true, preserveState: true });
    };
    const generate = () => router.post(route('song-drafts.generate', draft.id), { provider }, { preserveScroll: true, preserveState: true });

    if (draft.audio_track_id) {
        return (
            <div className="flex flex-wrap items-center gap-3 rounded-md border border-green-600/40 bg-green-600/5 p-3 text-xs">
                <Check className="size-4 text-green-600" />
                <span className="flex-1">Bài mới đã vào thư viện Âm thanh. Dùng bài này để làm MV (lời, ý tưởng MV và ca sĩ được điền sẵn).</span>
                <Button type="button" size="sm" onClick={() => onUse(draft.audio_track_id!)}>
                    Dùng bài này cho MV <ArrowRight />
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-3 rounded-md border p-3">
            <p className="flex items-center gap-2 font-medium">
                <Music className="size-4" /> Tạo nhạc cho bài mới
            </p>
            {Object.entries(providers).map(([key, p]) => (
                <label key={key} className={`flex items-start gap-2 text-xs ${p.available ? 'cursor-pointer' : 'opacity-60'}`}>
                    <input type="radio" className="mt-0.5" disabled={!p.available} checked={provider === key} onChange={() => setProvider(key)} />
                    <span>
                        <span className="font-medium">{p.label}</span>
                        <span className="text-muted-foreground block">{p.note}</span>
                    </span>
                </label>
            ))}

            {provider === 'manual' ? (
                <div className="space-y-2 text-xs">
                    <ol className="text-muted-foreground list-decimal space-y-0.5 pl-5">
                        <li>
                            Mở công cụ tạo nhạc (ví dụ{' '}
                            <a href="https://suno.com/create" target="_blank" rel="noreferrer" className="text-primary underline">
                                Suno
                            </a>{' '}
                            hoặc{' '}
                            <a href="https://www.udio.com" target="_blank" rel="noreferrer" className="text-primary underline">
                                Udio
                            </a>
                            ), chọn chế độ tự nhập lời (Custom).
                        </li>
                        <li>Copy "Phong cách nhạc" vào ô Style, "Lời bài hát" vào ô Lyrics, đặt tên bài, bấm tạo.</li>
                        <li>Nghe thử, chọn bản ưng nhất, tải về (mp3 / wav) rồi tải lên dưới đây.</li>
                    </ol>
                    <label className="hover:bg-muted/50 flex cursor-pointer items-center gap-2 rounded-md border border-dashed p-3">
                        <Upload className="text-muted-foreground size-4" />
                        <span className="flex-1 truncate">
                            {upload.data.song ? upload.data.song.name : `Chọn file bài hát đã tạo (tối đa ${maxMb}MB)`}
                        </span>
                        <input
                            type="file"
                            accept=".mp3,.wav,.m4a,audio/mpeg,audio/wav,audio/mp4"
                            className="hidden"
                            onChange={(e) => upload.setData('song', e.target.files?.[0] ?? null)}
                        />
                    </label>
                    <label className="flex items-start gap-2">
                        <Checkbox checked={upload.data.rights} onCheckedChange={(c) => upload.setData('rights', c === true)} className="mt-0.5" />
                        Tôi có quyền dùng bài nhạc này (theo điều khoản của công cụ tạo nhạc, ví dụ gói trả phí để dùng thương mại).
                    </label>
                    <InputError message={upload.errors.song || upload.errors.rights} />
                    <Button type="button" size="sm" onClick={send} disabled={upload.processing || !upload.data.song}>
                        {upload.processing ? <Loader2 className="animate-spin" /> : <Upload />} Tải bài hát lên
                    </Button>
                </div>
            ) : (
                <div className="space-y-2 text-xs">
                    <p className="text-muted-foreground">Chỉ gửi lời và phong cách nhạc của bài mới (không gửi bài tham khảo). Trả phí theo lượt.</p>
                    {draft.music_status === 'pending' ? (
                        <p className="text-muted-foreground flex items-center gap-1">
                            <Loader2 className="size-3 animate-spin" /> Đang tạo bài hát (vài phút)...
                        </p>
                    ) : (
                        <Button type="button" size="sm" onClick={generate} disabled={!providers[provider]?.available}>
                            <Sparkles /> Tạo nhạc bằng {provider}
                        </Button>
                    )}
                    {draft.music_status === 'failed' && <p className="text-destructive">{draft.music_error}</p>}
                </div>
            )}
        </div>
    );
}
