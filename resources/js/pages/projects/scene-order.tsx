import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type Scene, type VideoProject } from '@/types/models';
import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, GripVertical, RotateCcw } from 'lucide-react';
import { useState } from 'react';

/** Đổi thứ tự cảnh trước khi xuất video: kéo-thả hoặc bấm lên / xuống, rồi lưu. */
export default function SceneOrder({ project }: { project: VideoProject }) {
    const scenes = project.scenes ?? [];
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [order, setOrder] = useState<number[]>(() => scenes.map((scene) => scene.id));
    const [dragging, setDragging] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);

    const byId = new Map(scenes.map((scene) => [scene.id, scene]));
    const ordered = order.map((id) => byId.get(id)).filter((scene): scene is Scene => scene !== undefined);
    const dirty = order.join() !== scenes.map((scene) => scene.id).join();
    const disabled = project.is_busy || saving;

    const move = (from: number, to: number) => {
        if (to < 0 || to >= order.length || from === to) {
            return;
        }
        const next = [...order];
        const [id] = next.splice(from, 1);
        next.splice(to, 0, id);
        setOrder(next);
    };

    const save = () =>
        router.put(
            route('projects.scenes.order', project.id),
            { scene_ids: order },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );

    if (scenes.length < 2) {
        return null;
    }

    return (
        <div className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 className="font-medium">Thứ tự cảnh</h3>
                    <p className="text-muted-foreground text-xs">Kéo thả hoặc bấm mũi tên để đổi thứ tự; video xuất ra theo thứ tự này.</p>
                </div>
                {dirty && (
                    <div className="flex gap-2">
                        <Button size="sm" variant="ghost" onClick={() => setOrder(scenes.map((scene) => scene.id))} disabled={disabled}>
                            <RotateCcw /> Hoàn tác
                        </Button>
                        <Button size="sm" onClick={save} disabled={disabled}>
                            {saving ? 'Đang lưu...' : 'Lưu thứ tự'}
                        </Button>
                    </div>
                )}
            </div>

            <ol className="space-y-1.5">
                {ordered.map((scene, index) => {
                    const thumbnail = scene.image?.url;
                    return (
                        <li
                            key={scene.id}
                            draggable={!disabled}
                            onDragStart={() => setDragging(index)}
                            onDragOver={(e) => {
                                e.preventDefault();
                                if (dragging !== null && dragging !== index) {
                                    move(dragging, index);
                                    setDragging(index);
                                }
                            }}
                            onDragEnd={() => setDragging(null)}
                            className={cn(
                                'bg-background flex items-center gap-3 rounded-md border p-2 text-sm',
                                dragging === index && 'border-primary opacity-60',
                                !disabled && 'cursor-grab active:cursor-grabbing',
                            )}
                        >
                            <GripVertical className="text-muted-foreground size-4 shrink-0" />
                            <span className="text-muted-foreground w-5 shrink-0 text-right tabular-nums">{index + 1}</span>
                            <div className="bg-muted aspect-[9/16] w-8 shrink-0 overflow-hidden rounded">
                                {thumbnail && <img src={thumbnail} alt="" className="h-full w-full object-cover" />}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-1.5">
                                    {scene.phase && (
                                        <Badge variant={scene.phase === 'before' ? 'outline' : 'secondary'}>
                                            {scene.phase === 'before' ? 'Trước' : 'Sau'}
                                        </Badge>
                                    )}
                                    {scene.clip_url && <Badge variant="secondary">Clip</Badge>}
                                    <span className="truncate">{scene.on_screen_text || scene.visual}</span>
                                </div>
                                <span className="text-muted-foreground text-xs">
                                    {(scene.duration_ms / 1000).toLocaleString('vi-VN')} giây
                                    {scene.position !== index + 1 && ` · trước đây là cảnh ${scene.position}`}
                                </span>
                            </div>
                            <div className="flex shrink-0">
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7"
                                    onClick={() => move(index, index - 1)}
                                    disabled={disabled || index === 0}
                                    aria-label="Lên"
                                >
                                    <ArrowUp />
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7"
                                    onClick={() => move(index, index + 1)}
                                    disabled={disabled || index === ordered.length - 1}
                                    aria-label="Xuống"
                                >
                                    <ArrowDown />
                                </Button>
                            </div>
                        </li>
                    );
                })}
            </ol>
            <InputError message={errors.scene_ids} />
        </div>
    );
}
