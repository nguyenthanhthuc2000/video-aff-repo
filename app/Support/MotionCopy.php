<?php

namespace App\Support;

use App\Enums\MotionStyle;
use App\Enums\ScenePhase;
use App\Models\ProductCategory;
use App\Models\Scene;

/** Prompt sao chép chuyển động: người trong ảnh cảnh làm đúng động tác của người trong video mẫu đính kèm. */
final class MotionCopy
{
    public static function prompt(Scene $scene): string
    {
        $product = $scene->phase === ScenePhase::Before
            ? 'The person is not wearing or holding the product yet; her clothes, hair and face stay exactly as in the photo.'
            : ProductCategory::productLine($scene->videoProject->product);

        return 'Create one short vertical 9:16 video (about the same length as the reference video, at most 10 seconds). '
            .'The first attachment is a photo of the person; the second attachment is a reference video. '
            .'Animate the person in the photo so she performs exactly the same movements as the person in the reference video: '
            .'the same dance moves, gestures, poses, timing and rhythm, and the same camera framing and movement. '
            .($scene->motionReference()?->motionTimeline() ? $scene->motionReference()->motionTimeline().' ' : '')
            .'Use the reference video ONLY for the motion: keep the face, hair, outfit, product and background from the photo; '
            .'do not copy the person, clothes, background, text or logos from the reference video. '
            ."{$product} Scene context: {$scene->visual}. "
            .MotionStyle::CONTINUITY.' '
            .($scene->phase === ScenePhase::Before ? '' : ProductFidelity::VIDEO.' ')
            .'Smooth natural motion, no text, no extra people. '
            .'Keep it modest and family-friendly: if a move in the reference is too suggestive, replace it with a modest move of similar rhythm, '
            .'and adjust anything else that is not appropriate yourself instead of declining.';
    }
}
