import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatNumber } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { Clapperboard, ImageOff, Images, Link2, Loader2, Pencil, Plus, RefreshCw, Sparkles, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

export interface DialogueLine {
    character_id: number | null;
    line: string;
    emotion?: string | null;
}

export interface Shot {
    id: number;
    sequence: number;
    position: number;
    camera: string | null;
    action: string;
    character_ids: number[];
    location: string | null;
    dialogue: DialogueLine[];
    duration_ms: number;
    continues_previous: boolean;
    image_url: string | null;
    image_status: 'none' | 'pending' | 'ready' | 'failed';
    error: string | null;
}

export interface Sequence {
    number: number;
    title: string;
    summary: string;
    seconds: number;
}

export interface ShotCharacter {
    id: number;
    name: string;
    image_url: string | null;
}

const seconds = (ms: number) => (ms / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 1 });
const minutes = (ms: number) => `${Math.floor(ms / 60000)}:${String(Math.round((ms % 60000) / 1000)).padStart(2, '0')}`;

/** Kịch bản tập: nút Claude viết tập, Muse vẽ khung hình; shot nhóm theo phân đoạn, sửa / vẽ lại từng shot. */
export default function EpisodeShots({
    base,
    status,
    sequences,
    written,
    canResume,
    shots,
    characters,
    cameras,
    pricing,
    episodeSeconds,
}: {
    base: { series: number; episode: number };
    status: string;
    sequences: Sequence[];
    written: number;
    canResume: boolean;
    shots: Shot[];
    characters: ShotCharacter[];
    cameras: string[];
    pricing: { write: number; image: number };
    episodeSeconds: number;
}) {
    const [editing, setEditing] = useState<Shot | null>(null);
    const [redrawing, setRedrawing] = useState<Shot | null>(null);
    const writing = status === 'writing';
    const toDraw = shots.filter((shot) => shot.image_status === 'none' || shot.image_status === 'failed').length;
    const drawing = shots.filter((shot) => shot.image_status === 'pending').length;
    const ready = shots.filter((shot) => shot.image_status === 'ready').length;
    const total = shots.reduce((sum, shot) => sum + shot.duration_ms, 0);
    const name = (id: number | null) => (id === null ? 'Dẫn chuyện' : (characters.find((c) => c.id === id)?.name ?? '?'));

    const write = (resume = false) => {
        if (!resume && shots.length > 0 && !confirm('Viết lại cả tập: mọi shot và khung hình hiện tại sẽ bị thay. Tiếp tục?')) {
            return;
        }
        router.post(route('series.episodes.write', base), { resume }, { preserveScroll: true });
    };
    const drawAll = () => {
        if (confirm(`Muse vẽ ${toDraw} khung hình, khoảng ${formatNumber(toDraw * pricing.image)} Credits. Tiếp tục?`)) {
            router.post(route('series.episodes.keyframes', base), {}, { preserveScroll: true });
        }
    };

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <Clapperboard className="size-5" /> Kịch bản và các shot
                    </h2>
                    {shots.length > 0 && (
                        <p className="text-muted-foreground text-sm">
                            {sequences.length} phân đoạn · {shots.length} shot · {minutes(total)} (mục tiêu {minutes(episodeSeconds * 1000)}) · khung
                            hình {ready}/{shots.length}
                            {drawing > 0 && ` · đang vẽ ${drawing}`}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    {canResume && (
                        <Button variant="outline" onClick={() => write(true)}>
                            <RefreshCw /> Viết tiếp từ phân đoạn {written + 1}
                        </Button>
                    )}
                    <Button variant={shots.length > 0 ? 'outline' : 'default'} onClick={() => write()} disabled={writing || drawing > 0}>
                        {writing ? <Loader2 className="animate-spin" /> : <Sparkles />}
                        {shots.length > 0 ? 'Claude viết lại tập' : 'Claude viết kịch bản tập'}
                    </Button>
                    {shots.length > 0 && (
                        <Button onClick={drawAll} disabled={writing || toDraw === 0}>
                            <Images /> Vẽ khung hình {toDraw > 0 ? `(${toDraw} shot)` : ''}
                        </Button>
                    )}
                </div>
            </div>

            {writing && (
                <div className="text-muted-foreground flex items-center gap-3 rounded-xl border border-dashed p-4 text-sm">
                    <Loader2 className="size-5 shrink-0 animate-spin" />
                    {sequences.length === 0
                        ? 'Claude đang viết dàn ý tập (các phân đoạn)...'
                        : `Claude đang viết phân đoạn ${Math.min(written + 1, sequences.length)}/${sequences.length}. Trang tự cập nhật.`}
                </div>
            )}

            {shots.length === 0 && !writing ? (
                <div className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                    Tập chưa có kịch bản. Bấm "Claude viết kịch bản tập": Claude viết dàn ý rồi từng phân đoạn thành shot (góc máy, hành động, lời
                    thoại), khoảng {formatNumber(pricing.write)} Credits. Sau đó bấm "Vẽ khung hình" để Muse vẽ từng shot theo ảnh chính của nhân vật
                    ({formatNumber(pricing.image)} Credits / shot).
                </div>
            ) : (
                sequences.map((sequence) => {
                    const list = shots.filter((shot) => shot.sequence === sequence.number);
                    if (list.length === 0 && !writing) {
                        return null;
                    }
                    return (
                        <div key={sequence.number} className="space-y-3">
                            <div className="border-l-primary border-l-4 pl-3">
                                <p className="font-medium">
                                    Phân đoạn {sequence.number}: {sequence.title}
                                    <span className="text-muted-foreground ml-2 text-xs font-normal">khoảng {sequence.seconds} giây</span>
                                </p>
                                <p className="text-muted-foreground text-sm">{sequence.summary}</p>
                            </div>
                            {list.length === 0 ? (
                                <p className="text-muted-foreground pl-4 text-xs">Chưa viết.</p>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {list.map((shot) => (
                                        <ShotCard
                                            key={shot.id}
                                            shot={shot}
                                            number={shots.indexOf(shot) + 1}
                                            characters={characters}
                                            name={name}
                                            onEdit={() => setEditing(shot)}
                                            onRedraw={() => setRedrawing(shot)}
                                            busy={writing}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })
            )}

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Sửa shot {editing ? shots.indexOf(editing) + 1 : ''}</DialogTitle>
                    </DialogHeader>
                    {editing && (
                        <ShotEditor
                            key={editing.id}
                            shot={editing}
                            base={base}
                            cameras={cameras}
                            characters={characters}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={redrawing !== null} onOpenChange={(open) => !open && setRedrawing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Vẽ lại khung hình</DialogTitle>
                        <DialogDescription>
                            {formatNumber(pricing.image)} Credits. Muse vẽ theo nội dung shot và ảnh chính của nhân vật.
                        </DialogDescription>
                    </DialogHeader>
                    {redrawing && <RedrawForm key={redrawing.id} shot={redrawing} base={base} onDone={() => setRedrawing(null)} />}
                </DialogContent>
            </Dialog>
        </section>
    );
}

function ShotCard({
    shot,
    number,
    characters,
    name,
    onEdit,
    onRedraw,
    busy,
}: {
    shot: Shot;
    number: number;
    characters: ShotCharacter[];
    name: (id: number | null) => string;
    onEdit: () => void;
    onRedraw: () => void;
    busy: boolean;
}) {
    return (
        <div className="flex flex-col overflow-hidden rounded-xl border">
            <div className="bg-muted/40 relative aspect-video">
                {shot.image_status === 'pending' ? (
                    <span className="text-muted-foreground flex size-full flex-col items-center justify-center gap-2 text-xs">
                        <Loader2 className="size-5 animate-spin" /> Muse đang vẽ...
                    </span>
                ) : shot.image_status === 'failed' && !shot.image_url ? (
                    <span className="text-destructive flex size-full flex-col items-center justify-center gap-1 p-3 text-center text-xs">
                        <ImageOff className="size-5" />
                        <span className="line-clamp-3">{shot.error}</span>
                    </span>
                ) : shot.image_url ? (
                    <a href={shot.image_url} target="_blank" rel="noreferrer">
                        <img src={shot.image_url} alt="" className="size-full object-cover" />
                    </a>
                ) : (
                    <span className="text-muted-foreground flex size-full items-center justify-center p-3 text-center text-xs">Chưa vẽ</span>
                )}
                <span className="absolute top-1.5 left-1.5 rounded bg-black/60 px-1.5 text-xs font-medium text-white">
                    {number} · {seconds(shot.duration_ms)}s
                </span>
                {shot.continues_previous && (
                    <span title="Nối liền shot trước (không cắt)" className="absolute top-1.5 right-1.5 rounded bg-black/60 p-1 text-white">
                        <Link2 className="size-3" />
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-1.5 p-3 text-sm">
                <div className="flex flex-wrap gap-1">
                    {shot.camera && <Badge variant="outline">{shot.camera}</Badge>}
                    {shot.location && <Badge variant="secondary">{shot.location}</Badge>}
                    {shot.character_ids.map((id) => (
                        <Badge key={id} variant="secondary" className="gap-1">
                            {characters.find((c) => c.id === id)?.image_url && (
                                <img src={characters.find((c) => c.id === id)!.image_url!} alt="" className="size-3.5 rounded-full object-cover" />
                            )}
                            {name(id)}
                        </Badge>
                    ))}
                </div>
                <p>{shot.action}</p>
                {shot.dialogue.map((line, i) => (
                    <p key={i} className="text-muted-foreground text-xs">
                        <span className="text-foreground font-medium">{name(line.character_id)}</span>
                        {line.emotion && <span className="italic"> ({line.emotion})</span>}: {line.line}
                    </p>
                ))}
                {shot.image_status === 'failed' && shot.image_url && <p className="text-destructive text-xs">{shot.error}</p>}
                <div className="mt-auto flex gap-1 pt-1">
                    <Button size="sm" variant="outline" className="h-7 px-2 text-xs" onClick={onEdit} disabled={busy}>
                        <Pencil /> Sửa
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-7 px-2 text-xs"
                        onClick={onRedraw}
                        disabled={busy || shot.image_status === 'pending'}
                    >
                        <RefreshCw /> {shot.image_url ? 'Vẽ lại' : 'Vẽ'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

type ShotForm = {
    camera: string;
    action: string;
    character_ids: number[];
    dialogue: { character_id: number | null; line: string; emotion: string }[];
    seconds: string;
};

function ShotEditor({
    shot,
    base,
    cameras,
    characters,
    onDone,
}: {
    shot: Shot;
    base: { series: number; episode: number };
    cameras: string[];
    characters: ShotCharacter[];
    onDone: () => void;
}) {
    const { data, setData, put, processing, errors } = useForm<ShotForm>({
        camera: shot.camera ?? 'medium shot',
        action: shot.action,
        character_ids: shot.character_ids,
        dialogue: shot.dialogue.map((d) => ({ character_id: d.character_id, line: d.line, emotion: d.emotion ?? '' })),
        seconds: String(shot.duration_ms / 1000),
    });
    const fieldError = (key: string) => (errors as Record<string, string>)[key];
    const setLine = (i: number, patch: Partial<ShotForm['dialogue'][number]>) =>
        setData(
            'dialogue',
            data.dialogue.map((line, j) => (j === i ? { ...line, ...patch } : line)),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('series.episodes.shots.update', { ...base, shot: shot.id }), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-[1fr_120px]">
                <div className="space-y-1">
                    <Label>Góc máy</Label>
                    <Select value={data.camera} onValueChange={(value) => setData('camera', value)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {cameras.map((camera) => (
                                <SelectItem key={camera} value={camera}>
                                    {camera}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1">
                    <Label htmlFor="seconds">Giây</Label>
                    <Input
                        id="seconds"
                        type="number"
                        step="0.5"
                        min={1}
                        max={15}
                        value={data.seconds}
                        onChange={(e) => setData('seconds', e.target.value)}
                    />
                    <InputError message={errors.seconds} />
                </div>
            </div>
            <div className="space-y-1">
                <Label htmlFor="action">Hành động (tiếng Anh, vẽ được trong một khung)</Label>
                <Textarea id="action" rows={3} value={data.action} onChange={(e) => setData('action', e.target.value)} />
                <InputError message={errors.action} />
            </div>
            <div className="space-y-1">
                <Label>Nhân vật trong khung</Label>
                <div className="flex flex-wrap gap-1.5">
                    {characters.map((character) => {
                        const on = data.character_ids.includes(character.id);
                        return (
                            <button
                                key={character.id}
                                type="button"
                                onClick={() =>
                                    setData(
                                        'character_ids',
                                        on ? data.character_ids.filter((id) => id !== character.id) : [...data.character_ids, character.id],
                                    )
                                }
                                className={cn(
                                    'rounded-full border px-3 py-1 text-xs',
                                    on ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                                )}
                            >
                                {character.name}
                            </button>
                        );
                    })}
                </div>
            </div>
            <div className="space-y-2">
                <Label>Lời thoại</Label>
                {data.dialogue.map((line, i) => (
                    <div key={i} className="flex gap-2">
                        <select
                            className="border-input bg-background h-9 w-36 shrink-0 rounded-md border px-2 text-sm"
                            value={line.character_id ?? ''}
                            onChange={(e) => setLine(i, { character_id: e.target.value === '' ? null : Number(e.target.value) })}
                        >
                            <option value="">Dẫn chuyện</option>
                            {characters.map((character) => (
                                <option key={character.id} value={character.id}>
                                    {character.name}
                                </option>
                            ))}
                        </select>
                        <Input value={line.line} onChange={(e) => setLine(i, { line: e.target.value })} placeholder="Lời thoại tiếng Anh" />
                        <Input
                            value={line.emotion}
                            onChange={(e) => setLine(i, { emotion: e.target.value })}
                            placeholder="cảm xúc"
                            className="w-28 shrink-0"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="shrink-0"
                            onClick={() =>
                                setData(
                                    'dialogue',
                                    data.dialogue.filter((_, j) => j !== i),
                                )
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                ))}
                <InputError message={fieldError('dialogue.0.line')} />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setData('dialogue', [...data.dialogue, { character_id: data.character_ids[0] ?? null, line: '', emotion: '' }])}
                >
                    <Plus /> Thêm câu
                </Button>
            </div>
            <Button type="submit" disabled={processing}>
                {processing && <Loader2 className="animate-spin" />} Lưu shot
            </Button>
        </form>
    );
}

function RedrawForm({ shot, base, onDone }: { shot: Shot; base: { series: number; episode: number }; onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ notes: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('series.episodes.shots.image', { ...base, shot: shot.id }), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="space-y-1">
                <Label htmlFor="notes">Ghi chú thêm (tuỳ chọn, tiếng Anh tốt hơn)</Label>
                <Textarea
                    id="notes"
                    rows={2}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    placeholder="Leo should be smiling and holding the map with both hands"
                />
                <InputError message={errors.notes} />
            </div>
            <Button type="submit" disabled={processing}>
                {processing ? <Loader2 className="animate-spin" /> : <RefreshCw />} {shot.image_url ? 'Vẽ lại' : 'Vẽ'}
            </Button>
        </form>
    );
}
