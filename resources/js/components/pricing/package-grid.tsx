import { formatNumber, formatVndShort, packages, type TopUpPackage } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { Coins } from 'lucide-react';
import { type ReactNode } from 'react';

/** Các gói nạp tiền quy ra Credits. `action` vẽ nút của từng gói (trang chủ: đăng nhập; trang Nạp tiền: chọn gói). */
export default function PackageGrid({ action, selected }: { action: (pkg: TopUpPackage) => ReactNode; selected?: string | null }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {packages.map((pkg) => (
                <div
                    key={pkg.key}
                    className={cn(
                        'relative flex flex-col rounded-2xl border bg-white p-5 transition dark:bg-zinc-950',
                        pkg.popular ? 'border-orange-400 shadow-lg shadow-orange-500/10' : 'border-zinc-200 dark:border-zinc-800',
                        selected === pkg.key && 'ring-2 ring-orange-500',
                    )}
                >
                    {pkg.popular && (
                        <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-gradient-to-r from-orange-500 to-rose-500 px-3 py-0.5 text-xs font-semibold text-white">
                            Phổ biến nhất
                        </span>
                    )}
                    <p className="text-sm font-medium text-zinc-500">{pkg.name}</p>
                    <p className="mt-1 text-2xl font-bold">{formatVndShort(pkg.price)}</p>
                    <p className="mt-3 flex items-center gap-1.5 text-lg font-semibold text-orange-600 dark:text-orange-400">
                        <Coins className="size-5" /> {formatNumber(pkg.credits)} Credits
                    </p>
                    <p className="text-xs text-zinc-500">
                        {pkg.bonusPercent > 0 ? `Tặng thêm ${pkg.bonusPercent}%` : 'Không có quà tặng'} ·{' '}
                        {formatNumber(Math.round(pkg.price / pkg.credits))}đ / Credit
                    </p>
                    <p className="mt-3 flex-1 text-sm text-zinc-600 dark:text-zinc-400">{pkg.hint}</p>
                    <div className="mt-4">{action(pkg)}</div>
                </div>
            ))}
        </div>
    );
}
