import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Eye, Info, Loader2, Pencil, Plus, Save, Sparkles, Trash2, Users, X, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'MV ca nhạc', href: '/music-videos' },
    { title: 'Shorts nhạc', href: '/music-videos/shorts' },
];

type Member = { name: string; role: string; look: string; look_old: string; image_path?: string | null; image_url?: string | null };

type Band = {
    id: number;
    name: string;
    genre: string;
    era: string;
    lore: string | null;
    style_preset: string;
    status: string;
    error: string | null;
    music_videos_count: number;
    members: Member[];
    variations: Record<string, string[]>;
};

type Template = { label: string; hint: string; variation: string; lip_sync: string };
type Driver = { label: string; available: boolean; note: string };
type PreviewItem = { title: string; hashtags: string[]; description: string };

const emptyMember = (): Member => ({ name: '', role: '', look: '', look_old: '' });

/**
 * Series Shorts nhạc: ban nhạc ảo (Claude nghĩ ra hoặc tự nhập) + mẫu Shorts theo các dạng hiệu quả + đoạn bài hát → tạo hàng loạt MV ngắn 9:16,
 * xem trước tên / hashtag, xác nhận Credits, tuỳ chọn chạy tự động tới khi ghép xong. Chỉ nhân vật hư cấu.
 */
export default function Shorts({
    bands,
    templates,
    songs,
    stylePresets,
    drivers,
    creditsPerVideo,
    credits,
}: {
    bands: Band[];
    templates: Record<string, Template>;
    songs: { id: number; name: string; duration_ms: number | null }[];
    stylePresets: Record<string, string>;
    drivers: Record<string, Driver>;
    creditsPerVideo: number;
    credits: number;
}) {
    usePoll(
        bands.some((b) => b.status === 'writing'),
        ['bands'],
        4000,
    );
    const [creating, setCreating] = useState(bands.length === 0);
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shorts nhạc" />
            <div className="max-w-5xl space-y-6 p-4">
                <Heading
                    title="Shorts nhạc theo series"
                    description="Tạo ban nhạc / ca sĩ ảo, chọn mẫu Shorts (Classic Lineup, VS, Trẻ vs Già, Hành trình, Fan đi phố) và một đoạn bài hát, rồi tạo hàng loạt MV ngắn 9:16."
                />
                <FlashStatus />
                <div className="flex items-start gap-2 rounded-lg border px-4 py-3 text-xs">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    Chỉ dùng ban nhạc và người hư cấu: không dùng tên, khuôn mặt hay bài hát của nghệ sĩ thật (quyền hình ảnh, bản quyền, chính sách
                    nội dung giả mạo của YouTube). Mô tả mỗi video có sẵn dòng "AI-generated"; khi đăng hãy bật nhãn nội dung AI / chỉnh sửa của
                    YouTube và TikTok.
                </div>

                <section className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="flex items-center gap-2 font-medium">
                            <Users className="size-4" /> Ban nhạc ảo
                        </h2>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="ml-auto"
                            onClick={() => router.post(route('shorts.bands.examples'), {}, { preserveScroll: true })}
                        >
                            <Sparkles /> Thêm 3 ban nhạc mẫu
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => setCreating(!creating)}>
                            {creating ? <X /> : <Plus />} {creating ? 'Đóng' : 'Tạo ban nhạc'}
                        </Button>
                    </div>
                    {creating && <BandCreate stylePresets={stylePresets} onDone={() => setCreating(false)} />}
                    <div className="grid gap-3 md:grid-cols-2">
                        {bands.map((band) =>
                            editing === band.id ? (
                                <BandEdit key={band.id} band={band} stylePresets={stylePresets} onDone={() => setEditing(null)} />
                            ) : (
                                <BandCard key={band.id} band={band} onEdit={() => setEditing(band.id)} />
                            ),
                        )}
                    </div>
                </section>

                {bands.some((b) => b.status === 'ready' && b.members.length > 0) && (
                    <BatchForm
                        bands={bands.filter((b) => b.status === 'ready' && b.members.length > 0)}
                        templates={templates}
                        songs={songs}
                        drivers={drivers}
                        creditsPerVideo={creditsPerVideo}
                        credits={credits}
                    />
                )}
            </div>
        </AppLayout>
    );
}

