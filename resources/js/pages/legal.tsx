import { Head, Link } from '@inertiajs/react';

type Page = 'privacy' | 'terms' | 'data-deletion';

const titles: Record<Page, string> = {
    privacy: 'Chính sách quyền riêng tư',
    terms: 'Điều khoản sử dụng',
    'data-deletion': 'Hướng dẫn xoá dữ liệu',
};

function Privacy({ appName, contact }: { appName: string; contact: string }) {
    return (
        <>
            <p>{appName} giúp người bán tạo video giới thiệu sản phẩm bằng AI và đăng video lên Trang Facebook của chính họ.</p>
            <h2>Dữ liệu chúng tôi thu thập</h2>
            <ul>
                <li>Thông tin tài khoản: tên, email, mật khẩu (đã mã hoá).</li>
                <li>Nội dung bạn tải lên hoặc tạo ra: thông tin và ảnh sản phẩm, ảnh người mẫu, video mẫu, nhạc, kịch bản, ảnh và video do AI tạo.</li>
                <li>
                    Khi bạn kết nối Facebook: mã Trang, tên Trang và mã truy cập của Trang (lưu mã hoá) mà bạn chọn cấp quyền, qua các quyền pages_show_list,
                    pages_read_engagement, pages_manage_posts.
                </li>
            </ul>
            <h2>Cách chúng tôi sử dụng dữ liệu</h2>
            <ul>
                <li>Tạo kịch bản, ảnh và video theo yêu cầu của bạn.</li>
                <li>Dữ liệu Facebook chỉ dùng để liệt kê Trang bạn quản lý và đăng video bạn chủ động chọn đăng, với caption bạn duyệt. Chúng tôi không đọc tin nhắn, không đăng khi bạn chưa yêu cầu.</li>
                <li>Chúng tôi không bán dữ liệu và không chia sẻ dữ liệu Facebook với bên thứ ba.</li>
            </ul>
            <h2>Dịch vụ bên thứ ba</h2>
            <p>Để tạo nội dung, dữ liệu sản phẩm và hình ảnh có thể được gửi tới các dịch vụ AI (Claude của Anthropic, muse.ai) chỉ nhằm tạo nội dung cho bạn.</p>
            <h2>Lưu trữ và xoá</h2>
            <p>
                Dữ liệu được lưu trong thời gian bạn dùng dịch vụ. Kết quả phân tích phân cảnh (nhịp nhạc, kiểu shot, prompt) được giữ lại không gắn với tài
                khoản để cải thiện chất lượng tạo video. Bạn có thể ngắt kết nối Facebook hoặc xoá tài khoản bất kỳ lúc nào, xem{' '}
                <Link href={route('legal.data-deletion')}>Hướng dẫn xoá dữ liệu</Link>.
            </p>
            {contact && (
                <p>
                    Liên hệ: <a href={`mailto:${contact}`}>{contact}</a>
                </p>
            )}
        </>
    );
}

function Terms({ appName, contact }: { appName: string; contact: string }) {
    return (
        <>
            <ul>
                <li>Bạn chịu trách nhiệm về sản phẩm, hình ảnh, nhạc và nội dung bạn tải lên và đăng; bạn phải có quyền sử dụng chúng.</li>
                <li>Video có người do AI tạo: bạn đồng ý ghi rõ là nội dung AI khi nền tảng yêu cầu và không dùng để lừa dối người xem.</li>
                <li>Khi đăng lên Facebook, bạn tuân thủ Tiêu chuẩn cộng đồng và Chính sách nội dung có thương hiệu của Meta.</li>
                <li>Không dùng {appName} để đăng spam, nội dung vi phạm pháp luật, nội dung người lớn hay quảng cáo sai sự thật.</li>
                <li>Credits đã dùng để tạo nội dung không hoàn lại, trừ khi tạo lỗi do hệ thống.</li>
                <li>Chúng tôi có thể tạm khoá tài khoản vi phạm các điều trên.</li>
            </ul>
            {contact && (
                <p>
                    Liên hệ: <a href={`mailto:${contact}`}>{contact}</a>
                </p>
            )}
        </>
    );
}

function DataDeletion({ appName, contact }: { appName: string; contact: string }) {
    return (
        <>
            <p>Bạn có thể xoá dữ liệu của mình trên {appName} theo một trong các cách sau:</p>
            <ol>
                <li>
                    <b>Ngắt kết nối Facebook:</b> vào Cài đặt → Mạng xã hội → Ngắt kết nối. Mã truy cập và thông tin Trang bị xoá ngay.
                </li>
                <li>
                    <b>Gỡ app trên Facebook:</b> Facebook → Cài đặt → Ứng dụng và trang web → chọn {appName} → Gỡ. Mã truy cập sẽ không còn dùng được.
                </li>
                <li>
                    <b>Xoá toàn bộ tài khoản:</b> vào Cài đặt → Hồ sơ → Xoá tài khoản. Tài khoản, sản phẩm, dự án và các kết nối mạng xã hội bị xoá khỏi hệ thống (muốn xoá cả file ảnh / video đã tạo thì gửi yêu cầu qua email).
                </li>
                {contact && (
                    <li>
                        <b>Yêu cầu qua email:</b> gửi tới <a href={`mailto:${contact}`}>{contact}</a> từ email đăng ký, chúng tôi xoá trong vòng 30 ngày.
                    </li>
                )}
            </ol>
            <p>Video đã đăng lên Trang Facebook nằm trên Facebook; bạn xoá trực tiếp trên Trang.</p>
        </>
    );
}

/** Trang công khai: chính sách quyền riêng tư, điều khoản, hướng dẫn xoá dữ liệu (Meta yêu cầu khi cho người dùng kết nối Facebook). */
export default function Legal({ page, appName, contactEmail, updatedAt }: { page: Page; appName: string; contactEmail: string | null; updatedAt: string }) {
    const contact = contactEmail ?? '';

    return (
        <div className="mx-auto max-w-3xl px-4 py-10">
            <Head title={titles[page]} />
            <Link href="/" className="text-muted-foreground text-sm">
                ← {appName}
            </Link>
            <article className="mt-4 space-y-3 text-sm leading-relaxed [&_h2]:pt-3 [&_h2]:text-base [&_h2]:font-semibold [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5 [&_a]:underline">
                <h1 className="text-2xl font-semibold">{titles[page]}</h1>
                <p className="text-muted-foreground text-xs">Cập nhật: {updatedAt}</p>
                {page === 'privacy' && <Privacy appName={appName} contact={contact} />}
                {page === 'terms' && <Terms appName={appName} contact={contact} />}
                {page === 'data-deletion' && <DataDeletion appName={appName} contact={contact} />}
            </article>
            <nav className="text-muted-foreground mt-10 flex gap-4 text-xs">
                <Link href={route('legal.privacy')}>Chính sách quyền riêng tư</Link>
                <Link href={route('legal.terms')}>Điều khoản sử dụng</Link>
                <Link href={route('legal.data-deletion')}>Xoá dữ liệu</Link>
            </nav>
        </div>
    );
}
