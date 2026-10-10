import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Bookmark, Check, Copy, ExternalLink, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** Dữ liệu bookmarklet gom từ trang Shopee đã render trong trình duyệt của người dùng. */
export type ShopeeSnapshot = {
    url: string;
    title: string;
    text: string;
    images: string[];
    name: string;
    price: number | null;
};

// Chạy trên trang Shopee: gom chữ, ảnh sản phẩm và JSON-LD, mở trang này rồi gửi dữ liệu qua postMessage.
// Ảnh chỉ lấy từ các phần tử slide ảnh sản phẩm (.page-product .qIctnQ): src/srcset/data-src/background của chính
// phần tử và ảnh con. Không quét cả trang (lẫn ảnh gợi ý, đánh giá). Shopee đổi tên class (không còn .qIctnQ)
// thì lấy ảnh chính (JSON-LD, og:image), quá ít thì mới quét khu vực sản phẩm.
// Viết dạng chuỗi (String.raw) để bundler không biến đổi code.
const BOOKMARKLET = String.raw`(function(app){
if(!/(^|\.)shopee\./.test(location.hostname)){alert('Hãy mở trang sản phẩm trên Shopee rồi bấm lại.');return;}
var images=[],seen={},product={};
function add(u){var m=/^https:\/\/([a-z0-9-]+\.img\.susercontent\.com)\/file\/([A-Za-z0-9-]+)/i.exec(u||'');if(m&&!seen[m[2]]){seen[m[2]]=1;images.push('https://'+m[1]+'/file/'+m[2]);}}
document.querySelectorAll('script[type="application/ld+json"]').forEach(function(s){try{[].concat(JSON.parse(s.textContent)).forEach(function(d){if(d&&d['@type']==='Product'){product=d;}});}catch(e){}});
var re=/https:\/\/[a-z0-9-]+\.img\.susercontent\.com\/file\/[A-Za-z0-9-]+/gi;
var slides=document.querySelectorAll('.page-product .qIctnQ');
if(slides.length){slides.forEach(function(n){(n.outerHTML.match(re)||[]).forEach(add);});}
else{[].concat(product.image||[]).forEach(add);var og=document.querySelector('meta[property="og:image"]');if(og){add(og.content);}
if(images.length<2){var page=document.querySelector('.page-product')||document.body;(page.innerHTML.match(re)||[]).forEach(add);}}
console.log('[Lấy từ Shopee] '+slides.length+' phần tử .qIctnQ, '+images.length+' ảnh');
var offer=[].concat(product.offers||[])[0]||{};
var data={type:'videoaff:product',url:location.origin+location.pathname,title:document.title,text:document.body.innerText.slice(0,50000),images:images.slice(0,30),name:product.name||'',price:Number(offer.lowPrice||offer.price)||null};
var w=window.open(app+'/products/create?import=1','videoaff-import');
if(!w){alert('Trình duyệt đã chặn cửa sổ mới. Hãy cho phép popup rồi bấm lại.');return;}
function onMessage(e){if(e.origin===app&&e.data&&e.data.type==='videoaff:ready'){w.postMessage(data,app);window.removeEventListener('message',onMessage);}}
window.addEventListener('message',onMessage);
})(`;

const SHOPEE_ORIGIN = /^https:\/\/([a-z0-9-]+\.)*shopee\.[a-z.]+$/;

/** Nhận dữ liệu từ bookmarklet khi trang được mở bằng ?import=1. */
function useShopeeSnapshot(onSnapshot: (snapshot: ShopeeSnapshot) => void) {
    const callback = useRef(onSnapshot);
    callback.current = onSnapshot;

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (!params.has('import') || !window.opener) {
            return;
        }

        const onMessage = (e: MessageEvent) => {
            if (!SHOPEE_ORIGIN.test(e.origin) || e.data?.type !== 'videoaff:product') {
                return;
            }

            const { url, title, text, images, name, price } = e.data;
            callback.current({
                url: String(url ?? ''),
                title: String(title ?? ''),
                text: String(text ?? ''),
                images: Array.isArray(images) ? images.map(String) : [],
                name: String(name ?? ''),
                price: typeof price === 'number' ? price : null,
            });
            window.removeEventListener('message', onMessage);
            window.history.replaceState(null, '', window.location.pathname);
        };

        window.addEventListener('message', onMessage);
        // Tin "ready" không chứa dữ liệu nên gửi được cho mọi origin; bookmarklet tự kiểm tra origin của app.
        window.opener.postMessage({ type: 'videoaff:ready' }, '*');

        return () => window.removeEventListener('message', onMessage);
    }, []);
}

