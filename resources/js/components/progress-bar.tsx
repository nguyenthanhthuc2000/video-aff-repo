import { cn } from '@/lib/utils';

export default function ProgressBar({
    percent,
    label,
    failed,
    className,
}: {
    percent: number;
    label?: string;
    failed?: boolean;
    className?: string;
}) {
    const value = Math.max(0, Math.min(100, percent));

    return (
        <div className={cn('space-y-1', className)}>
            <div
                className="bg-muted h-2 overflow-hidden rounded-full"
                role="progressbar"
                aria-valuenow={value}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={label}
            >
                <div
                    className={cn(
                        'h-full rounded-full transition-[width] duration-500',
                        failed ? 'bg-red-500' : value === 100 ? 'bg-green-600' : 'bg-primary',
                    )}
                    style={{ width: `${failed ? 100 : value}%` }}
                />
            </div>
            {label && (
                <p className="text-muted-foreground flex justify-between text-xs">
                    <span>{label}</span>
                    {!failed && <span className="tabular-nums">{value}%</span>}
                </p>
            )}
        </div>
    );
}
