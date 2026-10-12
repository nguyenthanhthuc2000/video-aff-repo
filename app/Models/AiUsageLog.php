<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần gọi Claude: thao tác, token, chi phí (USD). Ghi bằng App\Services\Claude\AiUsage. */
class AiUsageLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** Tên thao tác hiển thị trên màn hình Chi phí AI. */
    public const ACTIONS = [
        'script' => 'Viết kịch bản & prompt phân cảnh',
        'product_import' => 'Đọc trang sản phẩm',
        'scene_prompt_rewrite' => 'Viết lại prompt phân cảnh (tạo lại ảnh)',
        'motion_analysis' => 'Phân tích video mẫu',
        'film_plan' => 'Phim: lên kế hoạch bộ phim',
        'film_episode' => 'Phim: viết tập (dàn ý, shot)',
        'auth_test' => 'Kiểm tra kết nối',
    ];

    protected $fillable = [
        'user_id', 'video_project_id', 'subject', 'action', 'provider', 'model', 'success',
        'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'duration_ms', 'error',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}
