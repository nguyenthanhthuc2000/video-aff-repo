import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/pricing';
import { router } from '@inertiajs/react';
import { Loader2, Music2, Play, Sparkles, X } from 'lucide-react';
import { useRef } from 'react';

export interface EpisodeSounds {
    status: 'planning' | 'ready' | 'failed' | null;
    error: string | null;
    library_ready: boolean;
    items: SoundItem[];
}

interface SoundItem {
    index: number;
    place: 'shot' | 'title' | 'end';
    shot_id: number | null;
    shot_number: number | null;
    sound: string;
    /** Tiếng của nhân vật (đổi cao độ theo nhân vật khi ghép). */
    character: string | null;
    description: string;
    url: string | null;
    at_ms: number;
    volume: number;
}

const placeLabel = (item: SoundItem) =>
    item.place === 'title' ? 'Thẻ tiêu đề' : item.place === 'end' ? 'Thẻ kết' : `Shot ${item.shot_number ?? '?'}`;

/** Âm thanh hiệu ứng của tập: Claude chọn từ kho miễn phí (Kenney CC0 + hiệu ứng tự tạo); nghe thử, bỏ bớt. */
export default function EpisodeSoundsPanel({
    base,
    sounds,
    hasShots,
    price,
}: {
    base: { series: number; episode: number };
    sounds: EpisodeSounds;
    hasShots: boolean;
    price: number;
}) {
    const player = useRef<HTMLAudioElement>(null);
    const planning = sounds.status === 'planning';
    const play = (url: string | null) => {
        if (url && player.current) {
            player.current.src = url;
            void player.current.play();
        }
    };
    const plan = () => {
        if (sounds.items.length === 0 || confirm('Chọn lại toàn bộ âm thanh của tập (thay danh sách hiện tại)?')) {
            router.post(route('series.episodes.sounds', base), {}, { preserveScroll: true });
        }
    };

    if (!hasShots) {
        return null;
    }

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <audio ref={player} className="hidden" />
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <Music2 className="size-5" /> Âm thanh hiệu ứng
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Tiếng chuyển cảnh, tiếng động theo hành động, đoạn nhạc ngắn nhấn cảm xúc. Kho miễn phí, dùng thương mại được (Kenney CC0 +
                        hiệu ứng tự tạo). Ghép tập sẽ trộn vào đúng chỗ.
                    </p>
                </div>
                <Button variant={sounds.items.length > 0 ? 'outline' : 'default'} onClick={plan} disabled={planning || !sounds.library_ready}>
                    {planning ? <Loader2 className="animate-spin" /> : <Sparkles />}
                    {planning ? 'Claude đang chọn...' : sounds.items.length > 0 ? 'Chọn lại âm thanh' : 'Claude chọn âm thanh'}
                    <span className="text-xs opacity-70">~{formatNumber(price)} Credits</span>
                </Button>
            </div>
            {!sounds.library_ready && (
                <p className="text-xs text-amber-600 dark:text-amber-400">Chưa có kho âm thanh. Admin chạy lệnh: php artisan film:sound-library</p>
            )}
            {sounds.status === 'failed' && sounds.error && <p className="text-destructive text-sm">Lỗi: {sounds.error}</p>}

            {sounds.items.length > 0 && (
                <div className="grid max-h-80 gap-1 overflow-y-auto sm:grid-cols-2">
                    {sounds.items.map((item) => (
                        <div key={item.index} className="flex items-center gap-2 rounded-md border px-2 py-1 text-xs">
                            <button
                                type="button"
                                className="text-primary hover:bg-muted rounded p-1"
                                title="Nghe thử"
                                onClick={() => play(item.url)}
                                disabled={!item.url}
                            >
                                <Play className="size-3.5" />
                            </button>
                            <span className="w-20 shrink-0 font-medium">
                                {placeLabel(item)} · {(item.at_ms / 1000).toFixed(1)}s
                            </span>
                            <span
                                className="text-muted-foreground min-w-0 flex-1 truncate"
                                title={`${item.sound} · âm lượng ${Math.round(item.volume * 100)}%`}
                            >
                                {item.character && <span className="text-foreground font-medium">{item.character}: </span>}
                                {item.description}
                            </span>
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-destructive rounded p-1"
                                title="Bỏ âm thanh này"
                                onClick={() =>
                                    router.delete(route('series.episodes.sounds.destroy', { ...base, index: item.index }), { preserveScroll: true })
                                }
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
