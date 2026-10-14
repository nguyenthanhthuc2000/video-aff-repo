import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Copy, Download, Lightbulb, Loader2, RefreshCw, Sparkles, Trash2, Video } from 'lucide-react';
import { useState } from 'react';
import Recorder, { PlayButton } from './recorder';

interface Mark {
    word: string;
    mark: 'ok' | 'unclear' | 'wrong';
}

interface RecordingInfo {
    id: number;
    url: string | null;
    score: number | null;
    transcript: string | null;
    words: Mark[];
    duration_ms: number;
}

interface Sentence {
    en: string;
    vi: string;
    key_word: string;
    key_ipa: string;
    tip_vi: string;
    audio_url: string | null;
    best: RecordingInfo | null;
    attempts: number;
}

interface Lesson {
    id: number;
    day: number;
    kind: 'sentences' | 'speaking';
    kind_label: string;
    level: string;
    topic: string;
    topic_vi: string;
    week: number;
    theme_vi: string;
    grammar: string;
    status: 'writing' | 'ready' | 'failed';
    error: string | null;
    sentences: Sentence[];
    sound_focus_vi: string | null;
    question: string | null;
    prompt: string | null;
    prompt_vi: string | null;
    phrases: { en: string; vi: string }[];
    sample_answer: string | null;
    speech: RecordingInfo | null;
    feedback: {
        status: 'correcting' | 'ready' | 'failed';
        error?: string | null;
        corrections?: { said: string; better: string; explain_vi: string; audio_url: string | null }[];
        praise_vi?: string;
        next_vi?: string;
        stale: boolean;
    } | null;
    post: { title: string; description: string; hashtags: string[] } | null;
    video: {
        status: 'rendering' | 'done' | 'failed' | null;
        error: string | null;
        url: string | null;
        thumbnail_url: string | null;
        rendered_at: string | null;
        music_track_id: number | null;
    };
}

const scoreColor = (score: number | null) =>
    score === null ? 'secondary' : score >= 8 ? 'bg-green-600 text-white' : score >= 5 ? 'bg-amber-500 text-white' : 'bg-red-500 text-white';

function MarkedSentence({ words }: { words: Mark[] }) {
    return (
        <p className="text-sm">
            {words.map((w, i) => (
                <span key={i} className={cn(w.mark === 'wrong' && 'text-red-600 underline decoration-2', w.mark === 'unclear' && 'text-amber-600')}>
                    {w.word}{' '}
                </span>
            ))}
        </p>
    );
}

