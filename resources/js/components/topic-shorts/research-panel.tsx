import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { BookCheck, ExternalLink, Loader2, RefreshCw, ShieldCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

export interface ResearchFact {
    id: string;
    kind: 'fact' | 'quote' | 'theory';
    era: string;
    text: string;
    source_url: string;
    source_title: string;
    characters: string[];
    enabled: boolean;
    lore_id: number | null;
}

export interface Research {
    summary: string;
    facts: ResearchFact[];
    sources: { url: string; title: string }[];
}

export interface FactCheck {
    status: 'supported' | 'inferred' | 'unsupported' | 'error';
    note: string;
}

const kindLabels = { fact: 'Sự kiện', quote: 'Câu thoại', theory: 'Giả thuyết' } as const;

const checkStyles: Record<FactCheck['status'], { label: string; className: string }> = {
    supported: { label: 'Có nguồn', className: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    inferred: { label: 'Suy ra', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    unsupported: { label: 'Không có nguồn', className: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
    error: { label: 'Chưa kiểm chứng', className: 'bg-muted text-muted-foreground' },
};

/** Bản tư liệu Claude tìm trên web: giữ / bỏ / sửa từng chi tiết, ghi chú, rồi duyệt để viết kịch bản. */
export function ResearchPanel({
    shortId,
    status,
    research,
    notes,
    seriesParts,
    hasScript,
}: {
    shortId: number;
    status: string;
    research: Research | null;
    notes: string | null;
    seriesParts: number;
    hasScript: boolean;
}) {
    const { data, setData, post, processing, errors } = useForm({
        facts: (research?.facts ?? []).map((f) => ({ id: f.id, enabled: f.enabled, text: f.text })),
        research_notes: notes ?? '',
    });
    const fieldErrors = errors as Record<string, string | undefined>;
    const researching = status === 'researching';
    const kept = data.facts.filter((f) => f.enabled).length;

    const setFact = (index: number, patch: Partial<{ enabled: boolean; text: string }>) =>
        setData(
            'facts',
            data.facts.map((fact, i) => (i === index ? { ...fact, ...patch } : fact)),
        );
    const approve: FormEventHandler = (e) => {
        e.preventDefault();
        if (!hasScript || confirm('Claude viết lại kịch bản theo tư liệu đã duyệt, các cảnh và ảnh hiện tại sẽ bị thay. Tiếp tục?')) {
            post(route('topic-shorts.research.approve', shortId), { preserveScroll: true });
        }
    };
    const again = () => {
        if (confirm('Claude tìm lại tư liệu từ đầu (theo ghi chú bên dưới). Tiếp tục?')) {
            router.post(route('topic-shorts.research', shortId), { research_notes: data.research_notes }, { preserveScroll: true });
        }
    };

    if (researching) {
        return (
            <section className="flex items-center gap-2 rounded-lg border p-4 text-sm">
                <Loader2 className="size-4 animate-spin" /> Claude đang tìm tư liệu trên Dragon Ball Wiki, Kanzenshuu, Wikipedia... (thường 2–6 phút)
            </section>
        );
    }
    if (!research) {
        return null;
    }

    const body = (
        <form onSubmit={approve} className="space-y-4">
            {research.summary && <p className="text-sm">{research.summary}</p>}
            <p className="text-muted-foreground text-sm">
                Bỏ tick chi tiết sai hoặc không muốn kể, sửa câu nếu cần. Chi tiết được giữ sẽ lưu vào{' '}
                <a href={route('lore-facts.index')} className="text-primary underline">
                    kho tư liệu
                </a>{' '}
                để dùng lại cho video sau.
            </p>
            <div className="divide-y rounded-lg border">
                {research.facts.map((fact, i) => (
                    <div key={fact.id} className={`flex gap-3 p-3 ${data.facts[i]?.enabled ? '' : 'opacity-50'}`}>
                        <input
                            type="checkbox"
                            className="mt-1 size-4 shrink-0"
                            aria-label={`Giữ ${fact.id}`}
                            checked={data.facts[i]?.enabled ?? false}
                            onChange={(e) => setFact(i, { enabled: e.target.checked })}
                        />
                        <div className="min-w-0 flex-1 space-y-1">
                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                <span className="font-mono">{fact.id}</span>
                                <Badge variant={fact.kind === 'theory' ? 'outline' : 'secondary'}>{kindLabels[fact.kind]}</Badge>
                                {fact.era && <span className="text-muted-foreground">{fact.era}</span>}
                                {fact.lore_id && <Badge variant="outline">Từ kho</Badge>}
                            </div>
                            <Textarea rows={2} value={data.facts[i]?.text ?? ''} onChange={(e) => setFact(i, { text: e.target.value })} />
                            {fact.source_url ? (
                                <a
                                    href={fact.source_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-primary inline-flex items-center gap-1 text-xs break-all"
                                >
                                    <ExternalLink className="size-3 shrink-0" /> {fact.source_title || fact.source_url}
                                </a>
                            ) : (
                                <p className="text-xs text-amber-700 dark:text-amber-400">Chưa có nguồn, nên kiểm tra lại trước khi giữ.</p>
                            )}
                            <InputError message={fieldErrors[`facts.${i}.text`]} />
                        </div>
                    </div>
                ))}
            </div>
            <div className="grid gap-2">
                <Label htmlFor="research_notes">Ghi chú cho Claude (không bắt buộc)</Label>
                <Textarea
                    id="research_notes"
                    rows={2}
                    value={data.research_notes}
                    onChange={(e) => setData('research_notes', e.target.value)}
                    placeholder="Ví dụ: chỉ kể bản manga, bỏ phần Super; nhấn mạnh cảnh Vegeta khóc ở Namek."
                />
            </div>
            <InputError message={fieldErrors.facts} />
            <div className="flex flex-wrap gap-2">
                <Button disabled={processing || kept < 3 || status === 'writing'}>
                    <BookCheck /> Duyệt {kept} chi tiết và {seriesParts > 1 ? `chia ${seriesParts} phần` : 'viết kịch bản'}
                </Button>
                <Button type="button" variant="outline" onClick={again} disabled={status === 'writing'}>
                    <RefreshCw /> Tìm lại tư liệu
                </Button>
            </div>
            {research.sources.length > 0 && (
                <details className="text-sm">
                    <summary className="cursor-pointer">Nguồn đã đọc ({research.sources.length})</summary>
                    <ul className="mt-2 list-disc space-y-1 pl-5">
                        {research.sources.map((source) => (
                            <li key={source.url}>
                                <a href={source.url} target="_blank" rel="noreferrer" className="text-primary break-all">
                                    {source.title || source.url}
                                </a>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </form>
    );

    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold">
                {status === 'research_ready'
                    ? 'Duyệt tư liệu trước khi viết kịch bản'
                    : `Tư liệu (${research.facts.filter((f) => f.enabled).length}/${research.facts.length} chi tiết đã dùng)`}
            </h2>
            {body}
        </section>
    );
}

/** 3 câu mở đầu Claude viết sẵn (câu hỏi / gây sốc / con số): bấm để đổi lời kể cảnh 1. */
export function HookPicker({ shortId, hooks, index }: { shortId: number; hooks: { style: string; text: string }[]; index: number | null }) {
    if (hooks.length < 2) {
        return null;
    }
    const labels: Record<string, string> = { question: 'Câu hỏi', shock: 'Gây sốc', number: 'Con số' };
    const choose = (i: number) => router.post(route('topic-shorts.hook', shortId), { index: i }, { preserveScroll: true });

    return (
        <div className="space-y-2 rounded-lg border p-3">
            <Label>Câu mở đầu (3 giây đầu quyết định người xem có ở lại)</Label>
            <div className="grid gap-2 md:grid-cols-3">
                {hooks.map((hook, i) => (
                    <button
                        key={i}
                        type="button"
                        onClick={() => choose(i)}
                        aria-pressed={index === i}
                        className={`rounded-md border p-2 text-left text-sm ${index === i ? 'border-primary bg-primary/5' : 'hover:bg-muted/50'}`}
                    >
                        <span className="text-muted-foreground block text-xs">{labels[hook.style] ?? hook.style}</span>
                        {hook.text}
                    </button>
                ))}
            </div>
        </div>
    );
}

/** Kết quả kiểm chứng một cảnh + nguồn của các chi tiết cảnh dùng. */
export function SceneFacts({ check, factIds, facts }: { check: FactCheck | null; factIds: string[]; facts: ResearchFact[] }) {
    const used = facts.filter((f) => factIds.includes(f.id));
    if (!check && used.length === 0) {
        return null;
    }
    const style = check ? checkStyles[check.status] : null;

    return (
        <div className="space-y-1 text-xs md:col-span-2">
            {style && (
                <p className={`rounded px-2 py-1 ${style.className}`}>
                    <ShieldCheck className="mr-1 inline size-3.5" />
                    {style.label}
                    {check?.note ? `: ${check.note}` : ''}
                </p>
            )}
            {used.length > 0 && (
                <p className="text-muted-foreground">
                    Dựa trên:{' '}
                    {used.map((fact, i) => (
                        <span key={fact.id}>
                            {i > 0 && ', '}
                            {fact.source_url ? (
                                <a href={fact.source_url} target="_blank" rel="noreferrer" className="text-primary" title={fact.text}>
                                    {fact.id}
                                </a>
                            ) : (
                                <span title={fact.text}>{fact.id}</span>
                            )}
                        </span>
                    ))}
                </p>
            )}
        </div>
    );
}
