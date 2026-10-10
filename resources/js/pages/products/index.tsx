import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatVnd } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AudioTrack, type Background, type FashionModel, type OutfitProduct, type Product, type SampleVideo } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { Clapperboard, ImageIcon, Plus, Video, X } from 'lucide-react';
import { useState } from 'react';
import CreateProjectForm from './create-project-form';

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

export default function ProductsIndex({
    products,
    fashionModels,
    sampleVideos,
    audioTracks,
    backgrounds,
    outfitProducts,
    autopilotAvailable,
}: {
    products: Product[];
    fashionModels: FashionModel[];
    sampleVideos: SampleVideo[];
    audioTracks: AudioTrack[];
    backgrounds: Background[];
    outfitProducts: OutfitProduct[];
    autopilotAvailable: boolean;
}) {
    // Chọn nhiều sản phẩm (đã duyệt ảnh) rồi tạo một video gộp: mỗi sản phẩm một bộ đồ, theo thứ tự chọn.
    const [selected, setSelected] = useState<number[]>([]);
    const [creating, setCreating] = useState(false);
    const toggle = (id: number) => setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
    const selectedProducts = selected.map((id) => products.find((product) => product.id === id)).filter((product) => product !== undefined);

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

                {products.some((product) => product.images_approved) && (
                    <p className="text-muted-foreground text-sm">Tick nhiều sản phẩm để gộp vào một video (mỗi sản phẩm một bộ đồ).</p>
                )}

                {products.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Chưa có sản phẩm nào. Bấm "Thêm sản phẩm" để bắt đầu.</p>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {products.map((product) => {
                            const order = selected.indexOf(product.id);
                            return (
                                <div
                                    key={product.id}
                                    className={cn(
                                        'flex overflow-hidden rounded-xl border transition',
                                        order >= 0 && 'border-primary ring-primary/30 bg-primary/5 ring-2',
                                    )}
                                >
                                    {/* Cột chọn riêng (ngoài link) để bấm chọn không mở trang sản phẩm. */}
                                    <button
                                        type="button"
                                        disabled={!product.images_approved}
                                        onClick={() => toggle(product.id)}
                                        title={product.images_approved ? 'Chọn để gộp vào video' : 'Duyệt ảnh sản phẩm trước khi làm video'}
                                        aria-label={`Chọn ${product.name}`}
                                        className="hover:bg-muted flex w-10 shrink-0 items-center justify-center border-r disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {order >= 0 ? (
                                            <span className="bg-primary text-primary-foreground flex size-6 items-center justify-center rounded-md text-xs font-bold">
                                                {order + 1}
                                            </span>
                                        ) : (
                                            <span className="border-input bg-background size-5 rounded-md border-2" />
                                        )}
                                    </button>
                                    <Link
                                        href={route('products.edit', product.id)}
                                        className="group hover:bg-muted/50 flex min-w-0 flex-1 gap-3 p-2.5 transition"
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
                                </div>
                            );
                        })}
                    </div>
                )}

                {selected.length > 0 && <div className="h-16" />}
            </div>

            {selected.length > 0 && (
                <div className="bg-background/95 fixed bottom-4 left-1/2 z-30 flex w-[min(640px,calc(100%-2rem))] -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border px-4 py-2.5 shadow-lg backdrop-blur md:ml-32">
                    <div className="flex -space-x-2">
                        {selectedProducts.slice(0, 5).map((product) => (
                            <img
                                key={product.id}
                                src={product.primary_image_url ?? ''}
                                alt=""
                                className="border-background size-9 rounded-md border-2 object-cover"
                            />
                        ))}
                    </div>
                    <span className="text-sm font-medium">Đã chọn {selected.length} sản phẩm</span>
                    <Button variant="ghost" size="sm" onClick={() => setSelected([])}>
                        <X /> Bỏ chọn
                    </Button>
                    <Button className="ml-auto" onClick={() => setCreating(true)}>
                        <Video /> {selected.length > 1 ? `Tạo video gộp ${selected.length} sản phẩm` : 'Tạo video'}
                    </Button>
                </div>
            )}

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{selected.length > 1 ? `Video gộp ${selected.length} sản phẩm` : 'Tạo video'}</DialogTitle>
                        <DialogDescription>
                            {selected.length > 1
                                ? 'Mỗi sản phẩm là một bộ đồ, mặc lần lượt theo thứ tự đã chọn. Chọn màu cho từng bộ ở mục "Gộp nhiều sản phẩm".'
                                : selectedProducts[0]?.name}
                        </DialogDescription>
                    </DialogHeader>
                    {creating &&
                        selected.length > 0 &&
                        (fashionModels.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Chưa có người mẫu đã xác nhận đồng ý.{' '}
                                <Link href={route('fashion-models.index')} className="underline">
                                    Thêm người mẫu
                                </Link>
                            </p>
                        ) : (
                            <CreateProjectForm
                                key={selected.join('-')}
                                productId={selected[0]}
                                fashionModels={fashionModels}
                                sampleVideos={sampleVideos}
                                audioTracks={audioTracks}
                                backgrounds={backgrounds}
                                outfitProducts={outfitProducts}
                                defaultOutfits={
                                    selected.length > 1 ? selected.map((id) => ({ product_id: id, product_image_id: null, color: null })) : []
                                }
                                autopilotAvailable={autopilotAvailable}
                            />
                        ))}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
