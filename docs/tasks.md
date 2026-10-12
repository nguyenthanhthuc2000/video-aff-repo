# Danh sách việc

Cập nhật: 2026-10-12. Thiết kế: [thiet-ke.md](thiet-ke.md) (hệ thống hiện tại), [phim-hoat-hinh.md](phim-hoat-hinh.md) (phim hoạt hình).

Quy ước: mỗi task có test; sao lưu DB trước mỗi migration; không xoá dữ liệu người dùng; commit / push khi được yêu cầu.

## Đã xong (video affiliate)

- Sản phẩm từ Shopee (bookmark), người mẫu, bối cảnh, video mẫu, nhạc.
- Claude qua Claude Code CLI (gói Pro/Max) hoặc API; Muse qua trình duyệt ngầm (ảnh, clip, phân tích).
- Phân cảnh Muse từ video mẫu + nhạc; shot dài 10–30 giây; quay toàn thân; 3 kiểu tiếng (nhạc nền / review / hát nhép).
- Chạy ngầm cả dự án; Credits, chi phí AI, trang admin.
- Đề xuất caption đăng bài; kho phân tích Muse (`muse:export`).
- Đăng Trang Facebook (Reels) và TikTok (đăng thẳng / hộp nháp); trang chính sách công khai.

## Phim hoạt hình

| # | Task | Phụ thuộc | Cần từ bạn |
| --- | --- | --- | --- |
| F0 | Thử nghiệm: 1 nhân vật 2D anime × 10 shot trên Muse (độ giống), thử giọng Kokoro tiếng Anh (chất lượng, giấy phép), đo thời gian clip. **Đã xong ảnh + giọng; còn thử clip** | | Đồng ý tốn lượt Muse cho clip thử; chọn giọng; ngân sách mỗi tập (mục 9) |
| F1 | **Xong.** Dữ liệu: `series`, `series_characters`, `series_locations`, `episodes`, `shots`, `shot_images`; policy theo `user_id` | F0 | |
| F2 | **Xong.** Tạo phim từ ý tưởng: form (độ tuổi từ … đến …, mặc định 6–12; chặn tên nhân vật có bản quyền), `PlanSeriesJob` (bible + nhân vật + bối cảnh + dàn ý các tập, quy tắc theo nhóm tuổi), sửa kế hoạch, lên kế hoạch lại | F1 | |
| F3 | Thiết kế nhân vật / bối cảnh. **Nhân vật xong:** trang nhân vật (thêm / xoá / sửa), thư viện ảnh (Muse vẽ: ảnh chính, bảng xoay, biểu cảm, tự mô tả; tải ảnh lên), chọn ảnh chính, chốt (`GenerateCharacterImageJob`, lời nhắn Muse riêng cho phim). Còn: ảnh bối cảnh | F2 | |
| F4 | **Xong.** Trang chi tiết tập; Claude viết tập: `WriteEpisodeJob` (dàn ý các phân đoạn) + `WriteSequenceJob` (shot từng phân đoạn, kèm vài shot cuối phân đoạn trước; viết tiếp được khi lỗi), sửa shot | F2 | |
| F5 | **Xong.** Keyframe: `GenerateShotImageJob` gửi kèm ảnh chính các nhân vật trong shot (+ ảnh bối cảnh khi có), vẽ cả tập / vẽ lại từng shot có ghi chú. Còn: ảnh bối cảnh, tự kiểm tra ảnh (khổ, đúng nhân vật) | F3, F4 | |
| F6 | **Xong phần giọng:** Kokoro trong dịch vụ `audio` (`POST /tts`), mỗi nhân vật một giọng riêng cố định (`Voices::assign`, chọn + nghe thử ở trang nhân vật), `ShotAudio` đọc lời từng shot (nút "Đọc lời thoại", miễn phí). Còn: xuất phụ đề `.srt`. Ban đầu: Kokoro TTS tiếng Anh trong dịch vụ `audio`, mỗi nhân vật một giọng; `SynthesizeDialogueJob`; chỉnh thời lượng shot theo lời thoại; xuất phụ đề `.srt` | F4 | Chọn giọng |
| F7 | Animatic: `RenderAnimaticJob` (ảnh tĩnh + tiếng + phụ đề, 16:9 / 9:16); màn hình storyboard + phát thử | F5, F6 | |
| F8 | **Xong bản đầu:** nút "Tạo video" (cả tập / từng shot): `AnimateShotJob` đọc lời → Muse làm clip 16:9 khớp môi theo tiếng thoại → `ShotVideo::mux` ghép clip + lời thoại (bỏ tiếng Muse). Còn: hàng đợi riêng, shot > 10 giây nhiều đoạn. Ban đầu: Clip: `AnimateShotJob` (nối khung shot liền, shot dài nhiều đoạn); hàng đợi `film` + worker riêng, giới hạn song song | F7 | |
| F9 | Ghép tập: `RenderEpisodeJob` (clip, thoại, nhạc, phụ đề, tiêu đề, 16:9); điều phối `Episode::advance()` chạy ngầm cả tập | F8 | Nhạc có quyền dùng |
| F10 | Dự toán Credits và thời gian trước khi chạy; trừ Credits theo bước | F5–F9 | Giá Credits cho phim (admin) |
| F11 | YouTube: kết nối kênh (OAuth Google), đăng tập "dành cho trẻ em", ảnh bìa, phụ đề, tiêu đề / mô tả / thẻ tiếng Anh (Claude), playlist; theo dõi hạn mức API | F9 | Tạo project Google Cloud, bật YouTube Data API, gửi kiểm duyệt API |
| F12 | Facebook video dài: đăng tập 5 phút lên Trang dạng video thường (tải theo đoạn) | F9 | |
| F13 | Phát hành thêm: trailer / Shorts 9:16, video tổng hợp 30–60 phút, mở đầu / kết thúc kênh, `SummarizeEpisodeJob` (sổ liên tục) | F9 | Nhạc hiệu có quyền dùng |
| F14 | Đa ngôn ngữ: Claude dịch tiêu đề, mô tả, phụ đề sang các thứ tiếng chọn khi tạo phim; tải lên YouTube cùng video | F11 | Chọn thứ tiếng |

Thứ tự đề xuất: F0 → F1–F3 (bộ phim) → F4–F7 (xem được animatic) → F8–F10 (tập hoàn chỉnh) → F11–F13 (phát hành). Nên gửi Google kiểm duyệt YouTube API sớm (song song F1), vì chờ duyệt lâu.
