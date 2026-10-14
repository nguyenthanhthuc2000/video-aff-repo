import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Copy, Download, Loader2, Mic2, Pencil, Play, Plus, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'MV ca nhạc', href: '/music-videos' }];

type Stage = { key: string; label: string; group: string; progress: number; episode_id: number | null };

type MusicVideo = {
    id: number;
    title: string;
    song: string | null;
    seconds: number;
    style_preset: string | null;
    style_label: string;
    aspect_ratio: string;
    lip_sync_label: string;
    stage: Stage;
    error: string | null;
    thumbnail_url: string | null;
    video_url: string | null;
    continue_url: string;
    download_url: string | null;
    updated_at: string;
    created_at: string;
};

type DraftItem = {
    id: number;
    title: string | null;
    reference: string;
    status: string;
    is_busy: boolean;
    has_music: boolean;
    too_similar: boolean;
    updated_at: string;
};

const ago = (date: string) => {
    const minutes = Math.round((Date.now() - new Date(date).getTime()) / 60000);
    if (minutes < 1) return 'vừa xong';
    if (minutes < 60) return `${minutes} phút trước`;
    if (minutes < 1440) return `${Math.round(minutes / 60)} giờ trước`;
    return new Date(date).toLocaleDateString('vi-VN');
};

const draftLabels: Record<string, string> = {
    fetching: 'Đang đọc link',
    profiling: 'Đang phân tích',
    writing: 'Đang sáng tác',
    ready: 'Có lời mới',
    failed: 'Lỗi',
};