function BandCard({ band, onEdit }: { band: Band; onEdit: () => void }) {
    const destroy = () => {
        if (confirm(`Xoá ban nhạc "${band.name}"? Các MV đã tạo vẫn còn.`)) {
            router.delete(route('shorts.bands.destroy', band.id));
        }
    };
    return (
        <div className="space-y-2 rounded-lg border p-3 text-sm">
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <p className="font-medium">{band.status === 'writing' ? 'Claude đang nghĩ ra ban nhạc...' : band.name}</p>
                    <p className="text-muted-foreground text-xs">
                        {band.genre} · {band.era} · {band.music_videos_count} MV
                    </p>
                </div>
                {band.status === 'writing' ? (
                    <Loader2 className="size-4 animate-spin" />
                ) : (
                    <>
                        <Button variant="ghost" size="icon" aria-label="Sửa" onClick={onEdit}>
                            <Pencil />
                        </Button>
                        <Button variant="ghost" size="icon" aria-label="Xoá" className="text-destructive" onClick={destroy}>
                            <Trash2 />
                        </Button>
                    </>
                )}
            </div>
            {band.error && <p className="text-destructive text-xs">{band.error}</p>}
            {band.lore && <p className="text-muted-foreground line-clamp-2 text-xs">{band.lore}</p>}
            <div className="flex flex-wrap gap-2">
                {band.members.map((m) => (
                    <span key={m.name} className="bg-muted flex items-center gap-1.5 rounded-full py-0.5 pr-2.5 pl-0.5 text-xs" title={m.look}>
                        {m.image_url ? (
                            <img src={m.image_url} alt="" className="size-5 rounded-full object-cover" />
                        ) : (
                            <span className="bg-background size-5 rounded-full" />
                        )}
                        {m.name}
                        {m.role && <span className="text-muted-foreground">· {m.role}</span>}
                    </span>
                ))}
            </div>
            {band.members.length > 0 && band.members.some((m) => !m.image_url) && (
                <p className="text-muted-foreground text-xs">
                    Thành viên chưa có ảnh: MV đầu tiên sẽ vẽ ảnh; chốt ảnh ở MV đó thì các MV sau dùng lại đúng khuôn mặt.
                </p>
            )}
        </div>
    );
}

function MemberFields({ members, onChange }: { members: Member[]; onChange: (members: Member[]) => void }) {
    const set = (i: number, patch: Partial<Member>) => onChange(members.map((m, j) => (j === i ? { ...m, ...patch } : m)));
    return (
        <div className="space-y-2">
            {members.map((m, i) => (
                <div key={i} className="grid gap-2 rounded-md border p-2 sm:grid-cols-2">
                    <Input value={m.name} onChange={(e) => set(i, { name: e.target.value })} placeholder="Tên (hư cấu)" />
                    <div className="flex gap-2">
                        <Input value={m.role} onChange={(e) => set(i, { role: e.target.value })} placeholder="Vai trò (lead vocals, guitar...)" />
                        <Button type="button" variant="ghost" size="icon" aria-label="Bỏ" onClick={() => onChange(members.filter((_, j) => j !== i))}>
                            <X />
                        </Button>
                    </div>
                    <Textarea
                        rows={2}
                        value={m.look}
                        onChange={(e) => set(i, { look: e.target.value })}
                        placeholder="Ngoại hình thời đỉnh cao (tiếng Anh tốt nhất)"
                    />
                    <Textarea
                        rows={2}
                        value={m.look_old}
                        onChange={(e) => set(i, { look_old: e.target.value })}
                        placeholder="Ngoại hình bây giờ (già đi)"
                    />
                </div>
            ))}
            {members.length < 6 && (
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...members, emptyMember()])}>
                    <Plus /> Thêm thành viên
                </Button>
            )}
        </div>
    );
}

