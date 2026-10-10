import FlashStatus from '@/components/flash-status';
import InputError from '@/components/input-error';
import Pagination, { type Paginated } from '@/components/pagination';
import ProgressBar from '@/components/progress-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { VND_PER_CREDIT } from '@/lib/pricing';
import { projectStatusLabels } from '@/lib/project-status';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type VideoProjectStatus } from '@/types/models';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CircleDollarSign,
    Clapperboard,
    Coins,
    ExternalLink,
    Film,
    Lock,
    LockOpen,
    type LucideIcon,
    Package,
    Play,
    Plus,
    Shield,
    ShieldOff,
    Wallet,
} from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Tab = 'topups' | 'usage' | 'videos' | 'products';

interface Transaction {
    id: number;
    type: 'topup' | 'usage' | 'refund' | 'adjustment';
    type_label: string;
    status: string;
    credits: number;
    balance_after: number | null;
    amount_vnd: number | null;
    package: string | null;
    method: string | null;
    action: string | null;
    project: { id: number; product: string | null } | null;
    description: string | null;
    creator: string | null;
    created_at: string;
}

interface UserVideo {
    id: number;
    product: string | null;
    image_url: string | null;
    status: VideoProjectStatus;
    video_type: 'standard' | 'transformation';
    progress: { percent: number; label: string };
    error: string | null;
    video_url: string | null;
    renders_count: number;
    ai_cost_usd: number;
    created_at: string;
}

interface UserProduct {
    id: number;
    name: string;
    price: number | null;
    shopee_url: string | null;
    image_url: string | null;
    video_projects_count: number;
    created_at: string;
}

interface Props {
    user: {
        id: number;
        name: string;
        email: string;
        is_admin: boolean;
        blocked_at: string | null;
        email_verified_at: string | null;
        created_at: string;
        credit_balance: number;
    };
    stats: {
        topup_vnd: number;
        topup_credits: number;
        topup_count: number;
        last_topup_at: string | null;
        used_credits: number;
        refunded_credits: number;
        adjusted_credits: number;
        products: number;
        projects: number;
        projects_by_status: Partial<Record<VideoProjectStatus, number>>;
        renders_done: number;
        ai_cost_usd: number;
        ai_calls: number;
    };
    tab: Tab;
    list: Paginated<Transaction | UserVideo | UserProduct>;
    usdVnd: number;
    methods: Record<string, string>;
}

const tabs: { value: Tab; label: string; icon: LucideIcon }[] = [
    { value: 'topups', label: 'Lịch sử nạp', icon: Wallet },
    { value: 'usage', label: 'Lịch sử dùng Credits', icon: Coins },
    { value: 'videos', label: 'Video', icon: Clapperboard },
    { value: 'products', label: 'Sản phẩm', icon: Package },
];

const number = (value: number) => value.toLocaleString('vi-VN');
const vnd = (value: number) => `${number(value)}đ`;
const date = (value: string) => new Date(value).toLocaleString('vi-VN');
const usd = (value: number) => `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: value < 1 ? 4 : 2 })}`;

