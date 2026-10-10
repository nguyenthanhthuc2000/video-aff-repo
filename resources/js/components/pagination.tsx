import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

/** Phân trang theo dữ liệu paginate() của Laravel. */
export default function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) {
        return page.total > 0 ? <p className="text-muted-foreground text-xs">{page.total} mục</p> : null;
    }

    const label = (text: string) =>
        text.replace('&laquo; Previous', '‹').replace('Next &raquo;', '›').replace('pagination.previous', '‹').replace('pagination.next', '›');

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-muted-foreground text-xs">
                {page.from}–{page.to} / {page.total} mục
            </p>
            <div className="flex flex-wrap gap-1">
                {page.links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            href={link.url}
                            preserveScroll
                            className={cn(
                                'min-w-8 rounded-md border px-2.5 py-1 text-center text-sm',
                                link.active ? 'bg-foreground text-background' : 'hover:bg-muted',
                            )}
                        >
                            {label(link.label)}
                        </Link>
                    ) : (
                        <span key={i} className="text-muted-foreground min-w-8 rounded-md border px-2.5 py-1 text-center text-sm opacity-50">
                            {label(link.label)}
                        </span>
                    ),
                )}
            </div>
        </div>
    );
}
