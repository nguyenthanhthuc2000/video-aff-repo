<?php

namespace App\Jobs;

use App\Models\TopicShortScene;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Support\AiProviders;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/** Muse (hoặc nhà cung cấp ảnh đã chọn cho khung hình) vẽ ảnh cảnh theo prompt, khung dọc 9:16. */
class GenerateTopicSceneImageJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 10;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public TopicShortScene $scene) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    /** Chất liệu ảnh theo kiểu ảnh của short. */
    public const MEDIUMS = [
        'manga' => 'Black-and-white manga panel exactly like the original printed manga: bold ink linework, screentone shading, dynamic speed lines and impact effects, characters drawn on-model like the original manga. No speech bubbles, no sound-effect lettering.',
        'manga_color' => 'Full-color manga illustration like the official colored manga pages: clean ink linework with flat vivid colors, speed lines and impact effects, characters drawn on-model like the original manga. No speech bubbles, no sound-effect lettering.',
        'anime' => 'TV anime frame: cel animation, dramatic cel shading, high detail, characters drawn on-model like the original anime.',
    ];

    /** Nhân vật có trong cảnh (theo danh sách nhân vật của short), kèm ảnh mẫu nếu đã tải. */
    public static function characters(TopicShortScene $scene): array
    {
        $names = array_map('mb_strtolower', $scene->characters ?? []);

        return array_values(array_filter($scene->topicShort->characters ?? [], fn ($c) => in_array(mb_strtolower($c['name']), $names, true)));
    }

    /** @return list<string> Ảnh mẫu nhân vật, rồi ảnh hiện tại của cảnh (wiki / tải lên) làm mẫu bố cục; Muse nhận tối đa 4 ảnh. */
    public static function references(TopicShortScene $scene): array
    {
        $paths = array_values(array_filter(array_column(self::characters($scene), 'image_path')));
        if (self::layoutReference($scene)) {
            $paths = [...array_slice($paths, 0, 3), $scene->image_path];
        }

        return array_slice($paths, 0, 4);
    }

    private static function layoutReference(TopicShortScene $scene): bool
    {
        return in_array($scene->image_source, ['web', 'upload'], true) && filled($scene->image_path);
    }

    public static function prompt(TopicShortScene $scene): string
    {
        $work = trim((string) $scene->topicShort->art_style);
        $medium = self::MEDIUMS[$scene->topicShort->image_style] ?? self::MEDIUMS['anime'];
        $lines = ['FRAME: vertical 9:16 portrait.', 'STYLE: '.($work !== '' ? "{$work}. " : '').$medium];

        $characters = self::characters($scene);
        if ($characters !== []) {
            $lines[] = '';
            $lines[] = 'CHARACTERS (keep each one identical to the original design: face, hair, eyes, outfit, colors):';
            $ref = 0;
            foreach ($characters as $character) {
                $tag = ! empty($character['image_path']) && $ref < 4 ? ' — looks exactly like attached reference image '.(++$ref) : '';
                $lines[] = "- {$character['name']}{$tag}: {$character['look']}";
            }
        }
        if (self::layoutReference($scene)) {
            $lines[] = '';
            $lines[] = 'The LAST attached image is a reference of this scene from the original anime: redraw that moment (composition, pose, setting) as a clean vertical frame.';
        }
        $lines[] = '';
        $lines[] = 'SCENE: '.trim((string) $scene->image_prompt);

        return implode("\n", $lines);
    }

    public function handle(): void
    {
        $scene = $this->scene->fresh();
        if ($scene === null || $scene->image_status !== 'pending') {
            return;
        }
        $generator = AiProviders::imageGenerator('keyframe');

        try {
            if ($scene->request_id === null) {
                if ($message = Credits::shortfall($scene->topicShort->user, CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                $scene->update(['request_id' => $generator->start(self::references($scene), self::prompt($scene))]);
                $this->release(15);

                return;
            }

            $result = $generator->poll($scene->request_id, self::references($scene));
            if ($result === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(30);

                return;
            }
            if ($e->retryable && Cache::increment("muse-retries:topic-scene:{$scene->id}") <= 2) {
                $scene->update(['request_id' => null]);
                $this->release(30);

                return;
            }
            $this->fail($e);

            return;
        }

        $this->save($scene, $result);
    }

    private function save(TopicShortScene $scene, GeneratedImage $result): void
    {
        $path = "topic-shorts/{$scene->topic_short_id}/scene-{$scene->id}-muse-".substr(md5($result->contents), 0, 8).".{$result->extension}";
        Media::disk()->put($path, $result->contents);
        $old = $scene->image_path;
        $scene->update(['image_source' => 'muse', 'image_status' => 'ready', 'image_path' => $path, 'image_url' => null, 'image_error' => null, 'request_id' => null]);
        $old && $old !== $path && Media::delete($old);

        Credits::charge(
            $scene->topicShort->user_id,
            CreditPricing::settings()['steps']['image'],
            'image',
            "topic-scene-image:{$scene->id}:".substr(md5($path), 0, 8),
            "Short {$scene->topicShort->topic}: ảnh cảnh {$scene->position}",
        );
    }

    public function failed(?Throwable $e): void
    {
        $this->scene->fresh()?->update(['image_status' => 'failed', 'request_id' => null, 'image_error' => mb_substr($e?->getMessage() ?: 'Vẽ ảnh thất bại.', 0, 500)]);
    }
}
