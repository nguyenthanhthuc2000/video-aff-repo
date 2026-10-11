<?php

namespace App\Support;

/**
 * Khung hình mặc định: toàn thân, máy đặt xa (khoảng 3 m). Muse hay tự cận mặt / nửa người hoặc dí máy sát người,
 * nên mọi prompt ảnh và clip đều nhắc giữ trọn người từ đầu đến chân; chi tiết sản phẩm khoe bằng tay chỉ / chạm, không zoom.
 */
final class Framing
{
    public const IMAGE = 'Framing: wide full-body shot taken from about 3 meters away at eye level: her whole body is visible from the top of the head to the feet, '
        .'with some space above the head and below the feet, and she fills about two thirds of the frame height. '
        .'Never crop the head, hands, legs or feet; no close-up, no half-body or waist-up framing.';

    public const VIDEO = 'Framing: wide full-body shot from about 3 meters away for the whole video: her whole body from head to feet stays in the frame at all times, '
        .'with space above the head and below the feet. The camera keeps this distance: no zoom-in, no push-in, no close-up, never crop the head or feet; '
        .'show product details only with her hands and moves while the full body stays visible.';

    /** Câu tả khung hình cận (Claude viết sẵn trong kịch bản cũ): bỏ đi để không ngược với khung toàn thân. */
    private const CLOSE = '/close-?up|macro|close[- ]focus|(shoulders|chest|waist|knees?)[- ]up|half[- ]body|cropped out|face (is )?not needed|fills? (most|much) of the frame|steps? closer to the camera|camera (moves|held) close|\\bcận\\b|nửa người|sát (mặt|người)|không cần thấy mặt/iu';

    /** Bỏ các câu tả cận cảnh trong prompt / mô tả cảnh; không còn câu nào thì giữ nguyên. */
    public static function strip(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $kept = array_filter($sentences, fn (string $sentence) => ! preg_match(self::CLOSE, $sentence));

        return $kept === [] ? trim($text) : implode(' ', $kept);
    }
}
