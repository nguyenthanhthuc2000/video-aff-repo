import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type SocialAccount } from '@/types/models';
import { Head, router, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Kết nối mạng xã hội', href: '/settings/social' }];

/** Kết nối Trang Facebook / tài khoản TikTok để đăng video từ trang dự án. */
function AccountList({ accounts, onDisconnect }: { accounts: SocialAccount[]; onDisconnect: (account: SocialAccount) => void }) {
    if (accounts.length === 0) return null;

    return (
        <ul className="divide-y rounded-lg border">
            {accounts.map((account) => (
                <li key={account.id} className="flex items-center justify-between gap-2 p-3 text-sm">
                    <span className="flex items-center gap-2">
                        {account.avatar_url && <img src={account.avatar_url} alt="" className="size-6 rounded-full" />}
                        {account.name}
                        {account.provider === 'facebook' && <span className="text-muted-foreground text-xs">(Trang {account.external_id})</span>}
                        {account.expired && <span className="text-destructive text-xs">Hết hạn, hãy kết nối lại</span>}
                    </span>
                    <Button size="sm" variant="ghost" className="text-destructive" onClick={() => onDisconnect(account)}>
                        Ngắt kết nối
                    </Button>
                </li>
            ))}
        </ul>
    );
}

export default function Social({
    accounts,
    facebookConfigured,
    facebookRedirectUri,
    tiktokConfigured,
    tiktokRedirectUri,
}: {
    accounts: SocialAccount[];
    facebookConfigured: boolean;
    facebookRedirectUri: string;
    tiktokConfigured: boolean;
    tiktokRedirectUri: string;
}) {
    const facebook = accounts.filter((account) => account.provider === 'facebook');
    const tiktok = accounts.filter((account) => account.provider === 'tiktok');
    const status = (usePage<SharedData>().props as { flash?: { status?: string } }).flash?.status;
    const connect = () => router.post(route('social.facebook.connect'));
    const disconnect = (account: SocialAccount) => {
        if (confirm(`Ngắt kết nối ${account.name}?`)) {
            router.delete(route('social.accounts.destroy', account.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kết nối mạng xã hội" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Trang Facebook" description="Kết nối Trang để đăng video dạng Reels ngay từ trang dự án." />
                    {status && <p className="text-sm">{status}</p>}
                    {!facebookConfigured ? (
                        <div className="text-muted-foreground space-y-1 text-sm">
                            <p>Chưa cài app Facebook. Thêm FACEBOOK_APP_ID và FACEBOOK_APP_SECRET vào file .env rồi khởi động lại app.</p>
                            <p>
                                URI chuyển hướng OAuth cần khai báo trong app Facebook: <code className="bg-muted rounded px-1">{facebookRedirectUri}</code>
                            </p>
                        </div>
                    ) : (
                        <Button onClick={connect}>{facebook.length ? 'Kết nối thêm / làm mới Trang' : 'Kết nối Facebook'}</Button>
                    )}
                    <AccountList accounts={facebook} onDisconnect={disconnect} />

                    <HeadingSmall title="TikTok" description="Kết nối tài khoản TikTok để đăng thẳng hoặc gửi video vào hộp nháp TikTok." />
                    {!tiktokConfigured ? (
                        <div className="text-muted-foreground space-y-1 text-sm">
                            <p>Chưa cài app TikTok. Thêm TIKTOK_CLIENT_KEY và TIKTOK_CLIENT_SECRET vào file .env rồi khởi động lại app.</p>
                            <p>
                                Redirect URI cần khai báo trong app TikTok (phải là https): <code className="bg-muted rounded px-1">{tiktokRedirectUri}</code>
                            </p>
                        </div>
                    ) : (
                        <Button onClick={() => router.post(route('social.tiktok.connect'))}>{tiktok.length ? 'Kết nối thêm tài khoản TikTok' : 'Kết nối TikTok'}</Button>
                    )}
                    <AccountList accounts={tiktok} onDisconnect={disconnect} />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
