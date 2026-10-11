import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type SocialAccount } from '@/types/models';
import { Head, router, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Kết nối mạng xã hội', href: '/settings/social' }];

/** Kết nối Trang Facebook để đăng video (Reels) từ trang dự án. */
export default function Social({
    accounts,
    facebookConfigured,
    facebookRedirectUri,
}: {
    accounts: SocialAccount[];
    facebookConfigured: boolean;
    facebookRedirectUri: string;
}) {
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
                        <Button onClick={connect}>{accounts.length ? 'Kết nối thêm / làm mới Trang' : 'Kết nối Facebook'}</Button>
                    )}
                    {accounts.length > 0 && (
                        <ul className="divide-y rounded-lg border">
                            {accounts.map((account) => (
                                <li key={account.id} className="flex items-center justify-between gap-2 p-3 text-sm">
                                    <span>
                                        {account.name} <span className="text-muted-foreground text-xs">(Trang {account.external_id})</span>
                                    </span>
                                    <Button size="sm" variant="ghost" className="text-destructive" onClick={() => disconnect(account)}>
                                        Ngắt kết nối
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