export default function AdminUserShow({ user, stats, tab, list, usdVnd, methods }: Props) {
    const { auth } = usePage<SharedData>().props;
    const isMe = user.id === auth.user.id;
    const [creditOpen, setCreditOpen] = useState(false);

    const update = (data: Record<string, boolean>, message: string) => {
        if (confirm(message)) {
            router.put(route('admin.users.update', user.id), data, { preserveScroll: true });
        }
    };

    const cards = [
        {
            label: 'Số dư',
            value: `${number(user.credit_balance)} Credits`,
            sub: `≈ ${vnd(user.credit_balance * VND_PER_CREDIT)}`,
            icon: Coins,
            accent: true,
        },
        {
            label: 'Đã nạp',
            value: vnd(stats.topup_vnd),
            sub: `${number(stats.topup_credits)} Credits · ${stats.topup_count} lần${stats.last_topup_at ? ` · gần nhất ${new Date(stats.last_topup_at).toLocaleDateString('vi-VN')}` : ''}`,
            icon: Wallet,
        },
        {
            label: 'Đã dùng',
            value: `${number(stats.used_credits)} Credits`,
            sub: `Hoàn ${number(stats.refunded_credits)} · Điều chỉnh ${stats.adjusted_credits > 0 ? '+' : ''}${number(stats.adjusted_credits)}`,
            icon: Coins,
        },
        {
            label: 'Video',
            value: number(stats.projects),
            sub: `${number(stats.renders_done)} lần xuất xong · ${number(stats.products)} sản phẩm`,
            icon: Film,
        },
        {
            label: 'Chi phí AI',
            value: usd(stats.ai_cost_usd),
            sub: `≈ ${number(Math.round(stats.ai_cost_usd * usdVnd))}đ · ${number(stats.ai_calls)} lần gọi`,
            icon: CircleDollarSign,
        },
    ];

    return (
        <AdminLayout title={user.name}>
            <Head title={`Người dùng: ${user.name}`} />
            <div className="space-y-6">
                <Link href={route('admin.users.index')} className="text-muted-foreground inline-flex items-center gap-1 text-sm hover:underline">
                    <ArrowLeft className="size-4" /> Người dùng
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-rose-500 text-xl font-bold text-white">
                            {user.name.slice(0, 1).toUpperCase()}
                        </div>
                        <div>
                            <div className="flex flex-wrap items-center gap-1.5">
                                <h1 className="text-xl font-semibold">{user.name}</h1>
                                {user.is_admin && <Badge>Admin</Badge>}
                                {user.blocked_at && <Badge variant="destructive">Bị khoá</Badge>}
                                {isMe && <Badge variant="outline">Bạn</Badge>}
                            </div>
                            <p className="text-muted-foreground text-sm">{user.email}</p>
                            <p className="text-muted-foreground text-xs">
                                Tham gia {new Date(user.created_at).toLocaleDateString('vi-VN')} ·{' '}
                                {user.email_verified_at ? 'Đã xác minh email' : 'Chưa xác minh email'}
                                {user.blocked_at && ` · Khoá từ ${date(user.blocked_at)}`}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => setCreditOpen(true)}>
                            <Plus /> Nạp / điều chỉnh Credits
                        </Button>
                        {!isMe && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        update(
                                            { is_admin: !user.is_admin },
                                            user.is_admin ? `Bỏ quyền admin của ${user.email}?` : `Cấp quyền admin cho ${user.email}?`,
                                        )
                                    }
                                >
                                    {user.is_admin ? <ShieldOff /> : <Shield />} {user.is_admin ? 'Bỏ admin' : 'Cấp admin'}
                                </Button>
                                <Button
                                    variant="outline"
                                    className={cn(!user.blocked_at && 'text-destructive')}
                                    onClick={() =>
                                        update(
                                            { blocked: !user.blocked_at },
                                            user.blocked_at ? `Mở khoá ${user.email}?` : `Khoá ${user.email}? Người dùng sẽ bị đăng xuất.`,
                                        )
                                    }
                                >
                                    {user.blocked_at ? <LockOpen /> : <Lock />} {user.blocked_at ? 'Mở khoá' : 'Khoá'}
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <FlashStatus />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    {cards.map((card) => (
                        <div
                            key={card.label}
                            className={cn(
                                'rounded-xl border p-4',
                                card.accent && 'border-transparent bg-gradient-to-br from-orange-500 to-rose-500 text-white',
                            )}
                        >
                            <div className="flex items-center justify-between">
                                <span className={cn('text-sm', card.accent ? 'text-white/85' : 'text-muted-foreground')}>{card.label}</span>
                                <card.icon className={cn('size-4', card.accent ? 'text-white/85' : 'text-muted-foreground')} />
                            </div>
                            <p className="mt-2 text-2xl font-bold">{card.value}</p>
                            <p className={cn('mt-1 text-xs', card.accent ? 'text-white/80' : 'text-muted-foreground')}>{card.sub}</p>
                        </div>
                    ))}
                </div>

                {stats.projects > 0 && (
                    <div className="flex flex-wrap gap-2 text-xs">
                        {(Object.keys(projectStatusLabels) as VideoProjectStatus[])
                            .filter((status) => (stats.projects_by_status[status] ?? 0) > 0)
                            .map((status) => (
                                <span
                                    key={status}
                                    className={cn('rounded-full border px-2.5 py-1', status === 'failed' && 'border-red-300 text-red-600')}
                                >
                                    {projectStatusLabels[status]}: <b>{stats.projects_by_status[status]}</b>
                                </span>
                            ))}
                    </div>
                )}

                <div className="space-y-4">
                    <div className="flex flex-wrap gap-1 border-b">
                        {tabs.map((item) => (
                            <Link
                                key={item.value}
                                href={route('admin.users.show', { user: user.id, tab: item.value })}
                                preserveScroll
                                className={cn(
                                    '-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm',
                                    tab === item.value
                                        ? 'border-orange-500 font-medium'
                                        : 'text-muted-foreground hover:text-foreground border-transparent',
                                )}
                            >
                                <item.icon className="size-4" /> {item.label}
                            </Link>
                        ))}
                    </div>

                    {tab === 'topups' && <Transactions rows={list.data as Transaction[]} kind="topups" />}
                    {tab === 'usage' && <Transactions rows={list.data as Transaction[]} kind="usage" />}
                    {tab === 'videos' && <Videos rows={list.data as UserVideo[]} />}
                    {tab === 'products' && <Products rows={list.data as UserProduct[]} />}
                    <Pagination page={list} />
                </div>
            </div>

            <CreditDialog open={creditOpen} onOpenChange={setCreditOpen} userId={user.id} balance={user.credit_balance} methods={methods} />
        </AdminLayout>
    );
}

