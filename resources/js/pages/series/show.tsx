import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronRight, ImageIcon, Loader2, Lock, Plus, RefreshCw, Save, Trash2, UserRound } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import CharacterFields, { type CharacterFormData, emptyCharacter } from './character-form';

interface Character {
    id: number;
    name: string;
    role: string;
    role_label: string;
    age: number | null;
    personality: string | null;
    appearance: string;
    voice_type: string | null;
    image_url: string | null;
    locked: boolean;
}

interface Location {
    id: number;
    name: string;
    description: string;
    image_url: string | null;
    locked: boolean;
}

interface Episode {
    id: number;
    number: number;
    title: string;
    synopsis: string;
    lesson: string | null;
    status: string;
    status_label: string;
}

interface Bible {
    title?: string;
    logline?: string;
    premise?: string;
    world?: string;
    tone?: string;
    themes?: string[];
}

interface SeriesDetail {
    id: number;
    title: string;
    idea: string;
    genre_label: string;
    age_min: number;
    age_max: number;
    made_for_kids: boolean;
    style: string;
    episode_count: number;
    episode_seconds: number;
    subtitle_languages: string[];
    bible: Bible | null;
    status: string;
    status_label: string;
    is_busy: boolean;
    error: string | null;
    can_replan: boolean;
    characters: Character[];
    locations: Location[];
    episodes: Episode[];
}

