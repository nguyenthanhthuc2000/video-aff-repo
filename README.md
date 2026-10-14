# Video Aff

Web tạo video affiliate Shopee: Laravel 12 + React + Inertia (khởi tạo từ `laravel/react-starter-kit`).

Tài liệu: [thiết kế hệ thống](docs/thiet-ke.md), [phim hoạt hình (đề xuất)](docs/phim-hoat-hinh.md), [danh sách việc](docs/tasks.md).

## Chạy bằng Docker

Cần Docker và Docker Compose.

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
docker compose exec node npm run build
```

Mở http://localhost:8080. Vite (hot reload) chạy ở cổng 5173.

**Lỗi `Vite manifest not found`:** service `node` chạy `npm install` khoảng 1 phút rồi mới bật Vite. Trong lúc đó (hoặc khi `node` đang dừng) chưa có `public/hot`, Laravel tìm `public/build/manifest.json` và báo lỗi. `docker compose exec node npm run build` tạo bản build dự phòng; khi Vite dev chạy, Laravel vẫn ưu tiên Vite. Bản build không tự cập nhật, nên chạy lại lệnh này sau khi sửa giao diện nếu dùng app lúc Vite tắt. Nếu báo `service "node" is not running`, dùng `docker compose run --rm node sh -c "npm install && npm run build"`.

| Dịch vụ | Việc | Cổng |
| --- | --- | --- |
| `app` | PHP 8.3-FPM, có ffmpeg | |
| `queue` | `php artisan queue:work` cho job nền | |
| `nginx` | web server | 8080 (`APP_PORT`) |
| `db` | MySQL 8.4 | 3306 (`FORWARD_DB_PORT`) |
| `node` | Vite dev server | 5173 |

App dùng MySQL. `.env.example` đã đặt sẵn `DB_HOST=db`, `video_aff` / `video_aff` / `secret`, khớp với service `db` trong Docker.

**Tài khoản admin:** `migrate --seed` chạy `AdminUserSeeder`, tạo tài khoản theo `ADMIN_NAME`, `ADMIN_EMAIL` (mặc định `admin@video-aff.local`) và `ADMIN_PASSWORD` trong `.env`. Để trống `ADMIN_PASSWORD` thì seeder sinh mật khẩu ngẫu nhiên khi tạo mới và in ra terminal một lần. Muốn đặt lại mật khẩu: điền `ADMIN_PASSWORD` rồi chạy `docker compose exec app php artisan db:seed --class=AdminUserSeeder`.

Trên Linux, nếu file tạo ra bị sai quyền, build lại với UID của bạn: `UID=$(id -u) GID=$(id -g) docker compose build`.

Lệnh hay dùng:

```bash
docker compose exec app php artisan test
docker compose exec app php artisan tinker
docker compose logs -f queue
docker compose exec node npm run build   # build giao diện (public/build)
docker compose down        # dừng
docker compose down -v     # dừng và xoá dữ liệu MySQL
```

## Chạy không dùng Docker

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed && php artisan storage:link
composer run dev
```

Khi chạy không qua Docker, đổi `DB_HOST=127.0.0.1` trong `.env` (MySQL của Docker vẫn mở cổng 3306), hoặc dùng `DB_CONNECTION=sqlite`.

Cần cài `ffmpeg` trên máy (có libass). `composer run dev` đã chạy kèm queue worker.

## Chế độ Claude và Muse

Mặc định (không có khoá API) app chạy **bản web**, không tốn phí API:

