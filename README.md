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
php artisan migrate
composer run dev
```