/** Một ngày học: nghe mẫu, thu giọng, xem điểm; bài nói thì Claude sửa lỗi; cuối cùng dựng video Shorts. */
export default function EnglishLesson({
    lesson,
    musicTracks,
    pricing,
    previous,
    next,
}: {
    lesson: Lesson;
    musicTracks: { id: number; name: string }[];
    pricing: { write: number; video: number };
    previous: number | null;
    next: number | null;
}) {
    usePoll(lesson.status === 'writing' || lesson.video.status === 'rendering' || lesson.feedback?.status === 'correcting', ['lesson'], 3000);
    const [music, setMusic] = useState<number | null>(lesson.video.music_track_id);
    const [showSample, setShowSample] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Học tiếng Anh', href: '/english' },
        { title: `Day ${lesson.day}`, href: route('english.show', lesson.id) },
    ];
    const recorded = lesson.kind === 'speaking' ? (lesson.speech ? 1 : 0) : lesson.sentences.filter((s) => s.best).length;
    const rewrite = () => {
        if (confirm(`Soạn lại bài Day ${lesson.day}? Các bản thu của bài này sẽ bị xoá. Khoảng ${formatNumber(pricing.write)} Credits.`)) {
            router.post(route('english.start', lesson.day));
        }
    };
    const makeVideo = () => {
        const missing = lesson.kind === 'sentences' ? lesson.sentences.length - recorded : 0;
        const note = missing > 0 ? `\n${missing} câu chưa thu sẽ hiện "Đến lượt bạn!" để người xem đọc theo.` : '';
        if (confirm(`Dựng video Shorts Day ${lesson.day}, khoảng ${formatNumber(pricing.video)} Credits.${note}\nTiếp tục?`)) {
            router.post(route('english.video', lesson.id), { music_track_id: music, music_volume: 10 }, { preserveScroll: true });
        }
    };
    const copyPost = () => {
        if (lesson.post) {
            void navigator.clipboard.writeText(`${lesson.post.title}\n\n${lesson.post.description}\n\n${lesson.post.hashtags.join(' ')}`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Day ${lesson.day}: ${lesson.topic}`} />
            <div className="max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Heading
                            title={`Day ${lesson.day}: ${lesson.topic}`}
                            description={`${lesson.topic_vi} · Tuần ${lesson.week}: ${lesson.theme_vi} · ${lesson.grammar}`}
                        />
                        <Badge variant="secondary">{lesson.level}</Badge>
                        <Badge variant="outline">{lesson.kind_label}</Badge>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild disabled={!previous}>
                            {previous ? (
                                <Link href={route('english.show', previous)}>
                                    <ChevronLeft /> Day {lesson.day - 1}
                                </Link>
                            ) : (
                                <span>
                                    <ChevronLeft />
                                </span>
                            )}
                        </Button>
                        {next ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('english.show', next)}>
                                    Day {lesson.day + 1} <ChevronRight />
                                </Link>
                            </Button>
                        ) : (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('english.index')}>
                                    Lộ trình <ChevronRight />
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
                <FlashStatus />

                {lesson.status === 'writing' && (
                    <div className="text-muted-foreground flex items-center gap-2 rounded-xl border p-6 text-sm">
                        <Loader2 className="animate-spin" /> Claude đang soạn bài và đọc giọng mẫu...
                    </div>
                )}
                {lesson.status === 'failed' && (
                    <div className="border-destructive/40 bg-destructive/5 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm">
                        <span className="text-destructive">Soạn bài lỗi: {lesson.error}</span>
                        <Button size="sm" onClick={() => router.post(route('english.start', lesson.day))}>
                            <RefreshCw /> Soạn lại
                        </Button>
                    </div>
                )}

                {lesson.status === 'ready' && lesson.kind === 'sentences' && (
                    <section className="space-y-3">
                        {lesson.sound_focus_vi && (
                            <p className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                <Lightbulb className="mt-0.5 size-4 shrink-0" /> Âm luyện hôm nay: {lesson.sound_focus_vi}
                            </p>
                        )}
                        {lesson.sentences.map((s, i) => (
                            <div key={i} className="space-y-2 rounded-xl border p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-muted-foreground text-xs">Câu {i + 1}</p>
                                        <p className="text-lg font-semibold">{s.en}</p>
                                        <p className="text-muted-foreground text-sm">{s.vi}</p>
                                        {s.key_word && (
                                            <p className="mt-1 text-sm">
                                                <span className="font-medium">{s.key_word}</span>{' '}
                                                <span className="text-primary font-mono">{s.key_ipa}</span>
                                                {s.tip_vi && <span className="text-muted-foreground"> · {s.tip_vi}</span>}
                                            </p>
                                        )}
                                    </div>
                                    {s.best && (
                                        <span className={cn('rounded-lg px-3 py-1 text-lg font-bold', scoreColor(s.best.score))}>
                                            {s.best.score}/10
                                        </span>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <PlayButton url={s.audio_url} label="Nghe mẫu" />
                                    <Recorder lessonId={lesson.id} item={i} maxSeconds={20} label={s.best ? 'Đọc lại' : 'Thu giọng'} />
                                    {s.best && <PlayButton url={s.best.url} label="Nghe mình đọc" />}
                                    {s.attempts > 0 && (
                                        <span className="text-muted-foreground text-xs">{s.attempts} lần đọc · giữ lần điểm cao nhất</span>
                                    )}
                                </div>
                                {s.best && (
                                    <div className="bg-muted/40 space-y-1 rounded-lg p-2">
                                        <MarkedSentence words={s.best.words} />
                                        <p className="text-muted-foreground text-xs">
                                            App nghe được: “{s.best.transcript}” · <span className="text-red-600">đỏ</span> = thiếu / nghe thành từ
                                            khác, <span className="text-amber-600">vàng</span> = chưa rõ
                                        </p>
                                    </div>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                {lesson.status === 'ready' && lesson.kind === 'speaking' && (
                    <section className="space-y-3">
                        <div className="space-y-2 rounded-xl border p-4">
                            <p className="text-lg font-semibold">{lesson.prompt}</p>
                            <p className="text-muted-foreground text-sm">{lesson.prompt_vi}</p>
                            <div className="grid gap-1 sm:grid-cols-2">
                                {lesson.phrases.map((p, i) => (
                                    <p key={i} className="text-sm">
                                        <span className="font-medium">{p.en}</span> <span className="text-muted-foreground">· {p.vi}</span>
                                    </p>
                                ))}
                            </div>
                            {lesson.sample_answer && (
                                <div>
                                    <button type="button" className="text-primary text-xs underline" onClick={() => setShowSample(!showSample)}>
                                        {showSample ? 'Ẩn bài mẫu' : 'Xem bài mẫu (nên tự nói trước)'}
                                    </button>
                                    {showSample && <p className="bg-muted/40 mt-1 rounded-lg p-2 text-sm">{lesson.sample_answer}</p>}
                                </div>
                            )}
                        </div>
                        <div className="space-y-2 rounded-xl border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <Recorder lessonId={lesson.id} item={0} maxSeconds={90} label={lesson.speech ? 'Nói lại' : 'Bắt đầu nói'} />
                                {lesson.speech && <PlayButton url={lesson.speech.url} label="Nghe lại" />}
                                <span className="text-muted-foreground text-xs">Nói khoảng 30–60 giây, tối đa 90 giây.</span>
                            </div>
                            {lesson.speech && <p className="bg-muted/40 rounded-lg p-2 text-sm">“{lesson.speech.transcript}”</p>}
                            {lesson.speech && (
                                <Button
                                    size="sm"
                                    variant={lesson.feedback?.status === 'ready' && !lesson.feedback.stale ? 'outline' : 'default'}
                                    disabled={lesson.feedback?.status === 'correcting'}
                                    onClick={() => router.post(route('english.correct', lesson.id), {}, { preserveScroll: true })}
                                >
                                    {lesson.feedback?.status === 'correcting' ? <Loader2 className="animate-spin" /> : <Sparkles />}
                                    {lesson.feedback?.status === 'correcting' ? 'Claude đang sửa...' : 'Claude sửa lỗi'}
                                    <span className="text-xs opacity-70">~{formatNumber(pricing.write)} Credits</span>
                                </Button>
                            )}
                            {lesson.feedback?.status === 'failed' && <p className="text-destructive text-sm">Lỗi: {lesson.feedback.error}</p>}
                            {lesson.feedback?.stale && lesson.feedback.status === 'ready' && (
                                <p className="text-xs text-amber-600">Bạn vừa nói lại: bấm "Claude sửa lỗi" để sửa theo lần nói mới.</p>
                            )}
                        </div>
                        {lesson.feedback?.status === 'ready' && (
                            <div className="space-y-2 rounded-xl border p-4">
                                {lesson.feedback.praise_vi && (
                                    <p className="text-sm text-green-700 dark:text-green-400">{lesson.feedback.praise_vi}</p>
                                )}
                                {lesson.feedback.corrections?.map((c, i) => (
                                    <div key={i} className="space-y-1 rounded-lg border p-3">
                                        <p className="text-sm text-red-600 line-through">{c.said}</p>
                                        <p className="font-medium text-green-700 dark:text-green-400">{c.better}</p>
                                        <p className="text-muted-foreground text-xs">{c.explain_vi}</p>
                                        <PlayButton url={c.audio_url} label="Nghe câu đúng" />
                                    </div>
                                ))}
                                {lesson.feedback.next_vi && <p className="text-muted-foreground text-sm">Lần sau: {lesson.feedback.next_vi}</p>}
                            </div>
                        )}
                    </section>
                )}

                {lesson.status === 'ready' && (
                    <section className="space-y-3 rounded-xl border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="flex items-center gap-2 text-lg font-semibold">
                                    <Video className="size-5" /> Video Shorts
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    Dọc 9:16, dưới 1 phút: giọng mẫu, giọng bạn kèm điểm, nghĩa tiếng Việt, câu hỏi cho người xem.
                                </p>
                            </div>
                            <Button
                                onClick={makeVideo}
                                disabled={lesson.video.status === 'rendering' || (lesson.kind === 'speaking' && !lesson.speech)}
                            >
                                {lesson.video.status === 'rendering' ? <Loader2 className="animate-spin" /> : <Video />}
                                {lesson.video.status === 'rendering' ? 'Đang dựng...' : lesson.video.url ? 'Dựng lại video' : 'Tạo video'}
                                <span className="text-xs opacity-70">~{formatNumber(pricing.video)} Credits</span>
                            </Button>
                        </div>
                        <select
                            aria-label="Nhạc nền"
                            className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                            value={music ?? ''}
                            onChange={(e) => setMusic(e.target.value === '' ? null : Number(e.target.value))}
                        >
                            <option value="">Không nhạc nền</option>
                            {musicTracks.map((t) => (
                                <option key={t.id} value={t.id}>
                                    Nhạc: {t.name}
                                </option>
                            ))}
                        </select>
                        {lesson.video.status === 'failed' && <p className="text-destructive text-sm">Dựng lỗi: {lesson.video.error}</p>}
                        {lesson.video.url && lesson.video.status !== 'rendering' && (
                            <div className="flex flex-wrap items-start gap-4">
                                <video
                                    key={lesson.video.url}
                                    src={lesson.video.url}
                                    controls
                                    preload="metadata"
                                    className="aspect-[9/16] w-64 rounded-lg bg-black"
                                />
                                <div className="min-w-0 flex-1 space-y-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={route('english.video.download', lesson.id)}>
                                            <Download /> Tải video (.mp4)
                                        </a>
                                    </Button>
                                    {lesson.video.thumbnail_url && (
                                        <div className="flex items-end gap-2">
                                            <img
                                                src={lesson.video.thumbnail_url}
                                                alt="Ảnh bìa"
                                                className="aspect-[9/16] w-20 rounded-md border object-cover"
                                            />
                                            <Button variant="outline" size="sm" asChild>
                                                <a href={route('english.thumbnail', lesson.id)}>
                                                    <Download /> Tải ảnh bìa
                                                </a>
                                            </Button>
                                        </div>
                                    )}
                                    {lesson.post && (
                                        <div className="bg-muted/40 space-y-1 rounded-lg p-3 text-sm">
                                            <p className="font-medium">{lesson.post.title}</p>
                                            <p className="text-muted-foreground whitespace-pre-line">{lesson.post.description}</p>
                                            <p className="text-primary">{lesson.post.hashtags.join(' ')}</p>
                                            <Button variant="outline" size="sm" onClick={copyPost}>
                                                <Copy /> Chép tiêu đề + mô tả
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </section>
                )}

                {lesson.status === 'ready' && (
                    <Button variant="ghost" size="sm" onClick={rewrite}>
                        <Trash2 /> Soạn lại bài này
                    </Button>
                )}
            </div>
        </AppLayout>
    );
}
