import Heading from '@/components/heading';
import Pagination, { type Paginated } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatNumber, formatVndShort } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Coins, Gift, Plus, ReceiptText, RotateCcw, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Lịch sử Credits', href: '/credits' }];

type TransactionType = 'topup' | 'usage' | 'refund' | 'bonus' | 'adjustment';
type Period = '7d' | '30d' | '90d' | 'all';

interface Transaction {
    id: number;
    type: TransactionType;
    type_label: string;
    title: string;
    detail: string | null;
    project: { id: number; product: string | null } | null;
    credits: number;
    balance_after: number | null;
    amount_vnd: number | null;
    created_at: string;
}

interface Props {
    balance: number;
    summary: { topup: number; bonus: number; used: number; refund: number; adjustment: number };
    transactions: Paginated<Transaction>;
    filters: { type: TransactionType | null; period: Period };
    types: Record<TransactionType, string>;
}

const periods: { value: Period; label: string }[] = [
    { value: '7d', label: '7 ngày' },
    { value: '30d', label: '30 ngày' },
    { value: '90d', label: '90 ngày' },
    { value: 'all', label: 'Tất cả' },
];

const typeStyles: Record<TransactionType, { icon: typeof Coins; badge: string; dot: string }> = {
    topup: {
        icon: Wallet,
        badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:ring-emerald-900',
        dot: 'bg-emerald-500',
    },
    bonus: {
        icon: Gift,
        badge: 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-950 dark:text-violet-300 dark:ring-violet-900',
        dot: 'bg-violet-500',
    },
    usage: {
        icon: ArrowUpRight,
        badge: 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-950 dark:text-orange-300 dark:ring-orange-900',
        dot: 'bg-orange-500',
    },
    refund: { icon: RotateCcw, badge: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950 dark:text-sky-300 dark:ring-sky-900', dot: 'bg-sky-500' },
    adjustment: {
        icon: ArrowDownLeft,
        badge: 'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-300 dark:ring-zinc-800',
        dot: 'bg-zinc-500',
    },
};

const dateText = (value: string) => new Date(value).toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
const timeText = (value: string) => new Date(value).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });

