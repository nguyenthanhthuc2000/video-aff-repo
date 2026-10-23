<?php

namespace App\Jobs;

use App\Models\CharacterPortrait;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
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

/** Muse vẽ chân dung một nhân vật Ngọc Rồng với một biểu cảm (manga trắng đen); biểu cảm khác bám theo ảnh mặt thường (E1). */
class GenerateCharacterPortraitJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public CharacterPortrait $portrait) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<string> Ảnh mặt thường của nhân vật làm mẫu (trừ khi đang vẽ chính ảnh mặt thường). */
    public static function references(CharacterPortrait $portrait): array
    {
        // Ảnh mặt thường (do Muse vẽ hoặc bạn tải lên) luôn làm ảnh mẫu, kể cả khi đã viết lại prompt, để các biểu cảm giống cùng một nhân vật.
        if ($portrait->expression === 'E1') {
            return [];
        }
        $base = CharacterPortrait::where('user_id', $portrait->user_id)->where('character', $portrait->character)
            ->where('expression', 'E1')->where('status', 'ready')->value('image_path');

        return $base ? [$base] : [];
    }

    /** Prompt gửi Muse: prompt Claude đã viết lại (nếu có), không thì prompt thư viện. */
    public static function prompt(CharacterPortrait $portrait, bool $withReference): string
    {
        if (filled($portrait->prompt)) {
            return trim($portrait->prompt).($withReference ? "\nThe attached image is this same character: keep the face, hair and outfit identical, change only the expression." : '');
        }

        return DragonBall::prompt($portrait->character, $portrait->expression, $withReference);
    }

    public static function rewriteKey(CharacterPortrait $portrait): string
    {
        return "muse-rewrite:portrait:{$portrait->id}";
    }

    /** Claude viết lại prompt theo lời từ chối của AI, lưu lại để lần vẽ tới dùng. */
    public static function rewrite(CharacterPortrait $portrait, string $refusal): void
    {
        $portrait->update(['prompt' => ScenePromptFixer::rewritePortrait($portrait, $refusal)]);
    }

    public function handle(): void
    {
        $portrait = $this->portrait->fresh();
        if ($portrait === null || $portrait->status !== 'pending' || ! isset(DragonBall::CHARACTERS[$portrait->character])) {
            return;
        }
        // Biểu cảm khác chờ ảnh mặt thường đang vẽ xong để bám theo cho giống.
        if ($portrait->expression !== 'E1' && $portrait->request_id === null && CharacterPortrait::where('user_id', $portrait->user_id)
            ->where('character', $portrait->character)->where('expression', 'E1')->where('status', 'pending')->exists()) {
            $this->release(30);

            return;
        }
        // Ảnh lỗi được bấm tạo lại: Claude viết lại prompt trước (ảnh mẫu bỏ theo prompt mới).
        if ($portrait->request_id === null && Cache::has(self::rewriteKey($portrait))) {
            self::rewrite($portrait, (string) Cache::pull(self::rewriteKey($portrait)));
        }
        $generator = AiProviders::imageGenerator('keyframe');
        $references = self::references($portrait);

        try {
            if ($portrait->request_id === null) {
                if ($message = Credits::shortfall($portrait->user, CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                $portrait->update(['request_id' => $generator->start($references, self::prompt($portrait, $references !== []))]);
                $this->release(15);

                return;
            }

            $result = $generator->poll($portrait->request_id, $references);
            if ($result === null) {
                $this->release(GenerateTopicSceneImageJob::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(30);

                return;
            }
            // AI từ chối (bản quyền): KHÔNG tự thử lại — Muse nhớ yêu cầu đã từ chối và chặn mọi lần thử lại giống vậy.
            // Người dùng bấm "Tạo lại ảnh lỗi" thì Claude viết thành nhân vật gốc, bố cục khác, không gửi ảnh mẫu.
            if ($e->retryable && Cache::increment("muse-retries:portrait:{$portrait->id}") <= 2) {
                $portrait->update(['request_id' => null]);
                $this->release(30);

                return;
            }
            $this->fail($e);

            return;
        }

        $this->save($portrait, $result);
    }

    private function save(CharacterPortrait $portrait, GeneratedImage $result): void
    {
        $path = "character-portraits/{$portrait->user_id}/{$portrait->character}-{$portrait->expression}-".substr(md5($result->contents), 0, 8).".{$result->extension}";
        Media::disk()->put($path, $result->contents);
        $old = $portrait->image_path;
        $portrait->update(['status' => 'ready', 'source' => 'muse', 'image_path' => $path, 'request_id' => null, 'error' => null]);
        $old && $old !== $path && Media::delete($old);

        Credits::charge(
            $portrait->user_id,
            CreditPricing::settings()['steps']['image'],
            'image',
            "character-portrait:{$portrait->id}:".substr(md5($path), 0, 8),
            'Nhân vật '.DragonBall::CHARACTERS[$portrait->character][0].': '.(DragonBall::pose($portrait->character, $portrait->expression)[0] ?? $portrait->expression),
        );
    }

    public function failed(?Throwable $e): void
    {
        $this->portrait->fresh()?->update(['status' => 'failed', 'request_id' => null, 'error' => mb_substr($e?->getMessage() ?: 'Vẽ ảnh thất bại.', 0, 500)]);
    }
}
