# Video Aff

Web tạo video affiliate Shopee: Laravel 12 + React + Inertia (khởi tạo từ `laravel/react-starter-kit`).

Thiết kế: [docs/thiet-ke.md](docs/thiet-ke.md).

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
```

Mở http://localhost:8080. Vite (hot reload) chạy ở cổng 5173.

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

Có thể ép chế độ bằng `SCRIPT_DRIVER=web|claude|fake` và `MUSE_DRIVER=web|fal|fake`. Sau khi sửa `.env` trong Docker, chạy `docker compose restart app queue`.

## Luồng dùng

1. **Người mẫu**: tải ảnh toàn thân, tick xác nhận đã có đồng ý của người mẫu.
2. **Sản phẩm**: dán link Shopee, tải 2–5 ảnh, nhập size, chất liệu, màu, vài đánh giá. Shopee chặn lấy dữ liệu tự động nên phải nhập tay.
3. Ở trang sản phẩm, tạo **dự án video** với một người mẫu.
4. **Kịch bản**: copy prompt sang claude.ai và dán câu trả lời về (hoặc bấm Tạo kịch bản nếu có khoá API) → sửa hook, chữ, lời, prompt ảnh → Lưu.
5. **Ảnh cảnh**: tạo ảnh trên meta.ai theo prompt từng cảnh rồi tải lên (hoặc bấm Tạo ảnh nếu có khoá fal, rồi duyệt / tạo lại).
6. **Xuất video**: chọn hook, thêm nhạc nền (tuỳ chọn) → tải MP4 1080×1920.
7. Đăng Shopee Video và **bật nhãn nội dung AI**.

Chi phí: mỗi ảnh cảnh ghi `cost_usd` (mặc định 0,01 USD theo `FAL_MUSE_COST_USD`); số token Claude ghi vào log (`claude.script`). Tổng chi phí ảnh của dự án hiện trên trang dự án.
