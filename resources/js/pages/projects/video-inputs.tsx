import { Badge } from '@/components/ui/badge';
import { formatVnd } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Background, type VideoProject } from '@/types/models';
import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';

/** Ảnh đã chọn cho video: người mẫu, bối cảnh, mọi sản phẩm (video gộp: theo thứ tự bộ đồ) kèm link. */
export default function VideoInputs({ project, backgrounds }: { project: VideoProject; backgrounds: Background[] }) {
    const products = project.video_products ?? [];
    const chosenBackgrounds = project.background_ids
        .map((id) => backgrounds.find((background) => background.id === id))
        .filter((background) => background !== undefined);
    const models = [
        project.before_fashion_model && { label: 'Trước', ...project.before_fashion_model },
        project.fashion_model && { label: project.before_fashion_model ? 'Sau' : 'Người mẫu', ...project.fashion_model },
    ].filter((model) => !!model);

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <div className="flex flex-wrap gap-6">
                <div className="space-y-1.5">
                    <p className="text-muted-foreground text-xs font-medium">Người mẫu</p>
                    <div className="flex gap-2">
                        {models.map((model) => (
                            <figure key={model.label} className="space-y-1">
                                {model.photo_url && <img src={model.photo_url} alt={model.name} className="h-32 rounded-lg border object-cover" />}
                                <figcaption className="text-muted-foreground text-center text-xs">{model.label}</figcaption>
                            </figure>
                        ))}
                    </div>
                </div>
                {chosenBackgrounds.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-muted-foreground text-xs font-medium">Bối cảnh ({chosenBackgrounds.length})</p>
                        <div className="flex flex-wrap gap-2">
                            {chosenBackgrounds.map((background, i) => (
                                <figure key={background.id} className="relative w-24 space-y-1">
                                    {background.photo_url && (
                                        <img src={background.photo_url} alt={background.name} className="h-32 w-24 rounded-lg border object-cover" />
                                    )}
                                    <span className="bg-background/90 absolute top-1 left-1 rounded px-1 text-[11px] font-bold">{i + 1}</span>
                                    <figcaption className="text-muted-foreground truncate text-center text-xs">{background.name}</figcaption>
                                </figure>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <div className="space-y-2">
                <p className="text-muted-foreground text-xs font-medium">
                    {products.length > 1 ? `Video gộp ${products.length} sản phẩm` : 'Sản phẩm'} · viền đậm = ảnh AI dùng khi tạo ảnh cảnh
                </p>
                <div className="grid gap-3 lg:grid-cols-2">
                    {products.map((product) => (
                        <div key={product.id} className="space-y-2 rounded-lg border p-3">
                            <div className="flex items-start gap-2">
                                {products.length > 1 && (
                                    <span className="bg-primary text-primary-foreground shrink-0 rounded-full px-2 py-0.5 text-xs font-bold">
                                        Bộ {product.outfit_numbers.join(', ')}
                                    </span>
                                )}
                                <div className="min-w-0 flex-1">
                                    <Link href={product.url} className="line-clamp-2 text-sm font-medium hover:underline">
                                        {product.name}
                                    </Link>
                                    <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        {product.price !== null && <span className="text-foreground font-semibold">{formatVnd(product.price)}</span>}
                                        {product.scenes.length > 0 && <span>Cảnh {product.scenes.join(', ')}</span>}
                                        {product.colors.map((color) => (
                                            <Badge key={color} variant="outline">
                                                {color}
                                            </Badge>
                                        ))}
                                        {product.shopee_url && (
                                            <a
                                                href={product.shopee_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-orange-600 hover:underline"
                                            >
                                                {product.shop_name ?? 'Shopee'} <ExternalLink className="size-3" />
                                            </a>
                                        )}
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {product.images.map((image) =>
                                    image.url ? (
                                        <a key={image.id} href={image.url} target="_blank" rel="noreferrer">
                                            <img
                                                src={image.url}
                                                alt=""
                                                className={cn(
                                                    'h-20 w-16 rounded-md object-cover',
                                                    image.used ? 'ring-primary ring-2 ring-offset-1' : 'opacity-60 hover:opacity-100',
                                                )}
                                            />
                                        </a>
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
