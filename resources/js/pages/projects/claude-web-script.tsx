import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type VideoProject } from '@/types/models';
import { useForm } from '@inertiajs/react';
import { Check, Copy, Download, ExternalLink } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

/** Chế độ Claude bản web: copy prompt sang claude.ai (tài khoản đăng nhập), dán câu trả lời JSON về. */
export default function ClaudeWebScript({ project }: { project: VideoProject }) {
    const [copied, setCopied] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ reply: '' });
    const productImages = (project.references ?? []).filter((ref) => ref.label.includes('sản phẩm'));

    const copy = async () => {
        await navigator.clipboard.writeText(project.script_prompt ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (project.script && !confirm('Nhập kịch bản mới sẽ thay toàn bộ cảnh hiện tại. Tiếp tục?')) {
            return;
        }

        post(route('projects.script.import', project.id), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <div className="space-y-4">
            <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                <li>Copy prompt bên dưới.</li>
                <li>
                    Mở{' '}
                    <a href={project.claude_web_url} target="_blank" rel="noreferrer" className="text-foreground underline">
                        claude.ai
                    </a>{' '}
                    (đăng nhập tài khoản của bạn), đính kèm ảnh sản phẩm, dán prompt và gửi.
                </li>
                <li>Copy toàn bộ câu trả lời của Claude, dán vào ô bên dưới rồi bấm "Nhập kịch bản".</li>
            </ol>

            <div className="flex flex-wrap items-end gap-3">
                {productImages.map((ref) => (
                    <a key={ref.url} href={ref.url} download className="group space-y-1 text-center text-xs">
                        <img src={ref.url} alt={ref.label} className="h-20 rounded-md border object-cover" />
                        <span className="text-muted-foreground group-hover:text-foreground inline-flex items-center gap-1">
                            <Download className="size-3" /> Tải ảnh
                        </span>
                    </a>
                ))}
            </div>

            <pre className="bg-muted max-h-48 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap">{project.script_prompt}</pre>
            <div className="flex flex-wrap gap-2">
                <Button variant="outline" onClick={copy}>
                    {copied ? <Check /> : <Copy />} {copied ? 'Đã copy' : 'Copy prompt'}
                </Button>
                <Button variant="outline" asChild>
                    <a href={project.claude_web_url} target="_blank" rel="noreferrer">
                        <ExternalLink /> Mở claude.ai
                    </a>
                </Button>
            </div>

            <form onSubmit={submit} className="space-y-2">
                <Label htmlFor="reply">Câu trả lời của Claude</Label>
                <Textarea
                    id="reply"
                    rows={6}
                    value={data.reply}
                    onChange={(e) => setData('reply', e.target.value)}
                    placeholder='{"hooks": [...], "scenes": [...], ...}'
                />
                <InputError message={errors.reply} />
                <Button type="submit" disabled={processing || data.reply.trim() === ''}>
                    Nhập kịch bản
                </Button>
            </form>
        </div>
    );
}
