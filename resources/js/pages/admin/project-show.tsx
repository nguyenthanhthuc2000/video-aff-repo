import ProjectCostDialog, { type ProjectCost, usd } from '@/components/admin/project-cost-dialog';
import ProgressBar from '@/components/progress-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { formatDuration } from '@/lib/duration';
import { projectStatusLabels } from '@/lib/project-status';
import { cn } from '@/lib/utils';
import { type Background, type VideoProjectStatus } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ChartPie, Check, Clock, Coins, Copy, FileText, Film, Image as ImageIcon, Video } from 'lucide-react';
import { useState } from 'react';

interface TimelineStep {
    label: string;
    started_at: string | null;
    ended_at: string | null;
    duration_ms: number | null;
    count: number;
}

interface SceneImageAttempt {
    id: number;
    status: string;
    url: string | null;
    provider: string | null;
    request_id: string | null;
    prompt: string | null;
    error: string | null;
    created_at: string;
    duration_ms: number | null;
}

interface AdminScene {
    id: number;
    position: number;
    phase: 'before' | 'after' | null;
    duration_ms: number;
    visual: string;
    on_screen_text: string | null;
    voiceover: string | null;
    image_prompt: string;
    full_prompt: string;
    background: Background | null;
    motion: string | null;
    motion_prompt: string | null;
    motion_prompt_sent: boolean;
    clip: {
        status: string | null;
        url: string | null;
        request_id: string | null;
        error: string | null;
        started_at: string | null;
        duration_ms: number | null;
    };
    images: SceneImageAttempt[];
}

interface ProfitRow {
    key: string;
    label: string;
    count: number;
    cost_usd: number;
    cost_vnd: number;
    sale_credits: number;
    sale_vnd: number;
    profit_vnd: number;
    margin: number | null;
    shared_with: number | null;
    note: string | null;
}

interface Profit {
    estimated: boolean;
    usd_vnd: number;
    rows: ProfitRow[];
    totals: { cost_usd: number; cost_vnd: number; sale_credits: number; sale_vnd: number; profit_vnd: number; margin: number | null };
}

interface Props {
    profit: Profit;
    project: {
        id: number;
        status: VideoProjectStatus;
        video_type: 'standard' | 'transformation';
        progress: { percent: number; label: string };
        error: string | null;
        created_at: string;
        user: { id: number | null; name: string | null; email: string | null };
        product: { id: number | null; name: string | null; image_url: string | null };
        fashion_model: { name: string; photo_url: string | null } | null;
        before_fashion_model: { name: string; photo_url: string | null } | null;
        motion_styles: string[];
        sample_video: string | null;
        audio_track: string | null;
        backgrounds: Background[];
        script: { hooks: string[]; hook_themes?: string[]; recommended_hook?: number; caption: string; hashtags: string[] } | null;
        selected_hook: number | null;
    };
    scenes: AdminScene[];
    renders: {
        id: number;
        status: string;
        url: string | null;
        duration_ms: number | null;
        error: string | null;
        created_at: string;
        processing_ms: number | null;
    }[];
    timeline: { total_ms: number | null; done: boolean; steps: Record<string, TimelineStep> };
    cost: ProjectCost;
    usdVnd: number;
}

const stepIcons: Record<string, typeof FileText> = { script: FileText, images: ImageIcon, clips: Video, render: Film };
const vnd = (value: number) => Math.round(value).toLocaleString('vi-VN');
const usdText = (value: number) => `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: value < 1 ? 4 : 2 })}`;
const date = (value: string | null) => (value ? new Date(value).toLocaleString('vi-VN') : '—');

