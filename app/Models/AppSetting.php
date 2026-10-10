<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cấu hình nhập từ giao diện, giá trị mã hoá bằng APP_KEY. */
class AppSetting extends Model
{
    public const CLAUDE_OAUTH_TOKEN = 'claude_oauth_token';

    /** Cài đặt độ dài video (menu Cài đặt video), JSON: {mode: auto|fixed, scene_count, target_seconds}. */
    public const VIDEO_LENGTH = 'video_length';

    /** Chế độ xử lý: auto (tự động mọi bước) hoặc manual (duyệt thủ công). */
    public const WORKFLOW = 'workflow';

    /** Bảng giá Claude và tỉ giá cho màn hình Chi phí AI, JSON: {usd_vnd, prices: {opus|sonnet|haiku: {input, output}}}. */
    public const AI_PRICING = 'ai_pricing';

    /** Model Claude theo thao tác, JSON: {default, actions: {script|product_import|...: model id}}. */
    public const CLAUDE_MODELS = 'claude_models';

    /** Giá bán Credits: % lãi theo dòng model, tối thiểu mỗi lần gọi AI, giá cố định các bước ảnh / clip / xuất video. */
    public const CREDIT_PRICING = 'credit_pricing';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function get(string $key): ?string
    {
        return static::find($key)?->value;
    }

    public static function put(string $key, ?string $value): void
    {
        $value === null
            ? static::whereKey($key)->delete()
            : static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
