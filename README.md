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
docker compose exec app php artisan migrate
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

Thông tin MySQL lấy từ biến `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` trong `.env` (mặc định `video_aff` / `video_aff` / `secret`). Docker ghi đè `DB_CONNECTION` và `DB_HOST`, nên `.env` vẫn có thể để `sqlite` khi chạy không qua Docker.

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
php artisan migrate && php artisan storage:link
composer run dev
```

Cần cài `ffmpeg` trên máy (có libass). `composer run dev` đã chạy kèm queue worker.

## Khoá API

Đặt trong `.env` (không commit, không gửi qua chat):

| Biến | Dùng cho | Khi để trống |
| --- | --- | --- |
| `ANTHROPIC_API_KEY` | Claude viết kịch bản (`CLAUDE_MODEL`, mặc định `claude-opus-5-5`) | Dùng kịch bản mẫu |
| `FAL_KEY` | Meta Muse Image qua fal (`meta/muse-image/edit`) mặc đồ lên người mẫu | Dùng lại ảnh người mẫu làm ảnh cảnh |

Có thể ép driver bằng `SCRIPT_DRIVER=fake|claude` và `MUSE_DRIVER=fake|fal`. Sau khi sửa `.env` trong Docker, chạy `docker compose restart app queue`.

## Luồng dùng

1. **Người mẫu**: tải ảnh toàn thân, tick xác nhận đã có đồng ý của người mẫu.
2. **Sản phẩm**: dán link Shopee, tải 2–5 ảnh, nhập size, chất liệu, màu, vài đánh giá. Shopee chặn lấy dữ liệu tự động nên phải nhập tay.
3. Ở trang sản phẩm, tạo **dự án video** với một người mẫu.
4. **Tạo kịch bản** → sửa hook, chữ, lời, prompt ảnh → Lưu.
5. **Tạo ảnh** cho các cảnh → duyệt ảnh giữ đúng sản phẩm, tạo lại ảnh sai (có thể sửa prompt).
6. **Xuất video**: chọn hook, thêm nhạc nền (tuỳ chọn) → tải MP4 1080×1920.
7. Đăng Shopee Video và **bật nhãn nội dung AI**.

Chi phí: mỗi ảnh cảnh ghi `cost_usd` (mặc định 0,01 USD theo `FAL_MUSE_COST_USD`); số token Claude ghi vào log (`claude.script`). Tổng chi phí ảnh của dự án hiện trên trang dự án.
