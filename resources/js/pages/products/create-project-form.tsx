import InputError from '@/components/input-error';
import OutfitPicker from '@/components/outfit-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { motionStyles, type MotionStyle } from '@/lib/motion-styles';
import { cn } from '@/lib/utils';
import { voiceModes } from '@/lib/voice-modes';
import {
    type AudioTrack,
    type Background,
    type FashionModel,
    type Outfit,
    type OutfitProduct,
    type SampleVideo,
    type VideoType,
    type VoiceMode,
} from '@/types/models';
import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const videoTypes: { value: VideoType; label: string; hint: string }[] = [
    { value: 'standard', label: 'Video thường', hint: 'Người mẫu mặc sản phẩm ở nhiều bối cảnh.' },
    { value: 'transformation', label: 'Biến hình', hint: 'Mở đầu đồ cũ, mặt mộc, tóc rối → makeup mặc sản phẩm.' },
];

/** Chọn một ảnh người mẫu (đã xác nhận đồng ý) từ danh sách. */
function ModelPicker({
    models,
    value,
    onChange,
    empty,
}: {
    models: FashionModel[];
    value: number | null;
    onChange: (id: number) => void;
    empty: string;
}) {
    if (models.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                {empty}{' '}
                <Link href={route('fashion-models.index')} className="underline">
                    Mở Người mẫu
                </Link>
            </p>
        );
    }

    return (
        <div className="grid grid-cols-[repeat(auto-fill,minmax(72px,1fr))] gap-2">
            {models.map((model) => (
                <button
                    key={model.id}
                    type="button"
                    onClick={() => onChange(model.id)}
                    className={cn('overflow-hidden rounded-lg border-2', value === model.id ? 'border-primary' : 'border-transparent')}
                    title={model.name}
                >
                    <img src={model.photo_url} alt={model.name} className="aspect-[3/4] w-full object-cover" />
                </button>
            ))}
        </div>
    );
}

/** Bối cảnh: AI tự đề xuất theo sản phẩm, hoặc chọn bối cảnh người dùng đã tải lên (các cảnh dùng lần lượt theo thứ tự chọn). */
function BackgroundPicker({
    backgrounds,
    custom,
    onModeChange,
    value,
    onChange,
    error,
}: {
    backgrounds: Background[];
    custom: boolean;
    onModeChange: (custom: boolean) => void;
    value: number[];
    onChange: (ids: number[]) => void;
    error?: string;
}) {
    const toggle = (id: number) => onChange(value.includes(id) ? value.filter((item) => item !== id) : [...value, id]);

    return (
        <div className="space-y-2">
            <Label>Bối cảnh</Label>
            <div className="grid grid-cols-2 gap-2">
                {[
                    { custom: false, label: 'AI tự đề xuất', hint: 'Bối cảnh hợp với sản phẩm, mỗi cảnh một nơi.' },
                    { custom: true, label: 'Bối cảnh của tôi', hint: 'Đặt người mẫu vào ảnh nền bạn đã tải lên.' },
                ].map((option) => (
                    <button
                        key={String(option.custom)}
                        type="button"
                        onClick={() => onModeChange(option.custom)}
                        className={cn(
                            'rounded-md border px-3 py-1.5 text-left text-sm',
                            custom === option.custom ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                        )}
                    >
                        <span className="font-medium">{option.label}</span>
                        <span className="text-muted-foreground block text-xs">{option.hint}</span>
                    </button>
                ))}
            </div>
            {custom &&
                (backgrounds.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Chưa có bối cảnh nào.{' '}
                        <Link href={route('backgrounds.index')} className="underline">
                            Thêm ở menu Bối cảnh
                        </Link>
                    </p>
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(72px,1fr))] gap-2">
                            {backgrounds.map((background) => {
                                const order = value.indexOf(background.id);
                                return (
                                    <button
                                        key={background.id}
                                        type="button"
                                        onClick={() => toggle(background.id)}
                                        title={background.name}
                                        className={cn(
                                            'relative overflow-hidden rounded-lg border-2 text-left',
                                            order >= 0 ? 'border-primary' : 'border-transparent',
                                        )}
                                    >
                                        {background.photo_url && (
                                            <img src={background.photo_url} alt={background.name} className="aspect-[3/4] w-full object-cover" />
                                        )}
                                        {order >= 0 && (
                                            <span className="bg-primary text-primary-foreground absolute top-1 left-1 flex size-5 items-center justify-center rounded-full text-xs font-bold">
                                                {order + 1}
                                            </span>
                                        )}
                                        <span className="block truncate px-1 py-0.5 text-[11px]">{background.name}</span>
                                    </button>
                                );
                            })}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Các cảnh dùng lần lượt theo thứ tự chọn (cảnh 1 → bối cảnh 1...), hết thì quay lại từ đầu. Đổi bối cảnh từng cảnh được ở
                            bước Ảnh cảnh.{' '}
                            <Link href={route('backgrounds.index')} className="underline">
                                Quản lý Bối cảnh
                            </Link>
                        </p>
                    </>
                ))}
            <InputError message={error} />
        </div>
    );
}

