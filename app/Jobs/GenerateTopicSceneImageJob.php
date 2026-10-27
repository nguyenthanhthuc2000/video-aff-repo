<?php

namespace App\Jobs;

use App\Models\CharacterPortrait;
use App\Models\TopicShortScene;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\ScenePromptFixer;
use App\Support\AiProviders;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\DragonBall;
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

    /** Muse bận (đủ 4 yêu cầu) quá số lần này (mỗi lần chờ 30 giây) thì dừng, để cảnh không treo hàng giờ. */
    public const MAX_BUSY = 40;

    public function __construct(public TopicShortScene $scene)
    {
        // Hàng riêng: job Claude / dựng video dài không chặn việc hỏi kết quả Muse.
        $this->onQueue('images');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    /** Chất liệu ảnh theo kiểu ảnh của short. */
    public const MEDIUMS = [
        'manga' => 'Black-and-white manga panel exactly like the original printed manga: bold ink linework, screentone shading, characters drawn on-model like the original manga. No speech bubbles, no sound-effect lettering.',
        'manga_color' => 'Full-color manga illustration like the official colored manga pages: clean ink linework with flat vivid colors, characters drawn on-model like the original manga. No speech bubbles, no sound-effect lettering.',
        'anime' => 'TV anime frame: cel animation, dramatic cel shading, high detail, characters drawn on-model like the original anime.',
    ];

    /**
     * Nhân vật có trong cảnh (theo danh sách nhân vật của short). ref: ảnh mẫu gửi kèm Muse, ưu tiên ảnh tải lên cho short,
     * không có thì ảnh thư viện nhân vật Ngọc Rồng đúng biểu cảm của cảnh (hoặc mặt thường).
     *
     * @return list<array{name: string, look: string, ref: ?string}>
     */
    public static function characters(TopicShortScene $scene): array
    {
        $names = array_map('mb_strtolower', $scene->characters ?? []);
        $short = $scene->topicShort;

        return array_values(array_map(function ($c) use ($scene, $short) {
            $ref = $c['image_path'] ?? null;
            if (! $ref && ($key = DragonBall::keyFor($c['name']))) {
                $ref = CharacterPortrait::best($short->user_id, $key, $scene->expression)?->image_path;
            }

            return ['name' => $c['name'], 'look' => $c['look'] ?? '', 'ref' => $ref];
        }, array_filter($short->characters ?? [], fn ($c) => in_array(mb_strtolower($c['name']), $names, true))));
    }

    /** @return list<string> Ảnh mẫu nhân vật, rồi ảnh hiện tại của cảnh (wiki / tải lên) làm mẫu bố cục; Muse nhận tối đa 4 ảnh. */
    public static function references(TopicShortScene $scene): array
    {
        $paths = array_slice(array_values(array_filter(array_column(self::characters($scene), 'ref'))), 0, 4);
        if (self::layoutReference($scene)) {
            $paths = [...array_slice($paths, 0, 3), $scene->image_path];
        }

        return array_slice($paths, 0, 4);
    }

    private static function layoutReference(TopicShortScene $scene): bool
    {
        return in_array($scene->image_source, ['web', 'upload'], true) && filled($scene->image_path);
    }

    /** Lời từ chối của AI chờ Claude viết lại prompt trước lần vẽ tới. */
    public static function rewriteKey(TopicShortScene $scene): string
    {
        return "muse-rewrite:topic-scene:{$scene->id}";
    }

    public static function freshKey(TopicShortScene $scene): string
    {
        return "muse-fresh-prompt:topic-scene:{$scene->id}";
    }

    /** Lần vẽ tới: Claude viết prompt mới theo lời kể của cảnh trước khi vẽ. */
    public static function markForFreshPrompt(TopicShortScene $scene): void
    {
        Cache::put(self::freshKey($scene), true, now()->addDay());
    }

    /** Lần vẽ lại sau khi bị từ chối: Claude viết lại prompt theo lời từ chối, giữ tên nhân vật và ảnh mẫu. */
    public static function markForRewrite(TopicShortScene $scene, string $refusal): void
    {
        Cache::put(self::rewriteKey($scene), $refusal, now()->addDay());
    }

    /** Mỗi lần bấm vẽ: đếm lại số lần Muse lỗi / bận từ đầu. */
    public static function resetCounters(TopicShortScene $scene): void
    {
        foreach (['muse-retries', 'muse-busy'] as $prefix) {
            Cache::forget("{$prefix}:topic-scene:{$scene->id}");
        }
    }

    /** Prompt vẽ cảnh; safe (sau khi AI từ chối): bỏ tên nhân vật / phim / tác giả, gọi nhân vật theo ảnh mẫu và mô tả ngoại hình. */
    public static function prompt(TopicShortScene $scene, bool $safe = false): string
    {
        $work = $safe ? '' : trim((string) $scene->topicShort->art_style);
        $medium = self::MEDIUMS[$scene->topicShort->image_style] ?? self::MEDIUMS['anime'];
        $lines = ['FRAME: vertical 9:16 portrait.', 'STYLE: '.($work !== '' ? "{$work}. " : '').$medium];

        $characters = self::characters($scene);
        if ($characters !== []) {
            $lines[] = '';
            $lines[] = 'CHARACTERS (keep each one identical to the original design: face, hair, eyes, outfit, colors):';
            $ref = 0;
            foreach ($characters as $character) {
                $tag = ! $safe && ! empty($character['ref']) && $ref < 4 ? ' — looks exactly like attached reference image '.(++$ref) : '';
                $lines[] = "- {$character['name']}{$tag}: {$character['look']}";
            }
        }
        if (! $safe && self::layoutReference($scene)) {
            $lines[] = '';
            $lines[] = 'The LAST attached image is a reference of this scene from the original anime: redraw that moment (composition, pose, setting) as a clean vertical frame.';
        }
        if ($scene->expression && isset(DragonBall::EXPRESSIONS[$scene->expression])) {
            $lines[] = '';
            $lines[] = 'MAIN EXPRESSION: '.DragonBall::EXPRESSIONS[$scene->expression][1].'.';
        }
        $lines[] = '';
        $lines[] = 'SCENE: '.trim((string) $scene->image_prompt);

        $text = implode("\n", $lines);
        if ($safe) {
            // Nhân vật gọi theo thứ tự (Character 1, 2...) để vẫn khớp ảnh mẫu và mô tả, rồi bỏ mọi tên riêng còn lại.
            foreach (self::characters($scene) as $i => $character) {
                $text = preg_replace('/\b(Son )?'.preg_quote($character['name'], '/').'\b/iu', 'Character '.($i + 1), $text);
            }
            $text = DragonBall::scrub($text);
        }

        return $text;
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
                // Bấm Muse vẽ: Claude viết prompt khớp lời kể hiện tại của cảnh (lỗi thì vẽ theo prompt cũ).
                if (Cache::pull(self::freshKey($scene))) {
                    try {
                        $scene->update(['image_prompt' => ScenePromptFixer::forScene($scene)]);
                    } catch (ScriptWriterException) {
                    }
                }
                if (Cache::has(self::rewriteKey($scene))) {
                    try {
                        $prompt = ScenePromptFixer::rewrite($scene, (string) Cache::get(self::rewriteKey($scene)));
                    } catch (ScriptWriterException) {
                        $prompt = ScenePromptFixer::fallback((string) $scene->image_prompt);
                    }
                    $scene->update(['image_prompt' => $prompt]);
                    Cache::forget(self::rewriteKey($scene));
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
                if (Cache::increment("muse-busy:topic-scene:{$scene->id}") > self::MAX_BUSY) {
                    $this->fail(new RuntimeException('Muse bận quá lâu (hơn 20 phút). Bấm vẽ lại sau, hoặc dùng Claude tìm ảnh / tải ảnh lên.'));

                    return;
                }
                $this->release(30);

                return;
            }
            // AI từ chối: không tự thử lại (Muse nhớ yêu cầu bị chặn, thử lại gần giống cũng bị chặn, chỉ mất thời gian).
            // Claude sửa prompt theo góp ý của Muse, cảnh hiện nút "Tạo lại ảnh với prompt này" để bạn xem / sửa rồi bấm.
            if ($e->rejected) {
                self::suggestFix($scene, (string) $e->refusal);
                $this->fail(new RuntimeException('Muse từ chối vẽ cảnh này. Xem góp ý của Muse và prompt Claude đã sửa bên dưới, rồi bấm Tạo lại ảnh (hoặc dùng Claude tìm ảnh / tải ảnh lên).'));

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

    /** Lưu lời từ chối của Muse và prompt Claude viết lại theo đó (lỗi thì bản làm nhẹ đơn giản). */
    public static function suggestFix(TopicShortScene $scene, string $refusal): void
    {
        try {
            $prompt = ScenePromptFixer::rewrite($scene, $refusal);
        } catch (ScriptWriterException) {
            $prompt = ScenePromptFixer::fallback((string) $scene->image_prompt);
        }
        $scene->update(['muse_refusal' => mb_substr(trim($refusal), 0, 2000) ?: null, 'muse_fix_prompt' => $prompt]);
    }

    private function save(TopicShortScene $scene, GeneratedImage $result): void
    {
        $path = "topic-shorts/{$scene->topic_short_id}/scene-{$scene->id}-muse-".substr(md5($result->contents), 0, 8).".{$result->extension}";
        Media::disk()->put($path, $result->contents);
        $old = $scene->image_path;
        self::resetCounters($scene);
        $scene->update(['muse_refusal' => null, 'muse_fix_prompt' => null, 'image_source' => 'muse', 'image_status' => 'ready', 'image_path' => $path, 'image_url' => null, 'image_error' => null, 'request_id' => null]);
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
        $scene = $this->scene->fresh();
        // Cảnh đã có ảnh (wiki / tải lên / lần vẽ trước) thì vẫn dùng ảnh đó, chỉ kèm lời nhắn.
        $scene?->update(['image_status' => $scene->image_path ? 'ready' : 'failed', 'request_id' => null, 'image_error' => mb_substr($e?->getMessage() ?: 'Vẽ ảnh thất bại.', 0, 500)]);
    }
}
