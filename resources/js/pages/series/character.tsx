import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ImageOff, Loader2, Lock, LockOpen, Save, Sparkles, Star, Trash2, Upload, UserRound, Volume2 } from 'lucide-react';
import { type FormEvent, useRef, useState } from 'react';
import CharacterFields, { type CharacterFormData } from './character-form';

interface CharacterImage {
    id: number;
    kind: string;
    kind_label: string;
    source: 'muse' | 'upload';
    status: 'pending' | 'ready' | 'failed';
    url: string | null;
    is_main: boolean;
    prompt: string | null;
    error: string | null;
    created_at: string;
}

interface Character {
    id: number;
    name: string;
    role: string;
    age: number | null;
    personality: string | null;
    appearance: string;
    voice_type: string | null;
    voice: string | null;
    voice_sample_url: string | null;
    main_image_url: string | null;
    locked: boolean;
    images: CharacterImage[];
}

export default function SeriesCharacterPage({
    series,
    character,
    characters,
    kinds,
    roles,
    voiceTypes,
    voices,
    usedVoices,
    imageCredits,
    species,
    sounds,
}: {
    species: string | null;
    sounds: CharacterSound[];
    series: { id: number; title: string; style: string };
    character: Character;
    characters: { id: number; name: string; image_url: string | null }[];
    kinds: Record<string, string>;
    roles: Record<string, string>;
    voiceTypes: Record<string, string>;
    voices: VoiceOption[];
    usedVoices: Record<string, string>;
    imageCredits: number;
}) {
    usePoll(
        character.images.some((image) => image.status === 'pending'),
        ['character'],
        5000,
    );
    const [preview, setPreview] = useState<CharacterImage | null>(null);
    const base = { series: series.id, character: character.id };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Phim hoạt hình', href: '/series' },
        { title: series.title, href: route('series.show', series.id) },
        { title: character.name, href: route('series.characters.show', base) },
    ];

    const toggleLock = () => router.put(route('series.characters.lock', base), { locked: !character.locked }, { preserveScroll: true });
    const destroy = () => {
        if (confirm(`Xoá nhân vật ${character.name} và mọi ảnh của nhân vật?`)) {
            router.delete(route('series.characters.destroy', base));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={character.name} />
            <div className="space-y-6 p-4">
                {/* Chuyển nhanh giữa các nhân vật của bộ phim */}
                <div className="flex gap-2 overflow-x-auto pb-1">
                    {characters.map((item) => (
                        <Link
                            key={item.id}
                            href={route('series.characters.show', { series: series.id, character: item.id })}
                            className={cn(
                                'flex shrink-0 items-center gap-2 rounded-full border py-1 pr-3 pl-1 text-sm',
                                item.id === character.id ? 'border-primary bg-primary/10 font-medium' : 'hover:bg-muted',
                            )}
                        >
                            <Avatar url={item.image_url} className="size-7" />
                            {item.name}
                        </Link>
                    ))}
                </div>

                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Heading title={character.name} description={`Nhân vật của "${series.title}"`} />
                        {character.locked && (
                            <Badge>
                                <Lock /> Đã chốt
                            </Badge>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant={character.locked ? 'outline' : 'default'}
                            onClick={toggleLock}
                            disabled={!character.locked && !character.main_image_url}
                        >
                            {character.locked ? <LockOpen /> : <Lock />} {character.locked ? 'Bỏ chốt' : 'Chốt nhân vật'}
                        </Button>
                        <Button variant="outline" className="text-destructive" onClick={destroy}>
                            <Trash2 /> Xoá
                        </Button>
                    </div>
                </div>
                <FlashStatus />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                    <div className="space-y-4">
                        <div className="bg-muted/40 aspect-video overflow-hidden rounded-xl border">
                            {character.main_image_url ? (
                                <img src={character.main_image_url} alt={character.name} className="size-full object-contain" />
                            ) : (
                                <div className="text-muted-foreground flex size-full flex-col items-center justify-center gap-2 text-sm">
                                    <UserRound className="size-8" />
                                    Chưa có ảnh chính
                                </div>
                            )}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Ảnh chính được gửi kèm mọi khung hình có {character.name} để Muse vẽ giống nhau. Chốt nhân vật khi ảnh chính đã ưng.
                        </p>
                        <VoicePanel character={character} base={base} voices={voices} usedVoices={usedVoices} />
                        {sounds.length > 0 && <SoundsPanel name={character.name} base={base} species={species} sounds={sounds} />}
                        {/* Đổi giọng ở trên thì dựng lại form để loại giọng trong form khớp giọng mới. */}
                        <CharacterEditor key={character.voice ?? ''} character={character} base={base} roles={roles} voiceTypes={voiceTypes} />
                    </div>

                    <div className="space-y-6">
                        <GeneratePanel base={base} kinds={kinds} hasMain={character.main_image_url !== null} imageCredits={imageCredits} />
                        <UploadPanel base={base} />

                        <section className="space-y-3">
                            <h2 className="font-semibold">Thư viện ảnh ({character.images.length})</h2>
                            {character.images.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Chưa có ảnh nào.</p>
                            ) : (
                                <div className="grid grid-cols-2 gap-3 xl:grid-cols-3">
                                    {character.images.map((image) => (
                                        <ImageCard
                                            key={image.id}
                                            image={image}
                                            base={base}
                                            locked={character.locked}
                                            onOpen={() => setPreview(image)}
                                        />
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                </div>
            </div>

            <Dialog open={preview !== null} onOpenChange={(open) => !open && setPreview(null)}>
                <DialogContent className="sm:max-w-4xl">
                    <DialogTitle>{preview?.kind_label}</DialogTitle>
                    {preview?.url && <img src={preview.url} alt="" className="w-full rounded-lg border" />}
                    {preview?.prompt && (
                        <details className="text-xs">
                            <summary className="text-muted-foreground cursor-pointer">Prompt đã gửi Muse</summary>
                            <p className="mt-2 whitespace-pre-line">{preview.prompt}</p>
                        </details>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Avatar({ url, className }: { url: string | null; className?: string }) {
    return url ? (
        <img src={url} alt="" className={cn('rounded-full border object-cover', className)} />
    ) : (
        <span className={cn('bg-muted flex items-center justify-center rounded-full', className)}>
            <UserRound className="text-muted-foreground size-4" />
        </span>
    );
}

interface CharacterSound {
    id: string;
    group: string;
    description: string;
}

const SPECIES_LABELS: Record<string, string> = {
    cat: 'mèo',
    dog: 'chó',
    duck: 'vịt',
    chicken: 'gà',
    bird: 'chim',
    owl: 'cú',
    frog: 'ếch',
    sheep: 'cừu',
    goat: 'dê',
    cow: 'bò',
    pig: 'heo',
    horse: 'ngựa',
    hedgehog: 'nhím',
    mouse: 'chuột / thú nhỏ',
};

/** Tiếng của nhân vật (cười, khóc, thở dài... và tiếng kêu đúng loài), đã đổi cao độ riêng của nhân vật: bấm để nghe thử. */
function SoundsPanel({
    name,
    base,
    species,
    sounds,
}: {
    name: string;
    base: { series: number; character: number };
    species: string | null;
    sounds: CharacterSound[];
}) {
    const [playing, setPlaying] = useState<string | null>(null);
    const play = (id: string) => {
        setPlaying(id);
        const audio = new Audio(route('series.characters.sound', { ...base, sound: id }));
        audio.onended = () => setPlaying(null);
        audio.onerror = () => setPlaying(null);
        audio.play();
    };
    const label = (sound: CharacterSound) => sound.description.replace(/^a character /, '').replace(/^(a|an) /, '');

    return (
        <div className="space-y-2 rounded-xl border p-4">
            <p className="flex items-center gap-2 text-sm font-medium">
                <Volume2 className="size-4" /> Tiếng của {name}
                {species && <Badge variant="secondary">{SPECIES_LABELS[species] ?? species}</Badge>}
            </p>
            <div className="flex flex-wrap gap-1.5">
                {sounds.map((sound) => (
                    <Button
                        key={sound.id}
                        type="button"
                        size="sm"
                        variant={sound.group === 'animal' ? 'secondary' : 'outline'}
                        className="h-7 px-2 text-xs"
                        title={sound.description}
                        onClick={() => play(sound.id)}
                    >
                        {playing === sound.id ? <Loader2 className="animate-spin" /> : <Volume2 />} {label(sound)}
                    </Button>
                ))}
            </div>
            <p className="text-muted-foreground text-xs">
                Khi chọn âm thanh cho tập, Claude thêm tiếng này đúng lúc {name} cười, khóc, ngạc nhiên{species ? ', kêu' : ''}... Cao độ riêng theo
                giọng và vóc dáng của {name}, giống nhau ở mọi tập. Bản thu thật CC0 (BigSoundBank), miễn phí, dùng thương mại được.
                {!species && ' Nhân vật là thú mà chưa có tiếng kêu: ghi loài (cat, dog, duck...) trong mô tả ngoại hình.'}
            </p>
        </div>
    );
}

interface VoiceOption {
    value: string;
    type: string;
    label: string;
}

/** Giọng cố định của nhân vật (đọc mọi lời thoại ở mọi shot, mọi tập): chọn giọng, nghe thử (miễn phí). */
function VoicePanel({
    character,
    base,
    voices,
    usedVoices,
}: {
    character: Character;
    base: { series: number; character: number };
    voices: VoiceOption[];
    usedVoices: Record<string, string>;
}) {
    const [sampling, setSampling] = useState(false);
    const current = voices.find((voice) => voice.value === character.voice);
    const choose = (value: string) => {
        const option = voices.find((voice) => voice.value === value);
        if (!option) {
            return;
        }
        router.put(
            route('series.characters.update', base),
            {
                name: character.name,
                role: character.role,
                age: character.age === null ? '' : String(character.age),
                personality: character.personality ?? '',
                appearance: character.appearance,
                voice_type: option.type,
                voice: option.value,
            },
            { preserveScroll: true },
        );
    };
    const sample = () =>
        router.post(
            route('series.characters.voice-sample', base),
            {},
            { preserveScroll: true, onStart: () => setSampling(true), onFinish: () => setSampling(false) },
        );

    return (
        <div className="space-y-2 rounded-xl border p-4">
            <p className="flex items-center gap-2 text-sm font-medium">
                <Volume2 className="size-4" /> Giọng của {character.name}
            </p>
            <select
                aria-label="Giọng"
                className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                value={character.voice ?? ''}
                onChange={(e) => choose(e.target.value)}
            >
                {!current && <option value="">Chưa có giọng</option>}
                {voices.map((voice) => (
                    <option key={voice.value} value={voice.value}>
                        {voice.label}
                        {usedVoices[voice.value] ? ` (đang dùng cho ${usedVoices[voice.value]})` : ''}
                    </option>
                ))}
            </select>
            {character.voice && usedVoices[character.voice] && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                    Trùng giọng với {usedVoices[character.voice]}: khán giả khó phân biệt hai nhân vật, nên chọn giọng khác.
                </p>
            )}
            <div className="flex items-center gap-2">
                <Button type="button" size="sm" variant="outline" onClick={sample} disabled={sampling || !character.voice}>
                    {sampling ? <Loader2 className="animate-spin" /> : <Volume2 />} Nghe thử
                </Button>
                {character.voice_sample_url && (
                    <audio key={character.voice_sample_url} src={character.voice_sample_url} controls autoPlay className="h-8 min-w-0 flex-1" />
                )}
            </div>
            <p className="text-muted-foreground text-xs">
                Mọi lời thoại của {character.name} ở mọi shot, mọi tập đều đọc bằng đúng giọng này (miễn phí). Đổi giọng thì các shot đã đọc sẽ đọc
                lại.
            </p>
        </div>
    );
}

function CharacterEditor({
    character,
    base,
    roles,
    voiceTypes,
}: {
    character: Character;
    base: { series: number; character: number };
    roles: Record<string, string>;
    voiceTypes: Record<string, string>;
}) {
    const { data, setData, put, processing, errors, isDirty } = useForm<CharacterFormData>({
        name: character.name,
        role: character.role,
        age: character.age === null ? '' : String(character.age),
        personality: character.personality ?? '',
        appearance: character.appearance,
        voice_type: character.voice_type ?? 'adult_female',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('series.characters.update', base), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-xl border p-4">
            <CharacterFields
                data={data}
                setData={setData}
                errors={errors}
                roles={roles}
                voiceTypes={voiceTypes}
                appearanceLocked={character.locked}
            />
            <Button type="submit" size="sm" disabled={processing || !isDirty}>
                {processing ? <Loader2 className="animate-spin" /> : <Save />} Lưu thông tin
            </Button>
        </form>
    );
}

function GeneratePanel({
    base,
    kinds,
    hasMain,
    imageCredits,
}: {
    base: { series: number; character: number };
    kinds: Record<string, string>;
    hasMain: boolean;
    imageCredits: number;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ kind: 'main', notes: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('series.characters.generate', base), { preserveScroll: true, onSuccess: () => reset('notes') });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-xl border p-4">
            <h2 className="flex items-center gap-2 font-semibold">
                <Sparkles className="size-4" /> Muse vẽ ảnh nhân vật
            </h2>
            <div className="space-y-1">
                <Label>Loại ảnh</Label>
                <Select value={data.kind} onValueChange={(value) => setData('kind', value)}>
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.entries(kinds).map(([key, label]) => (
                            <SelectItem key={key} value={key} disabled={key !== 'main' && !hasMain}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {!hasMain && (
                    <p className="text-muted-foreground text-xs">Vẽ ảnh chính trước; các loại khác vẽ theo ảnh chính để giữ đúng nhân vật.</p>
                )}
                <InputError message={errors.kind} />
            </div>
            <div className="space-y-1">
                <Label htmlFor="notes">{data.kind === 'custom' ? 'Tư thế / hành động (tiếng Anh tốt hơn)' : 'Ghi chú thêm (tuỳ chọn)'}</Label>
                <Textarea
                    id="notes"
                    rows={2}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    placeholder={
                        data.kind === 'custom' ? 'waving hello with a big smile, holding a magnifying glass' : 'make the hoodie a brighter yellow'
                    }
                />
                <InputError message={errors.notes} />
            </div>
            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {processing ? <Loader2 className="animate-spin" /> : <Sparkles />} Vẽ
                </Button>
                <span className="text-muted-foreground text-xs">{formatNumber(imageCredits)} Credits / ảnh, khoảng 1–2 phút.</span>
            </div>
        </form>
    );
}

function UploadPanel({ base }: { base: { series: number; character: number } }) {
    const input = useRef<HTMLInputElement>(null);
    const [processing, setProcessing] = useState(false);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const upload = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }
        router.post(
            route('series.characters.upload', base),
            { photos: Array.from(files) },
            {
                preserveScroll: true,
                forceFormData: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    if (input.current) input.current.value = '';
                },
            },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-dashed p-4">
            <Button type="button" variant="outline" disabled={processing} onClick={() => input.current?.click()}>
                {processing ? <Loader2 className="animate-spin" /> : <Upload />} Tải ảnh lên
            </Button>
            <span className="text-muted-foreground text-xs">
                Ảnh tự vẽ hoặc tạo ở công cụ khác (JPG, PNG, WEBP, tối đa 10 MB, nhiều ảnh cùng lúc).
            </span>
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="hidden"
                onChange={(e) => upload(e.target.files)}
            />
            <InputError message={errors.photos || errors['photos.0']} />
        </div>
    );
}

function ImageCard({
    image,
    base,
    locked,
    onOpen,
}: {
    image: CharacterImage;
    base: { series: number; character: number };
    locked: boolean;
    onOpen: () => void;
}) {
    const params = { ...base, image: image.id };
    const setMain = () => router.put(route('series.characters.images.main', params), {}, { preserveScroll: true });
    const destroy = () => {
        if (confirm('Xoá ảnh này?')) {
            router.delete(route('series.characters.images.destroy', params), { preserveScroll: true });
        }
    };

    return (
        <div className={cn('overflow-hidden rounded-xl border', image.is_main && 'ring-primary ring-2')}>
            <button type="button" onClick={onOpen} disabled={!image.url} className="bg-muted/40 block aspect-video w-full">
                {image.status === 'pending' ? (
                    <span className="text-muted-foreground flex size-full flex-col items-center justify-center gap-2 text-xs">
                        <Loader2 className="size-5 animate-spin" /> Muse đang vẽ...
                    </span>
                ) : image.status === 'failed' ? (
                    <span className="text-destructive flex size-full flex-col items-center justify-center gap-1 p-2 text-center text-xs">
                        <ImageOff className="size-5" />
                        <span className="line-clamp-3">{image.error}</span>
                    </span>
                ) : (
                    image.url && <img src={image.url} alt="" className="size-full object-contain" />
                )}
            </button>
            <div className="space-y-2 p-2">
                <div className="flex flex-wrap items-center gap-1">
                    {image.is_main && (
                        <Badge>
                            <Star /> Ảnh chính
                        </Badge>
                    )}
                    <Badge variant="outline" className="max-w-full truncate">
                        {image.source === 'upload' ? 'Tải lên' : image.kind_label}
                    </Badge>
                </div>
                <div className="flex gap-1">
                    {image.status === 'ready' && !image.is_main && (
                        <Button size="sm" variant="outline" className="h-7 px-2 text-xs" onClick={setMain} disabled={locked}>
                            <Star /> Đặt làm chính
                        </Button>
                    )}
                    {image.status !== 'pending' && (
                        <Button
                            size="sm"
                            variant="ghost"
                            className="text-destructive ml-auto h-7 px-2"
                            onClick={destroy}
                            disabled={locked && image.is_main}
                        >
                            <Trash2 />
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
