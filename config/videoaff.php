<?php

return [

    // Disk lưu ảnh sản phẩm, ảnh người mẫu, ảnh cảnh và video xuất ra.
    'disk' => env('MEDIA_DISK', 'public'),

    'product_images_max' => 5,

    // Viết kịch bản: "claude" gọi API thật, "fake" trả kịch bản mẫu (dev/test không cần khoá).
    'script_driver' => env('SCRIPT_DRIVER', env('ANTHROPIC_API_KEY') ? 'claude' : 'fake'),

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-opus-5-5'),
        'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 16000),
    ],

];
