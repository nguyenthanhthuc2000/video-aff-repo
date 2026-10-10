import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import { Check, Gauge, Info, Sparkles, Wallet } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ModelOption {
    id: string;
    name: string;
    family: string;
    hint: string;
    price: { input: number; output: number };
}

interface ActionRow {
    action: string;
    label: string;
    hint: string;
    recommended: string;
    last_model: string | null;
    tokens: { input: number; cache_write: number; cache_read: number; output: number };
    measured_calls: number;
    month_calls: number;
    month_cost_usd: number;
    estimates: Record<string, number>;
}

type Settings = { default: string | null; actions: Record<string, string | null> };

interface Props {
    settings: Settings;
    models: ModelOption[];
    actions: ActionRow[];
    envModel: string | null;
    driver: string;
    usdVnd: number;
}

const usd = (value: number) => `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: value < 1 ? 4 : 2 })}`;
const number = (value: number) => value.toLocaleString('vi-VN');

const familyStyles: Record<string, string> = {
    opus: 'from-violet-500 to-fuchsia-500',
    sonnet: 'from-orange-500 to-rose-500',
    haiku: 'from-emerald-500 to-teal-500',
};

export default function AdminAiModels({ settings, models, actions, envModel, driver, usdVnd }: Props) {
    const { data, setData, put, processing, isDirty } = useForm<Settings>(settings);
    const vnd = (value: number) => `${Math.round(value * usdVnd).toLocaleString('vi-VN')}đ`;
    const modelName = (id: string | null) => models.find((model) => model.id === id)?.name ?? id ?? 'Mặc định của Claude Code';

    /** Model thực sự dùng cho thao tác (giống ClaudeModels::for ở máy chủ). */
    const effective = (action: string) => data.actions[action] ?? data.default ?? envModel;

    const presets: { label: string; hint: string; icon: typeof Sparkles; apply: () => Settings }[] = [
        {
            label: 'Chất lượng cao nhất',
            hint: 'Mọi việc dùng Opus',
            icon: Sparkles,
            apply: () => ({ default: 'claude-opus-5-5', actions: Object.fromEntries(actions.map((row) => [row.action, null])) }),
        },
        {
            label: 'Cân bằng (gợi ý)',
            hint: 'Việc quan trọng dùng Sonnet, việc đơn giản dùng Haiku',
            icon: Gauge,
            apply: () => ({ default: 'claude-sonnet-5-5', actions: Object.fromEntries(actions.map((row) => [row.action, row.recommended])) }),
        },
        {
            label: 'Tiết kiệm nhất',
            hint: 'Mọi việc dùng Haiku',
            icon: Wallet,
            apply: () => ({ default: 'claude-haiku-5-5', actions: Object.fromEntries(actions.map((row) => [row.action, null])) }),
        },
    ];

    const monthly = (model: (row: ActionRow) => string | null) =>
        actions.reduce((sum, row) => {
            const id = model(row);
            return sum + (id ? (row.estimates[id] ?? 0) : 0) * row.month_calls;
        }, 0);
    const plannedMonthly = monthly((row) => effective(row.action));
    const opusMonthly = monthly(() => 'claude-opus-5-5');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.ai-models.update'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="Model AI">
            <Head title="Model AI" />
            <form onSubmit={submit} className="space-y-6">
                <Heading
                    title="Model AI"
                    description="Chọn model Claude cho từng việc: việc quan trọng dùng model mạnh, việc đơn giản dùng model rẻ."
                />
                <FlashStatus />

                {driver !== 'claude' && driver !== 'claude_cli' && (
                    <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        Hệ thống chưa kết nối Claude nên cài đặt này chưa có tác dụng. Kết nối ở mục Xác thực Claude.
                    </div>
                )}

                {/* Model có sẵn */}
                <div className="grid gap-3 md:grid-cols-3">
                    {models.map((model) => (
                        <div key={model.id} className="overflow-hidden rounded-xl border">
                            <div className={cn('bg-gradient-to-r px-4 py-3 text-white', familyStyles[model.family])}>
                                <p className="font-semibold">{model.name}</p>
                                <p className="text-xs text-white/85">{model.hint}</p>
                            </div>
                            <p className="text-muted-foreground px-4 py-2 text-xs">
                                ${model.price.input} vào · ${model.price.output} ra / 1 triệu token
                            </p>
                        </div>
                    ))}
                </div>

                {/* Gói cài đặt nhanh */}
                <section className="space-y-3">
                    <h2 className="font-medium">Chọn nhanh</h2>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {presets.map((preset) => (
                            <button
                                key={preset.label}
                                type="button"
                                onClick={() => setData(preset.apply())}
                                className="hover:bg-muted flex items-start gap-3 rounded-xl border p-3 text-left transition"
                            >
                                <preset.icon className="mt-0.5 size-5 text-orange-500" />
                                <span>
                                    <span className="block text-sm font-medium">{preset.label}</span>
                                    <span className="text-muted-foreground text-xs">{preset.hint}</span>
                                </span>
                            </button>
                        ))}
                    </div>
                </section>

                {/* Mặc định */}
                <section className="space-y-2 rounded-xl border p-4">
                    <h2 className="font-medium">Model mặc định</h2>
                    <p className="text-muted-foreground text-sm">Dùng cho việc không chọn model riêng (và nút kiểm tra kết nối).</p>
                    <ModelPicker
                        models={models}
                        value={data.default}
                        onChange={(value) => setData('default', value)}
                        emptyLabel={envModel ? `Theo cấu hình máy chủ (${modelName(envModel)})` : 'Mặc định của Claude Code'}
                    />
                </section>

                {/* Từng thao tác */}
                <section className="space-y-3">
                    <h2 className="font-medium">Theo từng việc</h2>
                    {actions.map((row) => {
                        const current = effective(row.action);
                        const cheapest = Math.min(...Object.values(row.estimates));
                        return (
                            <div key={row.action} className="space-y-3 rounded-xl border p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-medium">{row.label}</p>
                                        <p className="text-muted-foreground text-sm">{row.hint}</p>
                                    </div>
                                    <div className="text-right text-xs">
                                        <p>
                                            30 ngày: <b>{number(row.month_calls)}</b> lần · <b>{usd(row.month_cost_usd)}</b>
                                        </p>
                                        {row.last_model && <p className="text-muted-foreground">Lần gần nhất dùng {modelName(row.last_model)}</p>}
                                    </div>
                                </div>

                                <ModelPicker
                                    models={models}
                                    value={data.actions[row.action] ?? null}
                                    onChange={(value) => setData('actions', { ...data.actions, [row.action]: value })}
                                    emptyLabel={`Theo mặc định (${modelName(data.default ?? envModel)})`}
                                    recommended={row.recommended}
                                    estimates={row.estimates}
                                    cheapest={cheapest}
                                    vnd={vnd}
                                />

                                <p className="text-muted-foreground text-xs">
                                    Đang chọn: <b className="text-foreground">{modelName(current)}</b>
                                    {current && row.estimates[current] !== undefined && (
                                        <>
                                            {' '}
                                            · khoảng <b className="text-foreground">{usd(row.estimates[current])}</b> ({vnd(row.estimates[current])})
                                            / lần
                                        </>
                                    )}
                                    {' · '}
                                    {row.measured_calls > 0
                                        ? `Ước tính theo trung bình ${number(row.tokens.input + row.tokens.cache_write + row.tokens.cache_read)} token vào, ${number(row.tokens.output)} token ra của ${row.measured_calls} lần gọi gần nhất.`
                                        : `Chưa có số liệu token, đang ước tính theo mẫu ${number(row.tokens.input)} vào / ${number(row.tokens.output)} ra.`}
                                </p>
                            </div>
                        );
                    })}
                </section>

                {/* Dự tính tháng */}
                <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-white/95 p-4 shadow-sm backdrop-blur dark:bg-neutral-950/95">
                    <div className="text-sm">
                        <p>
                            Dự tính 30 ngày với lựa chọn này: <b className="text-lg">{usd(plannedMonthly)}</b>{' '}
                            <span className="text-muted-foreground">({vnd(plannedMonthly)})</span>
                        </p>
                        <p className="text-muted-foreground text-xs">
                            Theo số lần gọi 30 ngày qua.
                            {opusMonthly > plannedMonthly && ` Tiết kiệm ${usd(opusMonthly - plannedMonthly)} so với dùng toàn Opus.`}
                            {driver === 'claude_cli' &&
                                ' Đang dùng gói Claude Pro/Max: đây là chi phí ước tính theo giá API; model rẻ hơn giúp đỡ chạm giới hạn gói.'}
                        </p>
                    </div>
                    <Button disabled={processing || !isDirty}>Lưu model</Button>
                </div>
            </form>
        </AdminLayout>
    );
}