export default function ShopeeImport() {
    const bookmarkletRef = useRef<HTMLAnchorElement>(null);
    const [bookmarkletCode, setBookmarkletCode] = useState('');
    const [copied, setCopied] = useState(false);
    const [link, setLink] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // React chặn href "javascript:" nên gán trực tiếp vào DOM.
    useEffect(() => {
        const code = 'javascript:' + encodeURIComponent(BOOKMARKLET + JSON.stringify(window.location.origin) + ')');
        bookmarkletRef.current?.setAttribute('href', code);
        setBookmarkletCode(code);
    }, []);

    // Trình duyệt không cho trang web tự thêm bookmark: copy mã để dán vào ô URL khi tạo bookmark bằng tay.
    const copyBookmarklet = async () => {
        await navigator.clipboard.writeText(bookmarkletCode);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    // Nhận dữ liệu là tạo sản phẩm luôn; ảnh và phân tích Claude chạy ngầm.
    useShopeeSnapshot((snapshot) => {
        setLink(snapshot.url);
        router.post(route('products.import'), snapshot, {
            onStart: () => {
                setSaving(true);
                setError(null);
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Không thêm được sản phẩm.'),
            onFinish: () => setSaving(false),
        });
    });

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <p className="text-muted-foreground text-sm">
                Shopee chặn máy chủ đọc trang, nên app đọc trang ngay trong trình duyệt của bạn bằng một bookmark. Bấm bookmark là sản phẩm được thêm
                ngay; ảnh và thông tin (AI phân tích) được lấy ngầm.
            </p>

            <ol className="text-muted-foreground list-decimal space-y-2 pl-5 text-sm">
                <li className="space-y-2">
                    <span>Tạo bookmark "Lấy từ Shopee" (chỉ cần làm một lần):</span>
                    <p className="text-xs text-amber-700 dark:text-amber-400">
                        Đã cập nhật: bookmark mới chỉ lấy ảnh trong khung slide ảnh sản phẩm. Nếu đã tạo bookmark trước đây, hãy xoá bookmark cũ và
                        tạo lại.
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <a
                            ref={bookmarkletRef}
                            onClick={(e) => e.preventDefault()}
                            className="bg-primary text-primary-foreground inline-flex cursor-grab items-center gap-2 rounded-md px-4 py-2 text-sm font-medium shadow-sm active:cursor-grabbing"
                            title="Kéo nút này lên thanh bookmark"
                        >
                            <Bookmark className="size-4" /> Lấy từ Shopee
                        </a>
                        <Button type="button" variant="outline" onClick={copyBookmarklet} disabled={!bookmarkletCode}>
                            {copied ? <Check /> : <Copy />} {copied ? 'Đã copy mã' : 'Copy mã bookmark'}
                        </Button>
                    </div>
                    <ul className="list-disc space-y-1 pl-5 text-xs">
                        <li>
                            <span className="text-foreground">Cách 1:</span> kéo nút xanh lên thanh bookmark (bấm Ctrl/⌘+Shift+B nếu chưa thấy thanh
                            bookmark).
                        </li>
                        <li>
                            <span className="text-foreground">Cách 2:</span> bấm "Copy mã bookmark", nhấn Ctrl/⌘+D để thêm bookmark trang này, chọn
                            "Thêm…"/"Chỉnh sửa", đặt tên "Lấy từ Shopee" và dán mã vào ô URL thay cho địa chỉ cũ.
                        </li>
                    </ul>
                </li>
                <li>
                    Dán link sản phẩm và mở trang. Cuộn xuống phần mô tả và đánh giá để Shopee tải hết nội dung.
                    <div className="mt-2 flex gap-2">
                        <Input type="url" value={link} onChange={(e) => setLink(e.target.value)} placeholder="https://shopee.vn/..." />
                        <Button type="button" variant="outline" disabled={!/^https?:\/\//.test(link)} onClick={() => window.open(link, '_blank')}>
                            <ExternalLink /> Mở trang
                        </Button>
                    </div>
                </li>
                <li>Trên trang Shopee, bấm bookmark "Lấy từ Shopee". Sản phẩm được thêm và mở trang chỉnh sửa.</li>
            </ol>

            {saving && (
                <p className="text-muted-foreground inline-flex items-center gap-2 text-sm">
                    <LoaderCircle className="size-4 animate-spin" /> Đang thêm sản phẩm...
                </p>
            )}
            <InputError message={error ?? undefined} />
        </div>
    );
}