export default function CreateProjectForm({
    productId,
    fashionModels,
    defaultModelId,
    defaultMotionStyles,
    defaultVideoType,
    defaultBeforeModelId,
    motionSuggestions = [],
    productCategory,
    audioTracks = [],
    defaultAudioTrackId,
    sampleVideos = [],
    defaultSampleVideoId,
    backgrounds = [],
    defaultBackgroundIds = [],
    outfitProducts = [],
    defaultOutfits = [],
    autopilotAvailable,
}: {
    productId: number;
    fashionModels: FashionModel[];
    defaultModelId?: number;
    defaultMotionStyles?: MotionStyle[];
    defaultVideoType?: VideoType;
    defaultBeforeModelId?: number | null;
    /** Kiểu chuyển động hợp với sản phẩm (theo loại sản phẩm), từ hợp nhất. */
    motionSuggestions?: MotionStyle[];
    productCategory?: string | null;
    /** Nhạc trong thư viện Âm thanh (chỉ bài có file). */
    audioTracks?: AudioTrack[];
    defaultAudioTrackId?: number | null;
    /** Video mẫu (menu Video mẫu) để sao chép chuyển động. */
    sampleVideos?: SampleVideo[];
    defaultSampleVideoId?: number | null;
    /** Bối cảnh của người dùng (menu Bối cảnh). */
    backgrounds?: Background[];
    defaultBackgroundIds?: number[];
    /** Sản phẩm đã duyệt ảnh, để mỗi cảnh / shot mặc một bộ đồ. */
    outfitProducts?: OutfitProduct[];
    defaultOutfits?: Outfit[];
    autopilotAvailable: boolean;
}) {
    const { workflow } = usePage<{ workflow: 'auto' | 'manual' | null }>().props;
    const { data, setData, post, processing, errors, transform } = useForm<{
        product_id: number;
        fashion_model_id: number;
        video_type: VideoType;
        before_fashion_model_id: number | null;
        motion_styles: MotionStyle[];
        audio_track_id: number | null;
        use_sample_audio: boolean;
        sample_video_id: number | null;
        background_ids: number[];
        outfits: Outfit[];
        one_take: boolean;
        voice_mode: VoiceMode;
        autopilot: boolean;
    }>({
        product_id: productId,
        fashion_model_id: defaultModelId ?? fashionModels.find((model) => model.kind === 'after')?.id ?? 0,
        video_type: defaultVideoType ?? 'standard',
        before_fashion_model_id: defaultBeforeModelId ?? null,
        motion_styles: defaultMotionStyles ?? [],
        audio_track_id: defaultAudioTrackId ?? null,
        use_sample_audio: false,
        sample_video_id: defaultSampleVideoId ?? null,
        background_ids: defaultBackgroundIds.filter((id) => backgrounds.some((background) => background.id === id)),
        one_take: false,
        voice_mode: 'music',
        outfits: defaultOutfits.filter((outfit) => outfitProducts.some((product) => product.id === outfit.product_id)),
        // Chế độ "Tự động tất cả các bước" (Cài đặt video) thì tick sẵn chạy ngầm.
        autopilot: autopilotAvailable && workflow !== 'manual',
    });

    const [customBackgrounds, setCustomBackgrounds] = useState(data.background_ids.length > 0);
    const [multiOutfit, setMultiOutfit] = useState(data.outfits.length > 0);
    const missingBackgrounds = customBackgrounds && data.background_ids.length === 0;

    const selectedSample = sampleVideos.find((video) => video.id === data.sample_video_id) ?? null;
    const shotCount = selectedSample?.shots?.length ?? 0;

    const toggleMultiOutfit = (on: boolean) => {
        setMultiOutfit(on);
        // Bật lần đầu: bộ 1 là sản phẩm hiện tại, bấm thêm sản phẩm khác ở lưới bên dưới.
        if (on && data.outfits.length === 0 && outfitProducts.some((product) => product.id === productId)) {
            const images = outfitProducts.find((product) => product.id === productId)?.images ?? [];
            // Sản phẩm nhiều ảnh (thường là nhiều màu / mẫu): mỗi ảnh một bộ, bỏ bớt được ở danh sách bên dưới.
            setData(
                'outfits',
                images.length > 1
                    ? images.slice(0, 12).map((image) => ({ product_id: productId, product_image_id: image.id, color: null }))
                    : [{ product_id: productId, product_image_id: null, color: null }],
            );
        }
    };

    // Kiểu hợp với sản phẩm lên đầu (theo thứ tự đề xuất), các kiểu khác sau.
    const orderedStyles = [
        ...motionSuggestions.map((value) => motionStyles.find((style) => style.value === value)).filter((style) => style !== undefined),
        ...motionStyles.filter((style) => !motionSuggestions.includes(style.value)),
    ];

    // Bật/tắt một kiểu; giữ thứ tự như danh sách (các cảnh dùng lần lượt theo thứ tự này).
    const toggleStyle = (value: MotionStyle) =>
        setData(
            'motion_styles',
            orderedStyles.map((style) => style.value).filter((v) => (v === value ? !data.motion_styles.includes(v) : data.motion_styles.includes(v))),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // "AI tự đề xuất" thì không gửi bối cảnh đã chọn trước đó.
        transform((form) => ({
            ...form,
            background_ids: customBackgrounds ? form.background_ids : [],
            outfits: multiOutfit ? form.outfits : [],
        }));
        post(route('projects.store'));
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="space-y-1">
                <Label>Kiểu dựng</Label>
                <div className="grid grid-cols-2 gap-2">
                    {[
                        { oneTake: false, label: 'Nhiều cảnh', hint: 'Mỗi cảnh một bối cảnh / góc máy, cắt ghép lại.' },
                        { oneTake: true, label: 'Một cảnh quay liền', hint: 'Một shot dài liền mạch, cùng địa điểm, không cắt cảnh.' },
                    ].map((option) => (
                        <button
                            key={String(option.oneTake)}
                            type="button"
                            onClick={() =>
                                setData((current) => ({
                                    ...current,
                                    one_take: option.oneTake,
                                    video_type: option.oneTake ? 'standard' : current.video_type,
                                }))
                            }
                            className={cn(
                                'rounded-md border px-3 py-1.5 text-left text-sm',
                                data.one_take === option.oneTake ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                            )}
                        >
                            <span className="font-medium">{option.label}</span>
                            <span className="text-muted-foreground block text-xs">{option.hint}</span>
                        </button>
                    ))}
                </div>
                {data.one_take && (
                    <p className="text-muted-foreground text-xs">
                        AI tạo ảnh cảnh 1, rồi làm từng clip khoảng 5 giây nối tiếp nhau: khung cuối clip trước là khung đầu clip sau, nên video chạy
                        liền một mạch. Các clip tạo lần lượt nên lâu hơn. Chỉ một bộ đồ, một bối cảnh.
                    </p>
                )}
                <InputError message={(errors as Record<string, string>).one_take} />
            </div>

            <div className="grid grid-cols-2 gap-2">
                {videoTypes.map((type) => (
                    <button
                        key={type.value}
                        type="button"
                        disabled={data.one_take && type.value === 'transformation'}
                        title={data.one_take && type.value === 'transformation' ? 'Biến hình cần cắt cảnh, chọn "Nhiều cảnh"' : undefined}
                        onClick={() => setData('video_type', type.value)}
                        className={cn(
                            'rounded-md border px-3 py-1.5 text-left text-sm disabled:cursor-not-allowed disabled:opacity-40',
                            data.video_type === type.value ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                        )}
                    >
                        <span className="font-medium">{type.label}</span>
                        <span className="text-muted-foreground block text-xs">{type.hint}</span>
                    </button>
                ))}
            </div>

            {data.video_type === 'transformation' && (
                <>
                    <Label>Ảnh trước: đồ cũ, mặt mộc / mụn, tóc rối</Label>
                    <ModelPicker
                        models={fashionModels.filter((model) => model.kind === 'before')}
                        empty='Chưa có ảnh "Trước". Vào Người mẫu, tab Trước để thêm ảnh mặt mộc, tóc rối, đồ cũ.'
                        value={data.before_fashion_model_id}
                        onChange={(id) => setData('before_fashion_model_id', id)}
                    />
                    <InputError message={errors.before_fashion_model_id} />
                </>
            )}

            {data.video_type === 'transformation' && <Label>Ảnh sau: đã makeup, sẽ mặc sản phẩm</Label>}
            <ModelPicker
                models={fashionModels.filter((model) => model.kind === 'after')}
                empty='Chưa có ảnh "Sau". Vào Người mẫu, tab Sau để thêm.'
                value={data.fashion_model_id}
                onChange={(id) => setData('fashion_model_id', id)}
            />
            <InputError message={errors.fashion_model_id ?? errors.product_id} />

            <BackgroundPicker
                backgrounds={backgrounds}
                custom={customBackgrounds}
                onModeChange={setCustomBackgrounds}
                value={data.background_ids}
                onChange={(ids) => setData('background_ids', ids)}
                error={
                    missingBackgrounds
                        ? 'Chọn ít nhất một bối cảnh, hoặc chọn "AI tự đề xuất".'
                        : (errors.background_ids ?? (errors as Record<string, string>)['background_ids.0'])
                }
            />

            <div className="space-y-2">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="motion"
                        checked={data.motion_styles.length > 0}
                        onCheckedChange={(checked) => setData('motion_styles', checked ? [orderedStyles[0].value] : [])}
                    />
                    <Label htmlFor="motion">Nhân vật chuyển động (nhảy trend, khoe sản phẩm...)</Label>
                </div>
                {data.motion_styles.length > 0 && (
                    <div className="grid gap-1.5 pl-7">
                        <p className="text-muted-foreground text-xs">
                            Chọn một hoặc nhiều kiểu; các cảnh dùng lần lượt từng kiểu.
                            {productCategory && ` Đề xuất cho loại sản phẩm: ${productCategory}.`}
                        </p>
                        {orderedStyles.map((style) => {
                            const checked = data.motion_styles.includes(style.value);
                            const suggested = motionSuggestions.includes(style.value);
                            return (
                                <label
                                    key={style.value}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-2 rounded-md border px-3 py-1.5 text-sm',
                                        checked ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                                    )}
                                >
                                    <Checkbox checked={checked} onCheckedChange={() => toggleStyle(style.value)} className="mt-0.5" />
                                    <span>
                                        <span className="font-medium">{style.label}</span>
                                        {suggested && <Badge className="ml-2 align-middle">Đề xuất</Badge>}
                                        <span className="text-muted-foreground block text-xs">{style.hint}</span>
                                    </span>
                                </label>
                            );
                        })}
                        <p className="text-muted-foreground text-xs">
                            Sau khi duyệt ảnh cảnh, AI tạo clip chuyển động cho từng cảnh (chạy ngầm thì tự tạo trước khi xuất video). Cũng có thể tự
                            tạo clip rồi tải lên.
                        </p>
                    </div>
                )}
                <InputError message={errors.motion_styles ?? (errors as Record<string, string>)['motion_styles.0']} />
            </div>

            <div className="space-y-1">
                <Label htmlFor="sample_video_id">Sao chép chuyển động từ video mẫu (không bắt buộc)</Label>
                <select
                    id="sample_video_id"
                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    value={data.sample_video_id ?? ''}
                    onChange={(e) =>
                        setData((current) => ({
                            ...current,
                            sample_video_id: e.target.value === '' ? null : Number(e.target.value),
                            use_sample_audio: e.target.value === '' ? false : current.use_sample_audio,
                        }))
                    }
                >
                    <option value="">Không dùng video mẫu</option>
                    {sampleVideos.map((video) => (
                        <option key={video.id} value={video.id}>
                            {video.name}
                            {(video.shots?.length ?? 0) > 1 ? ` (${video.shots?.length} shot)` : ''}
                        </option>
                    ))}
                </select>
                <p className="text-muted-foreground text-xs">
                    Người mẫu sẽ làm theo đúng động tác trong video mẫu (thay cho kiểu chuyển động ở trên).{' '}
                    {sampleVideos.length === 0 && 'Chưa có video mẫu. '}
                    <Link href={route('sample-videos.index')} className="underline">
                        Quản lý Video mẫu
                    </Link>
                </p>
                {shotCount > 1 && (
                    <p className="text-xs text-amber-700 dark:text-amber-400">
                        Video mẫu có {shotCount} shot: số cảnh và độ dài từng cảnh theo các shot. Bật "Gộp nhiều sản phẩm" để mỗi shot mặc một bộ.
                    </p>
                )}
                <InputError message={errors.sample_video_id} />
            </div>

            {outfitProducts.length > 0 && (
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <Checkbox id="multi_outfit" checked={multiOutfit} onCheckedChange={(checked) => toggleMultiOutfit(checked === true)} />
                        <Label htmlFor="multi_outfit">Gộp nhiều sản phẩm: mỗi cảnh một bộ đồ (đổi đồ, lookbook)</Label>
                    </div>
                    {multiOutfit && (
                        <div className="pl-7">
                            {data.one_take && (
                                <p className="text-muted-foreground mb-2 text-xs">
                                    Một cảnh quay liền nhiều bộ: mỗi bộ quay liền một đoạn, giữa các đoạn cắt cảnh đổi đồ.
                                </p>
                            )}
                            <OutfitPicker
                                products={outfitProducts}
                                value={data.outfits}
                                onChange={(outfits) => setData('outfits', outfits)}
                                shotCount={shotCount}
                            />
                            <InputError
                                message={
                                    multiOutfit && data.outfits.length === 0
                                        ? 'Thêm ít nhất một bộ đồ, hoặc bỏ chọn.'
                                        : (errors.outfits ?? Object.entries(errors).find(([key]) => key.startsWith('outfits.'))?.[1])
                                }
                            />
                        </div>
                    )}
                </div>
            )}

            <div className="space-y-1">
                <Label htmlFor="audio_track_id">Nhạc nền</Label>
                <select
                    id="audio_track_id"
                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    value={data.use_sample_audio ? 'sample' : (data.audio_track_id ?? '')}
                    onChange={(e) =>
                        setData((current) => ({
                            ...current,
                            use_sample_audio: e.target.value === 'sample',
                            audio_track_id: ['', 'sample'].includes(e.target.value) ? null : Number(e.target.value),
                        }))
                    }
                >
                    <option value="">Không có nhạc nền</option>
                    {selectedSample && selectedSample.has_audio !== false && (
                        <option value="sample">Nhạc như video mẫu: {selectedSample.name}</option>
                    )}
                    {audioTracks.map((track) => (
                        <option key={track.id} value={track.id}>
                            {track.name}
                        </option>
                    ))}
                </select>
                <p className="text-muted-foreground text-xs">
                    {audioTracks.length === 0 ? 'Chưa có nhạc. ' : 'Đổi nhạc được ở bước Xuất video. '}
                    <Link href={route('audio-tracks.index')} className="underline">
                        Quản lý Âm thanh
                    </Link>
                </p>
                <InputError message={errors.audio_track_id} />
            </div>

            <div className="space-y-1">
                <Label>Tiếng trong video</Label>
                <div className="grid gap-2 sm:grid-cols-3">
                    {voiceModes.map((mode) => (
                        <button
                            key={mode.value}
                            type="button"
                            onClick={() => setData('voice_mode', mode.value)}
                            className={cn(
                                'rounded-md border px-3 py-1.5 text-left text-sm',
                                data.voice_mode === mode.value ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                            )}
                        >
                            <span className="font-medium">{mode.label}</span>
                            <span className="text-muted-foreground block text-xs">{mode.hint}</span>
                        </button>
                    ))}
                </div>
                {data.voice_mode === 'sing' && !data.audio_track_id && !data.use_sample_audio && (
                    <p className="text-xs text-amber-600 dark:text-amber-400">Hát nhép cần chọn nhạc nền ở trên.</p>
                )}
                <InputError message={(errors as Record<string, string>).voice_mode} />
            </div>

            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="autopilot"
                        checked={data.autopilot}
                        disabled={!autopilotAvailable}
                        onCheckedChange={(checked) => setData('autopilot', checked === true)}
                    />
                    <Label htmlFor="autopilot">Chạy ngầm: AI viết kịch bản → tạo ảnh → xuất video</Label>
                </div>
                {!autopilotAvailable && (
                    <p className="text-muted-foreground pl-7 text-xs">Chạy ngầm chưa sẵn sàng trên hệ thống. Liên hệ quản trị viên để bật.</p>
                )}
            </div>

            <Button disabled={processing || missingBackgrounds || (multiOutfit && data.outfits.length === 0)} className="w-full">
                Tạo dự án video
            </Button>
        </form>
    );
}
