import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { FileText, Image as ImageIcon, Loader2, type LucideIcon, Package, RefreshCw, ScanSearch, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface CostStep {
    key: string;
    label: string;
    calls: number;
    failed: number;
    cost_usd: number;
    input_tokens: number;
    output_tokens: number;
    avg_duration_ms: number;
    shared_with: number | null;
    note: string | null;
}

export interface ProjectCost {
    total_usd: number;
    calls: number;
    steps: CostStep[];
}

interface AiCall {
    id: number;
    action: string;
    label: string;
    model: string | null;
    success: boolean;
    input_tokens: number;
    output_tokens: number;
    cost_usd: number;
    duration_ms: number | null;
    error: string | null;
    shared: boolean;
    created_at: string;
}

/** Màu, biểu tượng mỗi bước (giống thứ tự luồng làm video). */
const stepStyles: Record<string, { color: string; dot: string; icon: LucideIcon }> = {
    product_import: { color: 'bg-sky-500', dot: 'text-sky-500', icon: Package },
    motion_analysis: { color: 'bg-violet-500', dot: 'text-violet-500', icon: ScanSearch },
    script: { color: 'bg-orange-500', dot: 'text-orange-500', icon: FileText },
    scene_prompt_rewrite: { color: 'bg-rose-500', dot: 'text-rose-500', icon: RefreshCw },
    images: { color: 'bg-emerald-500', dot: 'text-emerald-500', icon: ImageIcon },
};
const fallbackStyle = { color: 'bg-slate-400', dot: 'text-slate-400', icon: Sparkles };

export const usd = (value: number) => `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: value < 1 ? 4 : 2 })}`;
const number = (value: number) => value.toLocaleString('vi-VN');
const seconds = (ms: number | null) => (ms ? `${(ms / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}s` : '—');

export default function ProjectCostDialog({
    project,
    usdVnd,
    onClose,
}: {
    project: { id: number; product: string | null; user: { email: string | null }; cost: ProjectCost } | null;
    usdVnd: number;
    onClose: () => void;
}) {
    const [calls, setCalls] = useState<AiCall[] | null>(null);
    const vnd = (value: number) => `${Math.round(value * usdVnd).toLocaleString('vi-VN')}đ`;

    useEffect(() => {
        setCalls(null);
        if (!project) return;
        let cancelled = false;
        fetch(route('admin.projects.ai-calls', project.id), { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((data) => !cancelled && setCalls(data.calls))
            .catch(() => !cancelled && setCalls([]));

        return () => {
            cancelled = true;
        };
    }, [project]);

    const cost = project?.cost;
    const total = cost?.total_usd ?? 0;
    const paidSteps = cost?.steps.filter((step) => step.cost_usd > 0) ?? [];
    const images = cost?.steps.find((step) => step.key === 'images');

    return (
        <Dialog open={project !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="pr-6">
                        Chi phí video #{project?.id} · {project?.product ?? '—'}
                    </DialogTitle>
                    <DialogDescription>{project?.user.email}</DialogDescription>
                </DialogHeader>

                {cost && (
                    <div className="space-y-5">
                        {/* Tổng */}
                        <div className="rounded-2xl bg-gradient-to-br from-orange-500 to-rose-500 p-5 text-white">
                            <p className="text-sm text-white/85">Tổng chi phí xử lý</p>
                            <p className="mt-1 text-4xl font-bold">{usd(total)}</p>
                            <p className="mt-1 text-sm text-white/85">
                                ≈ {vnd(total)} · {number(cost.calls)} lượt gọi AI{images ? ` · ${number(images.calls)} lần tạo ảnh` : ''}
                            </p>
                        </div>

                        {cost.steps.length === 0 ? (
                            <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">
                                Chưa có chi phí nào được ghi cho video này.
                            </p>
                        ) : (
                            <>
                                {/* Tỉ trọng từng bước */}
                                {total > 0 && (
                                    <div className="space-y-2">
                                        <div className="flex h-4 overflow-hidden rounded-full">
                                            {paidSteps.map((step) => (
                                                <div
                                                    key={step.key}
                                                    className={cn((stepStyles[step.key] ?? fallbackStyle).color, 'h-full transition-all')}
                                                    style={{ width: `${(step.cost_usd / total) * 100}%` }}
                                                    title={`${step.label}: ${usd(step.cost_usd)}`}
                                                />
                                            ))}
                                        </div>
                                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                            {paidSteps.map((step) => (
                                                <span key={step.key} className="flex items-center gap-1.5">
                                                    <span className={cn('size-2.5 rounded-full', (stepStyles[step.key] ?? fallbackStyle).color)} />
                                                    {step.label} <b>{Math.round((step.cost_usd / total) * 100)}%</b>
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Thẻ từng bước */}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {cost.steps.map((step) => {
                                        const style = stepStyles[step.key] ?? fallbackStyle;
                                        const isImages = step.key === 'images';
                                        return (
                                            <div key={step.key} className="space-y-2 rounded-xl border p-3">
                                                <div className="flex items-start justify-between gap-2">
                                                    <span className="flex items-center gap-2 text-sm font-medium">
                                                        <span
                                                            className={cn(
                                                                'flex size-7 shrink-0 items-center justify-center rounded-lg text-white',
                                                                style.color,
                                                            )}
                                                        >
                                                            <style.icon className="size-4" />
                                                        </span>
                                                        {step.label}
                                                    </span>
                                                    {total > 0 && step.cost_usd > 0 && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {Math.round((step.cost_usd / total) * 100)}%
                                                        </span>
                                                    )}
                                                </div>
                                                <p>
                                                    <span className="text-xl font-bold">{usd(step.cost_usd)}</span>{' '}
                                                    <span className="text-muted-foreground text-xs">{vnd(step.cost_usd)}</span>
                                                </p>
                                                <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
                                                    <dt>Số lần</dt>
                                                    <dd className="text-foreground text-right">
                                                        {number(step.calls)}
                                                        {step.failed > 0 && <span className="text-red-600"> ({step.failed} lỗi)</span>}
                                                    </dd>
                                                    {!isImages && (
                                                        <>
                                                            <dt>Token vào / lần</dt>
                                                            <dd className="text-foreground text-right">
                                                                {step.input_tokens > 0
                                                                    ? number(Math.round(step.input_tokens / Math.max(1, step.calls)))
                                                                    : '—'}
                                                            </dd>
                                                            <dt>Token ra / lần</dt>
                                                            <dd className="text-foreground text-right">
                                                                {step.output_tokens > 0
                                                                    ? number(Math.round(step.output_tokens / Math.max(1, step.calls)))
                                                                    : '—'}
                                                            </dd>
                                                            <dt>TB mỗi lần</dt>
                                                            <dd className="text-foreground text-right">
                                                                {usd(step.cost_usd / Math.max(1, step.calls))} · {seconds(step.avg_duration_ms)}
                                                            </dd>
                                                        </>
                                                    )}
                                                </dl>
                                                {step.note && <p className="text-muted-foreground text-xs italic">{step.note}</p>}
                                            </div>
                                        );
                                    })}
                                </div>
                            </>
                        )}

                        {/* Từng lượt gọi */}
                        <div className="space-y-2">
                            <h3 className="text-sm font-medium">Từng lượt gọi AI</h3>
                            {calls === null ? (
                                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                    <Loader2 className="size-4 animate-spin" /> Đang tải...
                                </p>
                            ) : calls.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Không có lượt gọi nào.</p>
                            ) : (
                                <ol className="relative space-y-3 border-l pl-5">
                                    {calls.map((call) => {
                                        const style = stepStyles[call.action] ?? fallbackStyle;
                                        return (
                                            <li key={call.id} className="relative">
                                                <span
                                                    className={cn(
                                                        'border-background absolute top-1 -left-[27px] size-3 rounded-full border-2',
                                                        call.success ? style.color : 'bg-red-500',
                                                    )}
                                                />
                                                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                                    <span className="text-sm">
                                                        {call.label}
                                                        {call.shared && (
                                                            <Badge variant="outline" className="ml-1.5">
                                                                Dùng chung
                                                            </Badge>
                                                        )}
                                                        {!call.success && (
                                                            <Badge variant="destructive" className="ml-1.5">
                                                                Lỗi
                                                            </Badge>
                                                        )}
                                                    </span>
                                                    <span className="text-sm font-semibold">{usd(call.cost_usd)}</span>
                                                </div>
                                                <p className="text-muted-foreground text-xs">
                                                    {new Date(call.created_at).toLocaleString('vi-VN')} · {seconds(call.duration_ms)}
                                                    {call.input_tokens + call.output_tokens > 0 &&
                                                        ` · ${number(call.input_tokens)} vào / ${number(call.output_tokens)} ra token`}
                                                    {call.model && ` · ${call.model}`}
                                                </p>
                                                {call.error && <p className="line-clamp-2 text-xs text-red-600 dark:text-red-400">{call.error}</p>}
                                            </li>
                                        );
                                    })}
                                </ol>
                            )}
                        </div>

                        <p className="text-muted-foreground border-t pt-3 text-xs">
                            Prompt cho từng phân cảnh được viết cùng lúc với kịch bản; mỗi lần bấm "Tạo lại" ảnh, AI viết lại prompt và tính thêm một
                            lượt. Dùng gói Claude Pro/Max thì chi phí là ước tính theo giá API. Lượt nhập từ log cũ không có số token.{' '}
                            <Link href={route('admin.ai-costs.index')} className="underline">
                                Xem trang Chi phí AI
                            </Link>
                            .
                        </p>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
