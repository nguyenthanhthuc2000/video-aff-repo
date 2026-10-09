import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import ShopeeImport from './shopee-import';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sản phẩm', href: '/products' },
    { title: 'Thêm sản phẩm', href: '/products/create' },
];

export default function ProductsCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Thêm sản phẩm" />
            <div className="max-w-3xl space-y-6 p-4">
                <Heading title="Thêm sản phẩm" description="Chỉ cần bấm bookmark trên trang Shopee, sản phẩm được thêm tự động." />
                <ShopeeImport />
            </div>
        </AppLayout>
    );
}
