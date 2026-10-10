<?php

namespace App\Enums;

/** Tiếng trong video: chỉ nhạc nền, người mẫu tự nói lời review, hoặc hát nhép theo nhạc nền. */
enum VoiceMode: string
{
    case Music = 'music';
    /** Người mẫu nói đúng lời voiceover của từng cảnh (AI làm clip có tiếng, khớp khẩu hình); các câu nối thành một bài review. */
    case Speak = 'speak';
    /** Người mẫu hát nhép theo nhạc nền: mỗi clip gửi kèm đúng đoạn nhạc của cảnh đó. */
    case Sing = 'sing';

    public function label(): string
    {
        return match ($this) {
            self::Music => 'Chỉ nhạc nền',
            self::Speak => 'Người mẫu nói (review)',
            self::Sing => 'Hát nhép theo nhạc',
        };
    }
}
