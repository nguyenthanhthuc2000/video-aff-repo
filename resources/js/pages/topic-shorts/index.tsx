import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shorts chủ đề', href: '/topic-shorts' }];

interface ShortItem {
    id: number;
    topic: string;
    title: string | null;
    language: string;
    status: 'writing' | 'ready' | 'failed';
    video_status: string | null;
    scenes_count: number;
    created_at: string;
}

const statusLabels: Record<string, string> = { writing: 'Đang viết', ready: 'Sẵn sàng', failed: 'Lỗi' };

/** Danh sách short theo chủ đề + form tạo mới. */
export default function TopicShortsIndex({
    shorts,
    languages,
    styles,
    image_styles,
    wikis,
}: {
    shorts: ShortItem[];
    languages: Record<string, string>;
    styles: Record<string, string>;
    image_styles: Record<string, string>;
    wikis: Record<string, string>;
}) {
    const { data, setData, post, processing, errors } = useForm({
        topic: '',
        style: 'moment',
        image_style: 'manga',
        language: 'vi',
        target_seconds: 45,
        wiki: 'commons',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('topic-shorts.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shorts chủ đề" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Shorts chủ đề"
                    description="Nhập một chủ đề (ví dụ: Lần đầu Goku biến thành Super Saiyan). Claude kể chuyện, tìm ảnh từng cảnh, giọng AI đọc, ghép video 9:16 dài 30–60 giây."
                />
                <FlashStatus />

                <form onSubmit={submit} className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <div className="grid gap-2">
                        <Label htmlFor="topic">Chủ đề</Label>
                        <Textarea
                            id="topic"
                            rows={2}
                            value={data.topic}
                            onChange={(e) => setData('topic', e.target.value)}
                            placeholder="Vì sao Goku biến Super Saiyan lần đầu ở Namek?"
                        />
                        <p className="text-muted-foreground text-xs">Viết chủ đề như một câu gây tò mò sẽ ra hook hay hơn một cái tên chung chung.</p>
                        <InputError message={errors.topic} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Dạng short</Label>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(styles).map(([value, label]) => (
                                <Button
                                    key={value}
                                    type="button"
                                    size="sm"
                                    variant={data.style === value ? 'default' : 'outline'}
                                    aria-pressed={data.style === value}
                                    onClick={() => setData('style', value)}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label>Kiểu ảnh</Label>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(image_styles).map(([value, label]) => (
                                <Button
                                    key={value}
                                    type="button"
                                    size="sm"
                                    variant={data.image_style === value ? 'default' : 'outline'}
                                    aria-pressed={data.image_style === value}
                                    onClick={() => setData('image_style', value)}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="language">Giọng đọc</Label>
                            <select
                                id="language"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={data.language}
                                onChange={(e) => setData('language', e.target.value)}
                            >
                                {Object.entries(languages).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="target_seconds">Độ dài: {data.target_seconds} giây</Label>
                            <input
                                id="target_seconds"
                                type="range"
                                min={30}
                                max={60}
                                step={5}
                                value={data.target_seconds}
                                onChange={(e) => setData('target_seconds', Number(e.target.value))}
                            />
                            <InputError message={errors.target_seconds} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="wiki">Tìm ảnh ở</Label>
                            <select
                                id="wiki"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={data.wiki}
                                onChange={(e) => setData('wiki', e.target.value)}
                            >
                                {Object.entries(wikis).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <Button disabled={processing || data.topic.trim().length < 5}>
                        <Sparkles /> Tạo short
                    </Button>
                </form>

                <div className="divide-y rounded-lg border">
                    {shorts.length === 0 && <p className="text-muted-foreground p-4 text-sm">Chưa có short nào.</p>}
                    {shorts.map((short) => (
                        <Link
                            key={short.id}
                            href={route('topic-shorts.show', short.id)}
                            className="hover:bg-muted/50 flex items-center justify-between gap-4 p-4"
                        >
                            <div className="min-w-0">
                                <div className="truncate font-medium">{short.title || short.topic}</div>
                                <div className="text-muted-foreground truncate text-sm">
                                    {short.topic} · {languages[short.language]} · {short.scenes_count} cảnh
                                </div>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                {short.video_status === 'done' && <Badge>Có video</Badge>}
                                <Badge variant={short.status === 'failed' ? 'destructive' : 'secondary'}>{statusLabels[short.status]}</Badge>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
