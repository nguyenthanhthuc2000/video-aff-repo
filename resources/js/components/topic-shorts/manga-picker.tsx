import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type Panel } from '@/pages/manga-panels/index';
import { Link, router } from '@inertiajs/react';
import { BookImage, Loader2, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/** Chọn 1–3 khung manga thật cho một cảnh (theo thứ tự trên xuống), rồi ghép lại ảnh cảnh. */
export function MangaPicker({ sceneId, current, hint }: { sceneId: number; current: Panel[]; hint: string }) {
    const [open, setOpen] = useState(false);
    const [chosen, setChosen] = useState<Panel[]>(current);
    const [q, setQ] = useState(hint);
    const [results, setResults] = useState<Panel[] | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!open) return;
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const response = await fetch(route('manga-panels.search', { q }), { headers: { Accept: 'application/json' } });
                setResults(((await response.json()) as { panels: Panel[] }).panels);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [open, q]);

    const toggle = (panel: Panel) =>
        setChosen((list) =>
            list.some((p) => p.id === panel.id) ? list.filter((p) => p.id !== panel.id) : list.length >= 3 ? list : [...list, panel],
        );
    const save = (ids: number[]) =>
        router.put(route('topic-short-scenes.manga', sceneId), { ids }, { preserveScroll: true, onSuccess: () => setOpen(false) });
    const changed = chosen.map((p) => p.id).join(',') !== current.map((p) => p.id).join(',');

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-1">
                <span className="text-muted-foreground text-xs">Khung manga thật:</span>
                {current.map((panel) => (
                    <img
                        key={panel.id}
                        src={panel.url}
                        alt={panel.description ?? ''}
                        title={panel.description ?? ''}
                        className="h-14 rounded border object-contain"
                    />
                ))}
                {current.length === 0 && <span className="text-muted-foreground text-xs">chưa có</span>}
                <Button size="sm" variant="outline" onClick={() => setOpen(!open)}>
                    <BookImage /> {current.length > 0 ? 'Đổi khung' : 'Chọn khung manga'}
                </Button>
                {current.length > 0 && (
                    <Button size="sm" variant="ghost" className="text-muted-foreground" onClick={() => save([])}>
                        Bỏ khung thật
                    </Button>
                )}
            </div>
            {open && (
                <div className="space-y-2 rounded-md border p-2">
                    <div className="flex items-center gap-2">
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Tìm: Goku, biến hình, Namek..."
                            className="h-8 text-sm"
                        />
                        {loading && <Loader2 className="size-4 animate-spin" />}
                    </div>
                    <p className="text-muted-foreground text-xs">Bấm để chọn tối đa 3 khung, theo thứ tự từ trên xuống. Đã chọn {chosen.length}/3.</p>
                    {chosen.length > 0 && (
                        <div className="flex flex-wrap gap-1">
                            {chosen.map((panel, i) => (
                                <button key={panel.id} type="button" onClick={() => toggle(panel)} className="relative" title="Bỏ chọn">
                                    <img src={panel.url} alt="" className="border-primary h-16 rounded border-2 object-contain" />
                                    <span className="bg-primary text-primary-foreground absolute top-0 left-0 rounded-br px-1 text-xs">{i + 1}</span>
                                    <X className="absolute top-0 right-0 size-3 bg-white text-black" />
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="grid max-h-80 grid-cols-3 gap-1 overflow-y-auto sm:grid-cols-4">
                        {results?.map((panel) => (
                            <button
                                key={panel.id}
                                type="button"
                                onClick={() => toggle(panel)}
                                title={panel.description ?? ''}
                                className={`flex flex-col items-center gap-1 rounded border-2 p-1 text-left text-[11px] ${chosen.some((p) => p.id === panel.id) ? 'border-primary' : 'hover:border-muted-foreground/40 border-transparent'}`}
                            >
                                <img src={panel.url} alt="" loading="lazy" className="h-20 max-w-full object-contain" />
                                <span className="line-clamp-2">{panel.description ?? panel.characters.join(', ')}</span>
                            </button>
                        ))}
                    </div>
                    {results?.length === 0 && !loading && (
                        <p className="text-muted-foreground text-xs">
                            Không có khung nào khớp.{' '}
                            <Link href={route('manga-panels.index')} className="text-primary underline">
                                Tải thêm trang truyện vào Kho khung manga
                            </Link>
                            .
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Button size="sm" disabled={!changed} onClick={() => save(chosen.map((p) => p.id))}>
                            Ghép vào cảnh
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                            Đóng
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
