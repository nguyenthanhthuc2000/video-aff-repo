import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ImageOff, Loader2, RefreshCw, Upload, Wand2 } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Nhân vật Ngọc Rồng', href: '/dragon-ball' }];

interface Portrait {
    status: 'pending' | 'ready' | 'failed';
    source: 'muse' | 'upload' | null;
    image_url: string | null;
    error: string | null;
}

interface Character {
    key: string;
    name: string;
    look: string;
    suggested: string[];
    portraits: Record<string, Portrait | null>;
}

const options = { preserveScroll: true };

/** Thư viện nhân vật 7 Viên Ngọc Rồng: Muse vẽ mỗi nhân vật với các biểu cảm, kiểu manga trắng đen. Shorts chủ đề dùng làm ảnh mẫu / ảnh cảnh. */
export default function CharacterLibrary({
    characters,
    expressions,
    price,
    failed,
    busy,
}: {
    characters: Character[];
    expressions: Record<string, string>;
    price: number;
    failed: number;
    busy: boolean;
}) {
    usePoll(busy, ['characters', 'busy', 'failed'], 4000);
    const retryFailed = () => {
        if (
            confirm(
                `Vẽ lại ${failed} ảnh lỗi với prompt đã viết lại (bỏ tên nhân vật / phim), khoảng ${formatNumber(failed * price)} Credits. Tiếp tục?`,
            )
        ) {
            router.post(route('character-library.draw'), { failed_only: true }, options);
        }
    };
    const missing = (c: Character) => c.suggested.filter((e) => !c.portraits[e] || c.portraits[e]?.status === 'failed').length;
    const totalMissing = characters.reduce((sum, c) => sum + missing(c), 0);

    const drawAll = () => {
        if (
            confirm(
                `Muse vẽ ${totalMissing} ảnh còn thiếu (biểu cảm gợi ý của mọi nhân vật), khoảng ${formatNumber(totalMissing * price)} Credits. Tiếp tục?`,
            )
        ) {
            router.post(route('character-library.draw'), {}, options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nhân vật Ngọc Rồng" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Nhân vật 7 Viên Ngọc Rồng"
                        description="Mỗi nhân vật vẽ kiểu manga trắng đen với nhiều biểu cảm. Muse vẽ mặt thường trước, các biểu cảm khác bám theo cho giống. Shorts chủ đề tự dùng các ảnh này làm ảnh cảnh và ảnh mẫu."
                    />
                    <div className="flex flex-wrap gap-2">
                        {failed > 0 && (
                            <Button variant="outline" onClick={retryFailed}>
                                <RefreshCw /> Tạo lại {failed} ảnh lỗi
                            </Button>
                        )}
                        <Button onClick={drawAll} disabled={totalMissing === 0}>
                            <Wand2 /> Vẽ {totalMissing} ảnh còn thiếu ({formatNumber(totalMissing * price)} Credits)
                        </Button>
                    </div>
                </div>
                <FlashStatus />

                <div className="space-y-4">
                    {characters.map((character) => (
                        <CharacterRow
                            key={character.key}
                            character={character}
                            expressions={expressions}
                            price={price}
                            missing={missing(character)}
                        />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function CharacterRow({
    character,
    expressions,
    price,
    missing,
}: {
    character: Character;
    expressions: Record<string, string>;
    price: number;
    missing: number;
}) {
    const draw = (expression?: string) =>
        router.post(route('character-library.draw'), { character: character.key, expression: expression ?? null }, options);
    const upload = (expression: string, file: File | undefined) =>
        file && router.post(route('character-library.upload', [character.key, expression]), { image: file }, { ...options, forceFormData: true });

    return (
        <section className="rounded-lg border p-4">
            <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="font-semibold">{character.name}</h2>
                    <p className="text-muted-foreground text-xs">{character.look}</p>
                </div>
                {missing > 0 && (
                    <Button size="sm" variant="outline" onClick={() => draw()}>
                        <Wand2 /> Vẽ {missing} biểu cảm gợi ý ({formatNumber(missing * price)} Credits)
                    </Button>
                )}
            </div>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-9">
                {Object.entries(expressions).map(([code, label]) => {
                    const portrait = character.portraits[code];
                    const suggested = character.suggested.includes(code);

                    return (
                        <div key={code} className={cn('space-y-1', !suggested && !portrait && 'opacity-60')}>
                            <div className="bg-muted relative flex aspect-[9/16] items-center justify-center overflow-hidden rounded-md border">
                                {portrait?.status === 'pending' && <Loader2 className="text-muted-foreground size-5 animate-spin" />}
                                {portrait?.status !== 'pending' && portrait?.image_url && (
                                    <img
                                        src={portrait.image_url}
                                        alt={`${character.name} ${label}`}
                                        className="h-full w-full object-cover"
                                        loading="lazy"
                                    />
                                )}
                                {portrait?.status !== 'pending' && !portrait?.image_url && <ImageOff className="text-muted-foreground size-4" />}
                                {portrait?.source === 'upload' && (
                                    <Badge variant="secondary" className="absolute top-1 left-1 px-1 text-[10px]">
                                        Tải lên
                                    </Badge>
                                )}
                            </div>
                            <p className="truncate text-xs font-medium" title={label}>
                                {code} · {label}
                            </p>
                            {portrait?.status === 'failed' && portrait.error && (
                                <p className="line-clamp-2 text-[11px] text-red-600 dark:text-red-400" title={portrait.error}>
                                    {portrait.error}
                                </p>
                            )}
                            <div className="flex gap-1">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-7 px-2 text-xs"
                                    disabled={portrait?.status === 'pending'}
                                    onClick={() => draw(code)}
                                    title={portrait?.image_url ? 'Muse vẽ lại' : 'Muse vẽ'}
                                >
                                    <Wand2 className="size-3.5" />
                                </Button>
                                <label className="hover:bg-accent inline-flex h-7 cursor-pointer items-center rounded-md px-2" title="Tải ảnh lên">
                                    <Upload className="size-3.5" />
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        className="hidden"
                                        aria-label={`Ảnh ${character.name} ${label}`}
                                        onChange={(e) => upload(code, e.target.files?.[0])}
                                    />
                                </label>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
