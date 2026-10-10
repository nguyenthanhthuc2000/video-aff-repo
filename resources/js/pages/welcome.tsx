import AppLogoIcon from '@/components/app-logo-icon';
import CreditEstimator from '@/components/pricing/credit-estimator';
import PackageGrid from '@/components/pricing/package-grid';
import { type CreditRates, estimateCredits, formatNumber } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Bookmark,
    Captions,
    CheckCircle2,
    Clapperboard,
    Copy,
    Layers,
    type LucideIcon,
    Music,
    PenLine,
    Repeat,
    ShieldCheck,
    Sparkles,
    Wand2,
    Zap,
} from 'lucide-react';

const steps: { icon: LucideIcon; title: string; text: string }[] = [
    {
        icon: Bookmark,
        title: 'Lấy sản phẩm 1 chạm',
        text: 'Mở trang Shopee, bấm bookmark "Lấy từ Shopee". Ảnh, giá, size, đánh giá được lấy về, bạn chỉ cần duyệt ảnh.',
    },
    {
        icon: PenLine,
        title: 'AI viết kịch bản',
        text: '5 hook theo 5 chủ đề, tự chọn hook hợp nhất. Cảnh, lời đọc, caption, hashtag đúng thông tin sản phẩm, không bịa.',
    },
    {
        icon: Wand2,
        title: 'AI tạo ảnh & clip',
        text: 'Người mẫu của bạn mặc / đeo đúng sản phẩm ở nhiều bối cảnh, có thể nhảy trend hoặc làm y chang video mẫu.',
    },
    {
        icon: Clapperboard,
        title: 'Xuất video 9:16',
        text: 'Ghép cảnh, phụ đề tiếng Việt không che mặt, nhạc nền, end card "xem trong giỏ hàng". Tải MP4 là đăng được.',
    },
];

const features: { icon: LucideIcon; title: string; text: string }[] = [
    {
        icon: Repeat,
        title: 'Video biến hình',
        text: 'Mở đầu mặt mộc, đồ cũ, tóc rối, búng tay một cái là lên đồ mới. Dạng video giữ chân người xem tốt nhất.',
    },
    { icon: Copy, title: 'Sao chép chuyển động', text: 'Tải video trend lên, AI phân tích từng nhịp, người mẫu của bạn làm theo y chang.' },
    {
        icon: Layers,
        title: 'Bối cảnh theo sản phẩm',
        text: 'Đầm dự tiệc lên nhà hàng, đồ công sở vào văn phòng, ốp lưng lên bàn làm việc. Tự đề xuất theo tên sản phẩm.',
    },
    {
        icon: Zap,
        title: 'Chạy ngầm từ A tới Z',
        text: 'Bật chế độ tự động: tạo xong là có video, nhiều sản phẩm chạy song song. Hoặc duyệt thủ công từng bước.',
    },
    { icon: Music, title: 'Thư viện nhạc', text: 'Tải nhạc của bạn, nhập link, hoặc dùng luôn nhạc của video mẫu cho khớp nhịp.' },
    { icon: Captions, title: 'Phụ đề đẹp, đúng luật', text: 'Chữ đặt ở phần dưới không che mặt, không hứa hẹn ưu đãi hay số liệu bán hàng.' },
];

const faqs: { q: string; a: string }[] = [
    {
        q: 'Tính phí thế nào?',
        a: 'Nạp tiền để nhận Credits (gói lớn được tặng thêm). Mỗi thao tác trừ một lượng Credits tuỳ độ phức tạp: ảnh tĩnh rẻ nhất, clip chuyển động và sao chép video mẫu tốn nhiều hơn. Không phí duy trì, Credits không hết hạn.',
    },
    {
        q: 'Dữ liệu của tôi nằm ở đâu?',
        a: 'Sản phẩm, ảnh người mẫu và video thuộc tài khoản của bạn, chỉ bạn xem được. Ảnh người mẫu chỉ dùng để tạo video cho chính bạn, cần có sự đồng ý của người trong ảnh.',
    },
    {
        q: 'Sản phẩm không phải quần áo thì sao?',
        a: 'Mũ, kính, túi, giày, ốp điện thoại, mỹ phẩm đều được: ứng dụng nhận loại sản phẩm từ tên và đề xuất động tác phù hợp như đeo thử, mở hộp, dùng thử.',
    },
    {
        q: 'Đăng lên Shopee Video có cần lưu ý gì?',
        a: 'Bật nhãn nội dung do AI tạo, dùng nhạc có bản quyền thương mại, và nên chèn thêm 1–2 đoạn quay sản phẩm thật để tăng độ tin cậy.',
    },
];