function ModelPicker({
    models,
    value,
    onChange,
    emptyLabel,
    recommended,
    estimates,
    cheapest,
    vnd,
}: {
    models: ModelOption[];
    value: string | null;
    onChange: (value: string | null) => void;
    emptyLabel: string;
    recommended?: string;
    estimates?: Record<string, number>;
    cheapest?: number;
    vnd?: (value: number) => string;
}) {
    const options = [
        { id: null, name: emptyLabel, family: null as string | null },
        ...models.map((model) => ({ ...model, family: model.family as string | null })),
    ];

    return (
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            {options.map((option) => {
                const active = value === option.id;
                const estimate = option.id ? estimates?.[option.id] : undefined;
                return (
                    <button
                        key={option.id ?? 'default'}
                        type="button"
                        onClick={() => onChange(option.id)}
                        className={cn(
                            'relative rounded-lg border p-3 text-left transition',
                            active ? 'border-orange-500 bg-orange-50 ring-1 ring-orange-500 dark:bg-orange-950/40' : 'hover:bg-muted',
                        )}
                    >
                        {active && <Check className="absolute top-2 right-2 size-4 text-orange-500" />}
                        <span className="flex flex-wrap items-center gap-1 pr-5 text-sm font-medium">
                            {option.id && <span className={cn('size-2.5 rounded-full bg-gradient-to-r', familyStyles[option.family ?? ''])} />}
                            {option.name}
                        </span>
                        {estimate !== undefined && (
                            <span className="text-muted-foreground mt-1 block text-xs">
                                ~{usd(estimate)} / lần{vnd && ` · ${vnd(estimate)}`}
                            </span>
                        )}
                        <span className="mt-1 flex flex-wrap gap-1">
                            {recommended === option.id && <Badge>Gợi ý</Badge>}
                            {estimate !== undefined && estimate === cheapest && <Badge variant="secondary">Rẻ nhất</Badge>}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
