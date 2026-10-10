import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff, type LucideIcon } from 'lucide-react';
import { type ComponentProps, useState } from 'react';

/** Ô nhập của trang đăng nhập / đăng ký: cao hơn, có biểu tượng bên trái, ô mật khẩu có nút hiện / ẩn. */
export default function AuthInput({ icon: Icon, type, className, ...props }: ComponentProps<'input'> & { icon: LucideIcon }) {
    const [visible, setVisible] = useState(false);
    const isPassword = type === 'password';

    return (
        <div className="relative">
            <Icon className="text-muted-foreground pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2" />
            <Input
                type={isPassword && visible ? 'text' : type}
                className={cn(
                    'h-11 rounded-xl pl-10 focus-visible:border-orange-400 focus-visible:ring-orange-400/30',
                    isPassword && 'pr-10',
                    className,
                )}
                {...props}
            />
            {isPassword && (
                <button
                    type="button"
                    tabIndex={-1}
                    onClick={() => setVisible((value) => !value)}
                    className="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2"
                    aria-label={visible ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                >
                    {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
            )}
        </div>
    );
}

/** Nút chính của trang đăng nhập / đăng ký. */
export const authButtonClass =
    'h-11 w-full rounded-xl bg-gradient-to-r from-orange-500 to-rose-500 text-base font-semibold text-white shadow-lg shadow-orange-500/25 transition hover:opacity-90';
