import { Button } from '@/components/ui/button';
import { Check, ClipboardCheck, Copy, Megaphone } from 'lucide-react';
import { useState } from 'react';

export interface EpisodePackage {
    title: string;
    logline: string;
    beats: (string | null)[];
    continuity: string[];
    hooks: string[];
    captions: { tiktok: string; reels: string; shorts: string };
    hashtags: string[];
    qc: string[];
    fallback: string;
    notes_vi: string;
}

const PLATFORMS: { key: keyof EpisodePackage['captions']; label: string }[] = [
    { key: 'tiktok', label: 'TikTok' },
    { key: 'reels', label: 'Facebook Reels' },
    { key: 'shorts', label: 'YouTube Shorts' },
];

function CopyButton({ text }: { text: string }) {
    const [done, setDone] = useState(false);

    return (
        <Button
            type="button"
            size="sm"
            variant="ghost"
            className="h-7 shrink-0 px-2 text-xs"
            onClick={() => {
                navigator.clipboard.writeText(text);
                setDone(true);
                setTimeout(() => setDone(false), 1500);
            }}
        >
            {done ? <Check /> : <Copy />} {done ? 'Đã chép' : 'Chép'}
        </Button>
    );
}

/** Gói sản xuất / đăng bài của truyện ngắn: tiêu đề, hook dự phòng, caption từng nền tảng, checklist liên tục và kiểm tra lỗi, phương án tiết kiệm. */
export default function EpisodePackagePanel({ pack }: { pack: EpisodePackage }) {
    const [checked, setChecked] = useState<number[]>([]);
    const tags = pack.hashtags.join(' ');

    return (
        <section className="space-y-4 rounded-xl border p-4">
            <h2 className="flex items-center gap-2 text-lg font-semibold">
                <Megaphone className="size-5" /> Gói đăng bài
            </h2>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="space-y-3 text-sm">
                    <div>
                        <p className="text-muted-foreground text-xs">Tiêu đề video</p>
                        <div className="flex items-center gap-2">
                            <p className="min-w-0 flex-1 font-medium">{pack.title}</p>
                            <CopyButton text={pack.title} />
                        </div>
                        {pack.logline && <p className="text-muted-foreground text-xs">{pack.logline}</p>}
                    </div>
                    {PLATFORMS.map(({ key, label }) =>
                        pack.captions[key] ? (
                            <div key={key}>
                                <p className="text-muted-foreground text-xs">Caption {label}</p>
                                <div className="flex items-start gap-2">
                                    <p className="min-w-0 flex-1">
                                        {pack.captions[key]} <span className="text-primary">{tags}</span>
                                    </p>
                                    <CopyButton text={`${pack.captions[key]} ${tags}`.trim()} />
                                </div>
                            </div>
                        ) : null,
                    )}
                    {pack.hooks.length > 0 && (
                        <div>
                            <p className="text-muted-foreground text-xs">3 hook mở đầu dự phòng (thử khi 3 giây đầu giữ chân kém)</p>
                            <ol className="list-decimal space-y-0.5 pl-5">
                                {pack.hooks.map((hook, i) => (
                                    <li key={i}>{hook}</li>
                                ))}
                            </ol>
                        </div>
                    )}
                </div>

                <div className="space-y-3 text-sm">
                    {pack.qc.length > 0 && (
                        <div>
                            <p className="text-muted-foreground flex items-center gap-1 text-xs">
                                <ClipboardCheck className="size-3.5" /> Kiểm tra trước khi đăng ({checked.length}/{pack.qc.length})
                            </p>
                            <ul className="space-y-1">
                                {pack.qc.map((item, i) => (
                                    <li key={i}>
                                        <label className="flex items-start gap-2">
                                            <input
                                                type="checkbox"
                                                className="mt-1"
                                                checked={checked.includes(i)}
                                                onChange={(e) =>
                                                    setChecked((list) => (e.target.checked ? [...list, i] : list.filter((j) => j !== i)))
                                                }
                                            />
                                            <span className={checked.includes(i) ? 'text-muted-foreground line-through' : ''}>{item}</span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {pack.continuity.length > 0 && (
                        <div>
                            <p className="text-muted-foreground text-xs">Giữ liên tục giữa các shot</p>
                            <ul className="list-disc space-y-0.5 pl-5">
                                {pack.continuity.map((item, i) => (
                                    <li key={i}>{item}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {pack.fallback && (
                        <div>
                            <p className="text-muted-foreground text-xs">Phương án tiết kiệm (ảnh tĩnh + chuyển máy + SFX)</p>
                            <p>{pack.fallback}</p>
                        </div>
                    )}
                    {pack.notes_vi && (
                        <div className="rounded-lg bg-amber-50 px-3 py-2 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            <p className="text-xs font-medium">Ghi chú sản xuất</p>
                            <p className="whitespace-pre-line">{pack.notes_vi}</p>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}
