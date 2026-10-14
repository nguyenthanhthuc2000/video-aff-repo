import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Info, KeyRound, Loader2, Plug, Save } from 'lucide-react';
import { useState } from 'react';

type KeyInfo = {
    label: string;
    masked: string | null;
    source: 'ui' | 'env' | null;
    status: { ok: boolean; message: string; checked_at: string } | null;
};

type Option = { label: string; available: boolean; note: string; cost: string };
type Step = { label: string; selected: string; options: Record<string, Option> };

const keyHints: Record<string, string> = {
    minimax: 'platform.minimax.io → Account Management → API Keys. Chọn vùng Global nếu tài khoản tạo ở minimax.io, China nếu ở minimaxi.com.',
    fal: 'fal.ai → Dashboard → Keys.',
    anthropic: 'console.anthropic.com → API Keys. Không cần nếu đã đăng nhập gói Claude ở "Xác thực Claude".',
};

/** Kết nối AI: nhập khoá (mã hoá trong database), kiểm tra kết nối miễn phí, chọn công cụ cho từng bước và xem chi phí ước tính. */
export default function AiConnections({
    keys,
    minimaxRegion,
    steps,
}: {
    keys: Record<string, KeyInfo>;
    minimaxRegion: string;
    steps: Record<string, Step>;
}) {
    const keyForm = useForm({
        keys: Object.fromEntries(Object.keys(keys).map((k) => [k, ''])) as Record<string, string>,
        clear: [] as string[],
        minimax_region: minimaxRegion,
    });
    const providerForm = useForm({ providers: Object.fromEntries(Object.entries(steps).map(([k, s]) => [k, s.selected])) as Record<string, string> });
    const [checking, setChecking] = useState<string | null>(null);
    const check = (provider: string) =>
        router.post(
            route('admin.ai-connections.check', provider),
            {},
            { preserveScroll: true, onStart: () => setChecking(provider), onFinish: () => setChecking(null) },
        );

    return (
        <AdminLayout title="Kết nối AI">
            <Head title="Kết nối AI" />
            <div className="max-w-4xl space-y-6">
                <Heading title="Kết nối AI" description="Nhập khoá API, kiểm tra kết nối, chọn công cụ cho từng bước làm MV / Shorts." />
                <FlashStatus />

                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="flex items-center gap-2 font-medium">
                        <KeyRound className="size-4" /> Khoá API
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        Khoá được mã hoá trong database (như token Claude), không hiện lại trên giao diện, không ghi log. Để trống thì giữ khoá cũ;
                        khoá trong .env dùng khi chưa nhập ở đây. Kiểm tra kết nối chỉ gọi lệnh liệt kê model (miễn phí).
                    </p>
                    {Object.entries(keys).map(([provider, info]) => (
                        <div key={provider} className="space-y-2 rounded-md border p-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{info.label}</span>
                                {info.masked ? (
                                    <Badge variant="secondary">
                                        {info.masked} · {info.source === 'ui' ? 'nhập ở đây' : '.env'}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">Chưa có khoá</Badge>
                                )}
                                {info.status && (
                                    <Badge variant={info.status.ok ? 'default' : 'destructive'}>
                                        {info.status.ok ? 'Đang hoạt động' : info.status.message}
                                    </Badge>
                                )}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="ml-auto"
                                    disabled={!info.masked || checking === provider}
                                    onClick={() => check(provider)}
                                >
                                    {checking === provider ? <Loader2 className="animate-spin" /> : <Plug />} Kiểm tra kết nối
                                </Button>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Input
                                    type="password"
                                    autoComplete="off"
                                    className="max-w-md"
                                    value={keyForm.data.keys[provider]}
                                    onChange={(e) => keyForm.setData('keys', { ...keyForm.data.keys, [provider]: e.target.value })}
                                    placeholder={info.masked ? 'Dán khoá mới để thay' : 'Dán khoá'}
                                />
                                {provider === 'minimax' && (
                                    <select
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        value={keyForm.data.minimax_region}
                                        onChange={(e) => keyForm.setData('minimax_region', e.target.value)}
                                    >
                                        <option value="global">Global (api.minimax.io)</option>
                                        <option value="china">China (api.minimaxi.com)</option>
                                    </select>
                                )}
                                {info.source === 'ui' && (
                                    <label className="flex items-center gap-1.5 text-xs">
                                        <Checkbox
                                            checked={keyForm.data.clear.includes(provider)}
                                            onCheckedChange={(c) =>
                                                keyForm.setData(
                                                    'clear',
                                                    c === true ? [...keyForm.data.clear, provider] : keyForm.data.clear.filter((p) => p !== provider),
                                                )
                                            }
                                        />
                                        Xoá khoá đã nhập
                                    </label>
                                )}
                            </div>
                            <p className="text-muted-foreground text-xs">{keyHints[provider]}</p>
                        </div>
                    ))}
                    <Button
                        type="button"
                        disabled={keyForm.processing}
                        onClick={() =>
                            keyForm.put(route('admin.ai-connections.keys'), { preserveScroll: true, onSuccess: () => keyForm.reset('keys', 'clear') })
                        }
                    >
                        <Save /> Lưu khoá
                    </Button>
                </section>

                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="font-medium">Công cụ cho từng bước</h2>
                    <div className="flex items-start gap-2 text-xs">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        Mặc định dùng MiniMax khi khoá MiniMax kiểm tra được; không thì Muse qua trình duyệt. Chi phí là giá niêm yết tham khảo, trừ
                        vào tài khoản của nhà cung cấp.
                    </div>
                    {Object.entries(steps).map(([step, info]) => (
                        <div key={step} className="space-y-1.5 rounded-md border p-3 text-sm">
                            <p className="font-medium">{info.label}</p>
                            {Object.entries(info.options).map(([key, option]) => (
                                <label key={key} className={`flex items-start gap-2 text-xs ${option.available ? 'cursor-pointer' : 'opacity-60'}`}>
                                    <input
                                        type="radio"
                                        className="mt-0.5"
                                        disabled={!option.available}
                                        checked={providerForm.data.providers[step] === key}
                                        onChange={() => providerForm.setData('providers', { ...providerForm.data.providers, [step]: key })}
                                    />
                                    <span>
                                        <span className="font-medium">{option.label}</span>{' '}
                                        <span className="text-muted-foreground">· {option.cost}</span>
                                        <span className="text-muted-foreground block">{option.note}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    ))}
                    <Button
                        type="button"
                        disabled={providerForm.processing}
                        onClick={() => providerForm.put(route('admin.ai-connections.providers'), { preserveScroll: true })}
                    >
                        <Save /> Lưu lựa chọn
                    </Button>
                </section>
            </div>
        </AdminLayout>
    );
}
