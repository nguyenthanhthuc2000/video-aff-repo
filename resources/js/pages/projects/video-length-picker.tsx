import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type VideoPresetOption } from '@/types/models';

export type VideoLength = { scene_count: number; target_seconds: number };

/** Chọn độ dài video: mẫu gợi ý (có đánh dấu mẫu hợp với sản phẩm) hoặc tự nhập số cảnh / số giây. */
export default function VideoLengthPicker({
    presets,
    value,
    onChange,
    reason,
    compact = false,
}: {
    presets: VideoPresetOption[];
    value: VideoLength;
    onChange: (value: VideoLength) => void;
    reason?: string;
    compact?: boolean;
}) {
    const active = presets.find((preset) => preset.scenes === value.scene_count && preset.seconds === value.target_seconds);
    const perScene = value.target_seconds / Math.max(1, value.scene_count);

    return (
        <div className="space-y-3">
            <div className={cn('grid gap-2', compact ? 'grid-cols-2' : 'grid-cols-2 lg:grid-cols-4')}>
                {presets.map((preset) => (
                    <button
                        key={preset.key}
                        type="button"
                        onClick={() => onChange({ scene_count: preset.scenes, target_seconds: preset.seconds })}
                        className={cn(
                            'rounded-lg border p-3 text-left text-sm transition',
                            active?.key === preset.key ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                        )}
                    >
                        <div className="flex items-center justify-between gap-2">
                            <span className="font-medium">{preset.label}</span>
                            {preset.recommended && <Badge>Đề xuất</Badge>}
                        </div>
                        <div className="text-muted-foreground mt-1 text-xs">
                            {preset.seconds} giây · {preset.scenes} cảnh · ~{(preset.seconds / preset.scenes).toFixed(1)} giây/cảnh
                        </div>
                        {!compact && <div className="text-muted-foreground mt-1 text-xs">{preset.hint}</div>}
                    </button>
                ))}
            </div>
            {reason && <p className="text-muted-foreground text-xs">Gợi ý theo sản phẩm: {reason}</p>}
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="scene_count" className="text-xs">
                        Số cảnh (3–12)
                    </Label>
                    <Input
                        id="scene_count"
                        type="number"
                        min={3}
                        max={12}
                        className="w-24"
                        value={value.scene_count}
                        onChange={(e) => onChange({ ...value, scene_count: Number(e.target.value) })}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="target_seconds" className="text-xs">
                        Tổng thời lượng (10–60 giây)
                    </Label>
                    <Input
                        id="target_seconds"
                        type="number"
                        min={10}
                        max={60}
                        className="w-28"
                        value={value.target_seconds}
                        onChange={(e) => onChange({ ...value, target_seconds: Number(e.target.value) })}
                    />
                </div>
                <span className={cn('pb-2 text-xs', perScene < 2 || perScene > 8 ? 'text-red-600' : 'text-muted-foreground')}>
                    ~{perScene.toFixed(1)} giây/cảnh{!active && ' · tuỳ chỉnh'}
                </span>
            </div>
        </div>
    );
}