- **Claude bản web:** app tạo sẵn prompt, bạn copy sang [claude.ai](https://claude.ai) (đăng nhập tài khoản của bạn) kèm ảnh sản phẩm, rồi dán câu trả lời JSON về để nhập kịch bản.
- **Muse bản web:** mỗi cảnh có prompt sẵn và ảnh tham chiếu để tải. Bạn tạo ảnh trên [meta.ai](https://www.meta.ai), tải về rồi tải lên đúng cảnh. Ảnh tải lên được tính là đã duyệt.

Muốn tự động qua API thì đặt khoá trong `.env` (không commit, không gửi qua chat):

| Biến | Dùng cho | Khi để trống |
| --- | --- | --- |
| `ANTHROPIC_API_KEY` | Claude API viết kịch bản (`CLAUDE_MODEL`, mặc định `claude-opus-5-5`) | Claude bản web |
| `FAL_KEY` | Meta Muse Image qua fal (`meta/muse-image/edit`) | Muse bản web |

**Claude bằng gói Claude Pro/Max (`SCRIPT_DRIVER=claude_cli`, không cần API key):** app gọi Claude Code CLI (`claude -p`, đã cài trong image PHP) để viết kịch bản và phân tích sản phẩm trong job nền, mọi tool của CLI bị tắt. Đăng nhập ngay trên giao diện: menu **Xác thực Claude** → Bắt đầu đăng nhập → mở link, đăng nhập Claude, dán mã về (dịch vụ `browser` chạy `claude setup-token`). Token lưu mã hoá trong database và có hiệu lực ngay. Cách khác: chạy `claude setup-token` trên máy rồi dán token vào màn hình đó hoặc vào `CLAUDE_CODE_OAUTH_TOKEN` trong `.env`. Có token mà không có `ANTHROPIC_API_KEY` thì app tự dùng `claude_cli`. Lượt dùng tính vào hạn mức của gói; chạm giới hạn thì job tự thử lại sau. `CLAUDE_CLI_MODEL` chọn model (ví dụ `sonnet`, `opus`).

**Muse qua trình duyệt ngầm (`MUSE_DRIVER=browser`):** dịch vụ `browser` (Playwright) đăng nhập [muse.ai](https://muse.ai) bằng tài khoản của bạn và gửi lệnh tạo ảnh, nên tạo ảnh và chạy ngầm được mà không cần `FAL_KEY`. Đặt `MUSE_DRIVER=browser` và `MUSE_BROWSER_TOKEN` (chuỗi ngẫu nhiên) trong `.env`, chạy `docker compose up -d --build browser` và `docker compose restart app queue`, rồi vào **Xác thực Muse** trên menu: nhập email, nhập mã OTP gửi về mail. Phiên đăng nhập lưu trong volume `browser-data`; mật khẩu không được lưu. Điều khoản của Meta không cho phép truy cập tự động nên tài khoản có thể bị yêu cầu xác minh hoặc giới hạn, và bước tự động có thể hỏng khi muse.ai đổi giao diện (xem ảnh chụp trên màn hình Xác thực Muse).

**Phân cảnh bằng Muse:** dự án có video mẫu hoặc nhạc nền thì trước khi viết kịch bản, app gửi video mẫu (có tiếng), nhạc nền và ảnh sản phẩm cho Muse (đoạn chat phụ mới). Muse tự xem / nghe rồi trả JSON: đoạn nhạc bắt đầu, lời bài hát kèm mốc thời gian, từng shot (2–30 giây, động tác, máy quay, biểu cảm, đoạn video mẫu cần sao chép, lúc nào nhép lời / ngậm miệng). Thường mất 3–30 phút; job gửi rồi hỏi lại nên không chặn hàng đợi. Kịch bản (số cảnh, thời lượng, hành động), ảnh và clip làm theo phân cảnh này. Muse lỗi thì vẫn viết kịch bản như trước. Shot dài hơn 10 giây được Muse làm thành nhiều đoạn, đoạn sau nối từ khung cuối đoạn trước, rồi ghép lại thành một clip liền. Dán lời chuẩn ở trang **Âm thanh** để Muse dóng đúng lời.

**Dịch vụ `audio` (tuỳ chọn, phân tích bằng thuật toán):** Demucs tách giọng hát, faster-whisper ghi lời kèm mốc thời gian (CPU, miễn phí). Không còn tự chạy; chỉ chạy khi bấm phân tích ở trang **Âm thanh**, và chỉ dùng cho hát nhép khi dự án không có phân cảnh Muse.

Có thể ép chế độ bằng `SCRIPT_DRIVER=web|claude|fake` và `MUSE_DRIVER=web|fal|fake`. Sau khi sửa `.env` trong Docker, chạy `docker compose restart app queue`.

## Luồng dùng

1. **Người mẫu**: tải ảnh toàn thân, tick xác nhận đã có đồng ý của người mẫu.
2. **Sản phẩm**: kéo bookmark "Lấy từ Shopee" (trang Thêm sản phẩm) lên thanh bookmark một lần. Mở trang sản phẩm Shopee, cuộn xuống phần mô tả và đánh giá rồi bấm bookmark: sản phẩm được thêm ngay, job nền tải hết ảnh trên trang (tối đa 30) từ CDN Shopee và Claude API rút ra giá, size, chất liệu, màu, đặc điểm, đánh giá. Chưa có `ANTHROPIC_API_KEY` thì trang sản phẩm hiện prompt để dán sang claude.ai và dán JSON về.

   Máy chủ không tự đọc được link Shopee (Shopee trả trang rỗng và chặn API), nên trang phải được đọc trong trình duyệt của bạn.
3. Ở trang sản phẩm, tạo **dự án video** với một người mẫu. Tick **Chạy ngầm** để Claude viết kịch bản → Muse tạo ảnh → tự duyệt ảnh → xuất video, không cần ở lại trang (cần cả `ANTHROPIC_API_KEY` và `FAL_KEY`; bản web phải thao tác tay). Trang dự án cũng có nút **Chạy ngầm bằng Claude + Muse** cho các bước còn lại, và thanh tiến độ % cho từng video (cả ở danh sách dự án). Tick **Nhân vật chuyển động** và chọn kiểu (nhảy trend TikTok, hand dance, xoay khoe đồ, catwalk, lắc lư) nếu muốn video có chuyển động. Ở trang dự án có nút **Tạo thêm video** để làm thêm bản khác cho cùng sản phẩm.
4. **Kịch bản**: copy prompt sang claude.ai và dán câu trả lời về (hoặc bấm Tạo kịch bản nếu có khoá API) → sửa hook, chữ, lời, prompt ảnh → Lưu.
5. **Ảnh cảnh**: tạo ảnh trên meta.ai theo prompt từng cảnh rồi tải lên (hoặc bấm Tạo ảnh nếu có khoá fal, rồi duyệt / tạo lại).
5b. **Clip chuyển động** (khi đã tick): với `MUSE_DRIVER=browser`, bấm **Tạo clip bằng Muse cho mọi cảnh** (hoặc từng cảnh): app gửi ảnh cảnh đã duyệt kèm động tác cho muse.ai, chờ video xong rồi tự tải về (vài phút mỗi clip, `MUSE_CLIP_TIMEOUT`). Chạy ngầm thì tự tạo clip trước khi xuất video. Cũng có thể tự tạo ở công cụ khác rồi tải MP4 lên (tối đa 100MB). Cảnh có clip dùng clip, cảnh không có hoặc tạo lỗi dùng ảnh tĩnh; tiếng trong clip bị bỏ.

   Mỗi yêu cầu gửi muse.ai có một mã riêng `VA-XXXXXX` (Muse đặt làm tiêu đề ảnh/video). Job gửi xong là nhả hàng đợi rồi hỏi lại theo mã, nên nhiều cảnh, nhiều sản phẩm chạy song song và không lấy nhầm kết quả của nhau.
6. **Xuất video**: chọn hook, thêm nhạc nền (tuỳ chọn) → tải MP4 1080×1920.
7. Đăng Shopee Video và **bật nhãn nội dung AI**.

Chi phí: mỗi ảnh cảnh ghi `cost_usd` (mặc định 0,01 USD theo `FAL_MUSE_COST_USD`); số token Claude ghi vào log (`claude.script`). Tổng chi phí ảnh của dự án hiện trên trang dự án.
