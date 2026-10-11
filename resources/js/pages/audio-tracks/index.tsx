import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AudioTrack } from '@/types/models';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, FileText, Link2, LoaderCircle, Mic, Pencil, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Âm thanh', href: '/audio-tracks' }];

const duration = (ms: number | null) => {
    if (ms === null) {
        return null;
    }
    const total = Math.round(ms / 1000);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
};

export default function AudioTracksIndex({ audioTracks, maxMb }: { audioTracks: AudioTrack[]; maxMb: number }) {
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };
    // Tự làm mới khi đang tách giọng hát.
    usePoll(
        audioTracks.some((track) => track.vocal_status === 'pending'),
        ['audioTracks'],
        5000,
    );

    const rename = (track: AudioTrack) => {
        const name = window.prompt('Tên nhạc', track.name);
        if (name && name.trim() !== '' && name !== track.name) {
            router.put(route('audio-tracks.update', track.id), { name }, { preserveScroll: true });
        }
    };

    const destroy = (track: AudioTrack) => {
        if (confirm(`Xoá nhạc "${track.name}"?`)) {
            router.delete(route('audio-tracks.destroy', track.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Âm thanh" />
            <div className="grid gap-8 p-4 lg:grid-cols-[1fr_360px]">
                <div className="space-y-6">
                    <Heading
                        title="Âm thanh"
                        description="Thư viện nhạc nền cho video. Chọn nhạc khi tạo video (chạy tự động) hoặc ở bước Xuất video (duyệt thủ công)."
                    />
                    <FlashStatus />
                    <InputError message={pageErrors.audio} />

                    {audioTracks.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Chưa có nhạc nào. Tải file hoặc nhập link ở khung bên phải.</p>
                    ) : (
                        <div className="divide-y rounded-xl border">
                            {audioTracks.map((track) => (
                                <div key={track.id} className="flex flex-wrap items-center gap-3 p-3">
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="truncate text-sm font-medium">{track.name}</span>
                                            {!track.playable && <Badge variant="outline">Chỉ là link</Badge>}
                                            {duration(track.duration_ms) && (
                                                <span className="text-muted-foreground text-xs">{duration(track.duration_ms)}</span>
                                            )}
                                            {(track.used_count ?? 0) > 0 && <Badge variant="secondary">Dùng trong {track.used_count} video</Badge>}
                                        </div>
                                        {track.playable && track.url ? (
                                            <audio src={track.url} controls preload="none" className="h-8 w-full max-w-md" />
                                        ) : (
                                            <p className="text-muted-foreground text-xs">
                                                Link trang web, không phải file nhạc: không ghép vào video được. Tải file nhạc lên để dùng.
                                            </p>
                                        )}
                                        {track.playable && <VocalInfo track={track} />}
                                        {track.source_url && (
                                            <a
                                                href={track.source_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-muted-foreground inline-flex max-w-full items-center gap-1 truncate text-xs underline"
                                            >
                                                <ExternalLink className="size-3 shrink-0" /> {track.source_url}
                                            </a>
                                        )}
                                    </div>
                                    <div className="flex">
                                        <Button size="icon" variant="ghost" onClick={() => rename(track)} aria-label="Đổi tên">
                                            <Pencil />
                                        </Button>
                                        <Button size="icon" variant="ghost" onClick={() => destroy(track)} aria-label="Xoá">
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <AddTrackForm maxMb={maxMb} />
            </div>
        </AppLayout>
    );
}

function AddTrackForm({ maxMb }: { maxMb: number }) {
    const [source, setSource] = useState<'file' | 'link'>('file');
    const fileInput = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, progress, errors, reset, transform } = useForm<{
        audio: File | null;
        source_url: string;
        name: string;
        rights: boolean;
    }>({ audio: null, source_url: '', name: '', rights: true });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // Chỉ gửi nguồn đang chọn.
        transform((form) => (source === 'file' ? { ...form, source_url: '' } : { ...form, audio: null }));
        post(route('audio-tracks.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <HeadingSmall title="Thêm nhạc" description={`File MP3, M4A, AAC, WAV, OGG tối đa ${maxMb}MB, hoặc link tới file nhạc.`} />
            <div className="grid grid-cols-2 gap-2">
                {(
                    [
                        ['file', 'Tải file lên', Upload],
                        ['link', 'Nhập link', Link2],
                    ] as const
                ).map(([value, label, Icon]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => setSource(value)}
                        className={cn(
                            'inline-flex items-center justify-center gap-2 rounded-md border px-3 py-1.5 text-sm',
                            source === value ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                        )}
                    >
                        <Icon className="size-4" /> {label}
                    </button>
                ))}
            </div>

            {source === 'file' ? (
                <div className="grid gap-2">
                    <Label htmlFor="audio">File nhạc</Label>
                    <Input
                        id="audio"
                        ref={fileInput}
                        type="file"
                        accept=".mp3,.m4a,.aac,.wav,.ogg,audio/*"
                        onChange={(e) => setData('audio', e.target.files?.[0] ?? null)}
                        required
                    />
                    <InputError message={errors.audio} />
                </div>
            ) : (
                <div className="grid gap-2">
                    <Label htmlFor="source_url">Link nhạc</Label>
                    <Input
                        id="source_url"
                        type="url"
                        value={data.source_url}
                        onChange={(e) => setData('source_url', e.target.value)}
                        placeholder="https://.../nhac.mp3"
                        required
                    />
                    <p className="text-muted-foreground text-xs">
                        Link trực tiếp tới file nhạc thì app tải về. Link trang TikTok, YouTube... không tải được, chỉ lưu để tham khảo.
                    </p>
                    <InputError message={errors.source_url} />
                </div>
            )}

            <div className="grid gap-2">
                <Label htmlFor="name">Tên (bỏ trống thì lấy tên file)</Label>
                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} />
            </div>
            <div className="flex items-start gap-2">
                <Checkbox id="rights" checked={data.rights} onCheckedChange={(checked) => setData('rights', checked === true)} />
                <Label htmlFor="rights" className="leading-snug font-normal">
                    Tôi có quyền dùng nhạc này trong video bán hàng (nhạc tự làm, nhạc miễn phí bản quyền hoặc đã mua giấy phép).
                </Label>
            </div>
            <InputError message={errors.rights} />
            <Button disabled={processing}>{processing && progress ? `Đang tải ${progress.percentage}%` : 'Thêm nhạc'}</Button>
        </form>
    );
}

/** Giọng hát đã tách: khoảng có lời và lời (dùng để người mẫu chỉ nhép khi có lời). */
function VocalInfo({ track }: { track: AudioTrack }) {
    const [editing, setEditing] = useState(false);
    const [lyrics, setLyrics] = useState(track.lyrics ?? '');
    const analyze = () => router.post(route('audio-tracks.vocals', track.id), {}, { preserveScroll: true });

    if (track.vocal_status === 'pending') {
        return (
            <p className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                <LoaderCircle className="size-3 animate-spin" /> Đang tách giọng hát và ghi lời...
            </p>
        );
    }

    return (
        <div className="space-y-1 text-xs">
            {track.vocal_status === 'failed' && <p className="text-red-600 dark:text-red-400">Phân tích giọng hát lỗi: {track.vocal_error}</p>}
            {track.vocal_summary && (
                <details>
                    <summary className="cursor-pointer">
                        Giọng hát: {track.vocal_summary.sung_seconds} giây có lời, {track.vocal_summary.lines.length} câu
                    </summary>
                    <ol className="text-muted-foreground mt-1 max-h-48 space-y-0.5 overflow-y-auto">
                        {track.vocal_summary.lines.map((line, i) => (
                            <li key={i}>
                                <span className="tabular-nums">
                                    {line.start.toFixed(1)}–{line.end.toFixed(1)}s
                                </span>
                                : {line.text || '(không nghe rõ lời)'}
                            </li>
                        ))}
                    </ol>
                </details>
            )}
            <div className="flex flex-wrap gap-1">
                <Button size="sm" variant="outline" className="h-7 px-2 text-xs" onClick={analyze}>
                    <Mic /> {track.vocal_status ? 'Phân tích lại giọng hát' : 'Phân tích giọng hát (cho hát nhép)'}
                </Button>
                <Button size="sm" variant="outline" className="h-7 px-2 text-xs" onClick={() => setEditing((open) => !open)}>
                    <FileText /> {track.lyrics ? 'Sửa lời bài hát' : 'Dán lời bài hát'}
                </Button>
            </div>
            {editing && (
                <div className="space-y-1">
                    <textarea
                        aria-label="Lời bài hát"
                        className="border-input bg-background min-h-32 w-full max-w-md rounded-md border p-2 text-xs"
                        placeholder="Dán lời bài hát (đúng thứ tự, lặp điệp khúc nếu bài hát lặp). AI tự dóng lời vào đúng thời điểm hát."
                        value={lyrics}
                        onChange={(e) => setLyrics(e.target.value)}
                    />
                    <Button
                        size="sm"
                        className="h-7 px-2 text-xs"
                        onClick={() =>
                            router.put(
                                route('audio-tracks.lyrics', track.id),
                                { lyrics },
                                { preserveScroll: true, onSuccess: () => setEditing(false) },
                            )
                        }
                    >
                        Lưu lời
                    </Button>
                </div>
            )}
        </div>
    );
}