export default function SeriesShow({
    series,
    roles,
    voiceTypes,
}: {
    series: SeriesDetail;
    roles: Record<string, string>;
    voiceTypes: Record<string, string>;
}) {
    usePoll(series.is_busy, ['series']);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Phim hoạt hình', href: '/series' },
        { title: series.title, href: route('series.show', series.id) },
    ];

    const replan = () => {
        if (confirm('Claude lên kế hoạch lại từ đầu: nhân vật, bối cảnh và dàn ý các tập hiện tại sẽ bị thay. Tiếp tục?')) {
            router.post(route('series.plan', series.id), {}, { preserveScroll: true });
        }
    };
    const destroy = () => {
        if (confirm(`Xoá bộ phim "${series.title}"?`)) {
            router.delete(route('series.destroy', series.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={series.title} />
            <div className="max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <Heading title={series.title} />
                            <Badge variant={series.status === 'failed' ? 'destructive' : 'secondary'}>
                                {series.is_busy && <Loader2 className="animate-spin" />}
                                {series.status_label}
                            </Badge>
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {series.genre_label} · {series.age_min}–{series.age_max} tuổi{series.made_for_kids && ' · Dành cho trẻ em'} ·{' '}
                            {series.episode_count} tập × {Math.round(series.episode_seconds / 60)} phút
                            {series.subtitle_languages.length > 0 && ` · Phụ đề: ${series.subtitle_languages.join(', ')}`}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={replan} disabled={!series.can_replan}>
                            <RefreshCw /> Lên kế hoạch lại
                        </Button>
                        <Button variant="outline" className="text-destructive" onClick={destroy} disabled={series.is_busy}>
                            <Trash2 /> Xoá
                        </Button>
                    </div>
                </div>
                <FlashStatus />

                {series.error && (
                    <p className="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border px-4 py-3 text-sm">{series.error}</p>
                )}

                <details className="rounded-lg border px-4 py-3 text-sm">
                    <summary className="cursor-pointer font-medium">Ý tưởng gốc và phong cách</summary>
                    <p className="mt-2 whitespace-pre-line">{series.idea}</p>
                    <p className="text-muted-foreground mt-2 text-xs">Phong cách: {series.style}</p>
                </details>

                {series.status === 'planning' ? (
                    <div className="text-muted-foreground flex items-center gap-3 rounded-xl border border-dashed p-10 text-sm">
                        <Loader2 className="size-5 animate-spin" />
                        Claude đang viết kế hoạch bộ phim: nhân vật, bối cảnh, dàn ý {series.episode_count} tập. Trang tự cập nhật khi xong.
                    </div>
                ) : (
                    series.bible && (
                        <PlanEditor
                            key={`${series.id}-${series.episodes.map((e) => e.id).join(',')}-${series.characters.map((c) => c.id).join(',')}`}
                            series={series}
                            roles={roles}
                            voiceTypes={voiceTypes}
                        />
                    )
                )}
            </div>
        </AppLayout>
    );
}

type PlanForm = {
    title: string;
    bible: { logline: string; premise: string; world: string; tone: string };
    locations: { id: number; name: string; description: string }[];
    episodes: { id: number; title: string; synopsis: string; lesson: string }[];
};

function PlanEditor({ series, roles, voiceTypes }: { series: SeriesDetail; roles: Record<string, string>; voiceTypes: Record<string, string> }) {
    const { data, setData, put, processing, errors, isDirty } = useForm<PlanForm>({
        title: series.title,
        bible: {
            logline: series.bible?.logline ?? '',
            premise: series.bible?.premise ?? '',
            world: series.bible?.world ?? '',
            tone: series.bible?.tone ?? '',
        },
        locations: series.locations.map((l) => ({ id: l.id, name: l.name, description: l.description })),
        episodes: series.episodes.map((e) => ({ id: e.id, title: e.title, synopsis: e.synopsis, lesson: e.lesson ?? '' })),
    });
    const fieldError = (key: string) => (errors as Record<string, string>)[key];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('series.update', series.id), { preserveScroll: true });
    };

    const setItem = <K extends 'locations' | 'episodes'>(list: K, index: number, patch: Partial<PlanForm[K][number]>) =>
        setData(list, data[list].map((item, i) => (i === index ? { ...item, ...patch } : item)) as PlanForm[K]);

    return (
        <form onSubmit={submit} className="space-y-8">
            <section className="space-y-4">
                <h2 className="text-lg font-semibold">Kinh thánh bộ phim</h2>
                <div className="space-y-2">
                    <Label htmlFor="title">Tên phim</Label>
                    <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                    <InputError message={errors.title} />
                </div>
                <BibleField
                    label="Logline (một câu)"
                    value={data.bible.logline}
                    rows={2}
                    onChange={(logline) => setData('bible', { ...data.bible, logline })}
                />
                <BibleField label="Tiền đề" value={data.bible.premise} onChange={(premise) => setData('bible', { ...data.bible, premise })} />
                <div className="grid gap-4 sm:grid-cols-2">
                    <BibleField label="Thế giới" value={data.bible.world} onChange={(world) => setData('bible', { ...data.bible, world })} />
                    <BibleField label="Giọng kể" value={data.bible.tone} onChange={(tone) => setData('bible', { ...data.bible, tone })} />
                </div>
                {series.bible?.themes && series.bible.themes.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {series.bible.themes.map((theme) => (
                            <Badge key={theme} variant="outline">
                                {theme}
                            </Badge>
                        ))}
                    </div>
                )}
            </section>

            <CharactersSection series={series} roles={roles} voiceTypes={voiceTypes} />

            {data.locations.length > 0 && (
                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">Bối cảnh</h2>
                    <div className="grid gap-4 md:grid-cols-2">
                        {data.locations.map((location, index) => (
                            <div key={location.id} className="space-y-2 rounded-xl border p-4">
                                <Input
                                    value={location.name}
                                    onChange={(e) => setItem('locations', index, { name: e.target.value })}
                                    className="font-medium"
                                />
                                <Textarea
                                    rows={3}
                                    value={location.description}
                                    disabled={series.locations[index]?.locked}
                                    onChange={(e) => setItem('locations', index, { description: e.target.value })}
                                />
                                <InputError message={fieldError(`locations.${index}.description`)} />
                            </div>
                        ))}
                    </div>
                </section>
            )}

            <section className="space-y-4">
                <h2 className="text-lg font-semibold">Các tập</h2>
                <div className="space-y-3">
                    {data.episodes.map((episode, index) => (
                        <div key={episode.id} className="space-y-2 rounded-xl border p-4">
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground w-14 shrink-0 text-sm">Tập {series.episodes[index]?.number}</span>
                                <Input
                                    value={episode.title}
                                    onChange={(e) => setItem('episodes', index, { title: e.target.value })}
                                    className="font-medium"
                                />
                                <Badge variant="secondary" className="shrink-0">
                                    {series.episodes[index]?.status_label}
                                </Badge>
                                <Button type="button" size="sm" variant="outline" className="shrink-0" asChild>
                                    <Link href={route('series.episodes.show', { series: series.id, episode: episode.id })}>
                                        Mở tập <ChevronRight />
                                    </Link>
                                </Button>
                            </div>
                            <Textarea rows={3} value={episode.synopsis} onChange={(e) => setItem('episodes', index, { synopsis: e.target.value })} />
                            <Input
                                placeholder="Bài học / kiến thức của tập"
                                value={episode.lesson}
                                onChange={(e) => setItem('episodes', index, { lesson: e.target.value })}
                            />
                            <InputError message={fieldError(`episodes.${index}.synopsis`) || fieldError(`episodes.${index}.title`)} />
                        </div>
                    ))}
                </div>
            </section>

            <div className="bg-background/95 sticky bottom-0 flex items-center gap-3 border-t py-3">
                <Button type="submit" disabled={processing || !isDirty}>
                    {processing ? <Loader2 className="animate-spin" /> : <Save />} Lưu kế hoạch
                </Button>
                <span className="text-muted-foreground text-xs">
                    Lưu tên phim, kinh thánh, bối cảnh và dàn ý tập. Nhân vật sửa ở trang riêng của từng nhân vật.
                </span>
            </div>
        </form>
    );
}

