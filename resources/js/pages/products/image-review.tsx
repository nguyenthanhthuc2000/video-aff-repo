import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type Product } from '@/types/models';
import { router, usePage } from '@inertiajs/react';
import { Check, LoaderCircle, Star } from 'lucide-react';
import { useState } from 'react';

/** Chọn ảnh giữ lại sau khi lấy từ Shopee (trang có cả ảnh sản phẩm khác, ảnh đánh giá...). */
export default function ImageReview({ product }: { product: Product }) {
    const images = product.images ?? [];
    const { errors } = usePage().props as { errors: Record<string, string> };
    // Mặc định chọn hết, bỏ chọn ảnh không đúng sản phẩm.
    const [selected, setSelected] = useState<number[]>(() => images.map((image) => image.id));
    const [primaryId, setPrimaryId] = useState<number | null>(() => images.find((image) => image.is_primary)?.id ?? images[0]?.id ?? null);
    const [saving, setSaving] = useState(false);

    if (images.length === 0) {
        return (
            <section className="space-y-3">
                <HeadingSmall title="Ảnh sản phẩm" />
                <p className="text-muted-foreground inline-flex items-center gap-2 text-sm">
                    {product.import_status === 'importing' ? (
                        <>
                            <LoaderCircle className="size-4 animate-spin" /> Đang tải ảnh từ Shopee...
                        </>
                    ) : (
                        'Chưa có ảnh.'
                    )}
                </p>
            </section>
        );
    }

    const toggle = (id: number) => {
        const next = selected.includes(id) ? selected.filter((other) => other !== id) : [...selected, id];
        setSelected(next);
        if (primaryId === null || !next.includes(primaryId)) {
            setPrimaryId(next[0] ?? null);
        }
    };

    const makePrimary = (id: number) => {
        setPrimaryId(id);
        if (!selected.includes(id)) {
            setSelected([...selected, id]);
        }
    };

    const approve = () => {
        const removed = images.length - selected.length;
        if (removed > 0 && !confirm(`Giữ ${selected.length} ảnh và xoá ${removed} ảnh không chọn?`)) {
            return;
        }
        router.post(
            route('products.images.approve', product.id),
            { image_ids: selected, primary_id: primaryId },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
    };

    return (
        <section className="space-y-3 rounded-lg border border-amber-300 p-4 dark:border-amber-700">
            <HeadingSmall
                title={`Duyệt ảnh sản phẩm (${selected.length}/${images.length})`}
                description="Đã tải hết ảnh trên trang Shopee. Bấm vào ảnh để chọn/bỏ, bấm ngôi sao để chọn ảnh chính (dùng để AI mặc lên người mẫu). Duyệt xong mới tạo video và phân cảnh được."
            />
            <div className="grid grid-cols-3 gap-3 sm:grid-cols-5">
                {images.map((image) => {
                    const isSelected = selected.includes(image.id);
                    return (
                        <div
                            key={image.id}
                            className={cn(
                                'relative cursor-pointer overflow-hidden rounded-lg border-2 transition',
                                isSelected ? 'border-primary' : 'border-transparent opacity-40',
                            )}
                            onClick={() => toggle(image.id)}
                        >
                            <img src={image.url} alt="" className="aspect-square w-full object-cover" />
                            {isSelected && (
                                <span className="bg-primary text-primary-foreground absolute top-1 right-1 rounded-full p-0.5">
                                    <Check className="size-3.5" />
                                </span>
                            )}
                            {primaryId === image.id && <Badge className="absolute top-1 left-1">Ảnh chính</Badge>}
                            {primaryId !== image.id && (
                                <Button
                                    size="icon"
                                    variant="secondary"
                                    className="absolute right-1 bottom-1 size-7"
                                    title="Đặt làm ảnh chính"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        makePrimary(image.id);
                                    }}
                                >
                                    <Star />
                                </Button>
                            )}
                        </div>
                    );
                })}
            </div>
            <div className="flex flex-wrap gap-2">
                <Button onClick={approve} disabled={saving || selected.length === 0 || primaryId === null}>
                    <Check /> {saving ? 'Đang lưu...' : `Duyệt ${selected.length} ảnh`}
                </Button>
                <Button variant="ghost" onClick={() => setSelected(images.map((image) => image.id))}>
                    Chọn hết
                </Button>
                <Button variant="ghost" onClick={() => setSelected([])}>
                    Bỏ chọn hết
                </Button>
            </div>
            <InputError message={errors.image_ids ?? errors['image_ids.0'] ?? errors.primary_id} />
        </section>
    );
}
