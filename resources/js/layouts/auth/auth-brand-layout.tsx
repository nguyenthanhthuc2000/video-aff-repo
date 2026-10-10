import AppLogoIcon from '@/components/app-logo-icon';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Clapperboard, ImageIcon, Sparkles, Wand2 } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

const highlights = [
    { icon: Sparkles, text: 'AI viết kịch bản, 5 hook theo chủ đề' },
    { icon: Wand2, text: 'Người mẫu mặc đúng sản phẩm, nhảy trend, biến hình' },
    { icon: Clapperboard, text: 'Xuất video 9:16 có phụ đề và nhạc' },
];

/** Trang đăng nhập / đăng ký: thẻ lớn chia đôi giữa màn hình, bên trái minh hoạ sản phẩm, bên phải form. */
export default function AuthBrandLayout({ children, title, description }: AuthLayoutProps) {
    const { name } = usePage<SharedData>().props;

    return (
        <div className="relative flex min-h-dvh items-center justify-center overflow-hidden bg-zinc-50 p-4 sm:p-8 dark:bg-zinc-950">
            {/* Quầng màu nền */}
            <div className="pointer-events-none absolute -top-40 -left-40 size-[32rem] rounded-full bg-orange-300/30 blur-3xl dark:bg-orange-500/10" />
            <div className="pointer-events-none absolute -right-40 -bottom-40 size-[32rem] rounded-full bg-rose-300/30 blur-3xl dark:bg-rose-500/10" />

            <div className="relative grid w-full max-w-5xl overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-2xl shadow-zinc-900/10 lg:grid-cols-2 dark:border-zinc-800 dark:bg-zinc-900">
                {/* Bên trái: minh hoạ */}
                <aside className="relative hidden flex-col justify-between overflow-hidden bg-zinc-950 p-10 text-white lg:flex">
                    <div className="absolute -top-24 -right-24 size-80 rounded-full bg-orange-500/40 blur-3xl" />
                    <div className="absolute -bottom-24 -left-16 size-80 rounded-full bg-rose-600/40 blur-3xl" />

                    <Link href={route('home')} className="relative flex items-center gap-2 font-semibold">
                        <span className="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-500">
                            <AppLogoIcon className="size-5 fill-current" />
                        </span>
                        {name}
                    </Link>

                    <AppPreview />

                    <div className="relative space-y-5">
                        <h2 className="text-2xl leading-snug font-semibold text-balance">
                            Video bán hàng cho Shopee, không cần quay, không cần dựng.
                        </h2>
                        <ul className="space-y-2.5 text-sm text-zinc-300">
                            {highlights.map((item) => (
                                <li key={item.text} className="flex items-center gap-3">
                                    <span className="flex size-7 items-center justify-center rounded-lg bg-white/10">
                                        <item.icon className="size-4 text-orange-400" />
                                    </span>
                                    {item.text}
                                </li>
                            ))}
                        </ul>
                    </div>
                </aside>

                {/* Bên phải: form */}
                <main className="flex flex-col justify-center px-6 py-10 sm:px-12 sm:py-14">
                    <Link href={route('home')} className="mb-8 flex items-center gap-2 font-semibold lg:hidden">
                        <span className="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-500 text-white">
                            <AppLogoIcon className="size-5 fill-current" />
                        </span>
                        {name}
                    </Link>
                    <div className="mb-8 space-y-2">
                        <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
                        {description && <p className="text-muted-foreground">{description}</p>}
                    </div>
                    {children}
                </main>
            </div>
        </div>
    );
}

/** Minh hoạ màn hình ứng dụng: khung video dọc và các thẻ trạng thái. */
function AppPreview() {
    return (
        <div className="relative mx-auto my-8 flex w-full max-w-sm items-center justify-center">
            <div className="relative aspect-[9/16] w-44 overflow-hidden rounded-[1.75rem] border-4 border-zinc-800 bg-gradient-to-b from-amber-100 via-rose-200 to-orange-300 shadow-2xl">
                <div className="absolute inset-x-0 top-[16%] flex flex-col items-center">
                    <div className="size-10 rounded-full bg-gradient-to-b from-amber-200 to-amber-300" />
                    <div className="mt-1.5 h-28 w-16 rounded-t-[2rem] rounded-b-xl bg-gradient-to-b from-rose-400 to-orange-400" />
                </div>
                <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" />
                <p className="absolute inset-x-3 bottom-12 text-center text-xs leading-tight font-extrabold">Mặc đi đâu cũng được khen</p>
                <div className="absolute inset-x-2 bottom-2 flex items-center gap-1.5 rounded-lg bg-white/90 p-1.5 text-[8px] text-zinc-800">
                    <span className="size-5 rounded bg-gradient-to-br from-rose-300 to-orange-300" />
                    <span className="flex-1 font-semibold">Xem trong giỏ hàng</span>
                    <span className="rounded bg-orange-500 px-1.5 py-0.5 font-bold text-white">Mua</span>
                </div>
            </div>

            <div className="absolute top-6 -left-2 rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs backdrop-blur">
                <p className="flex items-center gap-1.5 font-semibold">
                    <ImageIcon className="size-3.5 text-orange-400" /> Đang tạo ảnh 4/6
                </p>
                <div className="mt-1.5 h-1 w-28 overflow-hidden rounded bg-white/20">
                    <div className="h-full w-2/3 bg-gradient-to-r from-orange-400 to-rose-400" />
                </div>
            </div>
            <div className="absolute top-[42%] -right-2 rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs backdrop-blur">
                <p className="flex items-center gap-1.5 font-semibold">
                    <Sparkles className="size-3.5 text-orange-400" /> 5 hook đề xuất
                </p>
                <p className="text-zinc-300">Tò mò · Xu hướng · Review</p>
            </div>
        </div>
    );
}