function BibleField({ label, value, onChange, rows = 3 }: { label: string; value: string; onChange: (value: string) => void; rows?: number }) {
    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <Textarea rows={rows} value={value} onChange={(e) => onChange(e.target.value)} />
        </div>
    );
}

function CharactersSection({
    series,
    roles,
    voiceTypes,
}: {
    series: SeriesDetail;
    roles: Record<string, string>;
    voiceTypes: Record<string, string>;
}) {
    const [adding, setAdding] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<CharacterFormData>(emptyCharacter);

    const submit = (e: FormEvent) => {
        // Hộp thoại nằm trong form kế hoạch (React vẫn chuyển sự kiện submit lên form ngoài).
        e.preventDefault();
        e.stopPropagation();
        post(route('series.characters.store', series.id), { onSuccess: () => reset() });
    };

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 className="text-lg font-semibold">Nhân vật ({series.characters.length})</h2>
                    <p className="text-muted-foreground text-sm">Mở từng nhân vật để Muse vẽ hoặc tải ảnh lên, chọn ảnh chính rồi chốt.</p>
                </div>
                <Button type="button" variant="outline" onClick={() => setAdding(true)}>
                    <Plus /> Thêm nhân vật
                </Button>
            </div>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                {series.characters.map((character) => (
                    <Link
                        key={character.id}
                        href={route('series.characters.show', { series: series.id, character: character.id })}
                        className="group overflow-hidden rounded-xl border hover:shadow-sm"
                    >
                        <div className="bg-muted/40 aspect-video">
                            {character.image_url ? (
                                <img src={character.image_url} alt={character.name} className="size-full object-contain" />
                            ) : (
                                <span className="text-muted-foreground flex size-full flex-col items-center justify-center gap-1 text-xs">
                                    <UserRound className="size-6" /> Chưa có ảnh
                                </span>
                            )}
                        </div>
                        <div className="space-y-1 p-3">
                            <div className="flex items-center gap-1.5">
                                <p className="truncate font-medium">{character.name}</p>
                                {character.locked && <Lock className="text-primary size-3.5 shrink-0" />}
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {character.role_label}
                                {character.age !== null && ` · ${character.age} tuổi`}
                            </p>
                            <p className="text-muted-foreground line-clamp-2 text-xs">{character.appearance}</p>
                            <span className="text-primary inline-flex items-center gap-1 text-xs group-hover:underline">
                                <ImageIcon className="size-3" /> Quản lý ảnh
                            </span>
                        </div>
                    </Link>
                ))}
            </div>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Thêm nhân vật</DialogTitle>
                        <DialogDescription>Sau khi thêm, mở trang nhân vật để vẽ ảnh chính.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <CharacterFields data={data} setData={setData} errors={errors} roles={roles} voiceTypes={voiceTypes} />
                        <Button type="submit" disabled={processing}>
                            {processing ? <Loader2 className="animate-spin" /> : <Plus />} Thêm
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
