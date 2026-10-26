# Kế hoạch: Kịch bản short có tư liệu thật

Ngày: 2026-10-23. Trạng thái: kế hoạch, chưa code.
Bản đọc trên web: https://claude.ai/artifact/MfmdUrsuHcQ7j974mnWvTV

## 1. Mục tiêu

Nhập một tiêu đề, ví dụ **"Vì sao Vegeta thay đổi?"**. Tool sẽ:

1. Tìm tư liệu thật trên internet (wiki, bài phân tích), tổng hợp lại và ghi rõ nguồn.
2. Viết kịch bản khoảng 60 giây, đúng nội dung truyện gốc.
3. Lên lịch cảnh (storyboard) dạng khung truyện tranh manga trắng đen.
4. Đọc lời kể bằng giọng AI, rồi dựng video 9:16.

**Giới hạn công cụ (người dùng chốt ngày 2026-10-26):** chỉ dùng **Claude** và **Muse**, không thêm API hay dịch vụ trả phí nào khác.

- **Claude** chạy qua Claude Code CLI bằng gói Pro/Max. Claude lo cả việc research web (WebSearch / WebFetch có sẵn trong CLI) lẫn viết kịch bản.
- **Muse** vẽ ảnh.
- **Còn lại chạy trên máy:** giọng đọc dùng Piper / Kokoro (container `audio` sẵn có), dựng video dùng ffmpeg, hiệu ứng âm thanh là file có sẵn trong repo.

## 2. Hiện trạng

| Phần | Đã có | Còn thiếu |
|---|---|---|
| Viết kịch bản | Shorts chủ đề (`ShortWriter`): 6–8 cảnh, 4 dạng (khoảnh khắc, sự thật, đối đầu, top 5) | Claude viết theo trí nhớ, không tìm tư liệu, không có nguồn, nên có thể sai chi tiết |
| Ảnh | Ảnh wiki, Muse vẽ hoặc tải lên. Thư viện 30 nhân vật × 9 biểu cảm (E1–E9) và tab đánh nhau (A1–A6, chiêu S1–S4) | Cảnh chưa dùng thẳng ảnh thư viện theo động tác. Chưa có trang truyện nhiều khung |
| Giọng đọc | TTS tiếng Việt (Piper) và tiếng Anh (Kokoro), có mốc thời gian từng câu | Chưa có giọng riêng cho thoại nhân vật |
| Dựng video | `ShortVideo`: ffmpeg 9:16, zoom chậm, chữ lớn, phụ đề, nhạc nền | Chưa có hiệu ứng lia lần lượt qua từng khung truyện |

## 3. Luồng mới (6 bước)

### Bước 1: Nhập chủ đề

Người dùng nhập:

- tiêu đề;
- bộ truyện (mặc định Dragon Ball);
- độ dài (60 giây);
- dạng short;
- giọng đọc;
- tuỳ chọn "Tìm tư liệu trên web trước khi viết" (mặc định bật).

### Bước 2: Research (tìm tư liệu)

Claude dùng **web search** và **web fetch** để tìm và đọc nguồn. Kết quả là một **bản tư liệu** (JSON), gồm:

- **Dòng sự kiện:** arc, chương hoặc tập, chuyện gì xảy ra, kèm link nguồn.
- **Câu thoại nổi bật:** câu ngắn, có nguồn.
- **Chi tiết đắt giá:** dùng làm hook.
- **Giả thuyết của fan:** ghi rõ là giả thuyết, không phải sự thật.
- **Danh sách nguồn:** URL và tiêu đề.

Nguồn ưu tiên: Dragon Ball Wiki (Fandom), Kanzenshuu (bản dịch và phỏng vấn tác giả), Wikipedia. Không lấy kịch bản của các kênh YouTube khác.

**Người dùng duyệt bản tư liệu trước khi viết:** bỏ tick những chi tiết không dùng, thêm ghi chú, hoặc bấm "Tìm lại".

Dàn ý ví dụ cho "Vì sao Vegeta thay đổi?" (cần research để kiểm chứng):

