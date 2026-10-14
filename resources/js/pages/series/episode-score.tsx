import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/pricing';
import { router } from '@inertiajs/react';
import { Loader2, Music, Play, Sparkles, Square } from 'lucide-react';
import { useRef, useState } from 'react';

export interface EpisodeScoreInfo {
    status: 'composing' | 'ready' | 'failed' | null;
    error: string | null;
    cues: {
        place: 'title' | 'sequence' | 'end';
        label: string;
        mood: string | null;
        style: string;
        tempo: number;
        lead: string;
        url: string | null;
    }[];
}

const STYLE_LABELS: Record<string, string> = {
    playful: 'vui tươi',
    adventure: 'phiêu lưu',
    mystery: 'bí ẩn',
    sneaky: 'rón rén',
    tender: 'dịu dàng',
    calm: 'êm',
    tense: 'hồi hộp',
    triumphant: 'chiến thắng',
    funny: 'hài hước',
};

/** Nhạc riêng của tập: Claude sáng tác nhạc hiệu + nhạc từng phân đoạn + nhạc kết; nghe thử từng đoạn. */
export default function EpisodeScorePanel({
    base,
    score,
    hasShots,
    price,
}: {
    base: { series: number; episode: number };
    score: EpisodeScoreInfo;
    hasShots: boolean;
    price: number;
}) {
    const player = useRef<HTMLAudioElement>(null);
    const [playing, setPlaying] = useState<number | null>(null);
    const composing = score.status === 'composing';
    if (!hasShots) {
        return null;
    }
    const play = (i: number, url: string | null) => {
        if (!player.current || !url) {
            return;
        }
        if (playing === i) {
            player.current.pause();
            setPlaying(null);
            return;
        }
        player.current.src = url;
        void player.current.play();
        setPlaying(i);
    };
    const compose = () => {
        if (score.cues.length === 0 || confirm('Sáng tác lại toàn bộ nhạc của tập (thay bản nhạc hiện tại)?')) {
            router.post(route('series.episodes.score', base), {}, { preserveScroll: true });
        }
    };

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <audio ref={player} className="hidden" onEnded={() => setPlaying(null)} />
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <Music className="size-5" /> Nhạc riêng của tập
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Claude sáng tác nhạc hiệu, nhạc cho từng phân đoạn theo không khí câu chuyện và nhạc kết; app phát bằng bộ nhạc cụ miễn phí
                        trên máy. Nhạc là của riêng tập này, dùng thương mại được. Khi ghép tập, chọn nhạc nền "Nhạc riêng của tập".
                    </p>
                </div>
                <Button variant={score.cues.length > 0 ? 'outline' : 'default'} onClick={compose} disabled={composing}>
                    {composing ? <Loader2 className="animate-spin" /> : <Sparkles />}
                    {composing ? 'Claude đang sáng tác...' : score.cues.length > 0 ? 'Sáng tác lại' : 'Claude sáng tác nhạc'}
                    <span className="text-xs opacity-70">~{formatNumber(price)} Credits</span>
                </Button>
            </div>
            {score.status === 'failed' && score.error && <p className="text-destructive text-sm">Lỗi: {score.error}</p>}
            {score.cues.length > 0 && (
                <div className="grid gap-1 sm:grid-cols-2">
                    {score.cues.map((cue, i) => (
                        <div key={i} className="flex items-center gap-2 rounded-md border px-2 py-1.5 text-xs">
                            <button
                                type="button"
                                className="text-primary hover:bg-muted rounded p-1"
                                title="Nghe thử"
                                disabled={!cue.url}
                                onClick={() => play(i, cue.url)}
                            >
                                {playing === i ? <Square className="size-3.5" /> : <Play className="size-3.5" />}
                            </button>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">{cue.label}</span>
                                <span className="text-muted-foreground">
                                    {STYLE_LABELS[cue.style] ?? cue.style} · {cue.tempo} BPM · {cue.lead.replace('_', ' ')}
                                    {cue.mood && ` · ${cue.mood}`}
                                </span>
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
