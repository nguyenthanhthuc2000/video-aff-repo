import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type Product } from '@/types/models';
import { router, useForm } from '@inertiajs/react';
import { Check, Copy, ExternalLink, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

/** Trạng thái lấy sản phẩm từ Shopee chạy ngầm, và bước dán câu trả lời claude.ai ở chế độ bản web. */
export default function ImportStatus({ product }: { product: Product }) {
    const [copied, setCopied] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ reply: '' });

    if (product.import_status === 'importing') {
        return (
            <div className="bg-muted/50 flex items-center gap-2 rounded-lg border p-3 text-sm">
                <LoaderCircle className="size-4 animate-spin" /> Đang tải ảnh và phân tích trang Shopee (chạy ngầm, bạn có thể rời trang)...
            </div>
        );
    }

    if (product.import_status === 'failed') {
        return (
            <div className="space-y-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <p>Lấy sản phẩm thất bại: {product.import_error}</p>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => router.post(route('products.import.retry', product.id), {}, { preserveScroll: true })}
                >
                    Thử lại
                </Button>
            </div>
        );
    }

    if (product.import_status !== 'awaiting_reply' || !product.import_prompt) {
        return null;
    }

    const copy = async () => {
        await navigator.clipboard.writeText(product.import_prompt ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('products.import.reply', product.id), { preserveScroll: true });
    };

    return (
        <div className="space-y-3 rounded-lg border p-4 text-sm">
            <p>
                Đã tải ảnh. Hệ thống chưa tự phân tích được sản phẩm: copy prompt sang công cụ AI và dán câu trả lời về để điền size, chất liệu, màu,
                đánh giá.
            </p>
            <pre className="bg-muted max-h-40 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap">{product.import_prompt}</pre>
            <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" onClick={copy}>
                    {copied ? <Check /> : <Copy />} {copied ? 'Đã copy' : 'Copy prompt'}
                </Button>
                <Button type="button" variant="outline" asChild>
                    <a href={product.claude_web_url} target="_blank" rel="noreferrer">
                        <ExternalLink /> Mở claude.ai
                    </a>
                </Button>
            </div>
            <form onSubmit={submit} className="space-y-2">
                <Label htmlFor="import-reply">Câu trả lời của AI</Label>
                <Textarea
                    id="import-reply"
                    rows={5}
                    value={data.reply}
                    onChange={(e) => setData('reply', e.target.value)}
                    placeholder='{"name": "...", ...}'
                />
                <InputError message={errors.reply} />
                <Button disabled={processing || data.reply.trim() === ''}>Điền thông tin</Button>
            </form>
        </div>
    );
}
