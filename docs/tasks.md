# Danh sách việc MVP

Theo [thiet-ke.md](thiet-ke.md). Làm theo thứ tự; mỗi task xong là một commit lên `main`, có test.

| # | Task | Phụ thuộc | Cần từ bạn |
| --- | --- | --- | --- |
| T1 | Nền tảng dữ liệu: migrations, models, factories, policy theo `user_id` | | |
| T2 | Sản phẩm: danh sách, tạo/sửa, tải 2–5 ảnh, chọn ảnh chính | T1 | |
| T3 | Thư viện người mẫu: tải ảnh, xác nhận đồng ý (`consent_at`) | T1 | |
| T4 | Dự án video: tạo từ sản phẩm + người mẫu, trang chi tiết theo trạng thái (tự làm mới) | T2, T3 | |
| T5 | Kịch bản: `ScriptWriter` (Claude SDK, JSON có cấu trúc), `GenerateScriptJob`, sửa hook/cảnh trên giao diện | T4 | Khoá Claude API để chạy thật |
| T6 | Ảnh cảnh: `ImageGenerator` (driver Muse qua fal + driver giả để dev), `GenerateSceneImageJob`, duyệt / tạo lại ảnh | T5 | Khoá fal (hoặc Meta) để chạy thật |
| T7 | Ghép video: `FfmpegVideoRenderer` (zoom, chữ + phụ đề `.ass`, font tiếng Việt, cảnh CTA, nhạc nền), `RenderVideoJob`, tải MP4 | T6 | Nhạc nền có bản quyền (tuỳ chọn) |
| T8 | Vận hành: cấu hình `.env`, ghi chi phí từng lần gọi API, retry/lỗi, README | xuyên suốt | |
| T9 | Test + CI: feature test dùng service giả, CI xanh | xuyên suốt | |

Ghi chú:
- T5–T7 code và test được bằng service giả, không cần khoá. Khoá chỉ cần khi chạy thật.
- Mặc định dùng Muse qua fal (một khoá, trả theo lượt); đổi sang Meta Model API bằng `MUSE_DRIVER`.
- Chưa làm (giai đoạn 2): giọng đọc TTS, clip quay thật, clip chuyển động AI, A/B nhiều hook, tiện ích lấy ảnh Shopee.