function BandCreate({ stylePresets, onDone }: { stylePresets: Record<string, string>; onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        mode: 'ai' as 'ai' | 'manual',
        genre: 'hard rock',
        era: '1970s',
        notes: '',
        name: '',
        lore: '',
        style_preset: 'cinematic',
        members: [emptyMember()] as Member[],
        fictional: false as boolean,
    });
    const errorsOf = errors as Record<string, string | undefined>;
    return (
        <div className="space-y-3 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap gap-x-6 gap-y-2">
                <label className="flex items-center gap-2">
                    <input type="radio" checked={data.mode === 'ai'} onChange={() => setData('mode', 'ai')} /> Claude nghĩ ra ban nhạc
                </label>
                <label className="flex items-center gap-2">
                    <input type="radio" checked={data.mode === 'manual'} onChange={() => setData('mode', 'manual')} /> Tự nhập
                </label>
            </div>
            <div className="grid gap-2 sm:grid-cols-3">
                <Input value={data.genre} onChange={(e) => setData('genre', e.target.value)} placeholder="Thể loại (hard rock, metal, V-pop...)" />
                <Input value={data.era} onChange={(e) => setData('era', e.target.value)} placeholder="Thời kỳ (1970s, 1985...)" />
                <select
                    className="border-input bg-background h-9 rounded-md border px-3"
                    value={data.style_preset}
                    onChange={(e) => setData('style_preset', e.target.value)}
                >
                    {Object.entries(stylePresets).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
            </div>
            {data.mode === 'ai' ? (
                <Textarea
                    rows={2}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    placeholder="Gợi ý thêm (không bắt buộc): 4 thành viên, có một nữ guitarist, phong cách glam..."
                />
            ) : (
                <>
                    <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Tên ban nhạc (hư cấu)" />
                    <Textarea
                        rows={2}
                        value={data.lore}
                        onChange={(e) => setData('lore', e.target.value)}
                        placeholder="Câu chuyện của ban (không bắt buộc)"
                    />
                    <MemberFields members={data.members} onChange={(members) => setData('members', members)} />
                </>
            )}
            <label className="flex items-center gap-2 text-xs">
                <Checkbox checked={data.fictional} onCheckedChange={(c) => setData('fictional', c === true)} />
                Nhân vật hư cấu, không mô phỏng người thật (tên, khuôn mặt, phong cách đặc trưng của nghệ sĩ có thật).
            </label>
            <InputError
                message={
                    errors.genre ||
                    errors.era ||
                    errors.name ||
                    errorsOf.fictional ||
                    Object.entries(errorsOf).find(([k]) => k.startsWith('members'))?.[1]
                }
            />
            <Button
                type="button"
                size="sm"
                disabled={processing}
                onClick={() => post(route('shorts.bands.store'), { preserveScroll: true, onSuccess: onDone })}
            >
                {processing ? <Loader2 className="animate-spin" /> : data.mode === 'ai' ? <Sparkles /> : <Save />}
                {data.mode === 'ai' ? 'Claude tạo ban nhạc' : 'Lưu ban nhạc'}
            </Button>
        </div>
    );
}

