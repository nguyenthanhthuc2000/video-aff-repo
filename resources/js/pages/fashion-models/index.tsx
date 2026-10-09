import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type FashionModel } from '@/types/models';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Người mẫu', href: '/fashion-models' }];

export default function FashionModelsIndex({ fashionModels }: { fashionModels: FashionModel[] }) {
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };
    const { data, setData, post, processing, errors, reset } = useForm<{ name: string; photo: File | null; consent: boolean }>({
        name: '',
        photo: null,
        consent: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('fashion-models.store'), { forceFormData: true, onSuccess: () => reset() });
    };

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

                    {fashionModels.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Chưa có người mẫu nào.</p>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            {fashionModels.map((model) => (
                                <div key={model.id} className="overflow-hidden rounded-xl border">
                                    <img src={model.photo_url} alt={model.name} className="aspect-[3/4] w-full object-cover" />
                                    <div className="flex items-center justify-between gap-2 p-3">
                                        <span className="text-sm font-medium">{model.name}</span>
                                        <Button size="sm" variant="ghost" onClick={() => destroy(model)}>
                                            Xoá
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <HeadingSmall
                        title="Thêm người mẫu"
                        description="Ảnh toàn thân, đứng thẳng, nền đơn giản, mặt rõ. Mặc áo dài qua hông nếu sản phẩm là chân váy/quần."
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="name">Tên</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="photo">Ảnh</Label>
                        <Input id="photo" type="file" accept="image/*" onChange={(e) => setData('photo', e.target.files?.[0] ?? null)} required />
                        <InputError message={errors.photo} />
                    </div>
                    <div className="flex items-start gap-2">
                        <Checkbox id="consent" checked={data.consent} onCheckedChange={(checked) => setData('consent', checked === true)} />
                        <Label htmlFor="consent" className="leading-snug font-normal">
                            Người mẫu đã đồng ý bằng văn bản cho dùng hình ảnh để làm video bán hàng có dùng AI.
                        </Label>
                    </div>
                    <InputError message={errors.consent} />
                    <Button disabled={processing}>Thêm người mẫu</Button>
                </form>
            </div>
        </AppLayout>
    );
}
