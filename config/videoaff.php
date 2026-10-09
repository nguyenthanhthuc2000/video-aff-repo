<?php

return [

    // Disk lưu ảnh sản phẩm, ảnh người mẫu, ảnh cảnh và video xuất ra.
    'disk' => env('MEDIA_DISK', 'public'),

    'product_images_max' => 5,

    // Viết kịch bản:
    // - "web": copy prompt sang claude.ai (tài khoản đăng nhập), dán câu trả lời JSON về. Không cần khoá.
    // - "claude": gọi Claude API (cần ANTHROPIC_API_KEY).
    // - "fake": kịch bản mẫu (test).
    'script_driver' => env('SCRIPT_DRIVER') ?: (env('ANTHROPIC_API_KEY') ? 'claude' : 'web'),

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

    'fal' => [
        'key' => env('FAL_KEY'),
        'endpoint' => env('FAL_MUSE_ENDPOINT', 'meta/muse-image/edit'),
        // Giá tham khảo để ghi chi phí; kiểm tra lại trên trang giá của fal.
        'cost_per_image' => (float) env('FAL_MUSE_COST_USD', 0.01),
    ],

    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),

    // Số ảnh sản phẩm tối đa gửi kèm ảnh người mẫu khi tạo ảnh cảnh.
    'image_product_refs' => 3,

];
