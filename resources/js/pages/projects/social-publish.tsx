import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type SocialAccount, type SocialPost, type TikTokCreator, type VideoProject } from '@/types/models';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Send } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

const statusLabels: Record<SocialPost['status'], string> = {
    pending: 'Chờ đăng',
    uploading: 'Đang tải video lên',
    processing: 'Đang xử lý',
    published: 'Đã đăng',
    inbox: 'Đã gửi vào hộp nháp TikTok: mở app TikTok để đăng',
    failed: 'Lỗi',
};

const providerLabels: Record<SocialAccount['provider'], string> = { facebook: 'Facebook', tiktok: 'TikTok' };

const privacyLabels: Record<string, string> = {
    PUBLIC_TO_EVERYONE: 'Mọi người',
    MUTUAL_FOLLOW_FRIENDS: 'Bạn bè (theo dõi nhau)',
    FOLLOWER_OF_CREATOR: 'Người theo dõi',
    SELF_ONLY: 'Chỉ mình tôi',
};

/** Tuỳ chọn khi đăng TikTok theo yêu cầu của TikTok: hiện tên tài khoản, tự chọn quyền xem (không chọn sẵn), tương tác, công khai nội dung thương mại. */
function TikTokOptions({
    account,
    options,
    setOptions,
    errors,
}: {
    account: SocialAccount;
    options: TikTokForm;
    setOptions: (options: TikTokForm) => void;
    errors: Partial<Record<string, string>>;
}) {
    const [creator, setCreator] = useState<TikTokCreator | null>(null);
    const [error, setError] = useState<string | null>(null);
    const direct = options.mode === 'direct';

    useEffect(() => {
        if (!direct) return;
        setCreator(null);
        setError(null);
        fetch(route('social.accounts.creator', account.id), { headers: { Accept: 'application/json' } })
            .then(async (response) => {
                const body = await response.json();
                if (response.ok) setCreator(body);
                else setError(body.message ?? 'Không lấy được thông tin tài khoản TikTok.');
            })
            .catch(() => setError('Không lấy được thông tin tài khoản TikTok.'));
    }, [account.id, direct]);

    const set = (patch: Partial<TikTokForm>) => setOptions({ ...options, ...patch });

    return (
        <div className="space-y-3 rounded-md border p-3">
            <p className="text-sm font-medium">TikTok: {account.name}</p>
            <div className="flex flex-wrap gap-4 text-sm">
                <label className="flex items-center gap-2">
                    <input type="radio" checked={!direct} onChange={() => set({ mode: 'inbox' })} />
                    Gửi vào hộp nháp (bạn mở app TikTok để đăng)
                </label>
                <label className="flex items-center gap-2">
                    <input type="radio" checked={direct} onChange={() => set({ mode: 'direct' })} />
                    Đăng thẳng
                </label>
            </div>
            {direct && (
                <>
                    {error && <p className="text-destructive text-sm">{error}</p>}
                    {!creator && !error && (
                        <p className="text-muted-foreground flex items-center gap-2 text-sm">
                            <LoaderCircle className="size-4 animate-spin" /> Đang lấy cài đặt tài khoản TikTok...
                        </p>
                    )}
                    {creator && (
                        <>
                            <p className="text-muted-foreground text-xs">
                                Đăng với tên <b>{creator.nickname || account.name}</b>
                                {creator.max_video_post_duration_sec > 0 && ` · video tối đa ${creator.max_video_post_duration_sec} giây`}
                            </p>
                            <div className="space-y-1">
                                <Label>Ai được xem video này</Label>
                                <Select value={options.privacy_level ?? ''} onValueChange={(value) => set({ privacy_level: value })}>
                                    <SelectTrigger className="w-64">
                                        <SelectValue placeholder="Chọn quyền xem" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {creator.privacy_level_options.map((level) => (
                                            <SelectItem key={level} value={level} disabled={options.brand_content && level === 'SELF_ONLY'}>
                                                {privacyLabels[level] ?? level}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors['tiktok.privacy_level']} />
                                {creator.privacy_level_options.length === 1 && creator.privacy_level_options[0] === 'SELF_ONLY' && (
                                    <p className="text-muted-foreground text-xs">App TikTok chưa được kiểm duyệt nên chỉ đăng được ở chế độ "Chỉ mình tôi".</p>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-4 text-sm">
                                {(
                                    [
                                        ['allow_comment', 'Cho bình luận', creator.comment_disabled],
                                        ['allow_duet', 'Cho Duet', creator.duet_disabled],
                                        ['allow_stitch', 'Cho Stitch', creator.stitch_disabled],
                                    ] as const
                                ).map(([key, label, disabled]) => (
                                    <label key={key} className={`flex items-center gap-2 ${disabled ? 'opacity-50' : ''}`}>
                                        <Checkbox disabled={disabled} checked={!disabled && options[key]} onCheckedChange={(checked) => set({ [key]: checked === true })} />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            <div className="space-y-2 text-sm">
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        checked={options.commercial}
                                        onCheckedChange={(checked) => set({ commercial: checked === true, brand_organic: false, brand_content: false })}
                                    />
                                    Video này quảng bá sản phẩm / thương hiệu
                                </label>
                                {options.commercial && (
                                    <div className="space-y-1 pl-6">
                                        <label className="flex items-center gap-2">
                                            <Checkbox checked={options.brand_organic} onCheckedChange={(checked) => set({ brand_organic: checked === true })} />
                                            Thương hiệu của bạn (nhãn "Nội dung quảng cáo")
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <Checkbox
                                                checked={options.brand_content}
                                                onCheckedChange={(checked) =>
                                                    set({
                                                        brand_content: checked === true,
                                                        privacy_level: checked === true && options.privacy_level === 'SELF_ONLY' ? null : options.privacy_level,
                                                    })
                                                }
                                            />
                                            Nội dung có thương hiệu: hợp tác / affiliate (nhãn "Hợp tác trả phí")
                                        </label>
                                        {!options.brand_organic && !options.brand_content && (
                                            <p className="text-destructive text-xs">Chọn ít nhất một loại.</p>
                                        )}
                                    </div>
                                )}
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Video được gắn nhãn "Nội dung do AI tạo". Khi đăng, bạn đồng ý với{' '}
                                {options.brand_content && (
                                    <>
                                        <a className="underline" href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noreferrer">
                                            Chính sách nội dung có thương hiệu
                                        </a>{' '}
                                        và{' '}
                                    </>
                                )}
                                <a className="underline" href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noreferrer">
                                    Xác nhận sử dụng âm nhạc
                                </a>{' '}
                                của TikTok. Video có thể mất vài phút để xuất hiện trên trang cá nhân.
                            </p>
                        </>
                    )}
                </>
            )}
        </div>
    );
}

type TikTokForm = {
    mode: 'inbox' | 'direct';
    privacy_level: string | null;
    allow_comment: boolean;
    allow_duet: boolean;
    allow_stitch: boolean;
    commercial: boolean;
    brand_organic: boolean;
    brand_content: boolean;
};

/** Đăng video đã xuất lên Trang Facebook / TikTok đã kết nối, chọn caption từ đề xuất hoặc tự viết. */
export default function SocialPublish({ project, accounts }: { project: VideoProject; accounts: SocialAccount[] }) {
    const usable = accounts.filter((account) => !account.expired);
    const suggestions = [...(project.post_captions?.sale ?? []), ...(project.post_captions?.view ?? [])];
    const { data, setData, post, processing, errors } = useForm({
        account_ids: usable.length === 1 ? [usable[0].id] : ([] as number[]),
        caption: suggestions[0] ?? '',
        tiktok: {
            mode: 'inbox',
            privacy_level: null,
            allow_comment: false,
            allow_duet: false,
            allow_stitch: false,
            commercial: false,
            brand_organic: false,
            brand_content: false,
        } as TikTokForm,
    });
    const posts = project.social_posts ?? [];
    const hasVideo = project.renders?.some((render) => render.status === 'done' && render.url) ?? false;
    const tiktokAccount = usable.find((account) => account.provider === 'tiktok' && data.account_ids.includes(account.id));
    const tiktokNotReady =
        !!tiktokAccount &&
        data.tiktok.mode === 'direct' &&
        (!data.tiktok.privacy_level || (data.tiktok.commercial && !data.tiktok.brand_organic && !data.tiktok.brand_content));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.social-posts.store', project.id), { preserveScroll: true });
    };
    const toggle = (id: number, checked: boolean) =>
        setData('account_ids', checked ? [...data.account_ids, id] : data.account_ids.filter((value) => value !== id));

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <p className="text-sm font-medium">Đăng lên Facebook / TikTok</p>
            {accounts.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Chưa kết nối tài khoản nào.{' '}
                    <Link href={route('social.index')} className="underline">
                        Kết nối Facebook / TikTok
                    </Link>
                </p>
            ) : !hasVideo ? (
                <p className="text-muted-foreground text-sm">Xuất video xong là đăng được.</p>
            ) : (
                <form onSubmit={submit} className="space-y-3">
                    <div className="flex flex-wrap gap-4">
                        {accounts.map((account) => (
                            <label key={account.id} className={`flex items-center gap-2 text-sm ${account.expired ? 'opacity-50' : ''}`}>
                                <Checkbox
                                    disabled={account.expired}
                                    checked={data.account_ids.includes(account.id)}
                                    onCheckedChange={(checked) => toggle(account.id, checked === true)}
                                />
                                <span className="text-muted-foreground text-xs">{providerLabels[account.provider]}</span>
                                {account.name}
                                {account.expired && <span className="text-destructive text-xs">(hết hạn, kết nối lại)</span>}
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.account_ids} />
                    {suggestions.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {suggestions.map((text, i) => (
                                <Button key={i} type="button" size="sm" variant={data.caption === text ? 'default' : 'outline'} onClick={() => setData('caption', text)}>
                                    {i < (project.post_captions?.sale?.length ?? 0) ? `Bán hàng ${i + 1}` : `Câu view ${i + 1 - (project.post_captions?.sale?.length ?? 0)}`}
                                </Button>
                            ))}
                        </div>
                    )}
                    <div className="space-y-1">
                        <Label htmlFor="social-caption">Caption</Label>
                        <Textarea id="social-caption" rows={5} value={data.caption} onChange={(e) => setData('caption', e.target.value)} />
                        <InputError message={errors.caption} />
                    </div>
                    {tiktokAccount && (
                        <TikTokOptions account={tiktokAccount} options={data.tiktok} setOptions={(options) => setData('tiktok', options)} errors={errors} />
                    )}
                    <Button type="submit" disabled={processing || data.account_ids.length === 0 || data.caption.trim() === '' || tiktokNotReady}>
                        <Send />
                        Đăng video mới nhất
                    </Button>
                </form>
            )}
            {posts.length > 0 && (
                <ul className="space-y-1 text-sm">
                    {posts.map((item) => (
                        <li key={item.id} className="flex flex-wrap items-center gap-2">
                            {['pending', 'uploading', 'processing'].includes(item.status) && <LoaderCircle className="size-4 animate-spin" />}
                            <span className="text-muted-foreground text-xs">{providerLabels[item.provider as SocialAccount['provider']] ?? item.provider}</span>
                            <span className="font-medium">{item.account_name}</span>
                            <span className={item.status === 'failed' ? 'text-destructive' : 'text-muted-foreground'}>{statusLabels[item.status]}</span>
                            {item.permalink && (
                                <a href={item.permalink} target="_blank" rel="noreferrer" className="underline">
                                    Xem bài
                                </a>
                            )}
                            <span className="text-muted-foreground text-xs">{new Date(item.created_at).toLocaleString('vi-VN')}</span>
                            {item.error && <span className="text-destructive w-full text-xs">{item.error}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
