import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Loader2, Plus, RotateCcw, Volume2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export interface PronunciationRow {
    word: string;
    say: string;
    custom: boolean;
}

/** Nghe thử một cách đọc bằng giọng Việt (máy đọc đúng chữ gõ trong ô). */
function useListen() {
    const [playing, setPlaying] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const listen = (say: string) => {
        if (say.trim() === '') return;
        setError(null);
        setPlaying(say);
        const audio = new Audio(route('pronunciations.preview', { say }));
        audio.onended = () => setPlaying(null);
        audio.onerror = () => {
            setPlaying(null);
            setError('Không phát được giọng đọc (dịch vụ audio trong docker có đang chạy không?).');
        };
        audio.play().catch(() => setPlaying(null));
    };

    return { playing, error, listen };
}

/**
 * Bảng "Cách đọc tên tiếng Anh": giọng Việt đánh vần chữ Anh như chữ Việt, nên trước khi đọc, tên được đổi sang cách đọc
 * kiểu Việt (Goku → Gô cu). Chỉ đổi lời đọc, phụ đề và chữ trên ảnh giữ tên gốc.
 */
export function PronunciationPanel({ rows }: { rows: PronunciationRow[] }) {
    const { playing, error, listen } = useListen();
    const [word, setWord] = useState('');
    const [say, setSay] = useState('');
    const add: FormEventHandler = (e) => {
        e.preventDefault();
        router.post(
            route('pronunciations.store'),
            { word, say },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setWord('');
                    setSay('');
                },
            },
        );
    };

    return (
        <section className="space-y-3 rounded-lg border p-4">
            <div>
                <h2 className="font-semibold">Cách đọc tên tiếng Anh</h2>
                <p className="text-muted-foreground text-sm">
                    Giọng Việt đọc tên theo cách viết ở cột phải. Phụ đề vẫn giữ tên gốc. Sửa cho đúng tai bạn, bấm loa để nghe thử, rồi dựng lại
                    video.
                </p>
            </div>
            {rows.length === 0 && <p className="text-muted-foreground text-sm">Lời kể không có tên tiếng Anh nào trong bảng.</p>}
            <div className="divide-y">
                {rows.map((row) => (
                    <Row key={`${row.word}:${row.say}`} row={row} playing={playing} listen={listen} />
                ))}
            </div>
            <form onSubmit={add} className="flex flex-wrap items-center gap-2 border-t pt-3">
                <Input className="w-40" value={word} onChange={(e) => setWord(e.target.value)} placeholder="Tên gốc (Hit)" aria-label="Tên gốc" />
                <Input
                    className="min-w-40 flex-1"
                    value={say}
                    onChange={(e) => setSay(e.target.value)}
                    placeholder="Cách đọc (Hít)"
                    aria-label="Cách đọc"
                />
                <ListenButton say={say} playing={playing} listen={listen} />
                <Button size="sm" variant="outline" disabled={word.trim() === '' || say.trim() === ''}>
                    <Plus /> Thêm
                </Button>
            </form>
            {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}
        </section>
    );
}

function Row({ row, playing, listen }: { row: PronunciationRow; playing: string | null; listen: (say: string) => void }) {
    const [say, setSay] = useState(row.say);
    const save = () => router.post(route('pronunciations.store'), { word: row.word, say }, { preserveScroll: true });
    const reset = () => router.delete(route('pronunciations.destroy'), { data: { word: row.word }, preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-2 py-2">
            <span className="w-40 truncate text-sm font-medium capitalize" title={row.word}>
                {row.word}
            </span>
            <Input className="min-w-40 flex-1" value={say} onChange={(e) => setSay(e.target.value)} aria-label={`Cách đọc ${row.word}`} />
            <ListenButton say={say} playing={playing} listen={listen} />
            <Button size="sm" variant="outline" onClick={save} disabled={say.trim() === '' || say === row.say}>
                Lưu
            </Button>
            {row.custom && (
                <Button size="icon" variant="ghost" onClick={reset} title="Bỏ cách đọc riêng" aria-label={`Bỏ cách đọc riêng ${row.word}`}>
                    <RotateCcw />
                </Button>
            )}
        </div>
    );
}

function ListenButton({ say, playing, listen }: { say: string; playing: string | null; listen: (say: string) => void }) {
    return (
        <Button
            type="button"
            size="icon"
            variant="ghost"
            onClick={() => listen(say)}
            disabled={say.trim() === ''}
            title="Nghe thử"
            aria-label="Nghe thử"
        >
            {playing === say ? <Loader2 className="animate-spin" /> : <Volume2 />}
        </Button>
    );
}
