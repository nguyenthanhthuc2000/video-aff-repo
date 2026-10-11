import { Button } from '@/components/ui/button';
import { type VideoProject } from '@/types/models';
import { router } from '@inertiajs/react';
import { Check, Copy, LoaderCircle, Sparkles } from 'lucide-react';
import { useState } from 'react';

const groups = [
    { key: 'sale', title: 'Đăng bán hàng', hint: 'Nêu điểm đáng mua, dẫn về giỏ hàng.' },
    { key: 'view', title: 'Đăng câu view', hint: 'Gây tò mò, kéo bình luận và chia sẻ; không bán thẳng.' },
] as const;

function CaptionCard({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        await navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <li className="flex items-start gap-2 rounded-md border p-2">
            <p className="flex-1 text-sm whitespace-pre-line">{text}</p>
            <Button size="sm" variant="ghost" onClick={copy} title="Sao chép">
                {copied ? <Check /> : <Copy />}
            </Button>
        </li>
    );
}

/** Đề xuất caption đăng video (Claude viết theo sản phẩm và kịch bản), bấm để sao chép. */
export default function PostCaptionsPanel({ project }: { project: VideoProject }) {
    const status = project.post_captions_status;
    const captions = project.post_captions;
    const rewrite = () => router.post(route('projects.post-captions', project.id), {}, { preserveScroll: true });

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">Đề xuất caption đăng video</p>
                {status !== 'pending' && (
                    <Button size="sm" variant="outline" onClick={rewrite}>
                        <Sparkles />
                        {captions?.sale?.length ? 'Viết lại đề xuất' : 'Viết đề xuất'}
                    </Button>
                )}
            </div>
            {status === 'pending' && (
                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                    <LoaderCircle className="size-4 animate-spin" />
                    AI đang viết caption đăng bán hàng và câu view...
                </p>
            )}
            {status === 'failed' && captions?.error && <p className="text-destructive text-sm">{captions.error}</p>}
            {!status && <p className="text-muted-foreground text-sm">Bấm "Viết đề xuất" để AI viết 3 caption bán hàng và 3 caption câu view cho video này.</p>}
            {status === 'ready' && (
                <div className="grid gap-4 md:grid-cols-2">
                    {groups.map((group) => (
                        <div key={group.key} className="space-y-2">
                            <div>
                                <p className="text-sm font-medium">{group.title}</p>
                                <p className="text-muted-foreground text-xs">{group.hint}</p>
                            </div>
                            <ul className="space-y-2">
                                {(captions?.[group.key] ?? []).map((text, i) => (
                                    <CaptionCard key={i} text={text} />
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
