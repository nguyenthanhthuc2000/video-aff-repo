import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ImagePlus, Info, Loader2, Music, Sparkles, X } from 'lucide-react';
import { type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tạo MV ca nhạc', href: '/music-videos/create' }];

type Song = { id: number; name: string; duration_ms: number | null; lyrics: string | null };
type Driver = { label: string; available: boolean; note: string };

type Form = {
    source: 'upload' | 'library';
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
    stylePresets,
    lipSyncModes,
    drivers,
    defaultDriver,
    maxMb,
    estimate,
}: {
    songs: Song[];
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

                    {data.source === 'upload' ? (
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
                    <div className="space-y-2">
                        <Label htmlFor="idea">Ý tưởng / câu chuyện của MV</Label>
                        <Textarea
                            id="idea"
                            rows={5}
                            value={data.idea}
                            onChange={(e) => setData('idea', e.target.value)}
                            placeholder="Ví dụ: Cô gái đi một mình trong thành phố mưa đêm, nhớ lại mối tình cũ qua những khung cảnh quen. Điệp khúc ánh đèn neon bừng sáng, cuối MV cô đứng trên sân thượng mỉm cười. Màu xanh ngọc và hồng tím."
                        />
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
