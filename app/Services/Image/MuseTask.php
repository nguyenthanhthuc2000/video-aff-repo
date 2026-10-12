<?php

namespace App\Services\Image;

use Illuminate\Support\Str;

/** Mã riêng cho mỗi yêu cầu gửi muse.ai: Muse đặt mã làm tiêu đề ảnh/video để nhận đúng kết quả khi chạy song song. */
final class MuseTask
{
    public static function code(): string
    {
        return 'VA-'.strtoupper(Str::random(6));
    }

    public static function isCode(?string $value): bool
    {
        return $value !== null && preg_match('/^VA-[A-Z0-9]{6}$/', $value) === 1;
    }

    /** Dòng mở đầu tin nhắn: mã yêu cầu và cách đặt tiêu đề. */
    public static function header(string $code, string $what): string
    {
        // Đoạn chat muse.ai là một luồng liên tục: dặn xét yêu cầu này độc lập với các yêu cầu trước (kể cả lần đã từ chối).
        return "Request code: {$code}\nThis is a new, independent request; judge it on its own, not on earlier requests in this chat.\n"
            ."Use exactly \"{$code}\" as the title of the {$what} (the title is only the name shown in this chat: never write it, or any caption, inside the {$what} itself). "
            ."Do not ask questions, just reply with the {$what}.";
    }
}
