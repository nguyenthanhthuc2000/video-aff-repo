<?php

namespace App\Jobs;

use App\Models\CharacterPortrait;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
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
        if ($portrait->expression === 'E1') {
            return [];
        }
        $base = CharacterPortrait::where('user_id', $portrait->user_id)->where('character', $portrait->character)
            ->where('expression', 'E1')->where('status', 'ready')->value('image_path');

        return $base ? [$base] : [];
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
        $generator = AiProviders::imageGenerator('keyframe');
        $references = self::references($portrait);

        try {
            if ($portrait->request_id === null) {
                if ($message = Credits::shortfall($portrait->user, CreditPricing::settings()['steps']['image'], 'vẽ ảnh')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                $portrait->update(['request_id' => $generator->start($references, DragonBall::prompt($portrait->character, $portrait->expression, $references !== []))]);
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
            'Nhân vật '.DragonBall::CHARACTERS[$portrait->character][0].': '.DragonBall::EXPRESSIONS[$portrait->expression][0],
        );
    }

    public function failed(?Throwable $e): void
    {
        $this->portrait->fresh()?->update(['status' => 'failed', 'request_id' => null, 'error' => mb_substr($e?->getMessage() ?: 'Vẽ ảnh thất bại.', 0, 500)]);
    }
}