function Transactions({ rows, kind }: { rows: Transaction[]; kind: 'topups' | 'usage' }) {
    if (rows.length === 0) {
        return (
            <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">
                {kind === 'topups' ? 'Chưa có lần nạp nào.' : 'Chưa dùng Credits lần nào.'}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                    <tr>
                        <th className="px-4 py-2.5 font-medium">Thời gian</th>
                        <th className="px-4 py-2.5 font-medium">Loại</th>
                        <th className="px-4 py-2.5 font-medium">{kind === 'topups' ? 'Thanh toán' : 'Nội dung'}</th>
                        <th className="px-4 py-2.5 text-right font-medium">Credits</th>
                        <th className="px-4 py-2.5 text-right font-medium">Số dư sau</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((row) => (
                        <tr key={row.id} className="align-top">
                            <td className="text-muted-foreground px-4 py-3 text-xs whitespace-nowrap">{date(row.created_at)}</td>
                            <td className="px-4 py-3">
                                <Badge variant={row.type === 'usage' ? 'secondary' : row.type === 'adjustment' ? 'outline' : 'default'}>
                                    {row.type_label}
                                </Badge>
                                {row.status !== 'completed' && <p className="text-muted-foreground mt-1 text-xs">{row.status}</p>}
                            </td>
                            <td className="px-4 py-3 text-xs">
                                {row.amount_vnd !== null && <p className="text-sm font-medium">{vnd(row.amount_vnd)}</p>}
                                {row.method && <p className="text-muted-foreground">{row.method}</p>}
                                {row.project && (
                                    <p>
                                        Video #{row.project.id} {row.project.product}
                                    </p>
                                )}
                                {row.description && <p>{row.description}</p>}
                                {row.creator && <p className="text-muted-foreground">Bởi {row.creator}</p>}
                            </td>
                            <td
                                className={cn(
                                    'px-4 py-3 text-right font-semibold whitespace-nowrap',
                                    row.credits > 0 ? 'text-emerald-600' : 'text-red-600',
                                )}
                            >
                                {row.credits > 0 ? '+' : ''}
                                {number(row.credits)}
                            </td>
                            <td className="text-muted-foreground px-4 py-3 text-right whitespace-nowrap">
                                {row.balance_after !== null ? number(row.balance_after) : '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Videos({ rows }: { rows: UserVideo[] }) {
    const [preview, setPreview] = useState<UserVideo | null>(null);

    if (rows.length === 0) {
        return <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Chưa có video nào.</p>;
    }

    return (
        <>
            <div className="grid gap-3 md:grid-cols-2">
                {rows.map((video) => (
                    <div key={video.id} className="flex gap-3 rounded-xl border p-3">
                        <button
                            type="button"
                            disabled={!video.video_url}
                            onClick={() => setPreview(video)}
                            className="bg-muted relative h-24 w-16 shrink-0 overflow-hidden rounded-md"
                            title={video.video_url ? 'Xem video' : 'Chưa có video xuất xong'}
                        >
                            {video.image_url && <img src={video.image_url} alt="" className="size-full object-cover" />}
                            {video.video_url && (
                                <span className="absolute inset-0 flex items-center justify-center bg-black/30 text-white">
                                    <Play className="size-6 fill-white" />
                                </span>
                            )}
                        </button>
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="text-muted-foreground text-xs">#{video.id}</span>
                                <span className="truncate text-sm font-medium">{video.product ?? '—'}</span>
                                {video.video_type === 'transformation' && <Badge variant="outline">Biến hình</Badge>}
                            </div>
                            <ProgressBar percent={video.progress.percent} label={video.progress.label} failed={video.status === 'failed'} />
                            <p className="text-muted-foreground text-xs">
                                <Badge variant={video.status === 'failed' ? 'destructive' : 'secondary'} className="mr-1">
                                    {projectStatusLabels[video.status]}
                                </Badge>
                                {video.renders_count} lần xuất · AI {usd(video.ai_cost_usd)} ·{' '}
                                {new Date(video.created_at).toLocaleDateString('vi-VN')}
                            </p>
                            {video.error && <p className="line-clamp-2 text-xs text-red-600 dark:text-red-400">{video.error}</p>}
                        </div>
                    </div>
                ))}
            </div>

            <Dialog open={preview !== null} onOpenChange={(open) => !open && setPreview(null)}>
                <DialogContent className="max-w-sm sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="line-clamp-2 pr-6">{preview?.product}</DialogTitle>
                    </DialogHeader>
                    {preview?.video_url && (
                        <video
                            src={preview.video_url}
                            controls
                            autoPlay
                            playsInline
                            className="mx-auto aspect-[9/16] max-h-[70vh] rounded-lg bg-black"
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function Products({ rows }: { rows: UserProduct[] }) {
    if (rows.length === 0) {
        return <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Chưa có sản phẩm nào.</p>;
    }

    return (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {rows.map((product) => (
                <div key={product.id} className="flex gap-3 rounded-xl border p-3">
                    <div className="bg-muted size-16 shrink-0 overflow-hidden rounded-md">
                        {product.image_url && <img src={product.image_url} alt="" className="size-full object-cover" />}
                    </div>
                    <div className="min-w-0 space-y-0.5">
                        <p className="line-clamp-2 text-sm font-medium">{product.name}</p>
                        <p className="text-muted-foreground text-xs">
                            {product.price ? vnd(product.price) : 'Chưa có giá'} · {product.video_projects_count} video
                        </p>
                        <p className="text-muted-foreground flex items-center gap-2 text-xs">
                            {new Date(product.created_at).toLocaleDateString('vi-VN')}
                            {product.shopee_url && (
                                <a href={product.shopee_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 underline">
                                    Shopee <ExternalLink className="size-3" />
                                </a>
                            )}
                        </p>
                    </div>
                </div>
            ))}
        </div>
    );
}

type CreditForm = { type: 'topup' | 'adjustment'; credits: string; amount_vnd: string; method: string; description: string };

function CreditDialog({
    open,
    onOpenChange,
    userId,
    balance,
    methods,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    userId: number;
    balance: number;
    methods: Record<string, string>;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<CreditForm>({
        type: 'topup',
        credits: '',
        amount_vnd: '',
        method: 'manual',
        description: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.credits.store', userId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    const setAmount = (value: string) => {
        setData((form) => ({
            ...form,
            amount_vnd: value,
            // Gợi ý Credits theo tỉ lệ 1 Credit = 1đ (admin sửa được, ví dụ cộng thêm khuyến mãi).
            credits: value === '' ? '' : String(Math.floor(Number(value) / VND_PER_CREDIT)),
        }));
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) clearErrors();
                onOpenChange(value);
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nạp / điều chỉnh Credits</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-2">
                        {(
                            [
                                ['topup', 'Nạp tiền', 'Đã nhận tiền ngoài hệ thống'],
                                ['adjustment', 'Điều chỉnh', 'Tặng, bù lỗi, trừ bớt'],
                            ] as const
                        ).map(([value, label, hint]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setData('type', value)}
                                className={cn(
                                    'rounded-lg border p-3 text-left',
                                    data.type === value ? 'border-orange-500 bg-orange-50 dark:bg-orange-950/40' : 'hover:bg-muted',
                                )}
                            >
                                <span className="block text-sm font-medium">{label}</span>
                                <span className="text-muted-foreground text-xs">{hint}</span>
                            </button>
                        ))}
                    </div>

                    {data.type === 'topup' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1">
                                <Label htmlFor="amount_vnd">Số tiền đã nhận (đ)</Label>
                                <Input id="amount_vnd" type="number" min="0" value={data.amount_vnd} onChange={(e) => setAmount(e.target.value)} />
                                <InputError message={errors.amount_vnd} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="method">Phương thức</Label>
                                <select
                                    id="method"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={data.method}
                                    onChange={(e) => setData('method', e.target.value)}
                                >
                                    {Object.entries(methods).map(([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    )}

                    <div className="grid gap-1">
                        <Label htmlFor="credits">Credits {data.type === 'adjustment' ? '(số âm để trừ)' : ''}</Label>
                        <Input id="credits" type="number" value={data.credits} onChange={(e) => setData('credits', e.target.value)} required />
                        <p className="text-muted-foreground text-xs">
                            Số dư hiện tại {number(balance)} Credits
                            {data.credits !== '' && ` → sau giao dịch ${number(balance + Number(data.credits))} Credits`}.
                        </p>
                        <InputError message={errors.credits} />
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="description">Ghi chú {data.type === 'adjustment' ? '(bắt buộc)' : ''}</Label>
                        <Input
                            id="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder={data.type === 'topup' ? 'Mã giao dịch ngân hàng...' : 'Lý do điều chỉnh'}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <Button disabled={processing} className="w-full">
                        Xác nhận
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
