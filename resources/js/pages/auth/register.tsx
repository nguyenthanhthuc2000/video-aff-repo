import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, Lock, Mail, ShieldCheck, User } from 'lucide-react';
import { FormEventHandler } from 'react';

import AuthInput, { authButtonClass } from '@/components/auth/auth-input';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type RegisterForm = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Tạo tài khoản" description="Bắt đầu làm video affiliate Shopee chỉ trong vài phút.">
            <Head title="Đăng ký" />
            <form className="space-y-4" onSubmit={submit}>
                <div className="space-y-2">
                    <Label htmlFor="name">Họ tên</Label>
                    <AuthInput
                        id="name"
                        icon={User}
                        type="text"
                        required
                        autoFocus
                        tabIndex={1}
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        disabled={processing}
                        placeholder="Nguyễn Văn A"
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <AuthInput
                        id="email"
                        icon={Mail}
                        type="email"
                        required
                        tabIndex={2}
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        disabled={processing}
                        placeholder="ban@email.com"
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="password">Mật khẩu</Label>
                        <AuthInput
                            id="password"
                            icon={Lock}
                            type="password"
                            required
                            tabIndex={3}
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            disabled={processing}
                            placeholder="Ít nhất 8 ký tự"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password_confirmation">Nhập lại mật khẩu</Label>
                        <AuthInput
                            id="password_confirmation"
                            icon={ShieldCheck}
                            type="password"
                            required
                            tabIndex={4}
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            disabled={processing}
                            placeholder="Nhập lại"
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>
                </div>

                <Button type="submit" className={`${authButtonClass} mt-2`} tabIndex={5} disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Tạo tài khoản
                </Button>

                <p className="text-muted-foreground text-center text-xs">
                    Bằng việc đăng ký, bạn đồng ý chỉ dùng ảnh người mẫu đã được người trong ảnh cho phép.
                </p>

                <p className="text-muted-foreground text-center text-sm">
                    Đã có tài khoản?{' '}
                    <TextLink href={route('login')} className="font-medium text-orange-600 no-underline hover:underline" tabIndex={6}>
                        Đăng nhập
                    </TextLink>
                </p>
            </form>
        </AuthLayout>
    );
}
