import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, ExternalLink, LoaderCircle, LogIn, Trash2, Zap } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Xác thực Claude', href: '/claude' }];

const driverLabels: Record<string, string> = {
    claude_cli: 'Gói Claude (Claude Code)',
    claude: 'Claude API (ANTHROPIC_API_KEY)',
    web: 'Claude bản web (copy/dán tay)',
    fake: 'Giả lập (test)',
};

export default function ClaudeAuth({
    driver,
    source,
    tokenHint,
    loginUrl,
    pending,
    loginError,
}: {
    driver: string;
    source: 'ui' | 'env' | null;
    tokenHint: string | null;
    loginUrl: string | null;
    pending: boolean;
    loginError: string | null;
}) {
    // Đang đổi mã lấy token ở dịch vụ browser: hỏi lại mỗi 2 giây.
    usePoll(pending, ['driver', 'source', 'tokenHint', 'loginUrl', 'pending', 'loginError', 'flash'], 2000);
    const [starting, setStarting] = useState(false);
    const [testing, setTesting] = useState(false);
    const [copied, setCopied] = useState(false);

    // Copy link để mở ở trình duyệt/máy khác đang đăng nhập Claude.
    const copyLoginUrl = async () => {
        if (!loginUrl) {
            return;
        }
        try {
            await navigator.clipboard.writeText(loginUrl);
        } catch {
            // navigator.clipboard chỉ có trên https/localhost: dùng cách cũ khi mở app qua IP.
            const input = document.createElement('textarea');
            input.value = loginUrl;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            input.remove();
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const start = () =>
        router.post(route('claude.start'), {}, { preserveScroll: true, onStart: () => setStarting(true), onFinish: () => setStarting(false) });
    const test = () =>
        router.post(route('claude.test'), {}, { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Xác thực Claude" />
            <div className="max-w-2xl space-y-6 p-4">
                <Heading
                    title="Xác thực Claude"
                    description="Đăng nhập gói Claude Pro/Max để viết kịch bản và phân tích sản phẩm chạy ngầm, không cần API key. Lượt dùng tính vào hạn mức của gói."
                />
                <FlashStatus />

                <div className="space-y-2 rounded-lg border p-4 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground">Đang dùng:</span>
                        <Badge variant={driver === 'claude_cli' || driver === 'claude' ? 'default' : 'secondary'}>
                            {driverLabels[driver] ?? driver}
                        </Badge>
                    </div>
                    {tokenHint ? (
                        <p>
                            Token: <code>{tokenHint}</code> ({source === 'ui' ? 'đăng nhập trên giao diện' : 'từ .env'})
                        </p>
                    ) : (
                        <p className="text-muted-foreground">Chưa có token gói Claude.</p>
                    )}
                    {driver === 'claude' && (
                        <p className="text-muted-foreground text-xs">
                            Đang có ANTHROPIC_API_KEY nên app dùng Claude API; token gói chỉ dùng khi bỏ khoá API.
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2 pt-2">
                        {tokenHint && (
                            <Button variant="outline" size="sm" onClick={test} disabled={testing}>
                                <Zap /> {testing ? 'Đang kiểm tra...' : 'Kiểm tra kết nối'}
                            </Button>
                        )}
                        {source === 'ui' && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive"
                                onClick={() => confirm('Xoá token Claude?') && router.delete(route('claude.destroy'), { preserveScroll: true })}
                            >
                                <Trash2 /> Xoá token
                            </Button>
                        )}
                    </div>
                </div>

                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="font-medium">{tokenHint ? 'Đăng nhập lại' : 'Đăng nhập'}</h2>
                    <ol className="text-muted-foreground list-decimal space-y-2 pl-5 text-sm">
                        <li>
                            <Button size="sm" onClick={start} disabled={starting}>
                                <LogIn /> {starting ? 'Đang lấy link...' : 'Bắt đầu đăng nhập'}
                            </Button>
                        </li>
                        <li>
                            Mở link đăng nhập, đăng nhập tài khoản Claude có gói Pro/Max và bấm <span className="text-foreground">Authorize</span>.
                            {loginUrl && (
                                <div className="flex flex-wrap gap-2 pt-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={loginUrl} target="_blank" rel="noreferrer">
                                            <ExternalLink /> Mở trang đăng nhập Claude
                                        </a>
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={copyLoginUrl}>
                                        {copied ? <Check /> : <Copy />} {copied ? 'Đã copy link' : 'Copy link đăng nhập'}
                                    </Button>
                                </div>
                            )}
                        </li>
                        <li>Copy mã hiện ra sau khi đăng nhập và dán vào ô dưới đây.</li>
                    </ol>
                    {pending ? (
                        <p className="text-muted-foreground inline-flex items-center gap-2 text-sm">
                            <LoaderCircle className="size-4 animate-spin" /> Đang xác thực mã với Claude...
                        </p>
                    ) : (
                        loginUrl && <CodeForm />
                    )}
                    <InputError message={loginError ?? undefined} />
                </section>

                <details className="rounded-lg border p-4 text-sm">
                    <summary className="cursor-pointer font-medium">Dán token có sẵn</summary>
                    <p className="text-muted-foreground py-2">
                        Nếu đã chạy <code>claude setup-token</code> trên máy của bạn, dán token (bắt đầu bằng <code>sk-ant-oat</code>) vào đây.
                    </p>
                    <TokenForm />
                </details>
            </div>
        </AppLayout>
    );
}

function CodeForm() {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('claude.code'), { preserveScroll: true, onFinish: () => reset('code') });
    };

    return (
        <form onSubmit={submit} className="space-y-2">
            <Label htmlFor="code">Mã đăng nhập</Label>
            <Input id="code" autoComplete="off" value={data.code} onChange={(e) => setData('code', e.target.value)} required />
            <InputError message={errors.code} />
            <Button disabled={processing}>{processing ? 'Đang xác thực...' : 'Xác thực'}</Button>
        </form>
    );
}

function TokenForm() {
    const { data, setData, post, processing, errors, reset } = useForm({ token: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('claude.token'), { preserveScroll: true, onSuccess: () => reset('token') });
    };

    return (
        <form onSubmit={submit} className="space-y-2">
            <Input
                type="password"
                autoComplete="off"
                value={data.token}
                onChange={(e) => setData('token', e.target.value)}
                placeholder="sk-ant-oat..."
                required
            />
            <InputError message={errors.token} />
            <Button variant="outline" disabled={processing}>
                Lưu token
            </Button>
        </form>
    );
}
