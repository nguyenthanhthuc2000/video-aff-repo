import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Category {
    id: number;
    parent_id: number | null;
    key: string;
    name: string;
    keywords: string[];
    scene_settings: string[];
    motion_styles: string[];
    usage: string | null;
    sort_order: number;
}

type Props = { categories: Category[]; motionStyles: { value: string; label: string }[] };

const lines = (text: string) =>
    text
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

/** Master data loại sản phẩm: từ khoá nhận diện, bối cảnh, kiểu chuyển động, cách dùng trong ảnh. */
export default function AdminProductCategories({ categories, motionStyles }: Props) {
    const [editing, setEditing] = useState<Category | 'new' | null>(null);
    const parents = categories.filter((category) => category.parent_id === null);
    const label = (value: string) => motionStyles.find((style) => style.value === value)?.label ?? value;

    return (
        <AdminLayout title="Loại sản phẩm">
            <Head title="Loại sản phẩm" />
            <div className="space-y-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading title="Loại sản phẩm" description="Nhận diện loại theo tên sản phẩm để gợi ý bối cảnh và kiểu chuyển động." />
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> Thêm loại
                    </Button>
                </div>
                <FlashStatus />
                <div className="space-y-3">
                    {parents.map((parent) => (
                        <div key={parent.id} className="rounded-xl border">
                            {[parent, ...categories.filter((child) => child.parent_id === parent.id)].map((category) => (
                                <div key={category.id} className={`flex gap-3 p-4 ${category.parent_id ? 'border-t pl-8' : ''}`}>
                                    <div className="min-w-0 flex-1 space-y-1 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{category.name}</span>
                                            <Badge variant="outline">{category.key}</Badge>
                                            {category.parent_id && <Badge variant="secondary">Loại phụ</Badge>}
                                        </div>
                                        <p className="text-muted-foreground line-clamp-1 text-xs">Từ khoá: {category.keywords.join(', ') || '—'}</p>
                                        <p className="text-muted-foreground line-clamp-1 text-xs">
                                            Bối cảnh: {category.scene_settings.join('; ') || 'theo loại chính'}
                                        </p>
                                        <p className="text-muted-foreground line-clamp-1 text-xs">
                                            Chuyển động: {category.motion_styles.map(label).join(', ') || 'theo loại chính'}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0">
                                        <Button size="icon" variant="ghost" onClick={() => setEditing(category)} aria-label="Sửa">
                                            <Pencil />
                                        </Button>
                                        {category.key !== 'other' && (
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Xoá"
                                                onClick={() =>
                                                    confirm(`Xoá "${category.name}"?`) &&
                                                    router.delete(route('admin.product-categories.destroy', category.id), { preserveScroll: true })
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ))}
                </div>
            </div>
            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'Thêm loại sản phẩm' : 'Sửa loại sản phẩm'}</DialogTitle>
                    </DialogHeader>
                    {editing && (
                        <CategoryForm
                            key={editing === 'new' ? 'new' : editing.id}
                            category={editing === 'new' ? null : editing}
                            parents={parents}
                            motionStyles={motionStyles}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

function CategoryForm({
    category,
    parents,
    motionStyles,
    onDone,
}: {
    category: Category | null;
    parents: Category[];
    motionStyles: Props['motionStyles'];
    onDone: () => void;
}) {
    const { data, setData, post, put, processing, errors, transform } = useForm({
        name: category?.name ?? '',
        key: category?.key ?? '',
        parent_id: category?.parent_id ?? null,
        keywords: (category?.keywords ?? []).join('\n'),
        scene_settings: (category?.scene_settings ?? []).join('\n'),
        motion_styles: category?.motion_styles ?? ([] as string[]),
        usage: category?.usage ?? '',
        sort_order: category?.sort_order ?? 0,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => ({ ...form, keywords: lines(form.keywords), scene_settings: lines(form.scene_settings) }));
        const options = { preserveScroll: true, onSuccess: onDone };
        if (category) {
            put(route('admin.product-categories.update', category.id), options);
        } else {
            post(route('admin.product-categories.store'), options);
        }
    };

    const area = 'border-input bg-background w-full rounded-md border px-3 py-2 text-sm';

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="name">Tên</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="key">Mã (chữ thường, không dấu)</Label>
                    <Input id="key" value={data.key} onChange={(e) => setData('key', e.target.value)} required />
                    <InputError message={errors.key} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="parent_id">Thuộc loại chính</Label>
                    <select
                        id="parent_id"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={data.parent_id ?? ''}
                        onChange={(e) => setData('parent_id', e.target.value === '' ? null : Number(e.target.value))}
                    >
                        <option value="">Không (đây là loại chính)</option>
                        {parents
                            .filter((parent) => parent.id !== category?.id)
                            .map((parent) => (
                                <option key={parent.id} value={parent.id}>
                                    {parent.name}
                                </option>
                            ))}
                    </select>
                    <InputError message={errors.parent_id} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="sort_order">Thứ tự</Label>
                    <Input id="sort_order" type="number" value={data.sort_order} onChange={(e) => setData('sort_order', Number(e.target.value))} />
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="keywords">Từ khoá trong tên sản phẩm (mỗi dòng một từ, có dấu)</Label>
                <textarea id="keywords" rows={4} className={area} value={data.keywords} onChange={(e) => setData('keywords', e.target.value)} />
                <InputError message={errors.keywords} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="scene_settings">Bối cảnh gợi ý (mỗi dòng một bối cảnh; trống = theo loại chính)</Label>
                <textarea
                    id="scene_settings"
                    rows={4}
                    className={area}
                    value={data.scene_settings}
                    onChange={(e) => setData('scene_settings', e.target.value)}
                />
            </div>
            <div className="grid gap-1.5">
                <Label>Kiểu chuyển động gợi ý (theo thứ tự chọn; trống = theo loại chính)</Label>
                <div className="flex flex-wrap gap-1.5">
                    {motionStyles.map((style) => {
                        const on = data.motion_styles.includes(style.value);
                        return (
                            <button
                                key={style.value}
                                type="button"
                                onClick={() =>
                                    setData(
                                        'motion_styles',
                                        on ? data.motion_styles.filter((v) => v !== style.value) : [...data.motion_styles, style.value],
                                    )
                                }
                                className={`rounded-full border px-3 py-1 text-xs ${on ? 'bg-foreground text-background' : 'hover:bg-muted'}`}
                            >
                                {style.label}
                            </button>
                        );
                    })}
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="usage">Cách sản phẩm xuất hiện trong ảnh (tiếng Anh, đưa vào prompt)</Label>
                <textarea id="usage" rows={2} className={area} value={data.usage} onChange={(e) => setData('usage', e.target.value)} />
            </div>
            <Button disabled={processing}>Lưu</Button>
        </form>
    );
}
