import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { Loader2, Pencil, Plus, RefreshCw, Save, Sparkles, X } from 'lucide-react';
import { useState } from 'react';

export type Section = { section: string; start: number; end: number };

export type Analysis = {
    title: string;
    artist: string;
    genre: string;
    tempo_bpm: number | null;
    tempo_feel: string;
    mood: string;
    structure: Section[];
    themes: string[];
    lyrics: string;
    summary: string;
};

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;

const sectionColors: Record<string, string> = {
    chorus: 'bg-primary text-primary-foreground',
    'pre-chorus': 'bg-primary/40',
    'post-chorus': 'bg-primary/40',
    bridge: 'bg-amber-400/70',
    verse: 'bg-muted-foreground/30',
};

type Form = Omit<Analysis, 'lyrics' | 'tempo_bpm'> & { tempo_bpm: string };

/**
 * Claude phân tích bài hát (tên, ca sĩ, thể loại, nhịp ước lượng, không khí, cấu trúc có mốc, chủ đề): xem dạng thẻ, sửa được,
 * phân tích lại được. Dùng cho đề xuất ý tưởng và cắt shot theo đoạn bài hát.
 */
export default function SongAnalysisCard({
    trackId,
    analysis,
    status,
    error,
    duration,
    sections,
    onSeek,
}: {
    trackId: number;
    analysis: Analysis | null;
    status: string | null;
    error: string | null;
    duration: number;
    sections: string[];
    onSeek?: (seconds: number) => void;
}) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing, errors } = useForm<Form>({
        title: analysis?.title ?? '',
        artist: analysis?.artist ?? '',
        genre: analysis?.genre ?? '',
        tempo_bpm: analysis?.tempo_bpm ? String(analysis.tempo_bpm) : '',
        tempo_feel: analysis?.tempo_feel ?? '',
        mood: analysis?.mood ?? '',
        structure: analysis?.structure ?? [],
        themes: analysis?.themes ?? [],
        summary: analysis?.summary ?? '',
    });
    const errorsOf = errors as Record<string, string | undefined>;
    const reanalyze = () => router.post(route('music-videos.songs.analyze', trackId), {}, { preserveScroll: true, preserveState: true });
    const save = () =>
        put(route('music-videos.songs.analysis', trackId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setEditing(false),
        });
    const setSection = (index: number, patch: Partial<Section>) =>
        setData(
            'structure',
            data.structure.map((s, i) => (i === index ? { ...s, ...patch } : s)),
        );

    if (status === 'pending' || status === 'waiting') {
        return (
            <div className="text-muted-foreground flex items-center gap-2 rounded-md border p-3 text-xs">
                <Loader2 className="size-4 animate-spin" />
                {status === 'waiting'
                    ? 'Đang lấy lời theo thời gian, xong thì Claude phân tích bài hát...'
                    : 'Claude đang phân tích bài hát (khoảng 1 phút)...'}
            </div>
        );
    }
    if (!analysis) {
        return (
            <div className="flex flex-wrap items-center gap-3 rounded-md border p-3 text-xs">
                <Button type="button" variant="outline" size="sm" onClick={reanalyze}>
                    <Sparkles /> Claude phân tích bài hát
                </Button>
                <span className="text-muted-foreground">
                    {status === 'failed'
                        ? `Lần trước lỗi: ${error ?? 'không rõ'}.`
                        : 'Thể loại, nhịp, không khí, cấu trúc (intro / verse / điệp khúc...) và chủ đề, dùng để đề xuất ý tưởng và cắt shot.'}
                </span>
            </div>
        );
    }

    const total = duration || Math.max(1, ...analysis.structure.map((s) => s.end));

    return (
        <div className="space-y-3 rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">
                        {analysis.title || 'Bài hát'}
                        {analysis.artist && <span className="text-muted-foreground font-normal"> · {analysis.artist}</span>}
                    </p>
                    <p className="text-muted-foreground text-xs">Claude phân tích (không nghe được nhạc: nhịp là ước lượng, sửa nếu sai)</p>
                </div>
                <div className="flex gap-2">
                    <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(!editing)}>
                        {editing ? <X /> : <Pencil />} {editing ? 'Huỷ' : 'Sửa'}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={reanalyze}>
                        <RefreshCw /> Phân tích lại
                    </Button>
                </div>
            </div>

            {!editing ? (
                <>
                    <div className="flex flex-wrap gap-2">
                        {analysis.genre && <Badge variant="secondary">{analysis.genre}</Badge>}
                        {(analysis.tempo_feel || analysis.tempo_bpm) && (
                            <Badge variant="secondary">
                                {analysis.tempo_feel}
                                {analysis.tempo_bpm ? ` · ~${analysis.tempo_bpm} BPM` : ''}
                            </Badge>
                        )}
                        {analysis.themes.map((theme) => (
                            <Badge key={theme} variant="outline">
                                {theme}
                            </Badge>
                        ))}
                    </div>
                    {analysis.mood && <p className="text-xs">{analysis.mood}</p>}
                    {analysis.structure.length > 0 && (
                        <div className="space-y-1">
                            <div className="flex h-7 w-full overflow-hidden rounded text-[10px]">
                                {analysis.structure.map((s, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        title={`${s.section} ${clock(s.start)}–${clock(s.end)}`}
                                        onClick={() => onSeek?.(s.start)}
                                        style={{ width: `${((s.end - s.start) / total) * 100}%` }}
                                        className={`truncate border-r border-white/60 px-1 ${sectionColors[s.section] ?? 'bg-muted'}`}
                                    >
                                        {s.section}
                                    </button>
                                ))}
                            </div>
                            <p className="text-muted-foreground text-xs">Bấm một đoạn để nghe từ đó. Điệp khúc là phần đậm màu.</p>
                        </div>
                    )}
                    {analysis.summary && <p className="text-muted-foreground text-xs">{analysis.summary}</p>}
                </>
            ) : (
                <div className="space-y-3">
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="Tên bài" />
                        <Input value={data.artist} onChange={(e) => setData('artist', e.target.value)} placeholder="Ca sĩ" />
                        <Input value={data.genre} onChange={(e) => setData('genre', e.target.value)} placeholder="Thể loại" />
                        <div className="flex gap-2">
                            <Input
                                value={data.tempo_feel}
                                onChange={(e) => setData('tempo_feel', e.target.value)}
                                placeholder="Nhịp (chậm, vừa...)"
                            />
                            <Input
                                type="number"
                                className="w-24"
                                value={data.tempo_bpm}
                                onChange={(e) => setData('tempo_bpm', e.target.value)}
                                placeholder="BPM"
                            />
                        </div>
                    </div>
                    <Input value={data.mood} onChange={(e) => setData('mood', e.target.value)} placeholder="Không khí" />
                    <Input
                        value={data.themes.join(', ')}
                        onChange={(e) =>
                            setData(
                                'themes',
                                e.target.value.split(',').map((t) => t.trimStart()),
                            )
                        }
                        placeholder="Chủ đề, cách nhau bằng dấu phẩy"
                    />
                    <div className="space-y-1">
                        <p className="text-xs font-medium">Cấu trúc (giây)</p>
                        {data.structure.map((s, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <select
                                    className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                                    value={s.section}
                                    onChange={(e) => setSection(i, { section: e.target.value })}
                                >
                                    {sections.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                                <Input
                                    type="number"
                                    step="0.1"
                                    className="h-8 w-24"
                                    value={s.start}
                                    onChange={(e) => setSection(i, { start: Number(e.target.value) })}
                                />
                                –
                                <Input
                                    type="number"
                                    step="0.1"
                                    className="h-8 w-24"
                                    value={s.end}
                                    onChange={(e) => setSection(i, { end: Number(e.target.value) })}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Bỏ đoạn"
                                    onClick={() =>
                                        setData(
                                            'structure',
                                            data.structure.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                const last = data.structure[data.structure.length - 1];
                                setData('structure', [...data.structure, { section: 'verse', start: last?.end ?? 0, end: (last?.end ?? 0) + 10 }]);
                            }}
                        >
                            <Plus /> Thêm đoạn
                        </Button>
                        <InputError message={Object.entries(errorsOf).find(([key]) => key.startsWith('structure'))?.[1]} />
                    </div>
                    <Textarea rows={2} value={data.summary} onChange={(e) => setData('summary', e.target.value)} placeholder="Tóm tắt" />
                    <InputError message={errors.tempo_bpm || errors.genre || errors.mood} />
                    <Button type="button" size="sm" onClick={save} disabled={processing}>
                        {processing ? <Loader2 className="animate-spin" /> : <Save />} Lưu phân tích
                    </Button>
                </div>
            )}
        </div>
    );
}
