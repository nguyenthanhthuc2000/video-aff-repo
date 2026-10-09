import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type Product } from '@/types/models';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type ProductFormData = {
    name: string;
    shopee_url: string;
    price: string;
    details: { sizes: string; material: string; colors: string; notes: string };
    reviews: string;
    images: File[];
};

export default function ProductForm({ product, maxImages }: { product?: Product; maxImages: number }) {
    const { data, setData, post, processing, errors, reset, transform } = useForm<ProductFormData>({
        name: product?.name ?? '',
        shopee_url: product?.shopee_url ?? '',
        price: product?.price?.toString() ?? '',
        details: {
            sizes: product?.details.sizes ?? '',
            material: product?.details.material ?? '',
            colors: product?.details.colors ?? '',
            notes: product?.details.notes ?? '',
        },
        reviews: product?.reviews ?? '',
        images: [],
    });

    const remaining = maxImages - (product?.images?.length ?? 0);
    const fieldErrors = errors as Record<string, string | undefined>;
    const imageError = fieldErrors.images ?? Object.entries(fieldErrors).find(([key]) => key.startsWith('images.'))?.[1];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (product) {
            // Gửi file phải dùng POST + _method để Laravel nhận multipart.
            transform((form) => ({ ...form, _method: 'put' }));
            post(route('products.update', product.id), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => reset('images'),
            });
        } else {
            post(route('products.store'), { forceFormData: true });
        }
    };

    const setDetail = (key: keyof ProductFormData['details'], value: string) => setData('details', { ...data.details, [key]: value });

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-2">
                <Label htmlFor="name">Tên sản phẩm</Label>
                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="shopee_url">Link Shopee</Label>
                    <Input
                        id="shopee_url"
                        type="url"
                        value={data.shopee_url}
                        onChange={(e) => setData('shopee_url', e.target.value)}
                        placeholder="https://shopee.vn/..."
                    />
                    <InputError message={errors.shopee_url} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="price">Giá (₫)</Label>
                    <Input id="price" type="number" min={0} value={data.price} onChange={(e) => setData('price', e.target.value)} />
                    <InputError message={errors.price} />
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="sizes">Size</Label>
                    <Input id="sizes" value={data.details.sizes} onChange={(e) => setDetail('sizes', e.target.value)} placeholder="XS–XL" />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="material">Chất liệu</Label>
                    <Input
                        id="material"
                        value={data.details.material}
                        onChange={(e) => setDetail('material', e.target.value)}
                        placeholder="Lụa vân gỗ, 2 lớp"
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="colors">Màu</Label>
                    <Input id="colors" value={data.details.colors} onChange={(e) => setDetail('colors', e.target.value)} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="notes">Đặc điểm khác</Label>
                <Textarea
                    id="notes"
                    value={data.details.notes}
                    onChange={(e) => setDetail('notes', e.target.value)}
                    placeholder="Dáng đuôi cá, cạp ngang rốn..."
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="reviews">Đánh giá nổi bật (cả khen lẫn chê)</Label>
                <Textarea id="reviews" rows={4} value={data.reviews} onChange={(e) => setData('reviews', e.target.value)} />
                <InputError message={errors.reviews} />
            </div>

            {remaining > 0 && (
                <div className="grid gap-2">
                    <Label htmlFor="images">
                        {product ? `Thêm ảnh (còn ${remaining})` : `Ảnh sản phẩm (tối đa ${maxImages}, ảnh đầu là ảnh chính)`}
                    </Label>
                    <Input
                        id="images"
                        type="file"
                        accept="image/*"
                        multiple
                        onChange={(e) => setData('images', Array.from(e.target.files ?? []).slice(0, remaining))}
                        required={!product}
                    />
                    <p className="text-muted-foreground text-xs">Nên có 1 ảnh sản phẩm trên nền trắng, rõ chi tiết, không bị che.</p>
                    <InputError message={imageError} />
                </div>
            )}

            <Button disabled={processing}>{product ? 'Lưu' : 'Tạo sản phẩm'}</Button>
        </form>
    );
}
