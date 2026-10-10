import { type CreditRates, estimateCredits, formatNumber, formatVndShort, type MotionChoice } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { Calculator } from 'lucide-react';
import { useState } from 'react';

const motions: { value: MotionChoice; label: string }[] = [
    { value: 'none', label: 'Ảnh tĩnh' },
    { value: 'style', label: 'Chuyển động có sẵn' },
    { value: 'copy', label: 'Sao chép video mẫu' },
];

/** Ước lượng Credits cho một video theo số cảnh, thời lượng và kiểu chuyển động (giá theo bảng giá bán của admin). */
export default function CreditEstimator({ rates }: { rates: CreditRates }) {
    const [scenes, setScenes] = useState(6);
    const [seconds, setSeconds] = useState(25);
    const [motion, setMotion] = useState<MotionChoice>('none');
    const estimate = estimateCredits(rates, { scenes, motion, seconds });

    const chip = (active: boolean) =>
        cn(
            'rounded-full border px-3 py-1 text-sm transition',
            active ? 'border-orange-500 bg-orange-500 text-white' : 'border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-900',
        );

    return (
        <div className="grid gap-6 rounded-2xl border border-zinc-200 bg-white p-6 md:grid-cols-[1fr_280px] dark:border-zinc-800 dark:bg-zinc-950">
            <div className="space-y-5">
                <h3 className="flex items-center gap-2 font-semibold">
                    <Calculator className="size-5 text-orange-500" /> Ước tính Credits cho một video
                </h3>
                <label className="block space-y-1 text-sm">
                    <span className="flex justify-between">
                        <span>Số cảnh</span>
                        <span className="font-semibold">{scenes} cảnh</span>
                    </span>
                    <input
                        type="range"
                        min={3}
                        max={12}
                        value={scenes}
                        onChange={(e) => setScenes(Number(e.target.value))}
                        className="w-full accent-orange-500"
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    <span className="flex justify-between">
                        <span>Thời lượng</span>
                        <span className="font-semibold">{seconds} giây</span>
                    </span>
                    <input
                        type="range"
                        min={10}
                        max={60}
                        step={5}
                        value={seconds}
                        onChange={(e) => setSeconds(Number(e.target.value))}
                        className="w-full accent-orange-500"
                    />
                </label>
                <div className="space-y-2 text-sm">
                    <span>Chuyển động</span>
                    <div className="flex flex-wrap gap-2">
                        {motions.map((item) => (
                            <button key={item.value} type="button" className={chip(motion === item.value)} onClick={() => setMotion(item.value)}>
                                {item.label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            <div className="flex flex-col rounded-xl bg-gradient-to-br from-orange-50 to-rose-50 p-5 dark:from-orange-950/40 dark:to-rose-950/30">
                <ul className="flex-1 space-y-1.5 text-sm">
                    {estimate.items.map((item) => (
                        <li key={item.label} className="flex justify-between gap-2">
                            <span className="text-zinc-600 dark:text-zinc-400">{item.label}</span>
                            <span className="font-medium">{formatNumber(item.credits)}</span>
                        </li>
                    ))}
                </ul>
                <div className="mt-4 border-t border-orange-200 pt-3 dark:border-orange-900">
                    <p className="text-sm text-zinc-500">Tổng mỗi video</p>
                    <p className="text-3xl font-bold text-orange-600 dark:text-orange-400">{formatNumber(estimate.total)} Credits</p>
                    <p className="text-xs text-zinc-500">≈ {formatVndShort(estimate.vnd)} · chưa tính các lần tạo lại</p>
                </div>
            </div>
        </div>
    );
}
