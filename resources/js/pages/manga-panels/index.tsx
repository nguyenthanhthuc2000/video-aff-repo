import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { EyeOff, Loader2, Pencil, Scissors, Square, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Video truyện', href: '/story-videos' },
    { title: 'Kho khung manga', href: '/manga-panels' },
];

export const moodLabels: Record<string, string> = { tense: 'căng thẳng', sad: 'buồn', epic: 'hoành tráng', funny: 'hài', calm: 'bình thường' };

export interface Panel {
    id: number;
    url: string;
    width: number;
    height: number;
    scan_id: number;
    position: number;
    chapter: string | null;
    page: string | null;
    characters: string[];
    description: string | null;
    mood: string | null;
    useful: boolean;
    tagged: boolean;
}

interface Problem {
    id: number;
    name: string | null;
    chapter: string | null;
    status: string;
    error: string;
}

/** Kho khung manga thật: tải trang truyện, máy cắt khung, Claude ghi nhân vật / nội dung. Video truyện lấy khung từ đây. */
export default function MangaPanelsIndex({
    panels,
    filters,
    characters,
    chapters,
    processing,
    problems,
    total,
    pages,
    max_pages,
}: {
    panels: Panel[];
    filters: { character: string; chapter: string; q: string; all: boolean };
    characters: Record<string, string>;
    chapters: string[];
    processing: number;
    problems: Problem[];
    total: number;
    pages: number;
    max_pages: number;
}) {
    usePoll(processing > 0, ['panels', 'processing', 'problems', 'total', 'pages', 'chapters'], 4000);
    const [q, setQ] = useState(filters.q);
    const filter = (next: Partial<Record<keyof typeof filters, string | boolean>>) => {
        const params = Object.fromEntries(
            Object.entries({ ...filters, q, ...next })
                .filter(([, value]) => value !== '' && value !== false)
                .map(([key, value]) => [key, value === true ? '1' : String(value)]),
        );
        router.get(route('manga-panels.index'), params, { preserveState: true, preserveScroll: true, replace: true });
    };
    const first = useRef(true);
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => filter({ q }), 400);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kho khung manga" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Kho khung manga"
                    description={`${total} khung từ ${pages} trang truyện. Video truyện lấy khung thật ở đây thay cho ảnh AI: Claude chọn khung hợp với từng câu kể.`}
                />
                <FlashStatus />
                <UploadForm chapters={chapters} maxPages={max_pages} />
                {processing > 0 && (
                    <p className="text-muted-foreground flex items-center gap-2 text-sm">
                        <Loader2 className="size-4 animate-spin" /> Đang xử lý {processing} trang (cắt khung, Claude xem từng khung)...
                    </p>
                )}
                {problems.length > 0 && (
                    <div className="space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        {problems.map((p) => (
                            <div key={p.id} className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{p.name ?? `Trang ${p.id}`}</span>
                                <span className="flex-1">{p.error}</span>
                                <ScanActions scanId={p.id} />
                            </div>
                        ))}
                    </div>
                )}
                <div className="flex flex-wrap gap-2">
                    <select
                        aria-label="Nhân vật"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={filters.character}
                        onChange={(e) => filter({ character: e.target.value })}
                    >
                        <option value="">Mọi nhân vật</option>
                        {Object.entries(characters).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    {chapters.length > 0 && (
                        <select
                            aria-label="Chương"
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={filters.chapter}
                            onChange={(e) => filter({ chapter: e.target.value })}
                        >
                            <option value="">Mọi chương</option>
                            {chapters.map((c) => (
                                <option key={c} value={c}>
                                    {c}
                                </option>
                            ))}
                        </select>
                    )}
                    <Input
                        className="min-w-40 flex-1"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Tìm: Goku biến hình, Krillin chết..."
                    />
                    <label className="flex h-9 items-center gap-2 text-sm">
                        <input type="checkbox" className="size-4" checked={filters.all} onChange={(e) => filter({ all: e.target.checked })} />
                        Hiện cả khung đã ẩn
                    </label>
                </div>
                {panels.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-4 text-sm">
                        {total === 0 ? 'Kho đang trống. Tải các trang truyện lên ở trên (ảnh hoặc file zip).' : 'Không có khung nào khớp.'}
                    </p>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                        {panels.map((panel) => (
                            <PanelCard key={`${panel.id}:${panel.description}:${panel.useful}`} panel={panel} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function UploadForm({ chapters, maxPages }: { chapters: string[]; maxPages: number }) {
    const { data, setData, post, processing, errors, progress, reset } = useForm<{ chapter: string; pages: File[] }>({ chapter: '', pages: [] });
    // Đổi key để ô chọn file trống lại sau khi tải xong.
    const [round, setRound] = useState(0);
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('manga-panels.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                reset('pages');
                setRound((r) => r + 1);
            },
        });
    };
    const fieldErrors = errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="chapter">Chương / arc (không bắt buộc)</Label>
                    <Input
                        id="chapter"
                        list="chapters"
                        className="w-64"
                        value={data.chapter}
                        onChange={(e) => setData('chapter', e.target.value)}
                        placeholder="Chương 317 - Namek"
                    />
                    <datalist id="chapters">
                        {chapters.map((c) => (
                            <option key={c} value={c} />
                        ))}
                    </datalist>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="pages">Trang truyện (ảnh jpg / png / webp hoặc file zip, tối đa {maxPages} trang)</Label>
                    <Input
                        key={round}
                        id="pages"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,.zip,application/zip"
                        onChange={(e) => setData('pages', Array.from(e.target.files ?? []))}
                    />
                </div>
                <Button disabled={processing || data.pages.length === 0}>
                    {processing ? <Loader2 className="animate-spin" /> : <Upload />} Tải lên {data.pages.length > 0 && `(${data.pages.length} file)`}
                </Button>
            </div>
            {progress && <progress className="w-full" value={progress.percentage} max="100" />}
            <InputError message={errors.pages ?? Object.entries(fieldErrors).find(([k]) => k.startsWith('pages.'))?.[1]} />
            <p className="text-muted-foreground text-xs">
                Máy tự cắt mỗi trang thành từng khung theo khe trắng giữa các khung (đọc từ phải sang trái như manga). Trang cắt sai thì bấm "Dùng
                nguyên trang". Khung gốc thuộc bản quyền tác giả / nhà xuất bản: video review ngắn thường ổn nhưng vẫn có thể bị khiếu nại.
            </p>
        </form>
    );
}

function ScanActions({ scanId }: { scanId: number }) {
    const opts = { preserveScroll: true };
    return (
        <span className="flex gap-1">
            <Button
                size="sm"
                variant="outline"
                className="h-7"
                onClick={() => router.post(route('manga-scans.reprocess', scanId), { recut: true }, opts)}
            >
                <Scissors /> Cắt lại
            </Button>
            <Button size="sm" variant="outline" className="h-7" onClick={() => router.post(route('manga-scans.whole', scanId), {}, opts)}>
                <Square /> Dùng nguyên trang
            </Button>
            <Button
                size="sm"
                variant="ghost"
                className="text-destructive h-7"
                onClick={() => confirm('Xoá trang này và mọi khung của nó?') && router.delete(route('manga-scans.destroy', scanId), opts)}
            >
                <Trash2 /> Xoá trang
            </Button>
        </span>
    );
}

function PanelCard({ panel }: { panel: Panel }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing } = useForm({
        characters: panel.characters.join(', '),
        description: panel.description ?? '',
        mood: panel.mood ?? '',
        useful: panel.useful,
    });
    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('manga-panels.update', panel.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };
    const toggle = () =>
        router.put(
            route('manga-panels.update', panel.id),
            { characters: panel.characters.join(', '), description: panel.description, mood: panel.mood, useful: !panel.useful },
            { preserveScroll: true },
        );

    return (
        <div className={`flex flex-col overflow-hidden rounded-lg border ${panel.useful ? '' : 'opacity-50'}`}>
            <a href={panel.url} target="_blank" rel="noreferrer" className="bg-muted flex h-48 items-center justify-center">
                <img src={panel.url} alt={panel.description ?? 'Khung manga'} loading="lazy" className="max-h-full max-w-full object-contain" />
            </a>
            {editing ? (
                <form onSubmit={save} className="space-y-2 p-2">
                    <Input
                        value={data.characters}
                        onChange={(e) => setData('characters', e.target.value)}
                        placeholder="Goku, Frieza"
                        aria-label="Nhân vật"
                    />
                    <Textarea rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} aria-label="Mô tả" />
                    <select
                        aria-label="Cảm xúc"
                        className="border-input bg-background h-8 w-full rounded-md border px-2 text-sm"
                        value={data.mood}
                        onChange={(e) => setData('mood', e.target.value)}
                    >
                        <option value="">Cảm xúc?</option>
                        {Object.entries(moodLabels).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <div className="flex gap-1">
                        <Button size="sm" disabled={processing}>
                            Lưu
                        </Button>
                        <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)}>
                            Huỷ
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-1 flex-col gap-1 p-2 text-xs">
                    <div className="flex flex-wrap gap-1">
                        {panel.characters.map((c) => (
                            <Badge key={c} variant="secondary">
                                {c}
                            </Badge>
                        ))}
                        {panel.mood && <Badge variant="outline">{moodLabels[panel.mood] ?? panel.mood}</Badge>}
                    </div>
                    <p className="flex-1">{panel.description ?? (panel.tagged ? '' : 'Claude chưa xem khung này.')}</p>
                    <p className="text-muted-foreground truncate" title={panel.page ?? ''}>
                        {[panel.chapter, panel.page].filter(Boolean).join(' · ')} · khung {panel.position}
                    </p>
                    <div className="flex gap-1">
                        <Button size="icon" variant="ghost" className="size-7" aria-label="Sửa" title="Sửa" onClick={() => setEditing(true)}>
                            <Pencil />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label={panel.useful ? 'Ẩn khung' : 'Hiện lại khung'}
                            title={panel.useful ? 'Ẩn (không dùng làm ảnh cảnh)' : 'Hiện lại'}
                            onClick={toggle}
                        >
                            <EyeOff />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label="Cắt lại trang"
                            title="Trang này cắt sai: dùng nguyên trang làm một khung"
                            onClick={() => router.post(route('manga-scans.whole', panel.scan_id), {}, { preserveScroll: true })}
                        >
                            <Square />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="text-destructive size-7"
                            aria-label="Xoá khung"
                            title="Xoá khung"
                            onClick={() =>
                                confirm('Xoá khung này?') && router.delete(route('manga-panels.destroy', panel.id), { preserveScroll: true })
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
