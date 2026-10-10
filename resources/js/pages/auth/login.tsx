import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, Lock, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

import AuthInput, { authButtonClass } from '@/components/auth/auth-input';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Đăng nhập" description="Chào mừng trở lại! Tiếp tục làm video cho sản phẩm của bạn.">
            <Head title="Đăng nhập" />

            {status && (
                <div className="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300">
                    {status}
                </div>
            )}

            <form className="space-y-5" onSubmit={submit}>
                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <AuthInput
                        id="email"
                        icon={Mail}
                        type="email"
                        required
                        autoFocus
                        tabIndex={1}
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="ban@email.com"
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="password">Mật khẩu</Label>
                        {canResetPassword && (
                            <TextLink href={route('password.request')} className="text-sm text-orange-600 no-underline hover:underline" tabIndex={5}>
                                Quên mật khẩu?
                            </TextLink>
                        )}
                    </div>
                    <AuthInput
                        id="password"
                        icon={Lock}
                        type="password"
                        required
                        tabIndex={2}
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder="Nhập mật khẩu"
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        id="remember"
                        tabIndex={3}
                        checked={data.remember}
                        onCheckedChange={(checked) => setData('remember', checked === true)}
                    />
                    <Label htmlFor="remember" className="font-normal">
                        Ghi nhớ đăng nhập
                    </Label>
                </div>

                <Button type="submit" className={authButtonClass} tabIndex={4} disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Đăng nhập
                </Button>

                <p className="text-muted-foreground text-center text-sm">
                    Chưa có tài khoản?{' '}
                    <TextLink href={route('register')} className="font-medium text-orange-600 no-underline hover:underline" tabIndex={6}>
                        Đăng ký miễn phí
                    </TextLink>
                </p>
            </form>
        </AuthLayout>
    );
}
