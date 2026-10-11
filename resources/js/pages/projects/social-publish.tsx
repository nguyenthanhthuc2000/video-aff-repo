import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type SocialAccount, type SocialPost, type VideoProject } from '@/types/models';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Send } from 'lucide-react';
import { FormEventHandler } from 'react';

const statusLabels: Record<SocialPost['status'], string> = {
    pending: 'Chờ đăng',
    uploading: 'Đang tải video lên',
    processing: 'Facebook đang xử lý',
    published: 'Đã đăng',
    failed: 'Lỗi',
};

/** Đăng video đã xuất lên Trang Facebook đã kết nối, chọn caption từ đề xuất hoặc tự viết. */
export default function SocialPublish({ project, accounts }: { project: VideoProject; accounts: SocialAccount[] }) {
    const suggestions = [...(project.post_captions?.sale ?? []), ...(project.post_captions?.view ?? [])];
    const { data, setData, post, processing, errors } = useForm({
        account_ids: accounts.length === 1 ? [accounts[0].id] : ([] as number[]),
        caption: suggestions[0] ?? '',
    });
    const posts = project.social_posts ?? [];
    const hasVideo = project.renders?.some((render) => render.status === 'done' && render.url) ?? false;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.social-posts.store', project.id), { preserveScroll: true });
    };
    const toggle = (id: number, checked: boolean) =>
        setData('account_ids', checked ? [...data.account_ids, id] : data.account_ids.filter((value) => value !== id));

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <p className="text-sm font-medium">Đăng lên Facebook (Reels)</p>
            {accounts.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Chưa kết nối Trang Facebook.{' '}
                    <Link href={route('social.index')} className="underline">
                        Kết nối tài khoản
                    </Link>
                </p>
            ) : !hasVideo ? (
                <p className="text-muted-foreground text-sm">Xuất video xong là đăng được.</p>
            ) : (
                <form onSubmit={submit} className="space-y-3">
                    <div className="flex flex-wrap gap-4">
                        {accounts.map((account) => (
                            <label key={account.id} className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.account_ids.includes(account.id)} onCheckedChange={(checked) => toggle(account.id, checked === true)} />
                                {account.name}
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
                    <Button type="submit" disabled={processing || data.account_ids.length === 0 || data.caption.trim() === ''}>
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
