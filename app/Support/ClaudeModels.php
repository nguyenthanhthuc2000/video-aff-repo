<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Chọn model Claude cho từng thao tác (admin: Cài đặt hệ thống → Model AI) để cân bằng chất lượng và chi phí.
 * Thứ tự ưu tiên: model riêng của thao tác → model mặc định đã chọn → cấu hình .env (CLAUDE_MODEL / CLAUDE_CLI_MODEL).
 */
class ClaudeModels
{
    /** Model cho chọn (ID dùng được cho cả Claude API và Claude Code CLI). family khớp bảng giá AiUsage. */
    public const MODELS = [
        'claude-opus-5-5' => ['name' => 'Opus 5.5', 'family' => 'opus', 'hint' => 'Chất lượng cao nhất, chậm và đắt nhất.'],
        'claude-sonnet-5-5' => ['name' => 'Sonnet 5.5', 'family' => 'sonnet', 'hint' => 'Cân bằng chất lượng và chi phí.'],
        'claude-haiku-5-5' => ['name' => 'Haiku 5.5', 'family' => 'haiku', 'hint' => 'Rẻ và nhanh nhất, hợp việc đơn giản.'],
    ];

    /** Thao tác chọn được model riêng, kèm gợi ý cân bằng. */
    public const ACTIONS = [
        'script' => [
            'hint' => 'Viết 5 hook, kịch bản, lời đọc và prompt ảnh cho từng phân cảnh. Ảnh hưởng nhiều nhất đến chất lượng video.',
            'recommended' => 'claude-sonnet-5-5',
        ],
        'product_import' => [
            'hint' => 'Đọc trang Shopee / TikTok Shop lấy tên, giá, mô tả. Việc trích xuất đơn giản.',
            'recommended' => 'claude-haiku-5-5',
        ],
        'scene_prompt_rewrite' => [
            'hint' => 'Viết lại prompt khi bấm Tạo lại ảnh hoặc bị từ chối. Câu ngắn, gọi nhiều lần.',
            'recommended' => 'claude-haiku-5-5',
        ],
        'topic_short' => [
            'hint' => 'Shorts theo chủ đề: viết lời kể 30–60 giây và chia cảnh kèm từ khoá tìm ảnh. Cần đúng sự kiện trong truyện / phim.',
            'recommended' => 'claude-sonnet-5-5',
        ],
        'film_plan' => [
            'hint' => 'Phim hoạt hình: lên kế hoạch bộ phim (nhân vật, bối cảnh, dàn ý các tập) từ ý tưởng. Viết dài, cần sáng tạo.',
            'recommended' => 'claude-sonnet-5-5',
        ],
        'film_episode' => [
            'hint' => 'Phim hoạt hình: viết tập thành phân đoạn và shot (góc máy, hành động, lời thoại). Gọi nhiều lượt mỗi tập.',
            'recommended' => 'claude-sonnet-5-5',
        ],
        'motion_analysis' => [
            'hint' => 'Xem khung hình video mẫu và mô tả từng nhịp chuyển động. Cần đọc ảnh tốt.',
            'recommended' => 'claude-sonnet-5-5',
        ],
    ];

    /** @return array{default: ?string, actions: array<string, ?string>} */
    public static function settings(): array
    {
        $saved = json_decode((string) AppSetting::get(AppSetting::CLAUDE_MODELS), true) ?: [];
        $valid = fn ($model) => is_string($model) && isset(self::MODELS[$model]) ? $model : null;

        return [
            'default' => $valid($saved['default'] ?? null),
            'actions' => collect(self::ACTIONS)->mapWithKeys(fn ($_, $action) => [$action => $valid($saved['actions'][$action] ?? null)])->all(),
        ];
    }

    public static function save(?string $default, array $actions): void
    {
        AppSetting::put(AppSetting::CLAUDE_MODELS, json_encode([
            'default' => $default,
            'actions' => collect(self::ACTIONS)->mapWithKeys(fn ($_, $action) => [$action => $actions[$action] ?? null])->all(),
        ]));
    }

    /** Model cho thao tác; null = để Claude Code tự chọn mặc định. */
    public static function for(string $action, ?string $fallback): ?string
    {
        $settings = self::settings();

        return $settings['actions'][$action] ?? $settings['default'] ?? ($fallback ?: null);
    }
}
