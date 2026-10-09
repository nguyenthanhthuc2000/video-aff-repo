import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type Scene, type VideoProject } from '@/types/models';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type SceneData = Pick<Scene, 'id' | 'visual' | 'on_screen_text' | 'voiceover' | 'image_prompt' | 'duration_ms'>;

type ScriptFormData = {
    hooks: string[];
    selected_hook: number;
    caption: string;
    hashtags: string;
    scenes: SceneData[];
};

export default function ScriptEditor({ project, disabled }: { project: VideoProject; disabled: boolean }) {
    const { data, setData, put, processing, errors, isDirty, transform } = useForm<ScriptFormData>({
        hooks: project.script?.hooks ?? [],
        selected_hook: project.selected_hook ?? 0,
        caption: project.script?.caption ?? '',
        hashtags: (project.script?.hashtags ?? []).join(' '),
        scenes: (project.scenes ?? []).map(({ id, visual, on_screen_text, voiceover, image_prompt, duration_ms }) => ({
            id,
            visual,
            on_screen_text: on_screen_text ?? '',
            voiceover: voiceover ?? '',
            image_prompt,
            duration_ms,
        })),
    });

    const fieldErrors = errors as Record<string, string | undefined>;
    const totalSeconds = data.scenes.reduce((sum, scene) => sum + Number(scene.duration_ms || 0), 0) / 1000;

    const setHook = (index: number, value: string) =>
        setData(
            'hooks',
            data.hooks.map((hook, i) => (i === index ? value : hook)),
        );

    const setScene = <K extends keyof SceneData>(index: number, key: K, value: SceneData[K]) =>
        setData(
            'scenes',
            data.scenes.map((scene, i) => (i === index ? { ...scene, [key]: value } : scene)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => ({ ...form, hashtags: form.hashtags.split(/\s+/).filter(Boolean) }));
        put(route('projects.script.update', project.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <fieldset disabled={disabled} className="space-y-6">
                <div className="space-y-2">
                    <Label>Hook (chọn 1 câu mở đầu; Claude đã chọn sẵn hook đề xuất)</Label>
                    {data.hooks.map((hook, i) => (
                        <div key={i} className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="selected_hook"
                                aria-label={`Chọn hook ${i + 1}`}
                                checked={data.selected_hook === i}
                                onChange={() => setData('selected_hook', i)}
                            />
                            {project.script?.hook_themes?.[i] && (
                                <Badge variant="outline" className="shrink-0">
                                    {project.script.hook_themes[i]}
                                </Badge>
                            )}
                            <Input value={hook} onChange={(e) => setHook(i, e.target.value)} />
                            {project.script?.recommended_hook === i && <Badge className="shrink-0">Đề xuất</Badge>}
                        </div>
                    ))}
                    <InputError message={fieldErrors.selected_hook ?? Object.entries(fieldErrors).find(([k]) => k.startsWith('hooks'))?.[1]} />
                </div>

                <div className="space-y-3">
                    <div className="flex items-baseline justify-between">
                        <Label>Cảnh</Label>
                        <span className="text-muted-foreground text-sm">Tổng {totalSeconds.toLocaleString('vi-VN')} giây</span>
                    </div>
                    {data.scenes.map((scene, i) => (
                        <div key={scene.id} className="space-y-3 rounded-lg border p-4">
                            <div className="flex items-center justify-between gap-4">
                                <span className="font-medium">Cảnh {i + 1}</span>
                                <div className="flex items-center gap-2">
                                    <Label htmlFor={`duration-${scene.id}`} className="text-sm font-normal">
                                        Giây
                                    </Label>
                                    <Input
                                        id={`duration-${scene.id}`}
                                        type="number"
                                        min={1}
                                        max={10}
                                        step={0.5}
                                        className="w-20"
                                        value={scene.duration_ms / 1000}
                                        onChange={(e) => setScene(i, 'duration_ms', Math.round(Number(e.target.value) * 1000))}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label className="text-sm font-normal">Hình ảnh</Label>
                                    <Textarea rows={2} value={scene.visual} onChange={(e) => setScene(i, 'visual', e.target.value)} />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-sm font-normal">Chữ trên màn</Label>
                                    <Input value={scene.on_screen_text ?? ''} onChange={(e) => setScene(i, 'on_screen_text', e.target.value)} />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-sm font-normal">Lời đọc / phụ đề</Label>
                                    <Textarea rows={2} value={scene.voiceover ?? ''} onChange={(e) => setScene(i, 'voiceover', e.target.value)} />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-sm font-normal">Prompt ảnh (tiếng Anh)</Label>
                                    <Textarea rows={2} value={scene.image_prompt} onChange={(e) => setScene(i, 'image_prompt', e.target.value)} />
                                </div>
                            </div>
                            <InputError message={Object.entries(fieldErrors).find(([k]) => k.startsWith(`scenes.${i}.`))?.[1]} />
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="caption">Caption</Label>
                        <Textarea id="caption" rows={3} value={data.caption} onChange={(e) => setData('caption', e.target.value)} />
                        <InputError message={errors.caption} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="hashtags">Hashtag (cách nhau bằng dấu cách)</Label>
                        <Input id="hashtags" value={data.hashtags} onChange={(e) => setData('hashtags', e.target.value)} />
                        <InputError message={errors.hashtags} />
                    </div>
                </div>

                <Button type="submit" disabled={processing || !isDirty}>
                    Lưu kịch bản
                </Button>
            </fieldset>
        </form>
    );
}
