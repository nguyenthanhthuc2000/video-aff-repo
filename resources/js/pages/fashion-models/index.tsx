import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type FashionModel, type FashionModelKind } from '@/types/models';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Người mẫu', href: '/fashion-models' }];

const kinds: { value: FashionModelKind; label: string; hint: string; prefix: string }[] = [
    { value: 'after', label: 'Sau', hint: 'Đã makeup, chỉn chu: dùng cho video thường và ảnh "sau" của video biến hình.', prefix: 'Mẫu' },
    { value: 'before', label: 'Trước', hint: 'Mặt mộc, tóc rối, đồ cũ: chỉ dùng làm ảnh "trước" của video biến hình.', prefix: 'Mẫu trước' },
];

export default function FashionModelsIndex({ fashionModels }: { fashionModels: FashionModel[] }) {
    const [tab, setTab] = useState<FashionModelKind>('after');
    const current = kinds.find((kind) => kind.value === tab)!;
    const shown = fashionModels.filter((model) => model.kind === tab);
    // Số tiếp theo cho tên mặc định của loại đang chọn, giống cách máy chủ đặt tên.
    const pattern = new RegExp(`^${current.prefix} (\\d+)$`, 'u');
    const nextNumber = Math.max(0, ...fashionModels.map((model) => Number(pattern.exec(model.name)?.[1] ?? 0))) + 1;
    const defaultName = (i: number) => `${current.prefix} ${nextNumber + i}`;
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };
    // Đồng ý được tick sẵn; người dùng bỏ tick nếu chưa có đồng ý của người mẫu.
    const { data, setData, post, processing, progress, errors, reset, transform } = useForm<{ name: string; photos: File[]; consent: boolean }>({
        name: '',
        photos: [],
        consent: true,
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const previews = useMemo(() => data.photos.map((file) => URL.createObjectURL(file)), [data.photos]);
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => ({ ...form, kind: tab }));
        post(route('fashion-models.store'), {
            forceFormData: true,
            onSuccess: () => {
                reset();
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    };

    const photoError = errors.photos ?? Object.entries(errors).find(([key]) => key.startsWith('photos.'))?.[1];

    const destroy = (model: FashionModel) => {
        if (confirm(`Xoá người mẫu ${model.name}?`)) {
            router.delete(route('fashion-models.destroy', model.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Người mẫu" />
            <div className="grid gap-8 p-4 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <Heading title="Người mẫu" description="Ảnh người mẫu dùng làm nền để AI mặc sản phẩm lên." />
                    <FlashStatus />
                    <InputError message={pageErrors.fashion_model} />

                    <div className="flex flex-wrap items-center gap-2">
                        {kinds.map((kind) => (
                            <button
                                key={kind.value}
                                type="button"
                                onClick={() => setTab(kind.value)}
                                className={cn(
                                    'rounded-full border px-4 py-1 text-sm transition',
                                    tab === kind.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                                )}
                            >
                                {kind.label}{' '}
                                <span className="text-xs opacity-70">{fashionModels.filter((model) => model.kind === kind.value).length}</span>
                            </button>
                        ))}
                        <span className="text-muted-foreground text-xs">{current.hint}</span>
                    </div>

                    {shown.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Chưa có người mẫu loại "{current.label}". Thêm ở khung bên phải.</p>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            {shown.map((model) => (
                                <div key={model.id} className="overflow-hidden rounded-xl border">
                                    <img src={model.photo_url} alt={model.name} className="aspect-[3/4] w-full object-cover" />
                                    <div className="space-y-2 p-3">
                                        <span className="block text-sm font-medium">{model.name}</span>
                                        <div className="flex flex-wrap gap-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.put(
                                                        route('fashion-models.update', model.id),
                                                        { kind: model.kind === 'after' ? 'before' : 'after' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <ArrowLeftRight /> Chuyển sang {model.kind === 'after' ? 'Trước' : 'Sau'}
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => destroy(model)}>
                                                Xoá
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <HeadingSmall
                        title={`Thêm ảnh "${current.label}"`}
                        description={
                            tab === 'after'
                                ? 'Ảnh toàn thân, đứng thẳng, nền đơn giản, mặt rõ. Mặc áo dài qua hông nếu sản phẩm là chân váy/quần.'
                                : 'Cùng người với ảnh "sau" nhưng mặt mộc, tóc rối, mặc đồ cũ ở nhà. Toàn thân, mặt rõ.'
                        }
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="photos">Ảnh (chọn được nhiều ảnh, mỗi ảnh là một người mẫu)</Label>
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
                            <div className="grid grid-cols-4 gap-2">
                                {previews.map((url, i) => (
                                    <figure key={url} className="space-y-1">
                                        <img src={url} alt="" className="aspect-[3/4] w-full rounded-md border object-cover" />
                                        <figcaption className="text-muted-foreground truncate text-center text-xs">
                                            {data.name.trim() === ''
                                                ? `Mẫu ${nextNumber + i}`
                                                : previews.length === 1
                                                  ? data.name
                                                  : `${data.name} ${i + 1}`}
                                        </figcaption>
                                    </figure>
                                ))}
                            </div>
                        )}
                        <InputError message={photoError} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="name">
                            Tên (bỏ trống: Mẫu {nextNumber}, Mẫu {nextNumber + 1}...)
                        </Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder={defaultName(0)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="flex items-start gap-2">
                        <Checkbox id="consent" checked={data.consent} onCheckedChange={(checked) => setData('consent', checked === true)} />
                        <Label htmlFor="consent" className="leading-snug font-normal">
                            Người mẫu đã đồng ý bằng văn bản cho dùng hình ảnh để làm video bán hàng có dùng AI.
                        </Label>
                    </div>
                    <InputError message={errors.consent} />
                    <Button disabled={processing || data.photos.length === 0}>
                        {processing && progress
                            ? `Đang tải ${progress.percentage}%`
                            : data.photos.length > 1
                              ? `Thêm ${data.photos.length} người mẫu`
                              : 'Thêm người mẫu'}
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
