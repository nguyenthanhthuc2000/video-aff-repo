import { Button } from '@/components/ui/button';
import { Flag, FlagOff, Pause, Play, Repeat } from 'lucide-react';
import { type MouseEvent, useEffect, useMemo, useRef, useState } from 'react';

export type TimedLine = { start: number; end: number; text: string };

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;

const BARS = 400;

/** Tính độ lớn từng đoạn của bài để vẽ dạng sóng (giải mã ngay trong trình duyệt, không gửi đi đâu). */
async function peaksOf(source: string | File): Promise<number[]> {
    const buffer = source instanceof File ? await source.arrayBuffer() : await (await fetch(source)).arrayBuffer();
    const context = new AudioContext();
    try {
        const audio = await context.decodeAudioData(buffer);
        const channel = audio.getChannelData(0);
        const step = Math.max(1, Math.floor(channel.length / BARS));
        const peaks = Array.from({ length: BARS }, (_, i) => {
            let max = 0;
            for (let j = i * step; j < Math.min(channel.length, (i + 1) * step); j += 16) {
                max = Math.max(max, Math.abs(channel[j]));
            }
            return max;
        });
        const top = Math.max(...peaks, 0.01);
        return peaks.map((p) => p / top);
    } finally {
        void context.close();
    }
}

/**
 * Nghe thử bài hát trước khi tạo MV: dạng sóng (bấm để tua), chọn đoạn dùng cho MV từ vị trí đang nghe, nghe lặp đoạn đã chọn,
 * lời bài hát bên cạnh: có lời theo thời gian thì câu đang hát sáng lên và bấm câu để tua tới, không thì hiện lời đã dán.
 */
