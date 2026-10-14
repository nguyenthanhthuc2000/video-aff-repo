<?php

return [

    // Disk lưu ảnh sản phẩm, ảnh người mẫu, ảnh cảnh và video xuất ra.
    'disk' => env('MEDIA_DISK', 'public'),

    // Lấy từ Shopee tải hết ảnh trên trang (tối đa số này) để người dùng chọn.
    'product_images_max' => 30,

    // Host được phép tải ảnh khi lấy sản phẩm từ trang Shopee (bookmarklet). Khớp cả subdomain.
    // CDN ảnh sản phẩm được tải về: Shopee, TikTok Shop.
    'import_image_hosts' => ['susercontent.com', 'shopee.vn', 'shopeemobile.com', 'ibyteimg.com', 'tiktokcdn.com'],

    // Viết kịch bản:
    // - "web": copy prompt sang claude.ai (tài khoản đăng nhập), dán câu trả lời JSON về. Không cần khoá.
    // - "claude": gọi Claude API (cần ANTHROPIC_API_KEY).
    // - "claude_cli": Claude Code CLI đăng nhập bằng gói Claude Pro/Max (CLAUDE_CODE_OAUTH_TOKEN từ `claude setup-token`).
    // - "fake": kịch bản mẫu (test).
    'script_driver' => env('SCRIPT_DRIVER') ?: match (true) {
        (bool) env('ANTHROPIC_API_KEY') => 'claude',
        (bool) env('CLAUDE_CODE_OAUTH_TOKEN') => 'claude_cli',
        default => 'web',
    },

    'claude_cli' => [
        'binary' => env('CLAUDE_CLI_BINARY', 'claude'),
        'oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
        // Alias như "sonnet", "opus"; để trống thì dùng mặc định của gói.
        'model' => env('CLAUDE_CLI_MODEL'),
        'timeout' => (int) env('CLAUDE_CLI_TIMEOUT', 280),
    ],

    'claude_web_url' => env('CLAUDE_WEB_URL', 'https://claude.ai/new'),

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-opus-5-5'),
        'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 16000),
    ],

    // Tạo ảnh cảnh:
    // - "web": người dùng tự tạo ảnh trên meta.ai (Muse bản web) rồi tải lên. Không cần khoá.
    // - "fal": gọi Meta Muse Image qua API của fal.
    // - "fake": dùng lại ảnh người mẫu (test).
    'image_driver' => env('MUSE_DRIVER') ?: (env('FAL_KEY') ? 'fal' : 'web'),

    'muse_web_url' => env('MUSE_WEB_URL', 'https://www.meta.ai/'),

    // Clip chuyển động (nhân vật nhảy, xoay...) tạo bằng tay trên công cụ video AI (mặc định meta.ai) rồi tải lên từng cảnh.
    'motion_web_url' => env('MOTION_WEB_URL', 'https://www.meta.ai/'),

    'fal' => [
        'key' => env('FAL_KEY'),
        'endpoint' => env('FAL_MUSE_ENDPOINT', 'meta/muse-image/edit'),
        // MV: tạo bài hát mới (lời + phong cách → bài có giọng hát), trả phí theo lượt. Model mặc định: minimax, elevenlabs hoặc ace-step.
        'music_model' => env('FAL_MUSIC_MODEL', 'minimax'),
        // Giá tham khảo (USD) để ước tính trước khi tạo; kiểm tra lại ở fal.ai/pricing.
        'music_prices' => [
            'minimax' => ['per_song' => (float) env('FAL_MINIMAX_MUSIC_USD', 0.15)],
            'elevenlabs' => ['per_minute' => (float) env('FAL_ELEVENLABS_MUSIC_USD_PER_MIN', 0.8)],
            'ace-step' => ['per_second' => (float) env('FAL_ACE_STEP_USD_PER_SECOND', 0.0002)],
        ],
        // Giá tham khảo để ghi chi phí; kiểm tra lại trên trang giá của fal.
        'cost_per_image' => (float) env('FAL_MUSE_COST_USD', 0.01),
    ],

    // MUSE_DRIVER=browser: dịch vụ trình duyệt ngầm (docker service "browser") thao tác muse.ai.
    // Phân tích nhạc cho hát nhép (service docker "audio": Demucs tách giọng + faster-whisper ghi lời), chạy trên CPU.
    // Giọng đọc biểu cảm cho phim hoạt hình (service docker "expressive": Chatterbox, CPU, khoảng 1 phút mỗi câu).
    'expressive_tts' => [
        'url' => env('EXPRESSIVE_TTS_URL', 'http://expressive:3200'),
        'token' => env('AUDIO_ANALYZER_TOKEN'),
    ],

    'audio_analyzer' => [
        'url' => env('AUDIO_ANALYZER_URL', 'http://audio:3100'),
        'token' => env('AUDIO_ANALYZER_TOKEN'),
        // Tách giọng một bài 4–5 phút trên CPU mất vài phút.
        'timeout' => (int) env('AUDIO_ANALYZER_TIMEOUT', 900),
    ],

    // Email liên hệ hiện trên trang Chính sách / Điều khoản / Xoá dữ liệu.
    'contact_email' => env('CONTACT_EMAIL', env('MAIL_FROM_ADDRESS')),

    // Đăng video lên Trang Facebook (Reels) qua Graph API: app tạo ở developers.facebook.com.
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
    ],

    // Đăng video lên TikTok (Content Posting API): app tạo ở developers.tiktok.com.
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],

    'muse_browser' => [
        'url' => env('MUSE_BROWSER_URL', 'http://browser:3000'),
        'token' => env('MUSE_BROWSER_TOKEN'),
        // Chờ muse.ai tạo xong ảnh (giây, tính từ lúc gửi); job hỏi lại định kỳ, không giữ hàng đợi.
        'timeout' => (int) env('MUSE_BROWSER_TIMEOUT', 480),
        // Chờ muse.ai tạo xong clip chuyển động (giây).
        'clip_timeout' => (int) env('MUSE_CLIP_TIMEOUT', 900),
    ],

    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),

    // Video mẫu (sao chép chuyển động): dung lượng tối đa (KB) và độ dài khuyên dùng (giây).
    'sample_video_max_kb' => 102400,

    // Nhạc nền (menu Âm thanh): dung lượng tối đa (KB).
    'audio_max_kb' => 20480,
    'sample_video_recommended_seconds' => 30,

];