| Giai đoạn | Vegeta lúc đó |
|---|---|
| Saiyan | Kẻ thù, kiêu ngạo, muốn chiếm Trái Đất |
| Namek | Liên minh bất đắc dĩ. Bị Frieza giết, khóc kể chuyện tộc Saiyan bị diệt |
| Android / Cell | Có con trai Trunks. Phát cuồng khi Trunks tương lai bị giết |
| Buu | Thành Majin Vegeta, rồi hi sinh vì gia đình. Thừa nhận Goku là số 1 |
| Super | Người chồng, người cha. Vẫn ganh đua nhưng có lòng tự trọng mới |

### Bước 3: Viết kịch bản

`ShortWriter` mở rộng: Claude **chỉ được viết dựa trên bản tư liệu đã duyệt**.

- Mỗi cảnh ghi `fact_ids`, tức là sự kiện nguồn mà cảnh đó dựa vào, để kiểm tra lại được.
- 60 giây tương đương khoảng 180 từ tiếng Việt (3 từ mỗi giây), chia 8–10 cảnh.
- Giữ khuôn: hook 3 giây đầu, các ý cắt nhanh, chi tiết bất ngờ để cuối, kết bằng câu hỏi cho người xem.

### Bước 4: Lịch cảnh truyện tranh (storyboard)

Mỗi cảnh là một trang truyện gồm:

- **Bố cục:** 1 khung lớn, 2 khung hoặc 3 khung xếp dọc.
- **Mỗi khung:**
  - nhân vật;
  - mã biểu cảm (E1–E9), động tác (A1–A6) hoặc chiêu (S1–S4), lấy ảnh từ thư viện `/dragon-ball`;
  - ghi chú góc máy.
- **Thời gian:** bắt đầu và kết thúc, khớp với câu kể.

Giao diện hiện bảng timeline để sửa, đổi ảnh, đổi thời lượng từng cảnh.

### Bước 5: Ảnh và ghép trang

- **Nguồn ảnh:** ưu tiên ảnh đã vẽ trong thư viện nhân vật. Khung nào thiếu ảnh thì báo để người dùng vẽ ở `/dragon-ball`. Có thể dùng ảnh wiki, Muse vẽ hoặc tải lên như hiện tại.
- **Cách ghép:** tool tự ghép các khung thành trang manga 1080×1920 bằng ffmpeg: nền trắng, viền đen, khe giữa các khung. Không nhờ Muse vẽ cả trang, vì Muse hay sai chữ và bố cục.

### Bước 6: Giọng đọc và dựng video

- **Giọng đọc:** dùng TTS hiện có. Mốc thời gian từng câu quyết định độ dài từng cảnh.
- **Chuyển động:** trang nhiều khung thì camera lia lần lượt từ khung trên xuống khung dưới, như người đang đọc truyện. Trang một khung giữ kiểu zoom chậm như hiện tại.
- **Còn lại:** phụ đề, nhạc nền, nhãn nội dung AI.

## 3b. Bổ sung (2026-10-26)

### 1. Kiểm chứng sau khi viết (fact-check)

Sau khi viết kịch bản, Claude chạy thêm một lượt đọc lại và so từng câu kể với bản tư liệu đã duyệt.

- Mỗi câu được gắn một trong ba nhãn: "có nguồn", "suy diễn" hoặc "không có nguồn".
- Câu "không có nguồn" hiện màu đỏ ở bước Kịch bản. Bạn có thể sửa câu, bấm viết lại riêng câu đó, hoặc bỏ qua.
- Chưa xử lý hết câu đỏ thì nút Dựng video hiện cảnh báo, nhưng không chặn.

### 2. Chọn hook

Claude sinh 3 câu hook khác nhau cho cảnh 1, mỗi câu một kiểu:

- câu hỏi gây tò mò;
- khẳng định gây sốc;
- con số hoặc so sánh.

Bạn chọn 1 câu. Câu đã chọn được lưu lại cho thống kê sau này.

