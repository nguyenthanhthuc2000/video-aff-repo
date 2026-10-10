import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { Coins } from 'lucide-react';

export interface ProjectCreditsData {
    total: number;
    /** Video làm trước khi bật trừ Credits: số ước tính, chưa trừ thật. */
    estimated?: boolean;
    steps: { key: string; label: string; count: number; credits: number }[];
}

const number = (value: number) => value.toLocaleString('vi-VN');

/** Chi phí sản xuất video (Credits), rê chuột xem từng bước. */
export default function ProjectCredits({ credits, className }: { credits?: ProjectCreditsData; className?: string }) {
    if (!credits) return null;

    return (
        <TooltipProvider delayDuration={100}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 rounded-full border border-orange-200 bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-300',
                            className,
                        )}
                    >
                        <Coins className="size-3" /> {credits.estimated ? '~' : ''}
                        {number(credits.total)} Credits
                    </span>
                </TooltipTrigger>
                <TooltipContent className="w-64 p-3">
                    <p className="mb-1 font-medium">{credits.estimated ? 'Chi phí ước tính (chưa trừ)' : 'Đã trừ cho video này'}</p>
                    {credits.steps.length === 0 ? (
                        <p className="opacity-80">Chưa có bước nào tính phí.</p>
                    ) : (
                        <ul className="space-y-0.5">
                            {credits.steps.map((step) => (
                                <li key={step.key} className="flex justify-between gap-3">
                                    <span className="opacity-90">
                                        {step.label}
                                        {step.count > 1 && ` ×${step.count}`}
                                    </span>
                                    <span className="font-medium">{number(step.credits)}</span>
                                </li>
                            ))}
                            <li className="mt-1 flex justify-between gap-3 border-t pt-1 font-semibold">
                                <span>Tổng</span>
                                <span>{number(credits.total)} Credits</span>
                            </li>
                        </ul>
                    )}
                    <p className="mt-1 opacity-70">
                        {credits.estimated
                            ? 'Video làm trước khi bắt đầu trừ Credits.'
                            : 'Tạo lỗi không tính phí. Đọc thông tin sản phẩm tính riêng theo sản phẩm.'}
                    </p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
