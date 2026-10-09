<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Driver viết kịch bản đang dùng. Ngoài config (SCRIPT_DRIVER / khoá trong .env), token Claude đăng nhập
 * từ màn hình Xác thực Claude cũng bật "claude_cli" ngay, không cần khởi động lại queue.
 */
class ScriptDriver
{
    public static function current(): string
    {
        $driver = config('videoaff.script_driver');

        if ($driver === 'web' && self::claudeOauthToken() !== null) {
            return 'claude_cli';
        }

        return $driver;
    }

    /** Token gói Claude: đăng nhập trên giao diện được ưu tiên hơn .env. */
    public static function claudeOauthToken(): ?string
    {
        return AppSetting::get(AppSetting::CLAUDE_OAUTH_TOKEN) ?: (config('videoaff.claude_cli.oauth_token') ?: null);
    }
}
