<?php

namespace App\Support;

use App\Enums\MotionStyle;
use App\Enums\ScenePhase;
use App\Models\Scene;

/** Prompt sao chép chuyển động: người trong ảnh cảnh làm đúng động tác của người trong video mẫu đính kèm. */
final class MotionCopy
{
    public static function prompt(Scene $scene): string
    {
        $product = $scene->phase === ScenePhase::Before
            ? 'The person is not wearing or holding the product yet; her clothes, hair and face stay exactly as in the photo.'
            : $scene->productLine();

        $segment = $scene->motionSegment();
        $timeline = $scene->motionReference()?->motionTimeline($segment['start'], $segment['start'] + $segment['length']);
        $seconds = rtrim(rtrim(number_format($segment['length'], 1, '.', ''), '0'), '.');

        return "Create one short vertical 9:16 video, {$seconds} seconds long, the same length as the reference video. "
            .'The first attachment is a photo of the person; the second attachment is a reference video. '
            .'Animate the person in the photo so she performs exactly the same movements as the person in the reference video, '
            .'move for move from the first frame to the last: the same dance moves, gestures, poses, timing and rhythm, at the same real-time speed '
            .'(no slow motion, do not stretch or loop the moves), and the same camera framing and movement. '
            .'She starts moving right away from the very first frame, in the pose of the photo. '
            .($timeline ? $timeline.' ' : '')
            .'Use the reference video ONLY for the motion: keep the face, hair, outfit, product and background from the photo; '
            .'do not copy the person, clothes, background, text or logos from the reference video. '
            ."{$product} Scene context: {$scene->visual}. "
            .MotionStyle::CONTINUITY.' '
            .($scene->phase === ScenePhase::Before ? '' : ProductFidelity::VIDEO.' ')
            .'Keep the camera motion of the reference video; otherwise: '.Realism::VIDEO.' No text, no extra people. '
            .'Keep it modest and family-friendly: if a move in the reference is too suggestive, replace it with a modest move of similar rhythm, '
            .'and adjust anything else that is not appropriate yourself instead of declining.';
    }
}
