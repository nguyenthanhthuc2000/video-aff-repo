import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import ProgressBar from '@/components/progress-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type MotionStyle } from '@/lib/motion-styles';
import { projectStatusLabels } from '@/lib/project-status';
import { type BreadcrumbItem } from '@/types';
import { type AudioTrack, type Background, type FashionModel, type Product, type SampleVideo, type VideoProject } from '@/types/models';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Star, Trash2 } from 'lucide-react';
import CreateProjectForm from './create-project-form';
import ImageReview from './image-review';
import ImportStatus from './import-status';
import ProductForm from './product-form';

export default function ProductsEdit({
    product,
    fashionModels,
    maxImages,
    motionSuggestions,
    productCategory,
    audioTracks,
    sampleVideos,
    backgrounds,
    projects,
    autopilotAvailable,
}: {
    product: Product;
    fashionModels: FashionModel[];
    maxImages: number;
    motionSuggestions: MotionStyle[];
    productCategory: string | null;
    audioTracks: AudioTrack[];
    sampleVideos: SampleVideo[];
    backgrounds: Background[];
    projects: VideoProject[];
    autopilotAvailable: boolean;
}) {
    // Tự làm mới khi đang lấy sản phẩm hoặc có video đang chạy ngầm.
    usePoll(product.import_status === 'importing' || projects.some((project) => project.is_busy), ['product', 'projects']);
    const { errors } = usePage().props as { errors: Record<string, string> };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sản phẩm', href: '/products' },
        { title: product.name, href: route('products.edit', product.id) },
    ];

    const destroy = () => {
        if (confirm('Xoá sản phẩm này và toàn bộ dự án video của nó?')) {
            router.delete(route('products.destroy', product.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={product.name} />
            <div className="grid gap-8 p-4 lg:grid-cols-[1fr_320px]">
                <div className="max-w-3xl space-y-6">
                    <Heading title={product.name} />
                    <FlashStatus />
                    <ImportStatus product={product} />

                    {product.images_approved ? (
                        <section className="space-y-3">
                            <HeadingSmall title="Ảnh sản phẩm" description="Ảnh chính (có sao) được dùng để AI mặc sản phẩm lên người mẫu." />
                            <div className="grid grid-cols-3 gap-3 sm:grid-cols-5">
                                {product.images?.map((image) => (
                                    <div key={image.id} className="relative overflow-hidden rounded-lg border">
                                        <img src={image.url} alt="" className="aspect-square w-full object-cover" />
                                        {image.is_primary && <Badge className="absolute top-1 left-1">Ảnh chính</Badge>}
                                        <div className="absolute right-1 bottom-1 flex gap-1">
                                            {!image.is_primary && (
                                                <Button
                                                    size="icon"
                                                    variant="secondary"
                                                    className="size-7"
                                                    title="Đặt làm ảnh chính"
                                                    onClick={() =>
                                                        router.post(route('product-images.primary', image.id), {}, { preserveScroll: true })
                                                    }
                                                >
                                                    <Star />
                                                </Button>
                                            )}
                                            <Button
                                                size="icon"
                                                variant="secondary"
                                                className="size-7"
                                                title="Xoá ảnh"
                                                onClick={() => router.delete(route('product-images.destroy', image.id), { preserveScroll: true })}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <InputError message={errors.images} />
                        </section>
                    ) : (
                        // Đếm ảnh làm key: chọn lại từ đầu khi job nền tải thêm ảnh.
                        <ImageReview key={product.images?.length ?? 0} product={product} />
                    )}

                    {/* Tạo lại form khi job nền điền xong thông tin. */}
                    <ProductForm key={`${product.import_status}-${product.name}-${product.price}`} product={product} maxImages={maxImages} />

                    <Button variant="destructive" onClick={destroy}>
                        Xoá sản phẩm
                    </Button>
                </div>

                <aside className="space-y-3">
                    <HeadingSmall title="Tạo video" description="Chọn người mẫu để bắt đầu một dự án video." />
                    {!product.images_approved ? (
                        <p className="text-muted-foreground text-sm">Duyệt ảnh sản phẩm trước, sau đó mới tạo video và phân cảnh được.</p>
                    ) : fashionModels.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Chưa có người mẫu.{' '}
                            <Link href={route('fashion-models.index')} className="underline">
                                Thêm người mẫu
                            </Link>
                        </p>
                    ) : (
                        <CreateProjectForm
                            productId={product.id}
                            fashionModels={fashionModels}
                            motionSuggestions={motionSuggestions}
                            productCategory={productCategory}
                            sampleVideos={sampleVideos}
                            backgrounds={backgrounds}
                            audioTracks={audioTracks}
                            autopilotAvailable={autopilotAvailable}
                        />
                    )}

                    {projects.length > 0 && (
                        <div className="space-y-2 pt-4">
                            <HeadingSmall title="Dự án của sản phẩm này" />
                            {projects.map((project) => (
                                <Link
                                    key={project.id}
                                    href={route('projects.show', project.id)}
                                    className="hover:bg-muted/50 block space-y-2 rounded-md border px-3 py-2 text-sm"
                                >
                                    <div className="flex items-center justify-between">
                                        <span>{project.fashion_model?.name}</span>
                                        <Badge variant="secondary">{projectStatusLabels[project.status]}</Badge>
                                    </div>
                                    <ProgressBar
                                        percent={project.progress.percent}
                                        label={project.progress.label}
                                        failed={project.status === 'failed'}
                                    />
                                </Link>
                            ))}
                        </div>
                    )}
                </aside>
            </div>
        </AppLayout>
    );
}
