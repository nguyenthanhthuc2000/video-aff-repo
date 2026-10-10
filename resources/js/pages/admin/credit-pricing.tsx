import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { Info, Sparkles, TrendingUp } from 'lucide-react';
import { FormEventHandler } from 'react';

type Family = 'opus' | 'sonnet' | 'haiku';
type Step = 'image' | 'clip_style' | 'clip_copy' | 'render' | 'render_long';

type Settings = { markup: Record<Family, number>; min_ai_call: number; steps: Record<Step, number>; signup_bonus: number };

interface Props {
    settings: Settings;
    defaults: Settings;
    stepLabels: Record<Step, string>;
    families: { family: Family; name: string; price: { input: number; output: number } }[];
    actions: { action: string; label: string; model: string; family: Family; measured: number; input_tokens: number; output_tokens: number }[];
    usdVnd: number;
}

const number = (value: number) => Math.round(value).toLocaleString('vi-VN');
const familyStyles: Record<Family, string> = {
    opus: 'from-violet-500 to-fuchsia-500',
    sonnet: 'from-orange-500 to-rose-500',
    haiku: 'from-emerald-500 to-teal-500',
};

export default function AdminCreditPricing({ settings, defaults, stepLabels, families, actions, usdVnd }: Props) {
    const { data, setData, put, processing, errors, isDirty } = useForm<Settings>(settings);
    const fieldErrors = errors as Record<string, string | undefined>;

    const price = (family: Family) => families.find((item) => item.family === family)!.price;
    /** Chi phí API (VNĐ) cho một lượt gọi. */
    const apiVnd = (family: Family, input: number, output: number) =>
        ((input * price(family).input + output * price(family).output) / 1_000_000) * usdVnd;
    /** Credits bán ra (giống CreditPricing::forAiCall ở máy chủ, 1 Credit = 1đ). */
    const sell = (family: Family, cost: number) => Math.max(data.min_ai_call, Math.ceil((cost * (1 + data.markup[family] / 100)) / 10) * 10);

    const rows = actions.map((row) => {
        const cost = apiVnd(row.family, row.input_tokens, row.output_tokens);
        return { ...row, cost, credits: sell(row.family, cost) };
    });
    const ai = (action: string) => rows.find((row) => row.action === action)!;

    /** Video mẫu 6 cảnh, 25 giây: đọc sản phẩm, kịch bản, 1 lần viết lại prompt, 6 ảnh, xuất video. */
    const example = (motion: 'none' | 'style' | 'copy') => {
        const items = [
            { label: 'Đọc sản phẩm', cost: ai('product_import').cost, credits: ai('product_import').credits },
            { label: 'Kịch bản', cost: ai('script').cost, credits: ai('script').credits },
            { label: '1 lần viết lại prompt', cost: ai('scene_prompt_rewrite').cost, credits: ai('scene_prompt_rewrite').credits },
            ...(motion === 'copy'
                ? [{ label: 'Phân tích video mẫu', cost: ai('motion_analysis').cost, credits: ai('motion_analysis').credits }]
                : []),
            { label: '6 ảnh cảnh', cost: 0, credits: 6 * data.steps.image },
            ...(motion === 'style' ? [{ label: '6 clip chuyển động', cost: 0, credits: 6 * data.steps.clip_style }] : []),
            ...(motion === 'copy' ? [{ label: '6 clip sao chép', cost: 0, credits: 6 * data.steps.clip_copy }] : []),
            { label: 'Xuất video', cost: 0, credits: data.steps.render },
        ];
        const credits = items.reduce((sum, item) => sum + item.credits, 0);
        const cost = items.reduce((sum, item) => sum + item.cost, 0);
        return { items, credits, cost, profit: credits - cost };
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.credit-pricing.update'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="Giá bán Credits">
            <Head title="Giá bán Credits" />
            <form onSubmit={submit} className="space-y-6">
                <Heading
                    title="Giá bán Credits"
                    description="1 Credit = 1đ (nạp 10.000đ được 10.000 Credits). Đặt % lãi theo model AI và giá cố định các bước."
                />
                <FlashStatus />

                <div className="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    <span>
                        Bước AI viết chữ: Credits = chi phí API thật × (1 + % lãi), làm tròn lên 10, tối thiểu {number(data.min_ai_call)} Credits mỗi
                        lần. Ảnh, clip, xuất video: giá cố định mỗi lần (muse.ai không tính phí theo lượt, nhưng tốn thời gian, hạn mức tài khoản và
                        máy chủ). Model đang dùng chọn ở{' '}
                        <Link href={route('admin.ai-models.show')} className="underline">
                            Model AI
                        </Link>
                        ; giá API ở{' '}
                        <Link href={route('admin.ai-costs.index')} className="underline">
                            Chi phí AI
                        </Link>
                        .
                    </span>
                </div>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-medium">% lãi theo model AI</h2>
                        <Button type="button" variant="outline" size="sm" onClick={() => setData(defaults)}>
                            <Sparkles /> Dùng mức đề xuất
                        </Button>
                    </div>
                    <div className="grid gap-3 md:grid-cols-3">
                        {families.map((item) => {
                            const script = apiVnd(item.family, ai('script').input_tokens, ai('script').output_tokens);
                            return (
                                <div key={item.family} className="overflow-hidden rounded-xl border">
                                    <div className={cn('bg-gradient-to-r px-4 py-2 text-white', familyStyles[item.family])}>
                                        <p className="font-semibold">{item.name}</p>
                                        <p className="text-xs text-white/85">
                                            ${item.price.input} vào · ${item.price.output} ra / 1 triệu token
                                        </p>
                                    </div>
                                    <div className="space-y-2 p-3">
                                        <Label htmlFor={`markup-${item.family}`} className="text-xs">
                                            % lãi (đề xuất {defaults.markup[item.family]}%)
                                        </Label>
                                        <Input
                                            id={`markup-${item.family}`}
                                            type="number"
                                            min="0"
                                            value={data.markup[item.family]}
                                            onChange={(e) => setData('markup', { ...data.markup, [item.family]: Number(e.target.value) })}
                                        />
                                        <InputError message={fieldErrors[`markup.${item.family}`]} />
                                        <p className="text-muted-foreground text-xs">
                                            Một kịch bản: chi phí {number(script)}đ → bán{' '}
                                            <b className="text-foreground">{number(sell(item.family, script))} Credits</b>, lãi{' '}
                                            {number(sell(item.family, script) - script)}đ
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <div className="grid max-w-xs gap-1">
                        <Label htmlFor="min_ai_call">Tối thiểu mỗi lần gọi AI (Credits)</Label>
                        <Input
                            id="min_ai_call"
                            type="number"
                            min="0"
                            value={data.min_ai_call}
                            onChange={(e) => setData('min_ai_call', Number(e.target.value))}
                        />
                        <InputError message={errors.min_ai_call} />
                    </div>
                    <div className="grid max-w-xs gap-1">
                        <Label htmlFor="signup_bonus">Tặng khi đăng ký (Credits)</Label>
                        <Input
                            id="signup_bonus"
                            type="number"
                            min="0"
                            value={data.signup_bonus}
                            onChange={(e) => setData('signup_bonus', Number(e.target.value))}
                        />
                        <span className="text-muted-foreground text-xs">
                            Đề xuất {number(defaults.signup_bonus)} (khoảng 2–3 video ảnh tĩnh). 0 = không tặng.
                        </span>
                        <InputError message={errors.signup_bonus} />
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="font-medium">Giá các bước AI viết chữ với model đang chọn</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Bước</th>
                                    <th className="px-3 py-2 font-medium">Model</th>
                                    <th className="px-3 py-2 text-right font-medium">Token vào / ra</th>
                                    <th className="px-3 py-2 text-right font-medium">Chi phí API</th>
                                    <th className="px-3 py-2 text-right font-medium">Bán ra</th>
                                    <th className="px-3 py-2 text-right font-medium">Lãi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((row) => (
                                    <tr key={row.action}>
                                        <td className="px-3 py-2">
                                            {row.label}
                                            <p className="text-muted-foreground text-xs">
                                                {row.measured > 0 ? `TB ${row.measured} lần gần nhất` : 'Ước tính theo token mẫu'}
                                            </p>
                                        </td>
                                        <td className="px-3 py-2 text-xs">{families.find((item) => item.family === row.family)?.name}</td>
                                        <td className="px-3 py-2 text-right text-xs">
                                            {number(row.input_tokens)} / {number(row.output_tokens)}
                                        </td>
                                        <td className="px-3 py-2 text-right">{number(row.cost)}đ</td>
                                        <td className="px-3 py-2 text-right font-semibold">{number(row.credits)}</td>
                                        <td className="px-3 py-2 text-right text-emerald-600">+{number(row.credits - row.cost)}đ</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="font-medium">Giá cố định mỗi lần</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        {(Object.keys(stepLabels) as Step[]).map((step) => (
                            <div key={step} className="grid gap-1 rounded-xl border p-3">
                                <Label htmlFor={`step-${step}`} className="text-xs">
                                    {stepLabels[step]}
                                </Label>
                                <Input
                                    id={`step-${step}`}
                                    type="number"
                                    min="0"
                                    value={data.steps[step]}
                                    onChange={(e) => setData('steps', { ...data.steps, [step]: Number(e.target.value) })}
                                />
                                <span className="text-muted-foreground text-xs">Đề xuất {number(defaults.steps[step])}</span>
                                <InputError message={fieldErrors[`steps.${step}`]} />
                            </div>
                        ))}
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="flex items-center gap-2 font-medium">
                        <TrendingUp className="size-4 text-orange-500" /> Một video 6 cảnh, 25 giây
                    </h2>
                    <div className="grid gap-3 lg:grid-cols-3">
                        {(
                            [
                                ['none', 'Ảnh tĩnh'],
                                ['style', 'Chuyển động có sẵn'],
                                ['copy', 'Sao chép video mẫu'],
                            ] as const
                        ).map(([motion, label]) => {
                            const video = example(motion);
                            return (
                                <div key={motion} className="space-y-2 rounded-xl border p-4">
                                    <div className="flex items-center justify-between">
                                        <p className="font-medium">{label}</p>
                                        <Badge variant="secondary">
                                            {video.credits > 0 ? Math.round((video.profit / video.credits) * 100) : 0}% lãi gộp
                                        </Badge>
                                    </div>
                                    <ul className="text-muted-foreground space-y-0.5 text-xs">
                                        {video.items.map((item) => (
                                            <li key={item.label} className="flex justify-between gap-2">
                                                <span>{item.label}</span>
                                                <span className="text-foreground">{number(item.credits)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="border-t pt-2 text-sm">
                                        <p className="flex justify-between">
                                            <span>Người dùng trả</span>
                                            <b>
                                                {number(video.credits)} Credits = {number(video.credits)}đ
                                            </b>
                                        </p>
                                        <p className="text-muted-foreground flex justify-between text-xs">
                                            <span>Chi phí API AI viết chữ</span>
                                            <span>{number(video.cost)}đ</span>
                                        </p>
                                        <p className="flex justify-between text-emerald-600">
                                            <span>Lãi gộp</span>
                                            <b>+{number(video.profit)}đ</b>
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Lãi gộp chưa trừ chi phí cố định (gói Claude Pro/Max, tài khoản muse.ai, máy chủ). Dùng gói Claude thì chi phí API là ước
                        tính; chi phí thật mỗi lượt gần như 0 nên lãi thực cao hơn.
                    </p>
                </section>

                <div className="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-xl border bg-white/95 p-3 shadow-sm backdrop-blur dark:bg-neutral-950/95">
                    <Button disabled={processing || !isDirty}>Lưu giá bán</Button>
                </div>
            </form>
        </AdminLayout>
    );
}
