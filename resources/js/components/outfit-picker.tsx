import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type Outfit, type OutfitProduct } from '@/types/models';
import { ArrowDown, ArrowUp, Check, Copy, Images, X } from 'lucide-react';

function Chip({ active, onClick, children, title }: { active: boolean; onClick: () => void; children: React.ReactNode; title?: string }) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className={cn(
                'inline-flex h-6 items-center gap-1 rounded-full border px-2 text-xs transition',
                active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
            )}
        >
            {active && <Check className="size-3" />}
            {children}
        </button>
    );
}

/** Màu và ảnh đúng màu của một bộ đồ: bấm chip màu, bấm ảnh để AI chỉ nhìn ảnh đó. */
function OutfitDetails({ product, value, onChange }: { product: OutfitProduct; value: Outfit; onChange: (outfit: Outfit) => void }) {
    return (
        <div className="space-y-1.5">
            <div className="flex flex-wrap items-center gap-1">
                <span className="text-muted-foreground w-9 shrink-0 text-xs">Màu</span>
                <Chip active={!value.color} onClick={() => onChange({ ...value, color: null })}>
                    Như ảnh
                </Chip>
                {product.colors.map((color) => (
                    <Chip
                        key={color}
                        active={value.color === color}
                        onClick={() => onChange({ ...value, color: value.color === color ? null : color })}
                    >
                        {color}
                    </Chip>
                ))}
                {product.colors.length === 0 && (
                    <input
                        aria-label="Màu"
                        placeholder="Gõ màu (không bắt buộc)"
                        className="border-input bg-background h-6 w-40 rounded-full border px-2 text-xs"
                        value={value.color ?? ''}
                        onChange={(e) => onChange({ ...value, color: e.target.value || null })}
                    />
                )}
            </div>
            {product.images.length > 1 && (
                <div className="flex flex-wrap items-center gap-1">
                    <span className="text-muted-foreground w-9 shrink-0 text-xs">Ảnh</span>
                    <Chip
                        active={value.product_image_id === null}
                        onClick={() => onChange({ ...value, product_image_id: null })}
                        title="AI xem mọi ảnh của sản phẩm"
                    >
                        Tất cả
                    </Chip>
                    {product.images.map((image) => (
                        <button
                            key={image.id}
                            type="button"
                            title="Chỉ dùng ảnh này (đúng màu / mẫu)"
                            onClick={() => onChange({ ...value, product_image_id: value.product_image_id === image.id ? null : image.id })}
                            className={cn(
                                'overflow-hidden rounded-md border-2',
                                value.product_image_id === image.id ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100',
                            )}
                        >
                            {image.url && <img src={image.url} alt="" className="size-8 object-cover" />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Bộ đồ của một cảnh (bước Ảnh cảnh): chọn sản phẩm rồi màu / ảnh. */
export function OutfitFields({ products, value, onChange }: { products: OutfitProduct[]; value: Outfit; onChange: (outfit: Outfit) => void }) {
    const product = products.find((item) => item.id === value.product_id);

    return (
        <div className="space-y-1.5">
            <select
                aria-label="Sản phẩm"
                className="border-input bg-background h-7 w-full rounded-md border px-2 text-xs"
                value={value.product_id}
                onChange={(e) => onChange({ product_id: Number(e.target.value), product_image_id: null, color: null })}
            >
                {products.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.name}
                    </option>
                ))}
            </select>
            {product && <OutfitDetails product={product} value={value} onChange={onChange} />}
        </div>
    );
}

/**
 * Bộ đồ của video: bấm ảnh sản phẩm để thêm / bỏ (đánh số theo thứ tự mặc), rồi chọn màu cho từng bộ.
 * Cảnh (hoặc shot của video mẫu) thứ 1 mặc bộ 1, thứ 2 mặc bộ 2...
 */
export default function OutfitPicker({
    products,
    value,
    onChange,
    shotCount,
}: {
    products: OutfitProduct[];
    value: Outfit[];
    onChange: (outfits: Outfit[]) => void;
    /** Số shot của video mẫu đang chọn (nhiều shot thì mỗi shot một bộ). */
    shotCount?: number;
}) {
    const byId = new Map(products.map((product) => [product.id, product]));
    const toggle = (id: number) =>
        onChange(
            value.some((outfit) => outfit.product_id === id)
                ? value.filter((outfit) => outfit.product_id !== id)
                : [...value, { product_id: id, product_image_id: null, color: null }],
        );
    const update = (i: number, next: Outfit) => onChange(value.map((item, j) => (j === i ? next : item)));
    const move = (i: number, step: number) => {
        const next = [...value];
        [next[i], next[i + step]] = [next[i + step], next[i]];
        onChange(next);
    };
    const thumbnail = (outfit: Outfit) => {
        const product = byId.get(outfit.product_id);
        return product?.images.find((image) => image.id === outfit.product_image_id)?.url ?? product?.images[0]?.url ?? null;
    };

    return (
        <div className="space-y-3">
            <div className="space-y-1.5">
                <p className="text-muted-foreground text-xs">Bấm sản phẩm để thêm vào video (số là thứ tự mặc).</p>
                <div className="grid max-h-56 grid-cols-[repeat(auto-fill,minmax(68px,1fr))] gap-2 overflow-y-auto pr-1">
                    {products.map((product) => {
                        const numbers = value.flatMap((outfit, i) => (outfit.product_id === product.id ? [i + 1] : []));
                        return (
                            <button
                                key={product.id}
                                type="button"
                                onClick={() => toggle(product.id)}
                                title={product.name}
                                className={cn(
                                    'relative overflow-hidden rounded-lg border-2 text-left',
                                    numbers.length > 0 ? 'border-primary' : 'border-transparent',
                                )}
                            >
                                {product.images[0]?.url ? (
                                    <img src={product.images[0].url} alt={product.name} className="aspect-[3/4] w-full object-cover" />
                                ) : (
                                    <div className="bg-muted aspect-[3/4] w-full" />
                                )}
                                {numbers.length > 0 && (
                                    <span className="bg-primary text-primary-foreground absolute top-1 left-1 rounded-full px-1.5 text-xs font-bold">
                                        {numbers.join(',')}
                                    </span>
                                )}
                                <span className="block truncate px-1 py-0.5 text-[11px]">{product.name}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {value.length > 0 && (
                <ol className="divide-y rounded-lg border">
                    {value.map((outfit, i) => {
                        const product = byId.get(outfit.product_id);
                        if (!product) {
                            return null;
                        }
                        const thumb = thumbnail(outfit);
                        return (
                            <li key={i} className="flex gap-2.5 p-2">
                                <div className="relative shrink-0">
                                    {thumb ? (
                                        <img src={thumb} alt="" className="h-16 w-12 rounded-md object-cover" />
                                    ) : (
                                        <div className="bg-muted h-16 w-12 rounded-md" />
                                    )}
                                    <span className="bg-primary text-primary-foreground absolute -top-1.5 -left-1.5 flex size-5 items-center justify-center rounded-full text-xs font-bold">
                                        {i + 1}
                                    </span>
                                </div>
                                <div className="min-w-0 flex-1 space-y-1.5">
                                    <div className="flex items-start gap-1">
                                        <p className="line-clamp-1 flex-1 text-sm font-medium">{product.name}</p>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-6"
                                            disabled={i === 0}
                                            onClick={() => move(i, -1)}
                                            title="Lên"
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-6"
                                            disabled={i === value.length - 1}
                                            onClick={() => move(i, 1)}
                                            title="Xuống"
                                        >
                                            <ArrowDown />
                                        </Button>
                                        {value.length < 12 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-6"
                                                onClick={() =>
                                                    onChange([
                                                        ...value.slice(0, i + 1),
                                                        { ...outfit, color: null, product_image_id: null },
                                                        ...value.slice(i + 1),
                                                    ])
                                                }
                                                title="Thêm bộ cùng sản phẩm, màu khác"
                                            >
                                                <Copy />
                                            </Button>
                                        )}
                                        {product.images.length > 1 && outfit.product_image_id === null && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-6"
                                                onClick={() =>
                                                    onChange(
                                                        [
                                                            ...value.slice(0, i),
                                                            ...product.images.map((image) => ({ ...outfit, product_image_id: image.id })),
                                                            ...value.slice(i + 1),
                                                        ].slice(0, 12),
                                                    )
                                                }
                                                title={`Mỗi ảnh một bộ (${product.images.length} ảnh: các màu / mẫu khác nhau)`}
                                            >
                                                <Images />
                                            </Button>
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-6"
                                            onClick={() => onChange(value.filter((_, j) => j !== i))}
                                            title="Bỏ"
                                        >
                                            <X />
                                        </Button>
                                    </div>
                                    <OutfitDetails product={product} value={outfit} onChange={(next) => update(i, next)} />
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}

            <p className="text-muted-foreground text-xs">
                {shotCount && shotCount > 1
                    ? `Video mẫu có ${shotCount} shot: shot 1 mặc bộ 1, shot 2 mặc bộ 2... (hết bộ thì quay lại bộ 1).`
                    : 'Cảnh 1 mặc bộ 1, cảnh 2 mặc bộ 2... (hết bộ thì quay lại bộ 1).'}{' '}
                Muốn một sản phẩm nhiều màu: bấm <Images className="inline size-3" /> để mỗi ảnh thành một bộ, hoặc <Copy className="inline size-3" />{' '}
                rồi chọn màu khác. Đổi bộ đồ từng cảnh được ở bước Ảnh cảnh.
            </p>
        </div>
    );
}
