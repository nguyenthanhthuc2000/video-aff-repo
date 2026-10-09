import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import ProductForm from './product-form';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sản phẩm', href: '/products' },
    { title: 'Thêm sản phẩm', href: '/products/create' },
];

export default function ProductsCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Thêm sản phẩm" />
            <div className="max-w-3xl space-y-6 p-4">
                <Heading
                    title="Thêm sản phẩm"
                    description="Shopee không cho lấy dữ liệu tự động, nên bạn dán thông tin và tải ảnh từ trang sản phẩm lên đây."
                />
                <ProductForm maxImages={5} />
            </div>
        </AppLayout>
    );
}
