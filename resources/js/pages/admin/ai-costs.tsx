import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import Pagination, { type Paginated } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Activity, CircleDollarSign, Clapperboard, Coins, Info, Timer } from 'lucide-react';
import { FormEventHandler } from 'react';

type Period = 'today' | '7d' | '30d' | '90d' | 'all';
type Provider = 'claude_cli' | 'claude_api';

interface Sums {
    calls: number;
    failed: number;
    input_tokens: number;
    output_tokens: number;
    cache_write_tokens: number;
    cache_read_tokens: number;
    cost_usd: number;
    avg_duration_ms: number;
}

interface UsageLog {
    id: number;
    action: string;
    label: string;
    provider: Provider;
    model: string | null;
    success: boolean;
    input_tokens: number;
    output_tokens: number;
    cache_write_tokens: number;
    cache_read_tokens: number;
    cost_usd: number;
    duration_ms: number | null;
    error: string | null;
    user: string | null;
    project: { id: number; product: string | null } | null;
    subject: string | null;
    created_at: string;
}

type Family = 'opus' | 'sonnet' | 'haiku';

type Pricing = {
    usd_vnd: number;
    prices: Record<Family, { input: number; output: number }>;
};

interface Props {
    filters: { period: Period; action: string | null; provider: Provider | null };
    actions: Record<string, string>;
    totals: Sums & { projects: number; cost_per_project: number };
    byAction: (Sums & { action: string; label: string; avg_cost_usd: number })[];
    byModel: (Sums & { provider: Provider; model: string | null })[];
    byDay: { day: string; calls: number; cost_usd: number }[];
    topProjects: { id: number; product: string | null; user: string | null; calls: number; cost_usd: number }[];
    topUsers: { id: number; name: string | null; email: string | null; calls: number; cost_usd: number }[];
    logs: Paginated<UsageLog>;
    pricing: Pricing;
}

const periods: { value: Period; label: string }[] = [
    { value: 'today', label: 'Hôm nay' },
    { value: '7d', label: '7 ngày' },
    { value: '30d', label: '30 ngày' },
    { value: '90d', label: '90 ngày' },
    { value: 'all', label: 'Tất cả' },
];

const providers: Record<Provider, string> = { claude_cli: 'Claude Code (gói Pro/Max)', claude_api: 'Claude API' };

const families: Family[] = ['opus', 'sonnet', 'haiku'];

const number = (value: number) => value.toLocaleString('vi-VN');

/** USD nhỏ (vài phần nghìn đô) vẫn hiện đủ chữ số có nghĩa. */
const usd = (value: number) => `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: value < 1 ? 4 : 2 })}`;

const seconds = (ms: number | null) => (ms ? `${(ms / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}s` : '—');

