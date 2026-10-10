import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type SampleVideo } from '@/types/models';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, LoaderCircle, Music, Pencil, ScanSearch, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Video mẫu', href: '/sample-videos' }];

const seconds = (ms: number | null) => (ms === null ? '?' : `${(ms / 1000).toFixed(1)} giây`);
const megabytes = (bytes: number) => `${(bytes / 1024 / 1024).toFixed(1)} MB`;

export default function SampleVideosIndex({
    sampleVideos,
    maxMb,
    recommendedSeconds,
}: {
    sampleVideos: SampleVideo[];
    maxMb: number;
    recommendedSeconds: number;
}) {
    const [editing, setEditing] = useState<SampleVideo | null>(null);
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };
    // Tự làm mới khi Claude đang phân tích.
    usePoll(
        sampleVideos.some((video) => video.analysis_status === 'pending'),
        ['sampleVideos'],
    );

    const destroy = (video: SampleVideo) => {
        if (confirm(`Xoá video mẫu "${video.name}"?`)) {
            router.delete(route('sample-videos.destroy', video.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Video mẫu" />
            <div className="grid gap-8 p-4 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <Heading
                        title="Video mẫu"
                        description="Video có chuyển động bạn muốn người mẫu làm theo (điệu nhảy trend, cách tạo dáng, xoay khoe đồ...). Dùng cho tính năng sao chép chuyển động."
                    />
                    <FlashStatus />
                    <InputError message={pageErrors.sample_video} />

                    {sampleVideos.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Chưa có video mẫu nào. Tải video đầu tiên ở khung bên phải.</p>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                            {sampleVideos.map((video) => (
                                <div key={video.id} className="overflow-hidden rounded-xl border">
                                    <video
                                        src={video.url}
                                        poster={video.thumbnail_url ?? undefined}
                                        className="bg-muted aspect-[9/16] w-full object-cover"
                                        controls
                                        muted
                                        playsInline
                                        preload="none"
                                    />
                                    <div className="space-y-1 p-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="text-sm font-medium break-words">{video.name}</span>
                                            <div className="flex shrink-0">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="size-7"
                                                    onClick={() => setEditing(video)}
                                                    aria-label="Sửa"
                                                >
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="size-7"
                                                    onClick={() => destroy(video)}
                                                    aria-label="Xoá"
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </div>
                                        <div className="text-muted-foreground flex flex-wrap items-center gap-1 text-xs">
                                            <span>{seconds(video.duration_ms)}</span>
                                            {video.width && video.height && (
                                                <span>
                                                    · {video.width}×{video.height}
                                                </span>
                                            )}
                                            <span>· {megabytes(video.size_bytes)}</span>
                                            {video.duration_ms !== null && video.duration_ms > recommendedSeconds * 1000 && (
                                                <Badge variant="outline">Dài hơn {recommendedSeconds} giây</Badge>
                                            )}
                                        </div>
                                        {video.source_url && (
                                            <a
                                                href={video.source_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-muted-foreground inline-flex items-center gap-1 text-xs underline"
                                            >
                                                <ExternalLink className="size-3" /> Nguồn
                                            </a>
                                        )}
                                        {video.notes && <p className="text-muted-foreground line-clamp-2 text-xs">{video.notes}</p>}
                                        <AnalysisInfo video={video} />
                                        <div className="flex flex-wrap gap-1 pt-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="h-7 px-2 text-xs"
                                                disabled={video.analysis_status === 'pending'}
                                                onClick={() => router.post(route('sample-videos.analyze', video.id), {}, { preserveScroll: true })}
                                            >
                                                <ScanSearch /> {video.analysis ? 'Phân tích lại' : 'Phân tích'}
                                            </Button>
                                            {video.has_audio !== false &&
                                                (video.audio_track_id ? (
                                                    <Badge variant="secondary">Nhạc đã có trong Âm thanh</Badge>
                                                ) : (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7 px-2 text-xs"
                                                        onClick={() =>
                                                            router.post(route('sample-videos.audio', video.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        <Music /> Dùng nhạc này
                                                    </Button>
                                                ))}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <UploadForm maxMb={maxMb} recommendedSeconds={recommendedSeconds} />
            </div>

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Sửa video mẫu</DialogTitle>
                    </DialogHeader>
                    {editing && <EditForm key={editing.id} video={editing} onDone={() => setEditing(null)} />}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function UploadForm({ maxMb, recommendedSeconds }: { maxMb: number; recommendedSeconds: number }) {
    const { data, setData, post, processing, progress, errors, reset } = useForm<{
        video: File | null;
        name: string;
        source_url: string;
        notes: string;
        rights: boolean;
    }>({ video: null, name: '', source_url: '', notes: '', rights: false });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sample-videos.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <HeadingSmall
                title="Thêm video mẫu"
                description={`MP4, MOV hoặc WEBM, tối đa ${maxMb}MB. Nên là video dọc, một người, toàn thân, dưới ${recommendedSeconds} giây.`}
            />
            <div className="grid gap-2">
                <Label htmlFor="video">Video</Label>
                <Input
                    id="video"
                    type="file"
                    accept="video/mp4,video/quicktime,video/webm"
                    onChange={(e) => setData('video', e.target.files?.[0] ?? null)}
                    required
                />
                <InputError message={errors.video} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="name">Tên (bỏ trống thì lấy tên file)</Label>
                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Ví dụ: Trend vẫy tay 2024" />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="source_url">Link nguồn (không bắt buộc)</Label>
                <Input
                    id="source_url"
                    type="url"
                    value={data.source_url}
                    onChange={(e) => setData('source_url', e.target.value)}
                    placeholder="https://www.tiktok.com/..."
                />
                <InputError message={errors.source_url} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="notes">Ghi chú (không bắt buộc)</Label>
                <Input id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                <InputError message={errors.notes} />
            </div>
            <div className="flex items-start gap-2">
                <Checkbox id="rights" checked={data.rights} onCheckedChange={(checked) => setData('rights', checked === true)} />
                <Label htmlFor="rights" className="leading-snug font-normal">
                    Tôi có quyền sử dụng video này (tự quay, hoặc được chủ video cho phép) để tham khảo chuyển động.
                </Label>
            </div>
            <InputError message={errors.rights} />
            <Button disabled={processing}>
                <Upload /> {processing && progress ? `Đang tải ${progress.percentage}%` : 'Tải video lên'}
            </Button>
        </form>
    );
}

function EditForm({ video, onDone }: { video: SampleVideo; onDone: () => void }) {
    const { data, setData, put, processing, errors } = useForm({
        name: video.name,
        source_url: video.source_url ?? '',
        notes: video.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('sample-videos.update', video.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-2">
                <Label htmlFor="edit-name">Tên</Label>
                <Input id="edit-name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="edit-source">Link nguồn</Label>
                <Input id="edit-source" type="url" value={data.source_url} onChange={(e) => setData('source_url', e.target.value)} />
                <InputError message={errors.source_url} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="edit-notes">Ghi chú</Label>
                <Input id="edit-notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                <InputError message={errors.notes} />
            </div>
            <Button disabled={processing}>Lưu</Button>
        </form>
    );
}

function AnalysisInfo({ video }: { video: SampleVideo }) {
    if (video.analysis_status === 'pending') {
        return (
            <p className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                <LoaderCircle className="size-3 animate-spin" /> AI đang phân tích chuyển động...
            </p>
        );
    }
    if (video.analysis_status === 'failed') {
        return <p className="text-xs text-red-600 dark:text-red-400">Phân tích lỗi: {video.analysis_error}</p>;
    }
    if (!video.analysis) {
        return null;
    }

    return (
        <details className="text-xs">
            <summary className="cursor-pointer">
                <span className="font-medium">Chuyển động:</span> {video.analysis.summary}
            </summary>
            <ol className="text-muted-foreground mt-1 space-y-0.5">
                {video.analysis.steps.map((step, i) => (
                    <li key={i}>
                        <span className="tabular-nums">
                            {step.start.toFixed(1)}–{step.end.toFixed(1)}s
                        </span>
                        : {step.motion}
                    </li>
                ))}
            </ol>
            <p className="text-muted-foreground mt-1">
                Nhịp: {video.analysis.tempo} · Máy quay: {video.analysis.framing}
            </p>
        </details>
    );
}
