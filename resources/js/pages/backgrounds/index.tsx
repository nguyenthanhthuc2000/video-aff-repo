import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Background } from '@/types/models';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Pencil, X } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bối cảnh', href: '/backgrounds' }];

const ideas = ['Phòng thay đồ', 'Góc shop', 'Phòng ngủ', 'Ban công', 'Quán cà phê quen', 'Văn phòng'];

export default function BackgroundsIndex({ backgrounds }: { backgrounds: Background[] }) {
    const nextNumber = Math.max(0, ...backgrounds.map((item) => Number(/^Bối cảnh (\d+)$/u.exec(item.name)?.[1] ?? 0))) + 1;
    const { data, setData, post, processing, progress, errors, reset } = useForm<{ name: string; description: string; photos: File[] }>({
        name: '',
        description: '',
        photos: [],
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const previews = useMemo(() => data.photos.map((file) => URL.createObjectURL(file)), [data.photos]);
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('backgrounds.store'), {
            forceFormData: true,
            onSuccess: () => {
                reset();
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const photoError = errors.photos ?? Object.entries(errors).find(([key]) => key.startsWith('photos.'))?.[1];
    const previewName = (i: number) =>
        data.name.trim() === '' ? `Bối cảnh ${nextNumber + i}` : previews.length === 1 ? data.name : `${data.name} ${i + 1}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bối cảnh" />
            <div className="grid gap-8 p-4 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <Heading
                        title="Bối cảnh"
                        description="Ảnh nơi bạn muốn người mẫu xuất hiện (phòng thay đồ, góc shop...). Khi tạo video, chọn “Dùng bối cảnh của tôi” để AI đặt người mẫu vào đúng các nơi này."
                    />
                    <FlashStatus />

                    {backgrounds.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">
                            Chưa có bối cảnh nào. Thêm ở khung bên phải.
                        </p>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                            {backgrounds.map((background) => (
                                <BackgroundCard key={background.id} background={background} />
                            ))}
                        </div>
                    )}
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <HeadingSmall
                        title="Thêm bối cảnh"
                        description="Ảnh rõ, đủ sáng, chụp dọc càng tốt; không có người trong ảnh. Mỗi ảnh là một bối cảnh."
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="photos">Ảnh (chọn được nhiều ảnh)</Label>
                        <Input
                            id="photos"
                            ref={fileInput}
                            type="file"
                            accept="image/*"
                            multiple
                            onChange={(e) => setData('photos', Array.from(e.target.files ?? []))}
                            required
                        />
                        {previews.length > 0 && (
                            <div className="grid grid-cols-3 gap-2">
                                {previews.map((url, i) => (
                                    <figure key={url} className="space-y-1">
                                        <img src={url} alt="" className="aspect-[3/4] w-full rounded-md border object-cover" />
                                        <figcaption className="text-muted-foreground truncate text-center text-xs">{previewName(i)}</figcaption>
                                    </figure>
                                ))}
                            </div>
                        )}
                        <InputError message={photoError} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Tên (bỏ trống: Bối cảnh {nextNumber}...)</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Phòng thay đồ" />
                        <div className="flex flex-wrap gap-1">
                            {ideas.map((idea) => (
                                <button
                                    key={idea}
                                    type="button"
                                    onClick={() => setData('name', idea)}
                                    className="hover:bg-muted rounded-full border px-2 py-0.5 text-xs"
                                >
                                    {idea}
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="description">Mô tả (không bắt buộc)</Label>
                        <Input
                            id="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Gương lớn, rèm be, đèn vàng ấm"
                        />
                        <p className="text-muted-foreground text-xs">Giúp AI viết kịch bản hợp bối cảnh hơn.</p>
                        <InputError message={errors.description} />
                    </div>
                    <Button disabled={processing || data.photos.length === 0}>
                        {processing && progress
                            ? `Đang tải ${progress.percentage}%`
                            : data.photos.length > 1
                              ? `Thêm ${data.photos.length} bối cảnh`
                              : 'Thêm bối cảnh'}
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}

function BackgroundCard({ background }: { background: Background }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing, errors } = useForm({ name: background.name, description: background.description ?? '' });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('backgrounds.update', background.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const destroy = () => {
        const used = background.scenes_count ? ` Đang dùng ở ${background.scenes_count} cảnh; các cảnh đó sẽ chuyển về bối cảnh AI tự nghĩ.` : '';
        if (confirm(`Xoá bối cảnh ${background.name}?${used}`)) {
            router.delete(route('backgrounds.destroy', background.id), { preserveScroll: true });
        }
    };

    return (
        <div className="overflow-hidden rounded-xl border">
            {background.photo_url && <img src={background.photo_url} alt={background.name} className="aspect-[3/4] w-full object-cover" />}
            <div className="space-y-2 p-3">
                {editing ? (
                    <form onSubmit={save} className="space-y-2">
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} className="h-8" autoFocus />
                        <Input
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className="h-8"
                            placeholder="Mô tả"
                        />
                        <InputError message={errors.name ?? errors.description} />
                        <div className="flex gap-1">
                            <Button size="sm" disabled={processing}>
                                <Check /> Lưu
                            </Button>
                            <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)}>
                                <X />
                            </Button>
                        </div>
                    </form>
                ) : (
                    <>
                        <div>
                            <p className="text-sm font-medium">{background.name}</p>
                            {background.description && <p className="text-muted-foreground line-clamp-2 text-xs">{background.description}</p>}
                            {(background.scenes_count ?? 0) > 0 && (
                                <p className="text-muted-foreground text-xs">Dùng ở {background.scenes_count} cảnh</p>
                            )}
                        </div>
                        <div className="flex gap-1">
                            <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                                <Pencil /> Sửa
                            </Button>
                            <Button size="sm" variant="ghost" onClick={destroy}>
                                Xoá
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
