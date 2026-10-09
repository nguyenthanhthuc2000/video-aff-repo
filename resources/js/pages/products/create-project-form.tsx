import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { motionStyles, type MotionStyle } from '@/lib/motion-styles';
import { cn } from '@/lib/utils';
import { type FashionModel } from '@/types/models';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function CreateProjectForm({
    productId,
    fashionModels,
    defaultModelId,
    defaultMotionStyles,
    autopilotAvailable,
}: {
    productId: number;
    fashionModels: FashionModel[];
    defaultModelId?: number;
    defaultMotionStyles?: MotionStyle[];
    autopilotAvailable: boolean;
}) {
    const { data, setData, post, processing, errors } = useForm<{
        product_id: number;
        fashion_model_id: number;
        motion_styles: MotionStyle[];
        autopilot: boolean;
    }>({
        product_id: productId,
        fashion_model_id: defaultModelId ?? fashionModels[0]?.id ?? 0,
        motion_styles: defaultMotionStyles ?? [],
        autopilot: autopilotAvailable,
    });

    // Bật/tắt một kiểu; giữ thứ tự như danh sách (các cảnh dùng lần lượt theo thứ tự này).
    const toggleStyle = (value: MotionStyle) =>
        setData(
            'motion_styles',
            motionStyles.map((style) => style.value).filter((v) => (v === value ? !data.motion_styles.includes(v) : data.motion_styles.includes(v))),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.store'));
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid grid-cols-3 gap-2">
                {fashionModels.map((model) => (
                    <button
                        key={model.id}
                        type="button"
                        onClick={() => setData('fashion_model_id', model.id)}
                        className={`overflow-hidden rounded-lg border-2 ${data.fashion_model_id === model.id ? 'border-primary' : 'border-transparent'}`}
                        title={model.name}
                    >
                        <img src={model.photo_url} alt={model.name} className="aspect-[3/4] w-full object-cover" />
                    </button>
                ))}
            </div>
            <InputError message={errors.fashion_model_id ?? errors.product_id} />

            <div className="space-y-2">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="motion"
                        checked={data.motion_styles.length > 0}
                        onCheckedChange={(checked) => setData('motion_styles', checked ? [motionStyles[0].value] : [])}
                    />
                    <Label htmlFor="motion">Nhân vật chuyển động (nhảy trend TikTok)</Label>
                </div>
                {data.motion_styles.length > 0 && (
                    <div className="grid gap-1.5 pl-7">
                        <p className="text-muted-foreground text-xs">Chọn một hoặc nhiều kiểu; các cảnh dùng lần lượt từng kiểu.</p>
                        {motionStyles.map((style) => {
                            const checked = data.motion_styles.includes(style.value);
                            return (
                                <label
                                    key={style.value}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-2 rounded-md border px-3 py-1.5 text-sm',
                                        checked ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                                    )}
                                >
                                    <Checkbox checked={checked} onCheckedChange={() => toggleStyle(style.value)} className="mt-0.5" />
                                    <span>
                                        <span className="font-medium">{style.label}</span>
                                        <span className="text-muted-foreground block text-xs">{style.hint}</span>
                                    </span>
                                </label>
                            );
                        })}
                        <p className="text-muted-foreground text-xs">
                            Sau khi duyệt ảnh cảnh, Muse tạo clip chuyển động cho từng cảnh (chạy ngầm thì tự tạo trước khi xuất video). Cũng có thể
                            tự tạo clip rồi tải lên.
                        </p>
                    </div>
                )}
                <InputError message={errors.motion_styles ?? (errors as Record<string, string>)['motion_styles.0']} />
            </div>

            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="autopilot"
                        checked={data.autopilot}
                        disabled={!autopilotAvailable}
                        onCheckedChange={(checked) => setData('autopilot', checked === true)}
                    />
                    <Label htmlFor="autopilot">Chạy ngầm: Claude viết kịch bản → Muse tạo ảnh → xuất video</Label>
                </div>
                {!autopilotAvailable && (
                    <p className="text-muted-foreground pl-7 text-xs">
                        Cần Claude (CLAUDE_CODE_OAUTH_TOKEN từ gói Pro/Max, hoặc ANTHROPIC_API_KEY) và Muse (MUSE_DRIVER=browser đã đăng nhập, hoặc
                        FAL_KEY) trong .env. Bản web (claude.ai, meta.ai) phải thao tác tay nên không chạy ngầm được.
                    </p>
                )}
            </div>

            <Button disabled={processing} className="w-full">
                Tạo dự án video
            </Button>
        </form>
    );
}