export default function Welcome({ creditRates }: { creditRates: CreditRates }) {
    const { auth, name } = usePage<SharedData>().props;
    const primary = auth.user ? { href: route('dashboard'), label: 'Vào ứng dụng' } : { href: route('login'), label: 'Đăng nhập để bắt đầu' };

    return (
        <>
            <Head title="Làm video affiliate Shopee bằng AI" />
            <div className="min-h-screen bg-white text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
                {/* Thanh điều hướng */}
                <header className="sticky top-0 z-30 border-b border-zinc-200/70 bg-white/80 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/80">
                    <nav className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                        <Link href={route('home')} className="flex items-center gap-2 font-semibold">
                            <span className="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-rose-500 text-white">
                                <AppLogoIcon className="size-5 fill-current" />
                            </span>
                            {name}
                        </Link>
                        <div className="hidden items-center gap-6 text-sm text-zinc-600 md:flex dark:text-zinc-400">
                            <a href="#quy-trinh" className="hover:text-zinc-900 dark:hover:text-white">
                                Quy trình
                            </a>
                            <a href="#tinh-nang" className="hover:text-zinc-900 dark:hover:text-white">
                                Tính năng
                            </a>
                            <a href="#bang-gia" className="hover:text-zinc-900 dark:hover:text-white">
                                Bảng giá
                            </a>
                            <a href="#hoi-dap" className="hover:text-zinc-900 dark:hover:text-white">
                                Hỏi đáp
                            </a>
                        </div>
                        <Link
                            href={primary.href}
                            className="rounded-full bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                        >
                            {auth.user ? 'Vào ứng dụng' : 'Đăng nhập'}
                        </Link>
                    </nav>
                </header>

                <main>
                    {/* Hero */}
                    <section className="relative overflow-hidden">
                        <div className="pointer-events-none absolute inset-0 -z-0">
                            <div className="absolute -top-24 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-gradient-to-br from-orange-300/40 via-rose-300/30 to-fuchsia-300/20 blur-3xl dark:from-orange-500/20 dark:via-rose-500/10 dark:to-fuchsia-500/10" />
                        </div>
                        <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 md:py-24 lg:grid-cols-[1.1fr_0.9fr]">
                            <div className="space-y-6">
                                <span className="inline-flex items-center gap-2 rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-medium text-orange-700 dark:border-orange-900 dark:bg-orange-950/50 dark:text-orange-300">
                                    <Sparkles className="size-3.5" /> Dành cho dân affiliate Shopee Video
                                </span>
                                <h1 className="text-4xl leading-tight font-bold tracking-tight text-balance md:text-5xl lg:text-6xl">
                                    Từ link Shopee đến{' '}
                                    <span className="bg-gradient-to-r from-orange-500 to-rose-500 bg-clip-text text-transparent">video bán hàng</span>{' '}
                                    trong vài phút
                                </h1>
                                <p className="max-w-xl text-lg text-zinc-600 dark:text-zinc-400">
                                    AI viết kịch bản, cho người mẫu của bạn mặc đúng sản phẩm, nhảy trend hay biến hình, rồi ghép thành video 9:16 có
                                    phụ đề và nhạc. Không cần quay, không cần dựng.
                                </p>
                                <div className="flex flex-wrap items-center gap-3">
                                    <Link
                                        href={primary.href}
                                        className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-orange-500 to-rose-500 px-6 py-3 font-medium text-white shadow-lg shadow-orange-500/25 transition hover:opacity-90"
                                    >
                                        {primary.label} <ArrowRight className="size-4" />
                                    </Link>
                                    <a
                                        href="#quy-trinh"
                                        className="rounded-full border border-zinc-300 px-6 py-3 font-medium transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-900"
                                    >
                                        Xem cách hoạt động
                                    </a>
                                </div>
                                <dl className="grid max-w-md grid-cols-3 gap-4 pt-4">
                                    {[
                                        ['5', 'hook mỗi video'],
                                        ['9:16', 'chuẩn Shopee Video'],
                                        ['Credits', 'trả theo dùng'],
                                    ].map(([value, label]) => (
                                        <div key={label}>
                                            <dt className="text-2xl font-bold">{value}</dt>
                                            <dd className="text-xs text-zinc-500">{label}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>

                            <PhoneMockup />
                        </div>
                    </section>

                    {/* Quy trình */}
                    <section
                        id="quy-trinh"
                        className="scroll-mt-16 border-y border-zinc-200 bg-zinc-50 py-20 dark:border-zinc-800 dark:bg-zinc-900/40"
                    >
                        <div className="mx-auto max-w-6xl px-4">
                            <SectionTitle eyebrow="Quy trình" title="4 bước, chạy ngầm cũng được" />
                            <ol className="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                                {steps.map((step, i) => (
                                    <li
                                        key={step.title}
                                        className="relative rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                                    >
                                        <span className="absolute top-5 right-5 text-4xl font-bold text-zinc-100 dark:text-zinc-800">{i + 1}</span>
                                        <span className="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-500 text-white">
                                            <step.icon className="size-5" />
                                        </span>
                                        <h3 className="mt-4 font-semibold">{step.title}</h3>
                                        <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{step.text}</p>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>

                    {/* Tính năng */}
                    <section id="tinh-nang" className="scroll-mt-16 py-20">
                        <div className="mx-auto max-w-6xl px-4">
                            <SectionTitle eyebrow="Tính năng" title="Những thứ làm video ra đơn" />
                            <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {features.map((feature) => (
                                    <div
                                        key={feature.title}
                                        className="group rounded-2xl border border-zinc-200 p-6 transition hover:-translate-y-0.5 hover:border-orange-300 hover:shadow-lg hover:shadow-orange-500/5 dark:border-zinc-800 dark:hover:border-orange-800"
                                    >
                                        <feature.icon className="size-6 text-orange-500" />
                                        <h3 className="mt-4 font-semibold">{feature.title}</h3>
                                        <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{feature.text}</p>
                                    </div>
                                ))}
                            </div>

                            {/* Thường vs biến hình */}
                            <div className="mt-16 grid gap-6 lg:grid-cols-2">
                                <Compare
                                    title="Video thường"
                                    points={[
                                        'Người mẫu mặc sản phẩm ở 4–9 bối cảnh',
                                        'Nhảy trend, catwalk, xoay khoe đồ',
                                        'Hợp đa số sản phẩm thời trang, phụ kiện',
                                    ]}
                                />
                                <Compare
                                    highlight
                                    title="Video biến hình"
                                    points={[
                                        'Cảnh "trước": giữ nguyên đồ cũ, mặt mộc trong ảnh của bạn',
                                        'Khoảnh khắc chuyển: búng tay, che camera, xoay người',
                                        'Cảnh "sau": makeup, lên đồ mới, tự tin',
                                    ]}
                                />
                            </div>
                        </div>
                    </section>

                    {/* Bảng giá */}
                    <section id="bang-gia" className="scroll-mt-16 border-t border-zinc-200 py-20 dark:border-zinc-800">
                        <div className="mx-auto max-w-6xl space-y-12 px-4">
                            <SectionTitle eyebrow="Bảng giá" title="Nạp bao nhiêu dùng bấy nhiêu" />
                            <p className="mx-auto -mt-8 max-w-2xl text-center text-zinc-600 dark:text-zinc-400">
                                Nạp tiền quy ra Credits, gói càng lớn tặng càng nhiều. Mỗi thao tác trừ một lượng Credits tuỳ độ phức tạp của video:
                                ảnh tĩnh ít nhất, clip chuyển động và sao chép video mẫu nhiều hơn. Không phí tháng, Credits không hết hạn.
                            </p>
                            <PackageGrid
                                action={(pkg) => (
                                    <Link
                                        href={auth.user ? route('billing.index', { package: pkg.key }) : route('login')}
                                        className={cn(
                                            'block rounded-full px-4 py-2 text-center text-sm font-medium transition',
                                            pkg.popular
                                                ? 'bg-gradient-to-r from-orange-500 to-rose-500 text-white hover:opacity-90'
                                                : 'border border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-900',
                                        )}
                                    >
                                        Nạp gói này
                                    </Link>
                                )}
                            />
                            <div className="mx-auto max-w-3xl space-y-3">
                                <h3 className="text-center text-xl font-semibold">Một video tốn bao nhiêu?</h3>
                                <CreditEstimator rates={creditRates} />
                                <p className="text-center text-xs text-zinc-500">
                                    Ví dụ: video thường 6 cảnh ảnh tĩnh khoảng{' '}
                                    {formatNumber(estimateCredits(creditRates, { scenes: 6, motion: 'none', seconds: 25 }).total)} Credits (1 Credit =
                                    1đ); thêm chuyển động cho cả 6 cảnh khoảng{' '}
                                    {formatNumber(estimateCredits(creditRates, { scenes: 6, motion: 'style', seconds: 25 }).total)} Credits. Chỉ trừ
                                    Credits khi tạo thành công; tạo lỗi được hoàn lại.
                                </p>
                            </div>
                        </div>
                    </section>

                    {/* Hỏi đáp */}
                    <section id="hoi-dap" className="scroll-mt-16 border-t border-zinc-200 bg-zinc-50 py-20 dark:border-zinc-800 dark:bg-zinc-900/40">
                        <div className="mx-auto max-w-3xl px-4">
                            <SectionTitle eyebrow="Hỏi đáp" title="Câu hỏi thường gặp" />
                            <div className="mt-10 space-y-3">
                                {faqs.map((faq) => (
                                    <details
                                        key={faq.q}
                                        className="group rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                                    >
                                        <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                                            {faq.q}
                                            <span className="text-zinc-400 transition group-open:rotate-45">+</span>
                                        </summary>
                                        <p className="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{faq.a}</p>
                                    </details>
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* Kêu gọi */}
                    <section className="py-20">
                        <div className="mx-auto max-w-5xl px-4">
                            <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-orange-500 via-rose-500 to-fuchsia-600 px-8 py-14 text-center text-white">
                                <div className="absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl" />
                                <h2 className="relative text-3xl font-bold text-balance md:text-4xl">
                                    Video đầu tiên của bạn chỉ cách một cú bấm bookmark
                                </h2>
                                <p className="relative mx-auto mt-3 max-w-xl text-white/85">
                                    Đăng nhập, thêm người mẫu, lấy một sản phẩm từ Shopee và để ứng dụng làm phần còn lại.
                                </p>
                                <Link
                                    href={primary.href}
                                    className="relative mt-8 inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 font-medium text-zinc-900 transition hover:bg-zinc-100"
                                >
                                    {primary.label} <ArrowRight className="size-4" />
                                </Link>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-zinc-200 py-8 text-sm text-zinc-500 dark:border-zinc-800">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4">
                        <span className="flex items-center gap-2">
                            <ShieldCheck className="size-4" /> Nhớ gắn nhãn nội dung do AI tạo khi đăng video.
                        </span>
                        <span>
                            © {new Date().getFullYear()} {name}
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}

function SectionTitle({ eyebrow, title }: { eyebrow: string; title: string }) {
    return (
        <div className="text-center">
            <p className="text-sm font-semibold tracking-wide text-orange-500 uppercase">{eyebrow}</p>
            <h2 className="mt-2 text-3xl font-bold tracking-tight text-balance md:text-4xl">{title}</h2>
        </div>
    );
}

function Compare({ title, points, highlight = false }: { title: string; points: string[]; highlight?: boolean }) {
    return (
        <div
            className={cn(
                'rounded-2xl border p-6',
                highlight
                    ? 'border-orange-300 bg-gradient-to-br from-orange-50 to-rose-50 dark:border-orange-900 dark:from-orange-950/40 dark:to-rose-950/30'
                    : 'border-zinc-200 dark:border-zinc-800',
            )}
        >
            <h3 className="flex items-center gap-2 text-lg font-semibold">
                {title}
                {highlight && <span className="rounded-full bg-orange-500 px-2 py-0.5 text-xs font-medium text-white">Hot</span>}
            </h3>
            <ul className="mt-4 space-y-2 text-sm">
                {points.map((point) => (
                    <li key={point} className="flex gap-2">
                        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-orange-500" /> {point}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Minh hoạ màn hình video dọc (chỉ dùng CSS, không cần ảnh). */
function PhoneMockup() {
    return (
        <div className="relative mx-auto w-full max-w-xs">
            <div className="absolute -inset-6 -z-10 rounded-[3rem] bg-gradient-to-br from-orange-400/30 to-fuchsia-400/30 blur-2xl" />
            <div className="rounded-[2.5rem] border-8 border-zinc-900 bg-zinc-900 shadow-2xl dark:border-zinc-700">
                <div className="relative aspect-[9/16] overflow-hidden rounded-[2rem] bg-gradient-to-b from-amber-100 via-rose-100 to-orange-200 dark:from-zinc-800 dark:via-zinc-800 dark:to-zinc-700">
                    {/* Người mẫu cách điệu */}
                    <div className="absolute inset-x-0 top-[14%] flex flex-col items-center">
                        <div className="size-16 rounded-full bg-gradient-to-b from-amber-200 to-amber-300 shadow-inner dark:from-zinc-500 dark:to-zinc-600" />
                        <div className="mt-2 h-44 w-28 rounded-t-[3rem] rounded-b-2xl bg-gradient-to-b from-rose-400 to-orange-400 shadow-lg" />
                    </div>
                    {/* Lớp tối phía dưới để phụ đề dễ đọc */}
                    <div className="absolute inset-x-0 bottom-0 h-3/5 bg-gradient-to-t from-black/70 via-black/30 to-transparent" />
                    {/* Phụ đề */}
                    <div className="absolute inset-x-4 bottom-[22%] space-y-2 text-center">
                        <p className="text-lg leading-tight font-extrabold text-white [text-shadow:0_2px_6px_rgb(0_0_0/0.6)]">
                            Mặc đi đâu cũng được khen
                        </p>
                        <p className="text-xs font-semibold text-white [text-shadow:0_1px_4px_rgb(0_0_0/0.7)]">Vải mềm, form đứng dáng, có đủ size</p>
                    </div>
                    {/* Nút bên phải */}
                    <div className="absolute right-2 bottom-[30%] flex flex-col gap-3">
                        {['♥', '💬', '↗'].map((icon) => (
                            <span key={icon} className="flex size-8 items-center justify-center rounded-full bg-black/25 text-xs text-white">
                                {icon}
                            </span>
                        ))}
                    </div>
                    {/* Giỏ hàng */}
                    <div className="absolute inset-x-3 bottom-3 flex items-center gap-2 rounded-xl bg-white/90 p-2 text-[10px] shadow dark:bg-zinc-900/90">
                        <span className="size-8 shrink-0 rounded-md bg-gradient-to-br from-rose-300 to-orange-300" />
                        <span className="flex-1 leading-tight">
                            <span className="block font-semibold">Đầm dự tiệc ren</span>
                            <span className="text-zinc-500">Xem màu, size trong giỏ hàng</span>
                        </span>
                        <span className="rounded-md bg-orange-500 px-2 py-1 font-semibold text-white">Mua</span>
                    </div>
                    {/* Thanh tiến độ */}
                    <div className="absolute inset-x-3 top-3 flex gap-1">
                        {[1, 1, 0.4, 0, 0].map((fill, i) => (
                            <span key={i} className="h-0.5 flex-1 overflow-hidden rounded bg-white/40">
                                <span className="block h-full bg-white" style={{ width: `${fill * 100}%` }} />
                            </span>
                        ))}
                    </div>
                </div>
            </div>
            {/* Thẻ nổi */}
            <div className="absolute top-10 -left-10 hidden rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs shadow-lg sm:block dark:border-zinc-700 dark:bg-zinc-900">
                <p className="font-semibold">Hook đề xuất</p>
                <p className="text-zinc-500">Tò mò · Xu hướng · Review</p>
            </div>
            <div className="absolute top-1/3 -right-8 hidden rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs shadow-lg sm:block dark:border-zinc-700 dark:bg-zinc-900">
                <p className="flex items-center gap-1 font-semibold">
                    <Zap className="size-3 text-orange-500" /> Đang xuất video
                </p>
                <div className="mt-1 h-1.5 w-28 overflow-hidden rounded bg-zinc-200 dark:bg-zinc-700">
                    <div className="h-full w-3/4 bg-gradient-to-r from-orange-500 to-rose-500" />
                </div>
            </div>
        </div>
    );
}