### 3. Kho tư liệu dùng lại

Tư liệu đã duyệt được lưu vào bảng `lore_facts`, gắn với nhân vật và arc (ví dụ "Vegeta / Namek").

- Lần research sau, Claude đọc kho trước, chỉ tìm thêm trên web những gì còn thiếu. Nhanh hơn, ít lượt tìm hơn, và giữ chi tiết nhất quán giữa các video.
- Có trang "Kho tư liệu" để xem, sửa hoặc xoá từng chi tiết.

### 4. Hiệu ứng âm thanh và nhạc theo cảm xúc

- **Hiệu ứng âm thanh:** một bộ nhỏ file miễn phí bản quyền (CC0) để trong `resources/sfx/`: vút, va chạm, nổ năng lượng, tiếng "!" bất ngờ, lật trang.
  - Mỗi khung truyện có mã động tác (A1–A6, S1–S4), tool tự gắn âm tương ứng. Ví dụ: A1 Đấm gắn tiếng va chạm, S1 Kamehameha gắn tiếng nổ năng lượng.
  - Âm được trộn bằng ffmpeg, nhỏ hơn giọng đọc.
- **Nhạc nền:** Claude gắn nhãn cảm xúc cho từng cảnh (căng thẳng, buồn, hào hùng, hài). Tool gợi ý bài trong thư viện nhạc của bạn có nhãn đó. Không dùng API nhạc ngoài.

### 5. Báo thiếu ảnh và chi phí trước khi vẽ

Storyboard xong, trang hiện bảng tổng hợp:

- bao nhiêu khung đã có ảnh trong thư viện `/dragon-ball`;
- bao nhiêu khung còn thiếu;
- ước tính số Credits để Muse vẽ phần thiếu.

Nút "Vẽ tất cả khung thiếu" đưa các ô thiếu vào hàng đợi vẽ của thư viện, kèm nút Tạo lại như hiện tại. Ảnh vẽ xong được ghép vào trang tự động.

### 6. Series nhiều phần

Chủ đề dài (ví dụ "Cuộc đời Vegeta") được Claude đề xuất chia thành 2–5 phần, mỗi phần khoảng 60 giây.

- Các phần dùng chung một bản tư liệu.
- Phần sau mở bằng câu nhắc lại ngắn. Phần trước kết bằng "Xem phần tiếp" thay cho câu hỏi.
- Dữ liệu: bảng `topic_series`. `topic_shorts` thêm `series_id` và `part`.

## 4. Kỹ thuật

### Research

**Ưu tiên Claude Code CLI**, vì người dùng chỉ dùng Claude qua gói Pro/Max và chưa dùng API key. Đường Claude API viết sau, khi nào có API key.

- **Claude API (để sau):**
  - Dùng server tool `web_search_20260209` và `web_fetch_20260209`. Model Haiku dùng bản cơ bản `web_search_20250305` / `web_fetch_20250910`.
  - Đặt `max_uses` khoảng 8 lần tìm và 6 lần đọc, kèm danh sách trang ưu tiên.
  - Lần gọi đầu trả chữ có trích nguồn. Gặp `stop_reason: pause_turn` thì gửi lại để Claude làm tiếp.
  - Lần gọi thứ hai (không tool) chuyển bản tư liệu thành JSON theo schema, vì trích nguồn không dùng chung với `output_config.format`.
- **Claude Code CLI (gói Pro/Max):**
  - `ClaudeCli` hiện chạy với `--tools ''`. Thêm tham số để riêng job research bật `--tools WebSearch,WebFetch` và `--allowedTools WebSearch,WebFetch`, giữ `--json-schema`.
  - Tăng timeout cho job này.
- **Driver fake (test):** dùng bản tư liệu mẫu.
- **Model:** thêm thao tác `topic_research` vào `ClaudeModels::ACTIONS`. Gợi ý Sonnet 5.5.

### Dữ liệu

Bảng `topic_shorts` thêm:

- `use_research` (bool);
- `research` (JSON): `summary`, `facts[]` gồm `id`, `kind` (fact / quote / theory), `text`, `era`, `source_url`, `enabled`;
- `sources` (JSON): `url`, `title`;
- `research_notes` (text): ghi chú của người dùng.

Trạng thái mới:

```
researching → research_ready (chờ duyệt) → writing → ready
```

Bảng `topic_short_scenes` thêm:

- `fact_ids` (JSON);
- `layout` (`single`, `split2`, `split3`);
- `panels` (JSON): `character`, `pose`, `camera`.

### Code

Job và service:

- **Mới:** `ResearchTopicJob`, `TopicResearcher` (service), `MangaPage` (ghép trang bằng ffmpeg).
- **Sửa:**
  - `WriteTopicShortJob` và `ShortWriter`: nhận tư liệu, trả `fact_ids` và `panels`.
  - `ShortVideo::sceneFilter`: lia theo khung khi trang có 2–3 khung.
  - `FilmClaude::json` và `ClaudeCli::json`: thêm tuỳ chọn bật web tools.

Giao diện và route:

- **Giao diện:** các bước "Chủ đề → Tư liệu → Kịch bản → Storyboard → Ảnh → Video". Bước nào cũng có nút Tạo lại.
- **Route mới:**
  - `topic-shorts/{short}/research`: tìm lại tư liệu;
  - `topic-shorts/{short}/research/approve`: lưu tick và ghi chú, rồi viết kịch bản;
  - `topic-short-scenes/{scene}/compose`: ghép trang.

## 5. Rủi ro và cách xử lý

| Rủi ro | Cách xử lý |
|---|---|
| Thông tin sai | Bắt buộc có nguồn. Người dùng duyệt bản tư liệu. Claude không được thêm chi tiết ngoài bản tư liệu |
| Bản quyền | Lời kể tự viết, chỉ trích thoại ngắn. Không chép kịch bản kênh khác. Ảnh là fan art nên vẫn có thể bị chặn kiếm tiền |
| Muse từ chối vẽ | Ưu tiên ảnh thư viện đã vẽ được. Có nút Tạo lại và tải ảnh lên |
| Trang wiki chặn truy cập | web fetch chạy từ máy chủ Anthropic. Nếu vẫn bị chặn thì dùng kết quả tìm kiếm và nguồn khác |
| Chi phí research | Giới hạn số lần tìm mỗi short. Kiểm tra bảng giá web search trước khi bật mặc định |

## 6. Các mốc làm

1. **M1:** Research qua Claude Code CLI, màn hình duyệt bản tư liệu, kho tư liệu dùng lại (`lore_facts`).
2. **M2:** Viết kịch bản theo bản tư liệu, gắn nguồn cho từng cảnh, chọn 1 trong 3 hook, lượt kiểm chứng (câu đỏ).
3. **M3:** Storyboard truyện tranh, bảng thiếu ảnh và chi phí, nút vẽ khung thiếu, ghép trang manga.
4. **M4:** Khớp giọng đọc, lia theo khung, hiệu ứng âm thanh theo động tác, nhạc theo cảm xúc, dựng video.
5. **M5:** Series nhiều phần.
6. **M6:** Chạy thử trọn vẹn với "Vì sao Vegeta thay đổi?".

## 7. Câu hỏi còn mở

Nếu chưa có câu trả lời thì dùng giá trị mặc định ghi trong ngoặc.

1. ~~Chạy Claude bằng API key hay Claude Code CLI?~~ Đã chốt: chỉ Claude (CLI, gói Pro/Max) và Muse, không API nào khác.
2. Bóng thoại có chữ ngay trong ảnh, hay chỉ phụ đề? (Mặc định: chỉ phụ đề.)
3. Một giọng kể, hay thêm giọng riêng cho thoại nhân vật? (Mặc định: một giọng kể.)
4. Mỗi cảnh là một trang nhiều khung, hay một khung lớn? (Mặc định: Claude chọn 1–3 khung tuỳ cảnh.)
