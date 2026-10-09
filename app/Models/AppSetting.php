<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cấu hình nhập từ giao diện, giá trị mã hoá bằng APP_KEY. */
class AppSetting extends Model
{
    public const CLAUDE_OAUTH_TOKEN = 'claude_oauth_token';

    /** Cài đặt độ dài video (menu Cài đặt video), JSON: {mode: auto|fixed, scene_count, target_seconds}. */
    public const VIDEO_LENGTH = 'video_length';

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