export default function SongPreview({
    source,
    lines,
    lyrics,
    start,
    end,
    onStart,
    onEnd,
}: {
    source: string | File;
    lines: TimedLine[];
    lyrics: string;
    start: number | null;
    end: number | null;
    onStart: (seconds: number | null) => void;
    onEnd: (seconds: number | null) => void;
}) {
    const audio = useRef<HTMLAudioElement>(null);
    const canvas = useRef<HTMLCanvasElement>(null);
    const list = useRef<HTMLDivElement>(null);
    const [time, setTime] = useState(0);
    const [duration, setDuration] = useState(0);
    const [playing, setPlaying] = useState(false);
    const [loop, setLoop] = useState(false);
    const [peaks, setPeaks] = useState<number[]>([]);
    const src = useMemo(() => (source instanceof File ? URL.createObjectURL(source) : source), [source]);
    useEffect(() => () => (source instanceof File ? URL.revokeObjectURL(src) : undefined), [source, src]);

    useEffect(() => {
        let cancelled = false;
        setPeaks([]);
        peaksOf(source)
            .then((p) => !cancelled && setPeaks(p))
            .catch(() => undefined);
        return () => {
            cancelled = true;
        };
    }, [source]);

    const from = start ?? 0;
    const to = end ?? duration;

    // Dạng sóng: phần ngoài đoạn đã chọn mờ đi, vạch đỏ là vị trí đang nghe.
    useEffect(() => {
        const el = canvas.current;
        const ctx = el?.getContext('2d');
        if (!el || !ctx) {
            return;
        }
        const width = (el.width = el.clientWidth * devicePixelRatio);
        const height = (el.height = el.clientHeight * devicePixelRatio);
        ctx.clearRect(0, 0, width, height);
        const color = getComputedStyle(el).color;
        const bar = width / BARS;
        peaks.forEach((p, i) => {
            const t = duration ? (i / BARS) * duration : 0;
            ctx.globalAlpha = duration && (t < from || t > to) ? 0.25 : 0.85;
            ctx.fillStyle = color;
            const h = Math.max(2, p * height * 0.9);
            ctx.fillRect(i * bar, (height - h) / 2, Math.max(1, bar - 1), h);
        });
        if (duration) {
            ctx.globalAlpha = 1;
            ctx.fillStyle = '#ef4444';
            ctx.fillRect((time / duration) * width - 1, 0, 2 * devicePixelRatio, height);
        }
    }, [peaks, time, duration, from, to]);

    const seek = (seconds: number, play = false) => {
        const el = audio.current;
        if (!el) {
            return;
        }
        el.currentTime = Math.max(0, Math.min(seconds, duration || seconds));
        setTime(el.currentTime);
        if (play) {
            void el.play();
        }
    };
    const clickWave = (e: MouseEvent<HTMLCanvasElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();
        seek(((e.clientX - rect.left) / rect.width) * duration);
    };
    const toggle = () => (audio.current?.paused ? void audio.current.play() : audio.current?.pause());
    const toggleLoop = () => {
        setLoop(!loop);
        if (!loop) {
            seek(from, true);
        }
    };

    const active = lines.reduce((found, line, i) => (line.start <= time + 0.15 ? i : found), -1);
    useEffect(() => {
        if (active >= 0 && playing) {
            list.current?.querySelector(`[data-line="${active}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }, [active, playing]);

    return (
        <div className="space-y-3 rounded-md border p-3">
            <audio
                ref={audio}
                src={src}
                preload="metadata"
                onLoadedMetadata={(e) => setDuration(e.currentTarget.duration || 0)}
                onPlay={() => setPlaying(true)}
                onPause={() => setPlaying(false)}
                onTimeUpdate={(e) => {
                    const t = e.currentTarget.currentTime;
                    // Nghe lặp: hết đoạn đã chọn thì quay về đầu đoạn.
                    if (loop && (t >= to || t < from - 0.5)) {
                        e.currentTarget.currentTime = from;
                    }
                    setTime(e.currentTarget.currentTime);
                }}
            />
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" size="icon" onClick={toggle} aria-label={playing ? 'Tạm dừng' : 'Phát'}>
                    {playing ? <Pause /> : <Play />}
                </Button>
                <span className="text-muted-foreground font-mono text-xs">
                    {clock(time)} / {clock(duration)}
                </span>
                <div className="ml-auto flex flex-wrap gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={() => onStart(Math.round(time * 10) / 10)}>
                        <Flag /> Bắt đầu ở {clock(time)}
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={() => onEnd(Math.round(time * 10) / 10)} disabled={time <= from}>
                        <FlagOff /> Kết thúc ở {clock(time)}
                    </Button>
                    <Button type="button" variant={loop ? 'default' : 'outline'} size="sm" onClick={toggleLoop}>
                        <Repeat /> Nghe lặp đoạn đã chọn
                    </Button>
                </div>
            </div>
            <canvas ref={canvas} onClick={clickWave} className="text-primary h-16 w-full cursor-pointer" />
            <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 text-xs">
                <span>
                    Đoạn dùng cho MV: {clock(from)} – {clock(to)} ({Math.max(0, Math.round(to - from))} giây)
                </span>
                {(start !== null || end !== null) && (
                    <button
                        type="button"
                        className="text-primary hover:underline"
                        onClick={() => {
                            onStart(null);
                            onEnd(null);
                        }}
                    >
                        Dùng cả bài
                    </button>
                )}
            </div>

            <div ref={list} className="bg-muted/30 max-h-72 overflow-y-auto rounded-md p-3 text-sm leading-relaxed">
                {lines.length > 0 ? (
                    lines.map((line, i) => (
                        <button
                            key={i}
                            type="button"
                            data-line={i}
                            onClick={() => seek(line.start, true)}
                            className={`block w-full rounded px-2 py-0.5 text-left transition ${i === active ? 'bg-primary text-primary-foreground font-medium' : 'hover:bg-muted'} ${duration && (line.start < from || line.start >= to) ? 'opacity-40' : ''}`}
                        >
                            <span className="mr-2 font-mono text-[10px] opacity-70">{clock(line.start)}</span>
                            {line.text}
                        </button>
                    ))
                ) : lyrics.trim() ? (
                    <p className="whitespace-pre-wrap">{lyrics}</p>
                ) : (
                    <p className="text-muted-foreground">Chưa có lời: dán lời bài hát bên dưới, hoặc lấy lời theo thời gian.</p>
                )}
            </div>
        </div>
    );
}
