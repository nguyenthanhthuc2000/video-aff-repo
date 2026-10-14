import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/pricing';
import { router } from '@inertiajs/react';
import { Check, Loader2, Sparkles, Type } from 'lucide-react';
import { useState } from 'react';

export interface TitleIdea {
    title: string;
    thumbnail_text: string;
    vi: string;
}

export interface EpisodeTitles {
    status: 'suggesting' | 'ready' | 'failed' | null;
    error: string | null;
    ideas: TitleIdea[];
}

/** Claude gợi ý tiêu đề video cho tập (kèm chữ ngắn cho ảnh bìa); bấm "Dùng" để đặt làm tên tập. */
export default function EpisodeTitlesPanel({
    base,
    titles,
    current,
    hasShots,
    price,
}: {
    base: { series: number; episode: number };
    titles: EpisodeTitles;
    current: string;
    hasShots: boolean;
    price: number;
}) {
    const [busy, setBusy] = useState(false);
    const suggesting = titles.status === 'suggesting';
    const suggest = () => {
        setBusy(true);
        router.post(route('series.episodes.titles', base), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const use = (index: number) => router.post(route('series.episodes.titles.use', base), { index }, { preserveScroll: true });

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <Type className="size-5" /> Tiêu đề video
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Claude đọc nội dung tập và gợi ý {titles.ideas.length > 0 ? 'lại ' : ''}vài tiêu đề hấp dẫn cho YouTube / TikTok / Reels, kèm
                        chữ ngắn để in lên ảnh bìa.
                    </p>
                </div>
                <Button variant="outline" size="sm" onClick={suggest} disabled={busy || suggesting || !hasShots}>
                    {busy || suggesting ? <Loader2 className="animate-spin" /> : <Sparkles />}{' '}
                    {suggesting ? 'Claude đang nghĩ...' : titles.ideas.length > 0 ? 'Gợi ý lại' : 'AI gợi ý tiêu đề'}
                    <span className="text-xs opacity-70">~{formatNumber(price)} Credits</span>
                </Button>
            </div>
            {!hasShots && <p className="text-muted-foreground text-xs">Viết tập trước để Claude có nội dung đặt tiêu đề.</p>}
            {titles.status === 'failed' && titles.error && <p className="text-destructive text-sm">{titles.error}</p>}
            {titles.ideas.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {titles.ideas.map((idea, i) => {
                        const chosen = idea.title === current;

                        return (
                            <li key={i} className="flex items-start gap-3 p-3 text-sm">
                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <p className="font-medium">{idea.title}</p>
                                    <p className="text-muted-foreground text-xs">
                                        Chữ ảnh bìa: <span className="text-foreground font-semibold">{idea.thumbnail_text}</span>
                                        {idea.vi && ` · ${idea.vi}`}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant={chosen ? 'secondary' : 'outline'}
                                    className="h-7 px-2 text-xs"
                                    disabled={chosen}
                                    onClick={() => use(i)}
                                >
                                    {chosen ? <Check /> : null} {chosen ? 'Đang dùng' : 'Dùng'}
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
