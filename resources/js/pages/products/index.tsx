import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatVnd } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { type Product } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { Clapperboard, ImageIcon, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sản phẩm', href: '/products' }];

/** Nhãn trạng thái khi sản phẩm chưa sẵn sàng làm video. */
function statusBadge(product: Product) {
    if (product.import_status === 'importing') {
        return <Badge variant="secondary">Đang lấy dữ liệu</Badge>;
    }
    if (product.import_status === 'failed') {
        return <Badge variant="destructive">Lỗi lấy dữ liệu</Badge>;
    }
    if (product.import_status === 'awaiting_reply') {
        return <Badge variant="outline">Chờ phân tích</Badge>;
    }
    if (!product.images_approved) {
        return <Badge variant="outline">Chờ duyệt ảnh</Badge>;
    }
    return null;
}

export default function ProductsIndex({ products }: { products: Product[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sản phẩm" />
            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Sản phẩm" description="Sản phẩm affiliate bạn muốn làm video." />
                    <Button asChild>
                        <Link href={route('products.create')}>
                            <Plus /> Thêm sản phẩm
                        </Link>
                    </Button>
                </div>

                <FlashStatus />

                {products.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Chưa có sản phẩm nào. Bấm "Thêm sản phẩm" để bắt đầu.</p>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {products.map((product) => (
                            <Link
                                key={product.id}
                                href={route('products.edit', product.id)}
                                className="group hover:bg-muted/50 flex gap-3 rounded-xl border p-2.5 transition"
                            >
                                <div className="bg-muted size-20 shrink-0 overflow-hidden rounded-lg">
                                    {product.primary_image_url && (
                                        <img
                                            src={product.primary_image_url}
                                            alt={product.name}
                                            loading="lazy"
                                            className="size-full object-cover transition group-hover:scale-105"
                                        />
                                    )}
                                </div>
                                <div className="flex min-w-0 flex-1 flex-col justify-between gap-1">
                                    <p className="line-clamp-2 text-sm leading-snug font-medium">{product.name}</p>
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                        <span className="font-semibold">{formatVnd(product.price)}</span>
                                        {product.category && <span className="text-muted-foreground truncate">{product.category}</span>}
                                    </div>
                                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        <span className="inline-flex items-center gap-1">
                                            <ImageIcon className="size-3" /> {product.images_count ?? 0}
                                        </span>
                                        <span className="inline-flex items-center gap-1">
                                            <Clapperboard className="size-3" /> {product.video_projects_count ?? 0} video
                                        </span>
                                        <span>{new Date(product.created_at).toLocaleDateString('vi-VN')}</span>
                                        {statusBadge(product)}
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
