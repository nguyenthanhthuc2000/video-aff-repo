import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import CreditEstimator from '@/components/pricing/credit-estimator';
import PackageGrid from '@/components/pricing/package-grid';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type CreditRates, formatNumber, formatVndShort, packages, VND_PER_CREDIT } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, Coins, CreditCard, History, Info, Plus, Smartphone, Wallet } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Nạp tiền', href: '/billing' }];

const methods = [
    { key: 'bank', label: 'Chuyển khoản / VietQR', hint: 'Quét mã QR bằng app ngân hàng', icon: Building2 },
    { key: 'momo', label: 'Ví MoMo', hint: 'Thanh toán bằng ví MoMo', icon: Smartphone },
    { key: 'zalopay', label: 'ZaloPay', hint: 'Thanh toán bằng ví ZaloPay', icon: Wallet },
    { key: 'card', label: 'Thẻ ATM / Visa / Master', hint: 'Thẻ nội địa và quốc tế', icon: CreditCard },
];

/** Trang Nạp tiền: hiện là giao diện, thanh toán thật sẽ được thêm sau. */
interface Transaction {
    id: number;
    type_label: string;
    credits: number;
    amount_vnd: number | null;
    created_at: string;
}

export default function Billing({
    selectedPackage,
    balance,
    transactions,
    creditRates,
}: {
    selectedPackage: string | null;
    creditRates: CreditRates;
    balance: number;
    transactions: Transaction[];
}) {
    const [packageKey, setPackageKey] = useState<string>(packages.find((pkg) => pkg.key === selectedPackage)?.key ?? 'popular');
    const [method, setMethod] = useState('bank');
    const chosen = packages.find((pkg) => pkg.key === packageKey) ?? packages[0];
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nạp tiền" />
            <div className="space-y-8 p-4">
                <Heading title="Nạp tiền" description="Nạp tiền quy ra Credits. Mỗi thao tác tạo video trừ Credits tuỳ độ phức tạp." />
                <FlashStatus />

                {auth.user.is_admin ? (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3 text-sm">
                        <Info className="size-4 shrink-0" />
                        <span className="flex-1">Bạn là admin: tự cộng Credits cho tài khoản này (ghi vào sổ như lần nạp thủ công).</span>
                        <Button asChild size="sm">
                            <Link href={route('admin.users.show', { user: auth.user.id, credit: 1 })}>
                                <Plus /> Cộng Credits
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        Thanh toán online đang được hoàn thiện. Cần nạp Credits ngay, vui lòng liên hệ quản trị viên.
                    </div>
                )}

                {/* Số dư */}
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-2xl bg-gradient-to-br from-orange-500 to-rose-500 p-5 text-white md:col-span-1">
                        <p className="flex items-center gap-2 text-sm text-white/85">
                            <Coins className="size-4" /> Số dư hiện tại
                        </p>
                        <p className="mt-2 text-4xl font-bold">{formatNumber(balance)} Credits</p>
                        <p className="mt-1 text-sm text-white/80">≈ {formatVndShort(balance * VND_PER_CREDIT)}</p>
                    </div>
                    <div className="rounded-2xl border p-5 md:col-span-2">
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <History className="size-4" /> Lịch sử giao dịch
                            <Link href={route('credits.index')} className="text-muted-foreground ml-auto text-xs font-normal underline">
                                Xem tất cả
                            </Link>
                        </p>
                        {transactions.length === 0 ? (
                            <p className="text-muted-foreground mt-6 text-center text-sm">Chưa có giao dịch nào.</p>
                        ) : (
                            <ul className="mt-3 divide-y text-sm">
                                {transactions.map((row) => (
                                    <li key={row.id} className="flex items-center justify-between gap-3 py-2">
                                        <span>
                                            {row.type_label}
                                            {row.amount_vnd !== null && (
                                                <span className="text-muted-foreground"> · {formatVndShort(row.amount_vnd)}</span>
                                            )}
                                            <span className="text-muted-foreground block text-xs">
                                                {new Date(row.created_at).toLocaleString('vi-VN')}
                                            </span>
                                        </span>
                                        <span className={cn('font-semibold', row.credits > 0 ? 'text-emerald-600' : 'text-red-600')}>
                                            {row.credits > 0 ? '+' : ''}
                                            {formatNumber(row.credits)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                {/* Gói nạp */}
                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">1. Chọn gói nạp</h2>
                    <PackageGrid
                        selected={packageKey}
                        action={(pkg) => (
                            <Button
                                className="w-full"
                                variant={packageKey === pkg.key ? 'default' : 'outline'}
                                onClick={() => setPackageKey(pkg.key)}
                            >
                                {packageKey === pkg.key ? 'Đã chọn' : 'Chọn gói'}
                            </Button>
                        )}
                    />
                </section>

                {/* Phương thức + tóm tắt */}
                <section className="grid gap-6 lg:grid-cols-[1fr_340px]">
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold">2. Phương thức thanh toán</h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {methods.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() => setMethod(item.key)}
                                    className={cn(
                                        'flex items-center gap-3 rounded-xl border p-4 text-left transition',
                                        method === item.key ? 'border-orange-500 bg-orange-50 dark:bg-orange-950/40' : 'hover:bg-muted',
                                    )}
                                >
                                    <item.icon className="size-6 text-orange-500" />
                                    <span>
                                        <span className="block text-sm font-medium">{item.label}</span>
                                        <span className="text-muted-foreground block text-xs">{item.hint}</span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="h-fit space-y-4 rounded-2xl border p-5">
                        <h2 className="font-semibold">Tóm tắt</h2>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Gói</dt>
                                <dd className="font-medium">{chosen.name}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Nhận được</dt>
                                <dd className="font-medium">{formatNumber(chosen.credits)} Credits</dd>
                            </div>
                            {chosen.bonusPercent > 0 && (
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Tặng thêm</dt>
                                    <dd>
                                        <Badge variant="secondary">+{chosen.bonusPercent}%</Badge>
                                    </dd>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Phương thức</dt>
                                <dd>{methods.find((item) => item.key === method)?.label}</dd>
                            </div>
                            <div className="flex justify-between border-t pt-2 text-base">
                                <dt className="font-medium">Thanh toán</dt>
                                <dd className="font-bold">{formatVndShort(chosen.price)}</dd>
                            </div>
                        </dl>
                        <Button className="w-full" disabled>
                            Thanh toán (sắp ra mắt)
                        </Button>
                        <p className="text-muted-foreground text-xs">Credits không hết hạn. Chỉ trừ khi tạo thành công; tạo lỗi được hoàn lại.</p>
                    </div>
                </section>

                {/* Bảng giá */}
                <section className="max-w-3xl space-y-3">
                    <h2 className="text-lg font-semibold">Một video tốn bao nhiêu?</h2>
                    <CreditEstimator rates={creditRates} />
                </section>
            </div>
        </AppLayout>
    );
}
