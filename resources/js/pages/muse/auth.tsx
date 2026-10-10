import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePoll } from '@/hooks/use-poll';
import AdminLayout from '@/layouts/admin-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle, LogOut, RefreshCw } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type MuseState = 'starting' | 'logged_out' | 'submitting' | 'awaiting_code' | 'awaiting_password' | 'logged_in' | 'unknown' | 'error';

type MuseStatus = { state: MuseState; message: string; email: string | null; updatedAt?: string };

const stateLabels: Record<MuseState, string> = {
    starting: 'Đang khởi động',
    logged_out: 'Chưa đăng nhập',
    submitting: 'Đang xử lý',
    awaiting_code: 'Chờ mã OTP',
    awaiting_password: 'Chờ mật khẩu',
    logged_in: 'Đã đăng nhập',
    unknown: 'Không rõ',
    error: 'Lỗi',
};

export default function MuseAuth({ enabled, status }: { enabled: boolean; status: MuseStatus }) {
    const [shot, setShot] = useState(() => Date.now());
    usePoll(['starting', 'submitting'].includes(status.state), ['status']);

    // Ảnh chụp trình duyệt ngầm cập nhật theo trạng thái.
    useEffect(() => setShot(Date.now()), [status.state, status.updatedAt]);

    return (
        <AdminLayout title="Xác thực Muse">
            <Head title="Xác thực Muse" />
            <div className="grid gap-8 p-4 lg:grid-cols-[minmax(0,420px)_1fr]">
                <div className="space-y-6">
                    <Heading
                        title="Xác thực Muse"
                        description="Trình duyệt ngầm đăng nhập muse.ai bằng tài khoản của bạn để tạo ảnh cảnh chạy ngầm. Phiên đăng nhập được giữ lại, mật khẩu không được lưu."
                    />
                    <FlashStatus />

                    {!enabled && (
                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            Đặt <code>MUSE_DRIVER=browser</code> trong .env rồi chạy <code>docker compose up -d browser</code> và{' '}
                            <code>docker compose restart app queue</code> để app dùng trình duyệt ngầm khi tạo ảnh.
                        </div>
                    )}

                    <div className="flex items-center gap-2">
                        <Badge variant={status.state === 'logged_in' ? 'default' : status.state === 'error' ? 'destructive' : 'secondary'}>
                            {stateLabels[status.state] ?? status.state}
                        </Badge>
                        {status.email && <span className="text-muted-foreground text-sm">{status.email}</span>}
                    </div>
                    <p className="text-sm">{status.message}</p>

                    {status.state === 'logged_out' && <EmailStep />}
                    {status.state === 'awaiting_code' && <CodeStep />}
                    {status.state === 'awaiting_password' && <PasswordStep />}
                    {['submitting', 'starting'].includes(status.state) && (
                        <p className="text-muted-foreground inline-flex items-center gap-2 text-sm">
                            <LoaderCircle className="size-4 animate-spin" /> Đang chờ muse.ai...
                        </p>
                    )}
                    {['unknown', 'error'].includes(status.state) && <EmailStep />}

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => router.post(route('muse.refresh'), {}, { preserveScroll: true })}>
                            <RefreshCw /> Kiểm tra lại
                        </Button>
                        {status.state === 'logged_in' && (
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                onClick={() => confirm('Đăng xuất và xoá phiên muse.ai?') && router.post(route('muse.logout'))}
                            >
                                <LogOut /> Đăng xuất
                            </Button>
                        )}
                    </div>

                    <p className="text-muted-foreground text-xs">
                        Lưu ý: điều khoản của Meta không cho phép truy cập tự động; tài khoản có thể bị yêu cầu xác minh hoặc bị giới hạn. Giao diện
                        muse.ai thay đổi thì bước tự động có thể hỏng: xem ảnh chụp bên cạnh để biết trình duyệt đang ở đâu.
                    </p>
                </div>

                <div className="space-y-2">
                    <Label>Màn hình trình duyệt ngầm</Label>
                    <img
                        key={shot}
                        src={`${route('muse.screenshot')}?t=${shot}`}
                        alt="Ảnh chụp trình duyệt ngầm"
                        className="w-full rounded-lg border"
                    />
                </div>
            </div>
        </AdminLayout>
    );
}

function EmailStep() {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('muse.login'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-2">
            <Label htmlFor="email">Email hoặc số điện thoại tài khoản muse.ai</Label>
            <Input id="email" autoComplete="username" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
            <InputError message={errors.email} />
            <Button disabled={processing}>{processing ? 'Đang gửi...' : 'Gửi mã đăng nhập'}</Button>
        </form>
    );
}

function CodeStep() {
    const { data, setData, post, processing, errors } = useForm({ code: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('muse.code'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-2">
            <Label htmlFor="code">Mã OTP gửi về email</Label>
            <Input
                id="code"
                inputMode="numeric"
                autoComplete="one-time-code"
                autoFocus
                value={data.code}
                onChange={(e) => setData('code', e.target.value)}
                className="max-w-48 text-lg tracking-widest"
                required
            />
            <InputError message={errors.code} />
            <Button disabled={processing}>{processing ? 'Đang xác thực...' : 'Xác thực'}</Button>
        </form>
    );
}

function PasswordStep() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('muse.password'), { preserveScroll: true, onFinish: () => reset('password') });
    };

    return (
        <form onSubmit={submit} className="space-y-2">
            <Label htmlFor="password">Mật khẩu</Label>
            <Input
                id="password"
                type="password"
                autoComplete="current-password"
                value={data.password}
                onChange={(e) => setData('password', e.target.value)}
                required
            />
            <InputError message={errors.password} />
            <Button disabled={processing}>{processing ? 'Đang đăng nhập...' : 'Đăng nhập'}</Button>
        </form>
    );
}
