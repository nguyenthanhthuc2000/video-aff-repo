import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Loader2, Mic, Pause, Play, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** Nút phát một file âm thanh (mỗi lúc chỉ một nút phát). */
export function PlayButton({ url, label, size = 'sm' }: { url: string | null; label: string; size?: 'sm' | 'default' }) {
    const [playing, setPlaying] = useState(false);
    const audio = useRef<HTMLAudioElement | null>(null);
    useEffect(() => () => audio.current?.pause(), []);
    const toggle = () => {
        if (!url) {
            return;
        }
        if (playing && audio.current) {
            audio.current.pause();
            setPlaying(false);
            return;
        }
        window.dispatchEvent(new Event('english-audio-play'));
        audio.current = new Audio(url);
        audio.current.onended = () => setPlaying(false);
        void audio.current.play();
        setPlaying(true);
    };
    useEffect(() => {
        const stop = () => {
            audio.current?.pause();
            setPlaying(false);
        };
        window.addEventListener('english-audio-play', stop);
        return () => window.removeEventListener('english-audio-play', stop);
    }, []);

    return (
        <Button type="button" variant="outline" size={size} onClick={toggle} disabled={!url}>
            {playing ? <Pause /> : <Play />} {label}
        </Button>
    );
}

/** Thu giọng trên trình duyệt rồi gửi lên chấm (tự dừng sau maxSeconds). */
export default function Recorder({
    lessonId,
    item,
    maxSeconds,
    label = 'Thu giọng',
}: {
    lessonId: number;
    item: number;
    maxSeconds: number;
    label?: string;
}) {
    const [state, setState] = useState<'idle' | 'recording' | 'uploading'>('idle');
    const [seconds, setSeconds] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const recorder = useRef<MediaRecorder | null>(null);
    const timer = useRef<number | null>(null);

    const stop = () => {
        if (timer.current) {
            window.clearInterval(timer.current);
        }
        if (recorder.current?.state === 'recording') {
            recorder.current.stop();
        }
    };

    useEffect(() => stop, []);

    const start = async () => {
        setError(null);
        let stream: MediaStream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
        } catch {
            setError('Trình duyệt chưa cho dùng micro. Bấm biểu tượng ổ khoá trên thanh địa chỉ → cho phép Micro.');
            return;
        }
        window.dispatchEvent(new Event('english-audio-play'));
        const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/webm'].find((t) => MediaRecorder.isTypeSupported(t)) ?? '';
        const media = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
        const chunks: Blob[] = [];
        media.ondataavailable = (e) => {
            if (e.data.size > 0) {
                chunks.push(e.data);
            }
        };
        media.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            const blob = new Blob(chunks, { type: media.mimeType });
            if (blob.size < 1000) {
                setState('idle');
                return;
            }
            setState('uploading');
            const extension = media.mimeType.includes('mp4') ? 'm4a' : 'webm';
            router.post(
                route('english.recordings.store', lessonId),
                { item, audio: new File([blob], `recording.${extension}`, { type: media.mimeType }) },
                { forceFormData: true, preserveScroll: true, onFinish: () => setState('idle') },
            );
        };
        recorder.current = media;
        media.start();
        setSeconds(0);
        setState('recording');
        const started = Date.now();
        timer.current = window.setInterval(() => {
            const elapsed = Math.floor((Date.now() - started) / 1000);
            setSeconds(elapsed);
            if (elapsed >= maxSeconds) {
                stop();
            }
        }, 250);
    };

    return (
        <div className="flex flex-col items-start gap-1">
            {state === 'recording' ? (
                <Button type="button" variant="destructive" size="sm" onClick={stop}>
                    <Square /> Dừng ({seconds}s / {maxSeconds}s)
                </Button>
            ) : (
                <Button type="button" size="sm" onClick={start} disabled={state === 'uploading'}>
                    {state === 'uploading' ? <Loader2 className="animate-spin" /> : <Mic />}
                    {state === 'uploading' ? 'Đang chấm...' : label}
                </Button>
            )}
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}
