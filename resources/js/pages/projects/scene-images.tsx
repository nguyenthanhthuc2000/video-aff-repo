import { OutfitFields } from '@/components/outfit-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type Background, type Outfit, type OutfitProduct, type SceneImage, type VideoProject } from '@/types/models';
import { router } from '@inertiajs/react';
import { Check, ImageOff, LoaderCircle, RefreshCw } from 'lucide-react';

const imageStatusLabels: Record<SceneImage['status'], string> = {
    pending: 'Đang tạo',
    ready: 'Chờ duyệt',
    approved: 'Đã duyệt',
    rejected: 'Đã bỏ',
    failed: 'Lỗi',
};

export default function SceneImages({
    project,
    backgrounds = [],
    outfitProducts = [],
}: {
    project: VideoProject;
    backgrounds?: Background[];
    outfitProducts?: OutfitProduct[];
}) {
    const scenes = project.scenes ?? [];
    const canGenerate = ['script_ready', 'imaging', 'reviewing', 'done', 'failed'].includes(project.status) && scenes.length > 0;
    // Một cảnh quay liền: cảnh mở đầu mỗi bộ đồ tạo ảnh, cảnh sau trong cùng bộ lấy khung cuối clip cảnh trước.
    const linked = (index: number) => project.one_take === true && index > 0 && scenes[index].starts_take !== true;
    const missing = scenes.filter((scene, i) => !linked(i) && (!scene.image || ['rejected', 'failed'].includes(scene.image.status))).length;

    const generateAll = () => router.post(route('projects.images.store', project.id), {}, { preserveScroll: true });

    // Không hỏi prompt: máy chủ tự viết prompt mới (bản trang nhã nếu Muse đã từ chối) rồi tạo lại.
    const regenerate = (sceneId: number) => router.post(route('scenes.image.regenerate', sceneId), {}, { preserveScroll: true });

    // Đổi bộ đồ của cảnh (sản phẩm, ảnh đúng màu, màu); bấm Tạo lại để áp dụng.
    const changeOutfit = (sceneId: number, outfit: Outfit) => router.put(route('scenes.outfit', sceneId), { ...outfit }, { preserveScroll: true });

    // Đổi bối cảnh của cảnh (rỗng = AI tự nghĩ); bấm Tạo lại để áp dụng.
    const changeBackground = (sceneId: number, value: string) =>
        router.put(route('scenes.background', sceneId), { background_id: value === '' ? null : Number(value) }, { preserveScroll: true });

    // Đổi góc quay sản phẩm của cảnh (rỗng = theo kịch bản); bấm Tạo lại để áp dụng.
    const changeAngle = (sceneId: number, value: string) =>
        router.put(route('scenes.angle', sceneId), { angle: value === '' ? null : value }, { preserveScroll: true });
    const angleOptions = project.angle_options ?? [];

    const approve = (imageId: number) => router.post(route('scene-images.approve', imageId), {}, { preserveScroll: true });

    return (
        <div className="space-y-4">
            {canGenerate && missing > 0 && (
                <Button onClick={generateAll} disabled={project.status === 'imaging'}>
                    Tạo ảnh cho {missing} cảnh
                </Button>
            )}
            <p className="text-muted-foreground text-sm">
                {project.one_take
                    ? 'Một cảnh quay liền: cảnh mở đầu mỗi bộ đồ tạo ảnh (bộ sau dùng bối cảnh cảnh 1). Các cảnh sau trong cùng bộ tự lấy khung cuối của clip cảnh trước để nối liền.'
                    : 'AI mặc sản phẩm lên ảnh người mẫu cho từng cảnh. Chỉ duyệt ảnh giữ đúng màu, hoạ tiết và kiểu dáng sản phẩm.'}
            </p>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {scenes.map((scene, index) => {
                    const fromClip = linked(index);
                    // Khung lấy từ clip đã bỏ (clip cảnh trước làm lại): không hiện ảnh cũ, chờ khung mới từ clip.
                    const image = fromClip && scene.image && ['rejected', 'failed'].includes(scene.image.status) ? null : scene.image;

                    return (
                        <div key={scene.id} className="space-y-2">
                            <div className="bg-muted relative flex aspect-[9/16] items-center justify-center overflow-hidden rounded-lg border">
                                {image?.status === 'pending' && <LoaderCircle className="text-muted-foreground size-6 animate-spin" />}
                                {image?.url && image.status !== 'pending' && (
                                    <img src={image.url} alt={`Cảnh ${scene.position}`} className="h-full w-full object-cover" />
                                )}
                                {(!image || (!image.url && image.status !== 'pending')) &&
                                    (fromClip ? (
                                        <span className="text-muted-foreground px-3 text-center text-xs">
                                            Chờ clip cảnh {scene.position - 1} xong
                                        </span>
                                    ) : (
                                        <ImageOff className="text-muted-foreground size-6" />
                                    ))}
                                {fromClip && image?.url && (
                                    <Badge variant="secondary" className="absolute right-2 bottom-2">
                                        Khung cuối cảnh {scene.position - 1}
                                    </Badge>
                                )}
                                {image && (
                                    <Badge
                                        className="absolute top-2 left-2"
                                        variant={image.status === 'failed' ? 'destructive' : image.status === 'approved' ? 'default' : 'secondary'}
                                    >
                                        {imageStatusLabels[image.status]}
                                    </Badge>
                                )}
                            </div>
                            <div className="text-sm font-medium">
                                {scene.phase && (
                                    <Badge variant={scene.phase === 'before' ? 'outline' : 'secondary'} className="mr-1">
                                        {scene.phase === 'before' ? 'Trước' : 'Sau'}
                                    </Badge>
                                )}
                                Cảnh {scene.position}: {scene.on_screen_text}
                            </div>
                            {angleOptions.length > 0 && scene.phase !== 'before' && (
                                <select
                                    aria-label={`Góc quay cảnh ${scene.position}`}
                                    className="border-input bg-background h-7 w-full rounded-md border px-2 text-xs"
                                    value={scene.angle ?? ''}
                                    disabled={project.is_busy}
                                    onChange={(e) => changeAngle(scene.id, e.target.value)}
                                >
                                    <option value="">Góc quay: theo kịch bản</option>
                                    {angleOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            Góc quay: {option.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {(backgrounds.length > 0 || scene.background) && !fromClip && (
                                <div className="flex items-center gap-2">
                                    {scene.background?.photo_url && (
                                        <img
                                            src={scene.background.photo_url}
                                            alt=""
                                            className="size-7 shrink-0 rounded object-cover"
                                            title={scene.background.name}
                                        />
                                    )}
                                    <select
                                        aria-label={`Bối cảnh cảnh ${scene.position}`}
                                        className="border-input bg-background h-7 min-w-0 flex-1 rounded-md border px-2 text-xs"
                                        value={scene.background?.id ?? ''}
                                        disabled={project.is_busy}
                                        onChange={(e) => changeBackground(scene.id, e.target.value)}
                                    >
                                        <option value="">Bối cảnh: AI tự nghĩ</option>
                                        {backgrounds.map((background) => (
                                            <option key={background.id} value={background.id}>
                                                Bối cảnh: {background.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            {outfitProducts.length > 0 && scene.phase !== 'before' && !project.is_busy && !project.one_take && (
                                <details open={!!scene.outfit} className="text-xs">
                                    <summary className="text-muted-foreground cursor-pointer">
                                        Bộ đồ:{' '}
                                        {scene.outfit
                                            ? `${scene.outfit.product_name}${scene.outfit.color ? ` – ${scene.outfit.color}` : ''}`
                                            : 'sản phẩm của dự án'}
                                    </summary>
                                    <div className="pt-1.5">
                                        <OutfitFields
                                            products={outfitProducts}
                                            value={{
                                                product_id: scene.outfit?.product_id ?? project.product?.id ?? outfitProducts[0].id,
                                                product_image_id: scene.outfit?.product_image_id ?? null,
                                                color: scene.outfit?.color ?? null,
                                            }}
                                            onChange={(outfit) => changeOutfit(scene.id, outfit)}
                                        />
                                    </div>
                                </details>
                            )}
                            {image?.error && <p className="text-xs text-red-600 dark:text-red-400">{image.error}</p>}
                            {image && image.status !== 'pending' && canGenerate && !fromClip && (
                                <div className="flex gap-2">
                                    {image.status === 'ready' && (
                                        <Button size="sm" onClick={() => approve(image.id)}>
                                            <Check /> Duyệt
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => regenerate(scene.id)}
                                        title="Tự viết prompt mới cho cảnh rồi tạo lại ảnh"
                                    >
                                        <RefreshCw /> Tạo lại
                                    </Button>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