function BandEdit({ band, stylePresets, onDone }: { band: Band; stylePresets: Record<string, string>; onDone: () => void }) {
    const { data, setData, put, processing, errors } = useForm({
        name: band.name,
        genre: band.genre,
        era: band.era,
        lore: band.lore ?? '',
        style_preset: band.style_preset,
        members: band.members.map(({ name, role, look, look_old }) => ({ name, role, look, look_old })) as Member[],
    });
    const errorsOf = errors as Record<string, string | undefined>;
    return (
        <div className="space-y-2 rounded-lg border p-3 text-sm md:col-span-2">
            <div className="grid gap-2 sm:grid-cols-4">
                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Tên ban nhạc" />
                <Input value={data.genre} onChange={(e) => setData('genre', e.target.value)} placeholder="Thể loại" />
                <Input value={data.era} onChange={(e) => setData('era', e.target.value)} placeholder="Thời kỳ" />
                <select
                    className="border-input bg-background h-9 rounded-md border px-3"
                    value={data.style_preset}
                    onChange={(e) => setData('style_preset', e.target.value)}
                >
                    {Object.entries(stylePresets).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
            </div>
            <Textarea rows={2} value={data.lore} onChange={(e) => setData('lore', e.target.value)} placeholder="Câu chuyện của ban" />
            <MemberFields members={data.members} onChange={(members) => setData('members', members)} />
            <InputError message={errors.name || Object.entries(errorsOf).find(([k]) => k.startsWith('members'))?.[1]} />
            <div className="flex gap-2">
                <Button
                    size="sm"
                    disabled={processing}
                    onClick={() => put(route('shorts.bands.update', band.id), { preserveScroll: true, onSuccess: onDone })}
                >
                    <Save /> Lưu
                </Button>
                <Button size="sm" variant="ghost" onClick={onDone}>
                    Huỷ
                </Button>
            </div>
        </div>
    );
}

function BatchForm({
    bands,
    templates,
    songs,
    drivers,
    creditsPerVideo,
    credits,
}: {
    bands: Band[];
    templates: Record<string, Template>;
    songs: { id: number; name: string; duration_ms: number | null }[];
    drivers: Record<string, Driver>;
    creditsPerVideo: number;
    credits: number;
}) {
    const firstDriver = Object.entries(drivers).find(([, d]) => d.available)?.[0] ?? '';
    const { data, setData, post, processing, errors, transform } = useForm({
        band_id: String(bands[0].id),
        template: 'lineup',
        variations: bands[0].variations.lineup.join('\n'),
        audio_track_id: songs[0] ? String(songs[0].id) : '',
        song_start: '',
        seconds: '25',
        driver: firstDriver,
        autopilot: true as boolean,
        fictional: false as boolean,
    });
    const band = bands.find((b) => String(b.id) === data.band_id) ?? bands[0];
    // Đổi ban / mẫu: điền biến thể gợi ý.
    useEffect(() => {
        setData('variations', (band.variations[data.template] ?? []).join('\n'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.band_id, data.template]);
    const count = data.variations.split('\n').filter((l) => l.trim()).length;
    const total = Math.min(count, 10) * creditsPerVideo;
    const [preview, setPreview] = useState<PreviewItem[] | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const showPreview = async () => {
        setPreviewError(null);
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        const response = await fetch(route('shorts.preview'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify({
                band_id: data.band_id,
                template: data.template,
                variations: data.variations,
                audio_track_id: data.audio_track_id,
            }),
        });
        const body = await response.json();
        if (response.ok) {
            setPreview(body.items);
        } else {
            setPreviewError(body.message ?? 'Chưa xem trước được.');
        }
    };
    const create = () => {
        if (
            confirm(
                `Tạo ${Math.min(count, 10)} MV ngắn cho "${band.name}" (${templates[data.template].label})?\n` +
                    `Khoảng ${total} Credits cho lên ý tưởng + phân cảnh${data.autopilot ? '; sau đó tự vẽ khung hình, làm clip bằng Muse và ghép' : ''}.`,
            )
        ) {
            transform((d) => ({ ...d, autopilot: d.autopilot ? 1 : 0, fictional: d.fictional ? 1 : 0, confirm: 1 }));
            post(route('shorts.batch'));
        }
    };
    const select = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

    return (
        <section className="space-y-4 rounded-lg border p-4">
            <h2 className="flex items-center gap-2 font-medium">
                <Zap className="size-4" /> Tạo hàng loạt MV ngắn
            </h2>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label>Ban nhạc</Label>
                    <select className={select} value={data.band_id} onChange={(e) => setData('band_id', e.target.value)}>
                        {bands.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name} ({b.genre}, {b.era})
                            </option>
                        ))}
                    </select>
                </div>
                <div className="space-y-1">
                    <Label>Bài hát (thư viện Âm thanh)</Label>
                    <select className={select} value={data.audio_track_id} onChange={(e) => setData('audio_track_id', e.target.value)}>
                        {songs.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                    {songs.length === 0 && <p className="text-destructive text-xs">Chưa có bài hát: sáng tác / tải bài ở trang Tạo MV trước.</p>}
                    <InputError message={errors.audio_track_id} />
                </div>
            </div>
            <div className="space-y-1">
                <Label>Mẫu Shorts</Label>
                <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    {Object.entries(templates).map(([key, t]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setData('template', key)}
                            className={`rounded-lg border p-2 text-left text-xs transition ${data.template === key ? 'border-primary bg-primary/5' : 'hover:bg-muted'}`}
                        >
                            <span className="block text-sm font-medium">{t.label}</span>
                            <span className="text-muted-foreground">{t.hint}</span>
                        </button>
                    ))}
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                <div className="space-y-1">
                    <Label>Biến thể (mỗi dòng một MV, tối đa 10): {templates[data.template].variation}</Label>
                    <Textarea
                        rows={4}
                        value={data.variations}
                        onChange={(e) => setData('variations', e.target.value)}
                        className="font-mono text-xs"
                    />
                    <InputError message={errors.variations} />
                </div>
                <div className="space-y-2">
                    <div className="space-y-1">
                        <Label>Bắt đầu từ giây (đoạn điệp khúc)</Label>
                        <Input
                            type="number"
                            min={0}
                            step="0.5"
                            value={data.song_start}
                            onChange={(e) => setData('song_start', e.target.value)}
                            placeholder="0"
                        />
                        <InputError message={errors.song_start} />
                    </div>
                    <div className="space-y-1">
                        <Label>Độ dài (15–40 giây)</Label>
                        <Input type="number" min={15} max={40} value={data.seconds} onChange={(e) => setData('seconds', e.target.value)} />
                    </div>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <label className="flex items-center gap-2">
                    <Checkbox checked={data.fictional} onCheckedChange={(c) => setData('fictional', c === true)} /> Nhân vật hư cấu, không mô phỏng
                    người thật
                </label>
                <label className="flex items-center gap-2">
                    <Checkbox checked={data.autopilot} onCheckedChange={(c) => setData('autopilot', c === true)} /> Chạy tự động tới khi ghép xong
                </label>
                <select
                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    value={data.driver}
                    onChange={(e) => setData('driver', e.target.value)}
                >
                    {Object.entries(drivers).map(([k, d]) => (
                        <option key={k} value={k} disabled={!d.available}>
                            {d.label}
                            {d.available ? '' : ' (chưa dùng được)'}
                        </option>
                    ))}
                </select>
            </div>
            <InputError message={errors.driver || (errors as Record<string, string | undefined>).confirm} />

            <div className="flex flex-wrap items-center gap-3">
                <Button type="button" variant="outline" onClick={showPreview} disabled={count === 0 || !data.audio_track_id}>
                    <Eye /> Xem trước tên & hashtag
                </Button>
                <Button
                    type="button"
                    onClick={create}
                    disabled={processing || count === 0 || !data.audio_track_id || !data.driver || !data.fictional}
                >
                    {processing ? <Loader2 className="animate-spin" /> : <Zap />} Tạo {Math.min(count, 10)} MV ngắn
                </Button>
                <span className={`text-xs ${total > credits ? 'text-destructive' : 'text-muted-foreground'}`}>
                    Khoảng {total} Credits (còn {credits})
                </span>
            </div>
            {previewError && <p className="text-destructive text-xs">{previewError}</p>}
            {preview && (
                <div className="divide-y rounded-md border text-xs">
                    {preview.map((item, i) => (
                        <div key={i} className="space-y-1 p-2">
                            <p className="text-sm font-medium">{item.title}</p>
                            <p className="text-muted-foreground">{item.hashtags.join(' ')}</p>
                            <Badge variant="outline">Mô tả có dòng AI-generated</Badge>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