/** Danh sách MV: tìm, lọc theo trạng thái / phong cách, sắp xếp; làm tiếp, xem / tải, nhân bản, đổi tên, xoá. Tab bài mới Claude sáng tác. */
export default function MusicVideoIndex({
    musicVideos,
    groups,
    stylePresets,
    drafts,
}: {
    musicVideos: MusicVideo[];
    groups: Record<string, string>;
    stylePresets: Record<string, string>;
    drafts: DraftItem[];
}) {
    usePoll(musicVideos.some((mv) => mv.stage.group === 'busy') || drafts.some((d) => d.is_busy), ['musicVideos', 'drafts'], 5000);
    const [tab, setTab] = useState<'videos' | 'drafts'>('videos');
    const [query, setQuery] = useState('');
    const [group, setGroup] = useState('');
    const [style, setStyle] = useState('');
    const [sort, setSort] = useState<'updated' | 'created' | 'title'>('updated');
    const [watching, setWatching] = useState<MusicVideo | null>(null);

    const list = useMemo(() => {
        const q = query.trim().toLowerCase();
        return musicVideos
            .filter((mv) => !q || mv.title.toLowerCase().includes(q) || (mv.song ?? '').toLowerCase().includes(q))
            .filter((mv) => !group || mv.stage.group === group)
            .filter((mv) => !style || (style === 'custom' ? !mv.style_preset : mv.style_preset === style))
            .sort((a, b) =>
                sort === 'title'
                    ? a.title.localeCompare(b.title, 'vi')
                    : new Date(sort === 'created' ? b.created_at : b.updated_at).getTime() -
                      new Date(sort === 'created' ? a.created_at : a.updated_at).getTime(),
            );
    }, [musicVideos, query, group, style, sort]);

    const rename = (mv: MusicVideo) => {
        const title = prompt('Tên mới của MV', mv.title);
        if (title && title.trim() && title !== mv.title) {
            router.put(route('music-videos.rename', mv.id), { title }, { preserveScroll: true });
        }
    };
    const duplicate = (mv: MusicVideo) => {
        if (confirm(`Nhân bản "${mv.title}"? Giữ bài hát và các lựa chọn, Claude lên ý tưởng mới (tính Credits). Ảnh và clip không được chép.`)) {
            router.post(route('music-videos.duplicate', mv.id));
        }
    };
    const destroy = (mv: MusicVideo) => {
        if (confirm(`Xoá MV "${mv.title}"? Khung hình, clip và bản ghép của MV sẽ mất. Bài hát vẫn còn trong thư viện Âm thanh.`)) {
            router.delete(route('music-videos.destroy', mv.id), { preserveScroll: true });
        }
    };
    const counts = Object.fromEntries(Object.keys(groups).map((key) => [key, musicVideos.filter((mv) => mv.stage.group === key).length]));
    const select = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="MV ca nhạc" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading title="MV ca nhạc" description="Các MV đang làm và đã xong. Bấm một MV để làm tiếp đúng bước đang dừng." />
                    <Button asChild>
                        <Link href={route('music-videos.create')}>
                            <Plus /> Tạo MV mới
                        </Link>
                    </Button>
                </div>
                <FlashStatus />

                <div className="flex gap-1 border-b text-sm">
                    {(['videos', 'drafts'] as const).map((key) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={`-mb-px border-b-2 px-3 py-2 ${tab === key ? 'border-primary font-medium' : 'text-muted-foreground border-transparent'}`}
                        >
                            {key === 'videos' ? `MV (${musicVideos.length})` : `Bài Claude sáng tác (${drafts.length})`}
                        </button>
                    ))}
                </div>

                {tab === 'videos' ? (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative min-w-56 flex-1">
                                <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                                <Input
                                    className="pl-8"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Tìm theo tên MV hoặc bài hát"
                                />
                            </div>
                            <select className={select} value={group} onChange={(e) => setGroup(e.target.value)}>
                                <option value="">Mọi trạng thái</option>
                                {Object.entries(groups).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label} ({counts[key]})
                                    </option>
                                ))}
                            </select>
                            <select className={select} value={style} onChange={(e) => setStyle(e.target.value)}>
                                <option value="">Mọi phong cách</option>
                                {Object.entries(stylePresets).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                                <option value="custom">Phong cách riêng</option>
                            </select>
                            <select className={select} value={sort} onChange={(e) => setSort(e.target.value as typeof sort)}>
                                <option value="updated">Mới cập nhật</option>
                                <option value="created">Mới tạo</option>
                                <option value="title">Tên A–Z</option>
                            </select>
                        </div>

                        {musicVideos.length === 0 ? (
                            <div className="text-muted-foreground flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center text-sm">
                                <Mic2 className="size-8" />
                                Chưa có MV nào. Bấm "Tạo MV mới" để bắt đầu từ một bài hát hoặc một link YouTube tham khảo.
                            </div>
                        ) : list.length === 0 ? (
                            <p className="text-muted-foreground p-6 text-center text-sm">Không có MV nào khớp bộ lọc.</p>
                        ) : (
                            <div className="divide-y rounded-xl border">
                                {list.map((mv) => (
                                    <div key={mv.id} className="flex flex-wrap items-center gap-4 p-4 sm:flex-nowrap">
                                        <Link
                                            href={mv.continue_url}
                                            className={`bg-muted relative shrink-0 overflow-hidden rounded-md ${mv.aspect_ratio === '9:16' ? 'h-24 w-14' : 'h-16 w-28'}`}
                                        >
                                            {mv.thumbnail_url ? (
                                                <img src={mv.thumbnail_url} alt="" className="size-full object-cover" />
                                            ) : (
                                                <Mic2 className="text-muted-foreground absolute inset-0 m-auto size-6" />
                                            )}
                                        </Link>
                                        <Link href={mv.continue_url} className="min-w-0 flex-1 space-y-1">
                                            <p className="truncate font-medium">{mv.title}</p>
                                            <p className="text-muted-foreground truncate text-xs">
                                                {mv.song && `♪ ${mv.song} · `}
                                                {Math.floor(mv.seconds / 60)}:{String(mv.seconds % 60).padStart(2, '0')} · {mv.style_label} ·{' '}
                                                {mv.aspect_ratio} · Hát nhép: {mv.lip_sync_label.toLowerCase()}
                                            </p>
                                            <div className="flex items-center gap-2">
                                                <div className="bg-muted h-1.5 w-40 overflow-hidden rounded-full">
                                                    <div
                                                        className={`h-full rounded-full ${mv.stage.group === 'failed' ? 'bg-destructive' : 'bg-primary'}`}
                                                        style={{ width: `${mv.stage.progress}%` }}
                                                    />
                                                </div>
                                                <span className="text-muted-foreground text-xs">{mv.stage.progress}%</span>
                                            </div>
                                            {mv.stage.group === 'failed' && mv.error && (
                                                <p className="text-destructive line-clamp-1 text-xs">{mv.error}</p>
                                            )}
                                        </Link>
                                        <div className="flex shrink-0 flex-col items-end gap-2">
                                            <Badge
                                                variant={
                                                    mv.stage.group === 'failed'
                                                        ? 'destructive'
                                                        : mv.stage.group === 'done'
                                                          ? 'default'
                                                          : mv.stage.group === 'action'
                                                            ? 'outline'
                                                            : 'secondary'
                                                }
                                            >
                                                {mv.stage.group === 'busy' && <Loader2 className="animate-spin" />}
                                                {mv.stage.label}
                                            </Badge>
                                            <span className="text-muted-foreground text-xs">{ago(mv.updated_at)}</span>
                                            <div className="flex gap-1">
                                                {mv.video_url && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Xem MV"
                                                        title="Xem MV"
                                                        onClick={() => setWatching(mv)}
                                                    >
                                                        <Play />
                                                    </Button>
                                                )}
                                                {mv.download_url && (
                                                    <Button variant="ghost" size="icon" aria-label="Tải MV" title="Tải MV" asChild>
                                                        <a href={mv.download_url}>
                                                            <Download />
                                                        </a>
                                                    </Button>
                                                )}
                                                <Button variant="ghost" size="icon" aria-label="Đổi tên" title="Đổi tên" onClick={() => rename(mv)}>
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Nhân bản"
                                                    title="Nhân bản"
                                                    onClick={() => duplicate(mv)}
                                                >
                                                    <Copy />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Xoá"
                                                    title="Xoá"
                                                    className="text-destructive"
                                                    disabled={mv.stage.key === 'idea'}
                                                    onClick={() => destroy(mv)}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                ) : drafts.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        Chưa có bài nào. Ở trang Tạo MV, chọn "Sáng tác bài mới từ link YouTube" để Claude viết bài mới.
                    </div>
                ) : (
                    <div className="divide-y rounded-xl border">
                        {drafts.map((draft) => (
                            <Link
                                key={draft.id}
                                href={route('music-videos.create', { draft: draft.id })}
                                className="hover:bg-muted/50 flex items-center gap-4 p-4"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">{draft.title ?? 'Bài mới (đang sáng tác)'}</p>
                                    <p className="text-muted-foreground truncate text-xs">
                                        Lấy cảm hứng từ: {draft.reference} · {ago(draft.updated_at)}
                                    </p>
                                </div>
                                {draft.too_similar && <Badge variant="destructive">Lời còn giống bài tham khảo</Badge>}
                                <Badge variant={draft.status === 'failed' ? 'destructive' : draft.has_music ? 'default' : 'secondary'}>
                                    {draft.is_busy && <Loader2 className="animate-spin" />}
                                    {draft.has_music ? 'Đã có nhạc' : (draftLabels[draft.status] ?? draft.status)}
                                </Badge>
                            </Link>
                        ))}
                    </div>
                )}
            </div>

            {watching?.video_url && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" onClick={() => setWatching(null)}>
                    <div className="relative max-h-full w-full max-w-4xl" onClick={(e) => e.stopPropagation()}>
                        <Button
                            variant="secondary"
                            size="icon"
                            className="absolute -top-12 right-0"
                            aria-label="Đóng"
                            onClick={() => setWatching(null)}
                        >
                            <X />
                        </Button>
                        <video src={watching.video_url} controls autoPlay className="max-h-[80vh] w-full rounded-lg bg-black" />
                        <p className="mt-2 text-sm text-white">{watching.title}</p>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
