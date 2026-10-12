import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Loader2, Plus, Tv } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Phim hoạt hình', href: '/series' }];

export interface SeriesListItem {
    id: number;
    title: string;
    logline: string | null;
    genre: string;
    age_min: number;
    age_max: number;
    status: string;
    status_label: string;
    is_busy: boolean;
    episodes_count: number;
    created_at: string;
}

export default function SeriesIndex({ series }: { series: SeriesListItem[] }) {
    usePoll(
        series.some((item) => item.is_busy),
        ['series'],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Phim hoạt hình" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Phim hoạt hình"
                        description="Phim 2D nhiều tập tiếng Anh cho kênh YouTube trẻ em: nhập ý tưởng, Claude lên kế hoạch nhân vật, bối cảnh và các tập."
                    />
                    <Button asChild>
                        <Link href={route('series.create')}>
                            <Plus /> Tạo phim mới
                        </Link>
                    </Button>
                </div>
                <FlashStatus />

                {series.length === 0 ? (
                    <div className="text-muted-foreground flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center text-sm">
                        <Tv className="size-8" />
                        Chưa có bộ phim nào. Bấm "Tạo phim mới" và nhập ý tưởng để bắt đầu.
                    </div>
                ) : (
                    <div className="divide-y rounded-xl border">
                        {series.map((item) => (
                            <Link key={item.id} href={route('series.show', item.id)} className="hover:bg-muted/50 flex items-center gap-4 p-4">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">{item.title}</p>
                                    {item.logline && <p className="text-muted-foreground line-clamp-1 text-sm">{item.logline}</p>}
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {item.genre} · {item.age_min}–{item.age_max} tuổi · {item.episodes_count} tập
                                    </p>
                                </div>
                                <Badge variant={item.status === 'failed' ? 'destructive' : 'secondary'}>
                                    {item.is_busy && <Loader2 className="animate-spin" />}
                                    {item.status_label}
                                </Badge>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
