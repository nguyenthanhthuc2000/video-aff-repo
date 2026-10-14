import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Flame, GraduationCap, ListChecks, Loader2, Mic, UserRound, Video } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Học tiếng Anh', href: '/english' }];

interface DayItem {
    day: number;
    kind: 'sentences' | 'speaking';
    topic: string;
    topic_vi: string;
    lesson_id: number | null;
    status: string | null;
    recordings: number;
    has_video: boolean;
}

interface Week {
    number: number;
    theme: string;
    theme_vi: string;
    grammar: string;
    days: DayItem[];
}

interface NextDay {
    day: number;
    week: number;
    kind: 'sentences' | 'speaking';
    level: string;
    topic: string;
    topic_vi: string;
    exists: boolean;
}

/** Lộ trình 84 ngày A2 → B1: tiến độ, chuỗi ngày luyện, bài tiếp theo. */
export default function EnglishIndex({
    weeks,
    next,
    stats,
    price,
    profile,
    prepare,
}: {
    weeks: Week[];
    next: NextDay;
    stats: { done: number; videos: number; streak: number; total: number };
    price: number;
    profile: string | null;
    prepare: { missing: number; writing: number; failed: number };
}) {
    usePoll(prepare.writing > 0, ['weeks', 'next', 'prepare', 'stats'], 10000);
    const toWrite = prepare.missing + prepare.failed;
    const prepareAll = () => {
        if (
            confirm(
                `Claude soạn trước ${toWrite} bài còn lại của lộ trình, lần lượt từng ngày (khoảng 1 phút mỗi bài), khoảng ${formatNumber(toWrite * price)} Credits. Tiếp tục?`,
            )
        ) {
            router.post(route('english.prepare'), {}, { preserveScroll: true });
        }
    };
    const form = useForm({ english_profile: profile ?? '' });
    const nextLesson = weeks.flatMap((w) => w.days).find((d) => d.day === next.day);
    const start = () => {
        if (nextLesson?.lesson_id) {
            router.visit(route('english.show', nextLesson.lesson_id));
        } else if (confirm(`Claude soạn bài Day ${next.day} (${next.topic}), khoảng ${formatNumber(price)} Credits. Tiếp tục?`)) {
            router.post(route('english.start', next.day));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Học tiếng Anh mỗi ngày" />
            <div className="max-w-5xl space-y-6 p-4">
                <Heading
                    title="Học tiếng Anh mỗi ngày"
                    description="Luyện nói A2 → B1 trong 84 ngày. Mỗi ngày nghe mẫu, đọc lại, app chấm phát âm; bài luyện thành video Shorts luôn."
                />
                <FlashStatus />

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Tiến độ</p>
                        <p className="text-2xl font-semibold">
                            {stats.done}/{stats.total} ngày
                        </p>
                        <div className="bg-muted mt-2 h-1.5 overflow-hidden rounded-full">
                            <div className="bg-primary h-full" style={{ width: `${(stats.done / stats.total) * 100}%` }} />
                        </div>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Chuỗi ngày luyện</p>
                        <p className="flex items-center gap-2 text-2xl font-semibold">
                            <Flame className={cn('size-6', stats.streak > 0 ? 'text-orange-500' : 'text-muted-foreground')} /> {stats.streak} ngày
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Video đã làm</p>
                        <p className="flex items-center gap-2 text-2xl font-semibold">
                            <Video className="size-6" /> {stats.videos}
                        </p>
                    </div>
                </div>

                <div className="bg-primary/5 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4">
                    <div>
                        <p className="text-muted-foreground text-xs">
                            Tuần {next.week} · {next.level} · {next.kind === 'speaking' ? 'Nói 30 giây' : '5 câu mỗi ngày'}
                        </p>
                        <p className="text-lg font-semibold">
                            Day {next.day}: {next.topic}
                        </p>
                        <p className="text-muted-foreground text-sm">{next.topic_vi}</p>
                    </div>
                    <Button size="lg" onClick={start}>
                        <GraduationCap /> {nextLesson?.lesson_id ? `Tiếp tục Day ${next.day}` : `Bắt đầu Day ${next.day}`}
                    </Button>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm">
                    <p className="text-muted-foreground">
                        {prepare.writing > 0
                            ? `Claude đang soạn trước: còn ${prepare.writing} bài trong hàng đợi.`
                            : toWrite > 0
                              ? `Còn ${toWrite} bài chưa soạn${prepare.failed > 0 ? ` (${prepare.failed} bài soạn lỗi)` : ''}. Soạn trước để ngày nào cũng có bài sẵn.`
                              : 'Đã soạn sẵn cả lộ trình 84 bài.'}
                    </p>
                    {(toWrite > 0 || prepare.writing > 0) && (
                        <Button variant="outline" size="sm" onClick={prepareAll} disabled={prepare.writing > 0 || toWrite === 0}>
                            {prepare.writing > 0 ? <Loader2 className="animate-spin" /> : <ListChecks />}
                            {prepare.writing > 0 ? 'Đang soạn...' : `Soạn trước ${toWrite} bài`}
                        </Button>
                    )}
                </div>

                <form
                    className="space-y-2 rounded-xl border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(route('english.profile'), { preserveScroll: true });
                    }}
                >
                    <label htmlFor="english_profile" className="flex items-center gap-2 font-semibold">
                        <UserRound className="size-5" /> Về bạn
                    </label>
                    <p className="text-muted-foreground text-sm">
                        Claude soạn câu theo đúng đời sống của bạn (tên, quê, nghề, gia đình...), để mỗi câu bạn nói là chuyện của chính mình.
                    </p>
                    <Textarea
                        id="english_profile"
                        rows={3}
                        value={form.data.english_profile}
                        onChange={(e) => form.setData('english_profile', e.target.value)}
                        placeholder="Ví dụ: Tên Thức, 26 tuổi, quê Tiền Giang, sống ở TP.HCM, lập trình viên, đã có vợ."
                    />
                    {form.errors.english_profile && <p className="text-destructive text-sm">{form.errors.english_profile}</p>}
                    <Button type="submit" size="sm" variant="outline" disabled={form.processing || !form.isDirty}>
                        Lưu
                    </Button>
                </form>

                <div className="space-y-4">
                    {weeks.map((week) => (
                        <section key={week.number} className="rounded-xl border p-4">
                            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                                <h2 className="font-semibold">
                                    Tuần {week.number}: {week.theme_vi} <span className="text-muted-foreground font-normal">({week.theme})</span>
                                </h2>
                                <p className="text-muted-foreground text-xs">Ngữ pháp: {week.grammar}</p>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-7">
                                {week.days.map((day) => {
                                    const body = (
                                        <>
                                            <span className="flex items-center justify-between text-xs font-medium">
                                                Day {day.day}
                                                {day.has_video ? (
                                                    <Video className="size-3.5 text-green-600" />
                                                ) : day.recordings > 0 ? (
                                                    <Check className="size-3.5 text-green-600" />
                                                ) : day.kind === 'speaking' ? (
                                                    <Mic className="text-muted-foreground size-3.5" />
                                                ) : null}
                                            </span>
                                            <span className="line-clamp-2 text-xs">{day.topic_vi}</span>
                                        </>
                                    );
                                    const style = cn(
                                        'flex min-h-16 flex-col gap-1 rounded-lg border p-2 text-left',
                                        day.kind === 'speaking' && 'border-dashed',
                                        day.day === next.day && 'ring-primary ring-2',
                                    );

                                    return day.lesson_id ? (
                                        <Link key={day.day} href={route('english.show', day.lesson_id)} className={cn(style, 'hover:bg-muted/50')}>
                                            {body}
                                        </Link>
                                    ) : (
                                        <div key={day.day} className={cn(style, 'text-muted-foreground opacity-70')}>
                                            {body}
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