export default function AdminProjectShow({ project, scenes, renders, timeline, cost, profit, usdVnd }: Props) {
    const [costOpen, setCostOpen] = useState(false);
    const maxStep = Math.max(1, ...Object.values(timeline.steps).map((step) => step.duration_ms ?? 0));

    return (
        <AdminLayout title={`Video #${project.id}`}>
            <Head title={`Video #${project.id}`} />
            <div className="space-y-6">
                <Link href={route('admin.projects.index')} className="text-muted-foreground inline-flex items-center gap-1 text-sm hover:underline">
                    <ArrowLeft className="size-4" /> Dự án video
                </Link>

                <div className="flex flex-wrap items-start gap-4">
                    {project.product.image_url && <img src={project.product.image_url} alt="" className="size-16 rounded-lg object-cover" />}
                    <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-muted-foreground text-sm">#{project.id}</span>
                            <h1 className="text-xl font-semibold">{project.product.name ?? '—'}</h1>
                            <Badge variant={project.status === 'failed' ? 'destructive' : 'secondary'}>{projectStatusLabels[project.status]}</Badge>
                            {project.video_type === 'transformation' && <Badge variant="outline">Biến hình</Badge>}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {project.user.id ? (
                                <Link href={route('admin.users.show', project.user.id)} className="underline">
                                    {project.user.name} · {project.user.email}
                                </Link>
                            ) : (
                                '—'
                            )}{' '}
                            · tạo {date(project.created_at)}
                        </p>
                        <ProgressBar
                            className="max-w-md"
                            percent={project.progress.percent}
                            label={project.progress.label}
                            failed={project.status === 'failed'}
                        />
                        {project.error && <p className="text-sm text-red-600 dark:text-red-400">{project.error}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => setCostOpen(true)}>
                            <ChartPie /> Chi phí AI {usd(cost.total_usd)}
                        </Button>
                        <span className="inline-flex items-center gap-1 rounded-md border px-3 text-sm">
                            <Coins className="size-4 text-orange-500" /> Bán {vnd(profit.totals.sale_credits)} Credits
                        </span>
                    </div>
                </div>

                <ProfitSection profit={profit} />

                {/* Cài đặt */}
                <section className="grid gap-3 rounded-xl border p-4 text-sm md:grid-cols-2 xl:grid-cols-4">
                    <Setting label="Người mẫu">
                        {project.before_fashion_model ? `${project.before_fashion_model.name} → ` : ''}
                        {project.fashion_model?.name ?? '—'}
                    </Setting>
                    <Setting label="Chuyển động">
                        {project.sample_video ? `Sao chép: ${project.sample_video}` : project.motion_styles.join(', ') || 'Ảnh tĩnh'}
                    </Setting>
                    <Setting label="Bối cảnh">
                        {project.backgrounds.length > 0 ? project.backgrounds.map((item) => item.name).join(', ') : 'AI tự đề xuất'}
                    </Setting>
                    <Setting label="Nhạc nền">{project.audio_track ?? 'Không'}</Setting>
                </section>

                {/* Thời gian xử lý */}
                <section className="space-y-3 rounded-xl border p-4">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="flex items-center gap-2 font-medium">
                            <Clock className="size-4 text-orange-500" /> Thời gian xử lý
                        </h2>
                        <p className="text-sm">
                            Tổng (tạo dự án → xuất video xong):{' '}
                            <b className="text-lg">{timeline.done ? formatDuration(timeline.total_ms) : 'chưa xong'}</b>
                        </p>
                    </div>
                    <div className="space-y-2">
                        {Object.entries(timeline.steps).map(([key, step]) => {
                            const Icon = stepIcons[key] ?? Clock;
                            return (
                                <div key={key} className="grid items-center gap-2 text-sm sm:grid-cols-[180px_1fr_120px]">
                                    <span className="flex items-center gap-2">
                                        <Icon className="text-muted-foreground size-4" /> {step.label}
                                        {step.count > 1 && <span className="text-muted-foreground text-xs">×{step.count}</span>}
                                    </span>
                                    <div className="bg-muted h-2 overflow-hidden rounded">
                                        <div
                                            className="h-full rounded bg-gradient-to-r from-orange-500 to-rose-500"
                                            style={{ width: `${((step.duration_ms ?? 0) / maxStep) * 100}%` }}
                                        />
                                    </div>
                                    <span className="text-right font-medium" title={`${date(step.started_at)} → ${date(step.ended_at)}`}>
                                        {step.started_at && !step.ended_at ? 'đang chạy' : formatDuration(step.duration_ms)}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Mỗi bước tính từ lúc bắt đầu đến lúc xong (gồm thời gian chờ hàng đợi). Tổng gồm cả thời gian chờ người dùng duyệt giữa các
                        bước. Thời gian clip chỉ có từ khi bật ghi nhận.
                    </p>
                </section>

                {/* Kịch bản */}
                {project.script && (
                    <section className="space-y-2 rounded-xl border p-4">
                        <h2 className="font-medium">Kịch bản</h2>
                        <ol className="space-y-1 text-sm">
                            {project.script.hooks.map((hook, i) => (
                                <li key={i} className="flex flex-wrap items-center gap-1.5">
                                    <span className="text-muted-foreground">Hook {i + 1}.</span>
                                    {project.script?.hook_themes?.[i] && <Badge variant="outline">{project.script.hook_themes[i]}</Badge>}
                                    {hook}
                                    {project.selected_hook === i && <Badge>Đang dùng</Badge>}
                                </li>
                            ))}
                        </ol>
                        <p className="text-sm">
                            <span className="text-muted-foreground">Caption:</span> {project.script.caption} {project.script.hashtags.join(' ')}
                        </p>
                    </section>
                )}

                {/* Phân cảnh */}
                <section className="space-y-3">
                    <h2 className="font-medium">Phân cảnh và prompt ({scenes.length} cảnh)</h2>
                    {scenes.map((scene) => (
                        <SceneCard key={scene.id} scene={scene} />
                    ))}
                </section>

                {/* Xuất video */}
                {renders.length > 0 && (
                    <section className="space-y-2 rounded-xl border p-4">
                        <h2 className="font-medium">Các lần xuất video</h2>
                        <div className="divide-y text-sm">
                            {renders.map((render) => (
                                <div key={render.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>
                                        #{render.id} · {date(render.created_at)}
                                        {render.error && <span className="block text-xs text-red-600">{render.error}</span>}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <Badge variant={render.status === 'failed' ? 'destructive' : 'secondary'}>{render.status}</Badge>
                                        <span className="text-muted-foreground text-xs">
                                            video {formatDuration(render.duration_ms)} · xử lý {formatDuration(render.processing_ms)}
                                        </span>
                                        {render.url && (
                                            <a href={render.url} target="_blank" rel="noreferrer" className="text-xs underline">
                                                Xem
                                            </a>
                                        )}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>
                )}
            </div>

            <ProjectCostDialog
                project={costOpen ? { id: project.id, product: project.product.name, user: { email: project.user.email }, cost } : null}
                usdVnd={usdVnd}
                onClose={() => setCostOpen(false)}
            />
        </AdminLayout>
    );
}

/** Vốn (chi phí thật) và giá bán (Credits = VNĐ) từng bước, lãi gộp. */
function ProfitSection({ profit }: { profit: Profit }) {
    const { totals } = profit;
    const cards = [
        { label: 'Chi phí vốn', value: `${vnd(totals.cost_vnd)}đ`, sub: usdText(totals.cost_usd), className: '' },
        { label: 'Giá bán', value: `${vnd(totals.sale_credits)} Credits`, sub: `= ${vnd(totals.sale_vnd)}đ`, className: 'text-orange-600' },
        {
            label: 'Lãi gộp',
            value: `${totals.profit_vnd >= 0 ? '+' : ''}${vnd(totals.profit_vnd)}đ`,
            sub: 'Giá bán − vốn',
            className: totals.profit_vnd >= 0 ? 'text-emerald-600' : 'text-red-600',
        },
        {
            label: 'Biên lãi',
            value: totals.margin !== null ? `${totals.margin.toLocaleString('vi-VN')}%` : '—',
            sub: 'Lãi gộp / giá bán',
            className: '',
        },
    ];

    return (
        <section className="space-y-4 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 font-medium">
                    <Coins className="size-4 text-orange-500" /> Chi phí vốn và giá bán
                </h2>
                <Badge variant={profit.estimated ? 'outline' : 'secondary'}>
                    {profit.estimated ? 'Giá bán ước tính (chưa trừ Credits)' : 'Giá bán = Credits đã trừ thật'}
                </Badge>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {cards.map((card) => (
                    <div key={card.label} className="bg-muted/40 rounded-lg p-3">
                        <p className="text-muted-foreground text-xs">{card.label}</p>
                        <p className={cn('text-xl font-bold tabular-nums', card.className)}>{card.value}</p>
                        <p className="text-muted-foreground text-xs tabular-nums">{card.sub}</p>
                    </div>
                ))}
            </div>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-muted-foreground text-xs">
                        <tr>
                            <th className="px-3 py-2 text-left font-medium">Bước</th>
                            <th className="px-3 py-2 text-right font-medium">Số lần</th>
                            <th className="px-3 py-2 text-right font-medium">Vốn (VNĐ)</th>
                            <th className="px-3 py-2 text-right font-medium">Vốn (USD)</th>
                            <th className="px-3 py-2 text-right font-medium">Giá bán (Credits)</th>
                            <th className="px-3 py-2 text-right font-medium">Giá bán (VNĐ)</th>
                            <th className="px-3 py-2 text-right font-medium">Lãi (VNĐ)</th>
                            <th className="px-3 py-2 text-right font-medium">Biên lãi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y tabular-nums">
                        {profit.rows.map((row) => (
                            <tr key={row.key} className="hover:bg-muted/30">
                                <td className="px-3 py-2">
                                    <p className="font-medium">{row.label}</p>
                                    {(row.note || row.shared_with) && (
                                        <p className="text-muted-foreground text-xs">
                                            {row.shared_with ? `Chia đều cho ${row.shared_with} video dùng chung` : row.note}
                                        </p>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-right">{row.count}</td>
                                <td className="px-3 py-2 text-right">{vnd(row.cost_vnd)}đ</td>
                                <td className="text-muted-foreground px-3 py-2 text-right text-xs">{usdText(row.cost_usd)}</td>
                                <td className="px-3 py-2 text-right font-medium text-orange-600">{vnd(row.sale_credits)}</td>
                                <td className="px-3 py-2 text-right">{vnd(row.sale_vnd)}đ</td>
                                <td className={cn('px-3 py-2 text-right font-medium', row.profit_vnd >= 0 ? 'text-emerald-600' : 'text-red-600')}>
                                    {row.profit_vnd >= 0 ? '+' : ''}
                                    {vnd(row.profit_vnd)}đ
                                </td>
                                <td className="text-muted-foreground px-3 py-2 text-right">
                                    {row.margin !== null ? `${row.margin.toLocaleString('vi-VN')}%` : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="bg-muted/30 border-t font-semibold tabular-nums">
                        <tr>
                            <td className="px-3 py-2">Tổng</td>
                            <td />
                            <td className="px-3 py-2 text-right">{vnd(totals.cost_vnd)}đ</td>
                            <td className="text-muted-foreground px-3 py-2 text-right text-xs">{usdText(totals.cost_usd)}</td>
                            <td className="px-3 py-2 text-right text-orange-600">{vnd(totals.sale_credits)}</td>
                            <td className="px-3 py-2 text-right">{vnd(totals.sale_vnd)}đ</td>
                            <td className={cn('px-3 py-2 text-right', totals.profit_vnd >= 0 ? 'text-emerald-600' : 'text-red-600')}>
                                {totals.profit_vnd >= 0 ? '+' : ''}
                                {vnd(totals.profit_vnd)}đ
                            </td>
                            <td className="px-3 py-2 text-right">{totals.margin !== null ? `${totals.margin.toLocaleString('vi-VN')}%` : '—'}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p className="text-muted-foreground text-xs">
                1 Credit = 1đ · tỉ giá {vnd(profit.usd_vnd)}đ/USD. Vốn chỉ gồm chi phí AI viết chữ theo token (dùng gói Claude Pro/Max thì là ước tính
                theo giá API); ảnh, clip và xuất video chạy qua muse.ai và máy chủ, không tốn tiền theo lượt. Chưa trừ chi phí cố định (gói Claude,
                tài khoản muse.ai, máy chủ).
            </p>
        </section>
    );
}

function Setting({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <p className="text-muted-foreground text-xs">{label}</p>
            <p>{children}</p>
        </div>
    );
}

function PromptBlock({ title, text, hint }: { title: string; text: string | null; hint?: string }) {
    const [copied, setCopied] = useState(false);
    if (!text) return null;

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-medium">
                    {title} {hint && <span className="text-muted-foreground font-normal">· {hint}</span>}
                </p>
                <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground"
                    title="Copy"
                    onClick={async () => {
                        await navigator.clipboard.writeText(text);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1200);
                    }}
                >
                    {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
                </button>
            </div>
            <pre className="bg-muted max-h-40 overflow-auto rounded-md p-2 text-xs whitespace-pre-wrap">{text}</pre>
        </div>
    );
}

function SceneCard({ scene }: { scene: AdminScene }) {
    const [showAttempts, setShowAttempts] = useState(false);
    const latest = scene.images[0];

    return (
        <div className="grid gap-4 rounded-xl border p-4 lg:grid-cols-[140px_1fr]">
            <div className="space-y-2">
                <div className="bg-muted aspect-[9/16] overflow-hidden rounded-lg border">
                    {scene.clip.url ? (
                        <video src={scene.clip.url} muted loop autoPlay playsInline className="size-full object-cover" />
                    ) : (
                        latest?.url && <img src={latest.url} alt="" className="size-full object-cover" />
                    )}
                </div>
                {scene.background && (
                    <div className="flex items-center gap-1.5 text-xs">
                        {scene.background.photo_url && <img src={scene.background.photo_url} alt="" className="size-6 rounded object-cover" />}
                        {scene.background.name}
                    </div>
                )}
            </div>
            <div className="min-w-0 space-y-3">
                <div className="flex flex-wrap items-center gap-1.5 text-sm">
                    <b>Cảnh {scene.position}</b>
                    {scene.phase && <Badge variant="outline">{scene.phase === 'before' ? 'Trước' : 'Sau'}</Badge>}
                    <span className="text-muted-foreground">{formatDuration(scene.duration_ms)}</span>
                    {scene.motion && <Badge variant="secondary">{scene.motion}</Badge>}
                </div>
                <div className="grid gap-2 text-sm md:grid-cols-3">
                    <Setting label="Hình ảnh">{scene.visual}</Setting>
                    <Setting label="Chữ trên màn">{scene.on_screen_text || '—'}</Setting>
                    <Setting label="Lời đọc">{scene.voiceover || '—'}</Setting>
                </div>
                <PromptBlock title="Prompt ảnh (AI viết)" text={scene.image_prompt} />
                <PromptBlock
                    title="Prompt đầy đủ gửi tạo ảnh"
                    text={latest?.prompt ?? scene.full_prompt}
                    hint={latest?.prompt ? 'lần gần nhất' : 'sẽ gửi'}
                />
                <PromptBlock title="Prompt clip" text={scene.motion_prompt} hint={scene.motion_prompt_sent ? 'đã gửi' : 'sẽ gửi'} />

                <div className="flex flex-wrap items-center gap-3 text-xs">
                    <span>
                        Ảnh: <b>{scene.images.length}</b> lần tạo
                        {latest && ` · lần cuối ${formatDuration(latest.duration_ms)}`}
                    </span>
                    {scene.clip.status && (
                        <span>
                            Clip: <b>{scene.clip.status}</b> {scene.clip.request_id && `· ${scene.clip.request_id}`}
                            {scene.clip.duration_ms !== null && ` · ${formatDuration(scene.clip.duration_ms)}`}
                        </span>
                    )}
                    {scene.images.length > 0 && (
                        <button type="button" className="underline" onClick={() => setShowAttempts(!showAttempts)}>
                            {showAttempts ? 'Ẩn các lần tạo ảnh' : 'Xem các lần tạo ảnh'}
                        </button>
                    )}
                </div>
                {scene.clip.error && <p className="text-xs text-red-600 dark:text-red-400">Clip lỗi: {scene.clip.error}</p>}

                {showAttempts && (
                    <div className="space-y-2">
                        {scene.images.map((image) => (
                            <div key={image.id} className="flex gap-3 rounded-lg border p-2">
                                <div className="bg-muted h-24 w-14 shrink-0 overflow-hidden rounded">
                                    {image.url && <img src={image.url} alt="" className="size-full object-cover" />}
                                </div>
                                <div className="min-w-0 flex-1 space-y-1 text-xs">
                                    <p className="flex flex-wrap items-center gap-1.5">
                                        <Badge variant={image.status === 'failed' ? 'destructive' : 'secondary'}>{image.status}</Badge>
                                        <span>{date(image.created_at)}</span>
                                        <span className="text-muted-foreground">
                                            · {formatDuration(image.duration_ms)} · {image.provider ?? '—'}{' '}
                                            {image.request_id && `· ${image.request_id}`}
                                        </span>
                                    </p>
                                    {image.error && <p className="line-clamp-3 text-red-600 dark:text-red-400">{image.error}</p>}
                                    {image.prompt ? (
                                        <pre className={cn('bg-muted max-h-24 overflow-auto rounded p-1.5 whitespace-pre-wrap')}>{image.prompt}</pre>
                                    ) : (
                                        <p className="text-muted-foreground">Prompt chưa được ghi (lần tạo trước khi bật ghi nhận).</p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