export default function AdminAiCosts({ filters, actions, totals, byAction, byModel, byDay, topProjects, topUsers, logs, pricing }: Props) {
    const vnd = (value: number) => `${Math.round(value * pricing.usd_vnd).toLocaleString('vi-VN')}đ`;
    const go = (params: Record<string, string | null>) =>
        router.get(
            route('admin.ai-costs.index'),
            { period: filters.period, action: filters.action || undefined, provider: filters.provider || undefined, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const maxDay = Math.max(0.000001, ...byDay.map((day) => day.cost_usd));
    const maxAction = Math.max(0.000001, ...byAction.map((row) => row.cost_usd));

    const cards = [
        { label: 'Tổng chi phí', value: usd(totals.cost_usd), sub: vnd(totals.cost_usd), icon: CircleDollarSign },
        {
            label: 'Số lần gọi',
            value: number(totals.calls),
            sub: totals.failed > 0 ? `${number(totals.failed)} lần lỗi` : 'Không có lỗi',
            icon: Activity,
        },
        {
            label: 'Trung bình mỗi dự án',
            value: usd(totals.cost_per_project),
            sub: `${vnd(totals.cost_per_project)} · ${number(totals.projects)} dự án`,
            icon: Clapperboard,
        },
        {
            label: 'Token',
            value: number(totals.input_tokens + totals.cache_write_tokens + totals.cache_read_tokens + totals.output_tokens),
            sub: `Vào ${number(totals.input_tokens + totals.cache_write_tokens + totals.cache_read_tokens)} · Ra ${number(totals.output_tokens)}`,
            icon: Coins,
        },
        { label: 'Thời gian trung bình', value: seconds(totals.avg_duration_ms), sub: 'Mỗi lần gọi', icon: Timer },
    ];

    return (
        <AdminLayout title="Chi phí AI">
            <Head title="Chi phí AI" />
            <div className="space-y-6">
                <Heading
                    title="Chi phí AI"
                    description="Mỗi lần gọi Claude (viết kịch bản, đọc sản phẩm, viết lại prompt, phân tích video) tốn bao nhiêu."
                />
                <FlashStatus />

                <div className="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    <span>
                        Dùng Claude Code đăng nhập gói Pro/Max thì chi phí là <b>ước tính theo giá API</b> (gói trả cố định, không bị trừ thêm tiền),
                        dùng để so sánh độ tốn của từng thao tác. Dùng Claude API thì là chi phí thật, tính theo bảng giá bên dưới. Lượt gọi trước khi
                        có tính năng này không được ghi.
                    </span>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex flex-wrap gap-1">
                        {periods.map((period) => (
                            <Chip key={period.value} active={filters.period === period.value} onClick={() => go({ period: period.value })}>
                                {period.label}
                            </Chip>
                        ))}
                    </div>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={filters.action ?? ''}
                        onChange={(e) => go({ action: e.target.value || null })}
                    >
                        <option value="">Mọi thao tác</option>
                        {Object.entries(actions).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={filters.provider ?? ''}
                        onChange={(e) => go({ provider: e.target.value || null })}
                    >
                        <option value="">Mọi cách gọi</option>
                        {(Object.keys(providers) as Provider[]).map((key) => (
                            <option key={key} value={key}>
                                {providers[key]}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    {cards.map((card) => (
                        <div key={card.label} className="rounded-xl border p-4">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground text-sm">{card.label}</span>
                                <card.icon className="text-muted-foreground size-4" />
                            </div>
                            <p className="mt-2 text-2xl font-bold">{card.value}</p>
                            <p className="text-muted-foreground mt-1 text-xs">{card.sub}</p>
                        </div>
                    ))}
                </div>

                <div className="grid gap-6 xl:grid-cols-[1fr_1fr]">
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">Theo thao tác</h2>
                        {byAction.length === 0 && <p className="text-muted-foreground text-sm">Chưa có lượt gọi nào.</p>}
                        {byAction.map((row) => (
                            <button
                                key={row.action}
                                type="button"
                                onClick={() => go({ action: row.action })}
                                className="block w-full space-y-1 text-left"
                            >
                                <div className="flex justify-between gap-2 text-sm">
                                    <span className="font-medium">{row.label}</span>
                                    <span>
                                        <b>{usd(row.cost_usd)}</b> <span className="text-muted-foreground">({vnd(row.cost_usd)})</span>
                                    </span>
                                </div>
                                <div className="bg-muted h-1.5 overflow-hidden rounded">
                                    <div
                                        className="h-full rounded bg-gradient-to-r from-orange-500 to-rose-500"
                                        style={{ width: `${(row.cost_usd / maxAction) * 100}%` }}
                                    />
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    {number(row.calls)} lần · TB {usd(row.avg_cost_usd)} ({vnd(row.avg_cost_usd)}) / lần · vào{' '}
                                    {number(Math.round((row.input_tokens + row.cache_write_tokens + row.cache_read_tokens) / Math.max(1, row.calls)))}{' '}
                                    · ra {number(Math.round(row.output_tokens / Math.max(1, row.calls)))} token / lần · {seconds(row.avg_duration_ms)}
                                    {row.failed > 0 && <span className="text-red-600 dark:text-red-400"> · {row.failed} lỗi</span>}
                                </p>
                            </button>
                        ))}
                    </section>

                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">
                            Theo ngày{' '}
                            {byDay.length > 1 && <span className="text-muted-foreground text-sm font-normal">({byDay.length} ngày gần nhất)</span>}
                        </h2>
                        <div className="flex h-40 items-end gap-0.5">
                            {byDay.map((day) => (
                                <div
                                    key={day.day}
                                    className="group relative flex h-full flex-1 items-end"
                                    title={`${new Date(day.day).toLocaleDateString('vi-VN')}: ${usd(day.cost_usd)} · ${day.calls} lần`}
                                >
                                    <div
                                        className={cn('w-full rounded-t', day.cost_usd > 0 ? 'bg-orange-500 group-hover:bg-rose-500' : 'bg-muted')}
                                        style={{ height: `${Math.max(2, (day.cost_usd / maxDay) * 100)}%` }}
                                    />
                                </div>
                            ))}
                        </div>
                        <div className="text-muted-foreground flex justify-between text-xs">
                            <span>{byDay[0] && new Date(byDay[0].day).toLocaleDateString('vi-VN')}</span>
                            <span>Cao nhất {usd(maxDay < 0.0001 ? 0 : maxDay)} / ngày</span>
                            <span>{byDay.at(-1) && new Date(byDay.at(-1)!.day).toLocaleDateString('vi-VN')}</span>
                        </div>

                        <h3 className="pt-2 text-sm font-medium">Theo model</h3>
                        {byModel.length === 0 && <p className="text-muted-foreground text-sm">—</p>}
                        {byModel.map((row) => (
                            <div key={`${row.provider}-${row.model}`} className="flex justify-between gap-2 text-sm">
                                <span className="min-w-0 truncate">
                                    {row.model ?? 'Mặc định'} <span className="text-muted-foreground text-xs">· {providers[row.provider]}</span>
                                </span>
                                <span className="shrink-0">
                                    {usd(row.cost_usd)} <span className="text-muted-foreground text-xs">· {number(row.calls)} lần</span>
                                </span>
                            </div>
                        ))}
                    </section>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <RankList
                        title="Dự án tốn nhất"
                        empty="Chưa có lượt gọi gắn với dự án."
                        rows={topProjects.map((row) => ({
                            key: row.id,
                            title: `#${row.id} ${row.product ?? '—'}`,
                            sub: row.user,
                            calls: row.calls,
                            cost: row.cost_usd,
                        }))}
                        usd={usd}
                        vnd={vnd}
                    />
                    <RankList
                        title="Người dùng tốn nhất"
                        empty="Chưa có lượt gọi gắn với người dùng."
                        rows={topUsers.map((row) => ({ key: row.id, title: row.name ?? '—', sub: row.email, calls: row.calls, cost: row.cost_usd }))}
                        usd={usd}
                        vnd={vnd}
                    />
                </div>

                <section className="space-y-3">
                    <h2 className="font-medium">Từng lần gọi</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Thời gian</th>
                                    <th className="px-3 py-2 font-medium">Thao tác</th>
                                    <th className="px-3 py-2 font-medium">Đối tượng</th>
                                    <th className="px-3 py-2 font-medium">Model</th>
                                    <th className="px-3 py-2 text-right font-medium">Token vào / ra</th>
                                    <th className="px-3 py-2 text-right font-medium">Thời gian chạy</th>
                                    <th className="px-3 py-2 text-right font-medium">Chi phí</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                            Chưa có lượt gọi nào trong khoảng này.
                                        </td>
                                    </tr>
                                )}
                                {logs.data.map((log) => (
                                    <tr key={log.id} className="align-top">
                                        <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">
                                            {new Date(log.created_at).toLocaleString('vi-VN')}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex flex-wrap items-center gap-1">
                                                {log.label}
                                                {!log.success && <Badge variant="destructive">Lỗi</Badge>}
                                            </div>
                                            {log.error && <p className="line-clamp-2 max-w-xs text-xs text-red-600 dark:text-red-400">{log.error}</p>}
                                        </td>
                                        <td className="px-3 py-2 text-xs">
                                            {log.project ? (
                                                <Link href={route('projects.show', log.project.id)} className="underline">
                                                    #{log.project.id} {log.project.product}
                                                </Link>
                                            ) : (
                                                (log.subject ?? '—')
                                            )}
                                            <p className="text-muted-foreground">{log.user}</p>
                                        </td>
                                        <td className="px-3 py-2 text-xs">
                                            {log.model ?? '—'}
                                            <p className="text-muted-foreground">{log.provider === 'claude_cli' ? 'Gói Pro/Max' : 'API'}</p>
                                        </td>
                                        <td className="px-3 py-2 text-right text-xs whitespace-nowrap">
                                            {number(log.input_tokens + log.cache_write_tokens + log.cache_read_tokens)} / {number(log.output_tokens)}
                                            {log.cache_read_tokens > 0 && (
                                                <p className="text-muted-foreground">cache {number(log.cache_read_tokens)}</p>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right text-xs">{seconds(log.duration_ms)}</td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            <b>{usd(log.cost_usd)}</b>
                                            <p className="text-muted-foreground text-xs">{vnd(log.cost_usd)}</p>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination page={logs} />
                </section>

                <PricingForm pricing={pricing} />
            </div>
        </AdminLayout>
    );
}

function PricingForm({ pricing }: { pricing: Pricing }) {
    const { data, setData, put, processing, errors } = useForm<Pricing>(pricing);
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.ai-costs.pricing'), { preserveScroll: true });
    };
    const setPrice = (family: Family, key: 'input' | 'output', value: string) =>
        setData('prices', { ...data.prices, [family]: { ...data.prices[family], [key]: Number(value) } });

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-4 rounded-xl border p-4">
            <div>
                <h2 className="font-medium">Bảng giá</h2>
                <p className="text-muted-foreground text-sm">
                    USD cho 1 triệu token, dùng cho lượt gọi Claude API (Claude Code tự báo chi phí). Ghi cache tính 1,25 lần giá vào, đọc cache 0,1
                    lần. Đổi giá không tính lại các lượt đã ghi.
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
                {families.map((family) => (
                    <div key={family} className="space-y-2 rounded-lg border p-3">
                        <p className="text-sm font-medium capitalize">{family}</p>
                        {(['input', 'output'] as const).map((key) => (
                            <div key={key} className="grid gap-1">
                                <Label htmlFor={`${family}-${key}`} className="text-xs">
                                    {key === 'input' ? 'Token vào' : 'Token ra'} ($/1M)
                                </Label>
                                <Input
                                    id={`${family}-${key}`}
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={data.prices[family][key]}
                                    onChange={(e) => setPrice(family, key, e.target.value)}
                                />
                                <InputError message={(errors as Record<string, string>)[`prices.${family}.${key}`]} />
                            </div>
                        ))}
                    </div>
                ))}
            </div>
            <div className="grid max-w-xs gap-1">
                <Label htmlFor="usd_vnd">Tỉ giá (VNĐ cho 1 USD)</Label>
                <Input id="usd_vnd" type="number" min="1000" value={data.usd_vnd} onChange={(e) => setData('usd_vnd', Number(e.target.value))} />
                <InputError message={errors.usd_vnd} />
            </div>
            <Button disabled={processing}>Lưu bảng giá</Button>
        </form>
    );
}

function RankList({
    title,
    empty,
    rows,
    usd,
    vnd,
}: {
    title: string;
    empty: string;
    rows: { key: number; title: string; sub: string | null; calls: number; cost: number }[];
    usd: (value: number) => string;
    vnd: (value: number) => string;
}) {
    return (
        <section className="space-y-2 rounded-xl border p-4">
            <h2 className="font-medium">{title}</h2>
            {rows.length === 0 && <p className="text-muted-foreground text-sm">{empty}</p>}
            {rows.map((row) => (
                <div key={row.key} className="flex items-center justify-between gap-3 text-sm">
                    <div className="min-w-0">
                        <p className="truncate font-medium">{row.title}</p>
                        <p className="text-muted-foreground truncate text-xs">
                            {row.sub} · {number(row.calls)} lần
                        </p>
                    </div>
                    <div className="shrink-0 text-right">
                        <b>{usd(row.cost)}</b>
                        <p className="text-muted-foreground text-xs">{vnd(row.cost)}</p>
                    </div>
                </div>
            ))}
        </section>
    );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('rounded-full border px-3 py-1 text-sm', active ? 'bg-foreground text-background' : 'hover:bg-muted')}
        >
            {children}
        </button>
    );
}
