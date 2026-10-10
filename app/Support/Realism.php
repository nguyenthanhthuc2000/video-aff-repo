<?php

namespace App\Support;

/**
 * Cho ảnh / clip trông như người thật tự quay chụp bằng điện thoại (UGC), không bóng bẩy kiểu AI:
 * AI hay ra da mịn như nhựa, ánh sáng lung linh, màu rực, bố cục hoàn hảo và chuyển động trôi chậm.
 */
final class Realism
{
    public const IMAGE = 'Make it look like a real, unedited smartphone photo taken by a friend for an everyday social media post, not an AI render or a studio shoot: '
        .'natural available light with real shadows, true-to-life colors (not oversaturated, no HDR glow), real skin texture with pores and small natural imperfections, '
        .'natural hair with a few flyaway strands, real fabric wrinkles and folds, slightly imperfect casual framing, a real lived-in location with normal everyday details. '
        .'Avoid: plastic or airbrushed skin, perfectly symmetrical doll face, glossy CGI look, dreamy glow, heavy background blur, cinematic color grading, stiff overly perfect posing.';

    public const VIDEO = 'Make it look like real footage filmed on a smartphone by a friend: subtle natural handheld camera movement, real-time natural speed (no slow motion), '
        .'natural physics for hair and fabric, natural blinking and breathing, the same face and body in every frame, no morphing, warping or melting, '
        .'no dreamy glow, no cinematic color grading, natural available light.';
}
