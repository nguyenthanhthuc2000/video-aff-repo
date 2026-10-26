import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ExternalLink, Pencil, Search, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Shorts chủ đề', href: '/topic-shorts' },
    { title: 'Kho tư liệu', href: '/lore-facts' },
];

interface Fact {
    id: number;
    kind: 'fact' | 'quote' | 'theory';
    era: string | null;
    text: string;
    source_url: string | null;
    source_title: string | null;
    characters: string[];
}

/** Kho tư liệu đã duyệt: Claude dùng lại khi tìm tư liệu cho chủ đề mới. */
export default function LoreFactsIndex({
    facts,
    filters,
    kinds,
    characters,
    total,
}: {
    facts: Fact[];
    filters: { character: string; q: string };
    kinds: Record<string, string>;
    characters: Record<string, string>;
    total: number;
}) {
    const [q, setQ] = useState(filters.q);
    const filter = (next: Partial<typeof filters>) =>
        router.get(route('lore-facts.index'), { ...filters, q, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const search: FormEventHandler = (e) => {
        e.preventDefault();
        filter({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kho tư liệu" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Kho tư liệu"
                    description={`${total} chi tiết bạn đã duyệt. Khi tìm tư liệu cho chủ đề mới, Claude đọc kho trước và chỉ tìm phần còn thiếu.`}
                />
                <FlashStatus />
                <form onSubmit={search} className="flex max-w-2xl flex-wrap gap-2">
                    <select
                        aria-label="Nhân vật"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={filters.character}
                        onChange={(e) => filter({ character: e.target.value })}
                    >
                        <option value="">Mọi nhân vật</option>
                        {Object.entries(characters).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <Input className="min-w-40 flex-1" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tìm nội dung, arc..." />
                    <Button variant="outline">
                        <Search /> Tìm
                    </Button>
                </form>
                <div className="divide-y rounded-lg border">
                    {facts.length === 0 && (
                        <p className="text-muted-foreground p-4 text-sm">Chưa có tư liệu nào. Duyệt tư liệu ở một short để lưu vào kho.</p>
                    )}
                    {facts.map((fact) => (
                        <FactRow key={`${fact.id}:${fact.text}`} fact={fact} kinds={kinds} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function FactRow({ fact, kinds }: { fact: Fact; kinds: Record<string, string> }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        kind: fact.kind,
        era: fact.era ?? '',
        text: fact.text,
        source_url: fact.source_url ?? '',
    });
    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('lore-facts.update', fact.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };
    const destroy = () => confirm('Xoá chi tiết này khỏi kho?') && router.delete(route('lore-facts.destroy', fact.id), { preserveScroll: true });

    if (editing) {
        return (
            <form onSubmit={save} className="space-y-2 p-3">
                <div className="flex flex-wrap gap-2">
                    <select
                        aria-label="Loại"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={data.kind}
                        onChange={(e) => setData('kind', e.target.value as Fact['kind'])}
                    >
                        {Object.entries(kinds).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <Input className="min-w-40 flex-1" value={data.era} onChange={(e) => setData('era', e.target.value)} placeholder="Arc / tập" />
                </div>
                <Textarea rows={2} value={data.text} onChange={(e) => setData('text', e.target.value)} />
                <InputError message={errors.text} />
                <Input value={data.source_url} onChange={(e) => setData('source_url', e.target.value)} placeholder="Link nguồn" />
                <InputError message={errors.source_url} />
                <div className="flex gap-2">
                    <Button size="sm" disabled={processing}>
                        Lưu
                    </Button>
                    <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)}>
                        Huỷ
                    </Button>
                </div>
            </form>
        );
    }

    return (
        <div className="flex gap-3 p-3">
            <div className="min-w-0 flex-1 space-y-1">
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    <Badge variant={fact.kind === 'theory' ? 'outline' : 'secondary'}>{kinds[fact.kind]}</Badge>
                    {fact.era && <span className="text-muted-foreground">{fact.era}</span>}
                    {fact.characters.length > 0 && <span className="text-muted-foreground">· {fact.characters.join(', ')}</span>}
                </div>
                <p className="text-sm">{fact.text}</p>
                {fact.source_url && (
                    <a
                        href={fact.source_url}
                        target="_blank"
                        rel="noreferrer"
                        className="text-primary inline-flex items-center gap-1 text-xs break-all"
                    >
                        <ExternalLink className="size-3 shrink-0" /> {fact.source_title || fact.source_url}
                    </a>
                )}
            </div>
            <div className="flex shrink-0 gap-1">
                <Button size="icon" variant="ghost" aria-label="Sửa" onClick={() => setEditing(true)}>
                    <Pencil />
                </Button>
                <Button size="icon" variant="ghost" aria-label="Xoá" className="text-destructive" onClick={destroy}>
                    <Trash2 />
                </Button>
            </div>
        </div>
    );
}
