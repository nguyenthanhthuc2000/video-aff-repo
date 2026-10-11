import { Button } from '@/components/ui/button';
import { type MusePlan, type VideoProject } from '@/types/models';
import { router } from '@inertiajs/react';
import { LoaderCircle, Sparkles } from 'lucide-react';

const seconds = (value: number) => `${Math.round(value * 10) / 10}s`;

/** Phân cảnh Muse lên từ video mẫu và nhạc nền: kịch bản, ảnh và clip làm theo đúng các shot này. */
export default function MusePlanPanel({ project, canReplan }: { project: VideoProject; canReplan: boolean }) {
    const status = project.muse_plan_status;
    const plan = project.muse_plan;
    if (!status && !plan) return null;

    const replan = () => router.post(route('projects.muse-plan', project.id), {}, { preserveScroll: true });

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">Phân cảnh Muse (xem video mẫu, nghe nhạc)</p>
                {canReplan && status !== 'pending' && (
                    <Button size="sm" variant="outline" onClick={replan}>
                        <Sparkles />
                        Muse phân tích lại
                    </Button>
                )}
            </div>
            {status === 'pending' && (
                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                    <LoaderCircle className="size-4 animate-spin" />
                    Muse đang xem video mẫu và nghe nhạc để lên phân cảnh (thường 5–30 phút), xong sẽ tự viết kịch bản.
                </p>
            )}
            {status === 'failed' && (
                <p className="text-destructive text-sm">{project.muse_plan_error} Kịch bản được viết không theo phân cảnh.</p>
            )}
            {plan && (
                <>
                    {plan.summary && <p className="text-sm">{plan.summary}</p>}
                    <p className="text-muted-foreground text-xs">
                        {plan.shots.length} shot · {seconds(plan.shots.reduce((sum, shot) => sum + shot.duration, 0))}
                        {plan.music_start != null && ` · nhạc bắt đầu từ giây ${Math.round(plan.music_start * 10) / 10}`}
                        {plan.tempo_bpm ? ` · ${Math.round(plan.tempo_bpm)} BPM` : ''}
                        {plan.mood ? ` · ${plan.mood}` : ''}
                    </p>
                    <ol className="space-y-2 text-sm">
                        {plan.shots.map((shot, i) => (
                            <li key={i} className="rounded-md border p-2">
                                <p className="font-medium">
                                    Shot {i + 1} · {seconds(shot.duration)}
                                    {shot.duration > 10 && <span className="text-muted-foreground font-normal"> (shot dài, Muse làm {Math.ceil(shot.duration / 10 - 0.005)} đoạn nối liền)</span>}
                                    {shot.sample_start != null && shot.sample_end != null && (
                                        <span className="text-muted-foreground font-normal">
                                            {' '}
                                            · video mẫu {seconds(shot.sample_start)}–{seconds(shot.sample_end)}
                                        </span>
                                    )}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {shot.action} {shot.camera && `Máy quay: ${shot.camera}.`} {shot.expression && `Biểu cảm: ${shot.expression}.`}
                                </p>
                                {shot.mouth.some((part) => part.state === 'sing') && (
                                    <p className="text-xs">
                                        {shot.mouth
                                            .map((part) => `${seconds(part.start)}–${seconds(part.end)} ${part.state === 'sing' ? `hát "${part.words}"` : 'ngậm miệng'}`)
                                            .join(' · ')}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                </>
            )}
        </div>
    );
}