export default function CreditsIndex({ balance, summary, transactions, filters, types }: Props) {
    const go = (params: Record<string, string | null>) =>
        router.get(
            route('credits.index'),
            { type: filters.type || undefined, period: filters.period === 'all' ? undefined : filters.period, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const stats = [
        { label: 'Đã nạp', value: summary.topup, sign: '+', className: 'text-emerald-600' },
        { label: 'Được tặng', value: summary.bonus, sign: '+', className: 'text-violet-600' },
        { label: 'Đã dùng', value: summary.used, sign: '−', className: 'text-orange-600' },
        { label: 'Được hoàn', value: summary.refund, sign: '+', className: 'text-sky-600' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Lịch sử Credits" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Heading title="Lịch sử Credits" description="Mọi lần cộng và trừ Credits của bạn. 1 Credit = 1đ, chỉ trừ khi tạo thành công." />

                {/* Số dư + tổng */}
                <div className="overflow-hidden rounded-2xl border">
                    <div className="grid md:grid-cols-[260px_1fr]">
                        <div
                            className={cn(
                                'flex flex-col justify-between gap-4 p-5 text-white',
                                balance > 0 ? 'bg-gradient-to-br from-orange-500 to-rose-500' : 'bg-gradient-to-br from-zinc-600 to-zinc-800',
                            )}
                        >
                            <div>
                                <p className="flex items-center gap-1.5 text-sm text-white/85">
                                    <Coins className="size-4" /> Số dư hiện tại
                                </p>
                                <p className="mt-1 text-4xl font-bold tabular-nums">{formatNumber(balance)}</p>
                                <p className="text-sm text-white/80">Credits ≈ {formatVndShort(balance)}</p>
                            </div>
                            <Button asChild size="sm" variant="secondary" className="w-fit">
                                <Link href={route('billing.index')}>
                                    <Plus /> Nạp thêm
                                </Link>
                            </Button>
                        </div>
                        <div className="grid grid-cols-2 divide-x divide-y sm:grid-cols-4 sm:divide-y-0">
                            {stats.map((stat) => (
                                <div key={stat.label} className="flex flex-col justify-center p-5">
                                    <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{stat.label}</p>
                                    <p
                                        className={cn(
                                            'mt-1 text-2xl font-semibold tabular-nums',
                                            stat.value > 0 ? stat.className : 'text-muted-foreground',
                                        )}
                                    >
                                        {stat.value > 0 ? stat.sign : ''}
                                        {formatNumber(stat.value)}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {filters.period === 'all' ? 'Credits' : 'Credits trong khoảng đang xem'}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Bộ lọc */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-1.5">
                        <Chip active={!filters.type} onClick={() => go({ type: null })}>
                            Tất cả
                        </Chip>
                        {(Object.keys(types) as TransactionType[]).map((type) => (
                            <Chip key={type} active={filters.type === type} onClick={() => go({ type })}>
                                <span className={cn('size-1.5 rounded-full', typeStyles[type].dot)} /> {types[type]}
                            </Chip>
                        ))}
                    </div>
                    <div className="bg-muted inline-flex rounded-lg p-0.5">
                        {periods.map((period) => (
                            <button
                                key={period.value}
                                type="button"
                                onClick={() => go({ period: period.value === 'all' ? null : period.value })}
                                className={cn(
                                    'rounded-md px-3 py-1 text-sm transition',
                                    filters.period === period.value
                                        ? 'bg-background font-medium shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {period.label}
                            </button>
                        ))}
                    </div>
                </div>

                {transactions.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-2xl border border-dashed p-10 text-center">
                        <ReceiptText className="text-muted-foreground size-8" />
                        <p className="font-medium">Chưa có giao dịch nào</p>
                        <p className="text-muted-foreground text-sm">Giao dịch nạp tiền và các lần tạo video sẽ hiện ở đây.</p>
                    </div>
                ) : (
                    <>
                        {/* Bảng (màn hình rộng) */}
                        <div className="hidden overflow-hidden rounded-2xl border md:block">
                            <table className="w-full table-fixed text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-xs">
                                    <tr>
                                        <th className="w-28 px-4 py-3 text-left font-medium">Thời gian</th>
                                        <th className="px-4 py-3 text-left font-medium">Nội dung</th>
                                        <th className="w-40 px-4 py-3 text-left font-medium">Loại</th>
                                        <th className="w-28 px-4 py-3 text-right font-medium">Credits</th>
                                        <th className="w-28 px-4 py-3 text-right font-medium whitespace-nowrap">Số dư sau</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {transactions.data.map((row) => (
                                        <tr key={row.id} className="hover:bg-muted/30 transition-colors">
                                            <td className="px-4 py-3 align-top">
                                                <p className="tabular-nums">{dateText(row.created_at)}</p>
                                                <p className="text-muted-foreground text-xs tabular-nums">{timeText(row.created_at)}</p>
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <Content row={row} />
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <TypeBadge row={row} />
                                            </td>
                                            <td className="px-4 py-3 text-right align-top">
                                                <Amount credits={row.credits} />
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3 text-right align-top tabular-nums">
                                                {row.balance_after !== null ? formatNumber(row.balance_after) : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Danh sách (điện thoại) */}
                        <div className="divide-y overflow-hidden rounded-2xl border md:hidden">
                            {transactions.data.map((row) => (
                                <div key={row.id} className="flex items-start justify-between gap-3 p-4">
                                    <div className="min-w-0 space-y-1">
                                        <Content row={row} />
                                        <div className="flex items-center gap-2">
                                            <TypeBadge row={row} />
                                            <span className="text-muted-foreground text-xs tabular-nums">
                                                {dateText(row.created_at)} {timeText(row.created_at)}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <Amount credits={row.credits} />
                                        {row.balance_after !== null && (
                                            <p className="text-muted-foreground text-xs tabular-nums">Còn {formatNumber(row.balance_after)}</p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
                <Pagination page={transactions} />
            </div>
        </AppLayout>
    );
}

function Content({ row }: { row: Transaction }) {
    const extra = [row.detail, row.amount_vnd !== null ? `Thanh toán ${formatVndShort(row.amount_vnd)}` : null].filter(Boolean).join(' · ');

    return (
        <div className="min-w-0">
            <p className="truncate font-medium">{row.title}</p>
            {(extra || row.project) && (
                <p className="text-muted-foreground truncate text-xs" title={extra || undefined}>
                    {extra}
                    {extra && row.project && ' · '}
                    {row.project && (
                        <Link
                            href={route('projects.show', row.project.id)}
                            className="hover:text-foreground underline underline-offset-2"
                            title={row.project.product ?? undefined}
                        >
                            {row.project.product ?? `Video #${row.project.id}`}
                        </Link>
                    )}
                </p>
            )}
        </div>
    );
}

function TypeBadge({ row }: { row: Transaction }) {
    const style = typeStyles[row.type] ?? typeStyles.adjustment;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                style.badge,
            )}
        >
            <style.icon className="size-3" /> {row.type_label}
        </span>
    );
}

function Amount({ credits }: { credits: number }) {
    return (
        <span className={cn('text-base font-semibold tabular-nums', credits > 0 ? 'text-emerald-600' : 'text-foreground')}>
            {credits > 0 ? '+' : '−'}
            {formatNumber(Math.abs(credits))}
        </span>
    );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition',
                active ? 'border-foreground bg-foreground text-background' : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}
