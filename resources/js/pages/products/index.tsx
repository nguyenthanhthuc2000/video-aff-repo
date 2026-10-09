import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatVnd } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { type Product } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sản phẩm', href: '/products' }];

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
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {products.map((product) => (
                            <Link key={product.id} href={route('products.edit', product.id)} className="group overflow-hidden rounded-xl border">
                                <div className="bg-muted aspect-square overflow-hidden">
                                    {product.primary_image_url && (
                                        <img
                                            src={product.primary_image_url}
                                            alt={product.name}
                                            className="size-full object-cover transition group-hover:scale-105"
                                        />
                                    )}
                                </div>
                                <div className="space-y-1 p-3">
                                    <p className="line-clamp-2 text-sm font-medium">{product.name}</p>
                                    <p className="text-muted-foreground text-sm">{formatVnd(product.price)}</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
