import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type VideoPresetOption } from '@/types/models';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import VideoLengthPicker from '../projects/video-length-picker';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cài đặt video', href: '/video-settings' }];

type Settings = { mode: 'auto' | 'fixed'; scene_count: number; target_seconds: number };

/** Cài đặt chung cho mọi video: độ dài (số cảnh, số giây) Claude dùng khi viết kịch bản. */
export default function VideoSettings({ settings, presets }: { settings: Settings; presets: VideoPresetOption[] }) {
    const { data, setData, put, processing, errors, isDirty } = useForm<Settings>(settings);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('video-settings.update'), { preserveScroll: true });
    };

    const modes = [
        {
            value: 'auto' as const,
            title: 'Tự đề xuất theo từng sản phẩm (khuyên dùng)',
            hint: 'Ít thông tin → Ngắn 15 giây; đa số sản phẩm → Chuẩn 25 giây; nhiều màu, nhiều đánh giá, nhiều ảnh, giá cao → Chi tiết 35 giây hoặc Dài 50 giây.',
        },
        { value: 'fixed' as const, title: 'Cố định cho mọi video', hint: 'Chọn một mẫu hoặc tự nhập số cảnh và thời lượng.' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cài đặt video" />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <Heading
                    title="Cài đặt video"
                    description="Áp dụng cho toàn hệ thống: mọi dự án dùng khi Claude viết (hoặc viết lại) kịch bản. Kịch bản đã viết không tự đổi."
                />
                <FlashStatus />

                <section className="space-y-3">
                    <h2 className="font-medium">Độ dài video</h2>
                    <div className="grid gap-2">
                        {modes.map((mode) => (
                            <label
                                key={mode.value}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm',
                                    data.mode === mode.value ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="mode"
                                    className="mt-1"
                                    checked={data.mode === mode.value}
                                    onChange={() => setData('mode', mode.value)}
                                />
                                <span>
                                    <span className="font-medium">{mode.title}</span>
                                    <span className="text-muted-foreground block text-xs">{mode.hint}</span>
                                </span>
                            </label>
                        ))}
                    </div>

                    {data.mode === 'fixed' && (
                        <VideoLengthPicker
                            presets={presets}
                            value={{ scene_count: data.scene_count, target_seconds: data.target_seconds }}
                            onChange={(value) => setData((current) => ({ ...current, ...value }))}
                        />
                    )}
                    <InputError message={errors.target_seconds ?? errors.scene_count ?? errors.mode} />
                </section>

                <Button disabled={processing || !isDirty}>Lưu cài đặt</Button>
            </form>
        </AppLayout>
    );
}
