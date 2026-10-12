<?php

namespace App\Support;

use App\Enums\MotionStyle;
use App\Enums\SceneImageStatus;
use App\Models\Scene;
use App\Models\VideoProject;
use App\Services\Video\VideoProbe;
use Illuminate\Support\Facades\Log;

/**
 * Một cảnh quay liền (one take): Muse chỉ làm clip vài giây nên video dài được nối từ nhiều nhịp.
 * Nhịp đầu tạo từ ảnh cảnh 1; khung cuối của clip nhịp k thành ảnh mở đầu nhịp k+1, xuất video cắt thẳng nên không thấy chỗ nối.
 */
final class OneTake
{
    public static function prompt(Scene $scene, ?MotionStyle $style = null): string
    {
        $first = $scene->videoProject->startsTake($scene);

        return 'Animate this photo into a '.VideoProject::ONE_TAKE_BEAT_SECONDS.'-second vertical 9:16 video that is one part of a single continuous take (one shot, no cuts). '
            .($first
                ? 'This is the beginning of the shot. '
                : 'This photo is the exact last frame of the previous part: continue from it seamlessly, starting in exactly this pose, '
                    .'with the same camera position, framing, lighting and location; no cut, no jump, no reset to a new pose. ')
            .$scene->productLine().' '
            .'What happens in this part: '.Framing::strip((string) $scene->visual).'. '
            .(($angle = ProductCoverage::moveLine($scene->angle)) !== '' ? $angle.' ' : '')
            .($style ? "The person {$style->moves()}. " : '')
            .'End this part on a clear, steady, natural moment (no motion blur, face visible) so the next part can continue from the last frame. '
            .MotionStyle::CONTINUITY.' '
            .Framing::VIDEO.' '
            .'Keep the face, hair and outfit exactly the same. '
            .ProductFidelity::VIDEO.' '
            .Realism::VIDEO.' No text, no extra people. '
            .'Keep it modest, friendly and family-friendly: not sexy, not provocative, no body-focused moves or camera angles. '
            .'If any part of this request is not appropriate to create, adjust the moves or framing yourself to a modest version and create that instead of declining; never alter the product to do so.';
    }

    /**
     * Clip của nhịp vừa xong: độ dài cảnh = độ dài thật của clip (cắt bớt sẽ lộ chỗ nối), khung cuối thành ảnh duyệt của nhịp sau
     * rồi tạo clip nhịp sau. Các nhịp sau đó tạo lại theo (khung mở đầu đã đổi).
     */
    public static function continueAfter(Scene $scene): void
    {
        $project = $scene->videoProject;
        if (! $project->one_take || $scene->clip_path === null) {
            return;
        }

        $probe = app(VideoProbe::class);
        $dir = sys_get_temp_dir().'/one-take-'.uniqid();
        mkdir($dir);
        $clip = "{$dir}/clip.".(pathinfo($scene->clip_path, PATHINFO_EXTENSION) ?: 'mp4');
        $frame = "{$dir}/last.jpg";

        try {
            file_put_contents($clip, Media::disk()->get($scene->clip_path));
            $duration = $probe->probe($clip)['duration_ms'] ?? null;
            if ($duration) {
                $scene->update(['duration_ms' => max(1000, min(10000, $duration))]);
            }

            $next = $project->scenes()->where('position', '>', $scene->position)->orderBy('position')->first();
            // Hết video, hoặc cảnh sau mở đầu đoạn của bộ đồ khác (có ảnh riêng, cắt cảnh đổi đồ): không nối khung.
            if ($next === null || $project->startsTake($next)) {
                return;
            }
            $nextTake = $project->takeStarts()->first(fn (Scene $start) => $start->position > $next->position)?->position;
            if (! $probe->lastFrame($clip, $frame)) {
                Log::warning('one-take: không lấy được khung cuối', ['scene' => $scene->id]);

                return;
            }

            $path = "frames/{$project->id}/{$next->id}-{$scene->clip_request_id}.jpg";
            Media::disk()->put($path, file_get_contents($frame));
            $next->images()->where('status', SceneImageStatus::Approved)->update(['status' => SceneImageStatus::Rejected]);
            $next->images()->create([
                'status' => SceneImageStatus::Approved,
                'path' => $path,
                'provider' => 'one_take',
                'prompt' => "Khung cuối clip cảnh {$scene->position}",
                'completed_at' => now(),
            ]);

            // Khung mở đầu đổi thì clip các nhịp sau không còn khớp: bỏ để tạo lại lần lượt.
            $project->scenes()->where('position', '>', $scene->position)
                ->when($nextTake, fn ($query) => $query->where('position', '<', $nextTake))
                ->update(['clip_path' => null, 'clip_status' => null, 'clip_error' => null, 'clip_request_id' => null]);
            $next->refresh()->queueClip();
        } finally {
            @unlink($clip);
            @unlink($frame);
            @rmdir($dir);
        }
    }
}
