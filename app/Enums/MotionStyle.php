<?php

namespace App\Enums;

use App\Models\Scene;

/** Kiểu chuyển động của nhân vật khi biến ảnh cảnh thành clip (muse.ai tạo, hoặc tự tạo rồi tải lên). */
enum MotionStyle: string
{
    case TiktokDance = 'tiktok_dance';
    case HandDance = 'hand_dance';
    case OutfitSpin = 'outfit_spin';
    case Catwalk = 'catwalk';
    case Groove = 'groove';

    public function label(): string
    {
        return match ($this) {
            self::TiktokDance => 'Nhảy trend TikTok',
            self::HandDance => 'Vũ đạo tay (hand dance)',
            self::OutfitSpin => 'Xoay người khoe đồ',
            self::Catwalk => 'Catwalk tạo dáng',
            self::Groove => 'Lắc lư theo nhạc',
        };
    }

    /** Mô tả động tác bằng tiếng Anh cho công cụ tạo video. */
    public function moves(): string
    {
        return match ($this) {
            self::TiktokDance => 'does a short cheerful TikTok trend dance: light rhythmic steps side to side, arm waves, claps and a playful pose on the beat',
            self::HandDance => 'does a TikTok hand-gesture dance: quick rhythmic hand and arm moves in front of the chest and face, small head nods, feet mostly still',
            self::OutfitSpin => 'does a slow outfit-reveal spin: turns around once to show the front, side and back of the outfit, then poses facing the camera',
            self::Catwalk => 'walks toward the camera like a runway catwalk, then stops and strikes two confident model poses',
            self::Groove => 'grooves gently to the music: relaxed body sway, shoulder bounces and a natural smile',
        };
    }

    public function prompt(Scene $scene): string
    {
        return "Animate this photo into a 5-second vertical 9:16 video. The same person {$this->moves()}. "
            ."Scene context: {$scene->visual}. "
            .'Keep the face, hair and the outfit exactly the same (color, pattern, fabric, shape). '
            .'Full body stays in frame, smooth natural motion, static camera, no text, no extra people. '
            .'Keep it modest, friendly and family-friendly: not sexy, not provocative, no body-focused moves or camera angles. '
            .'If any part of this request is not appropriate to create, adjust the moves or framing yourself to a modest version and create that instead of declining.';
    }
}
