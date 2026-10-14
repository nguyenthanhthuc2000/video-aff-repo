<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\EpisodeRender;
use App\Services\Claude\AiUsage;
use App\Services\Film\EpisodeTitler;
use App\Services\Film\Thumbnail;
use App\Services\Image\AsyncImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseTask;
use App\Services\Script\ScriptWriterException;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Muse vẽ riêng ảnh nền cho ảnh bìa của một bản xuất (theo ảnh chính nhân vật), rồi in chữ lên thành ảnh bìa. */
class GenerateThumbnailArtJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 10;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public EpisodeRender $render, public string $prompt, public ?string $text = null) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(): void
    {
        $render = $this->render->fresh();
        if ($render === null || $render->art_status !== 'pending') {
            return;
        }
        /** @var AsyncImageGenerator $generator */
        $generator = app('film.image');
        $references = Thumbnail::artCharacters($render)->pluck('main_image_path')->all();
        $user = $render->episode->series->user;

        try {
            if (! MuseTask::isCode($render->art_request_id)) {
                if ($message = Credits::shortfall($user, CreditPricing::settings()['steps']['image'], 'vẽ ảnh bìa')) {
                    $this->fail(new RuntimeException($message));

                    return;
                }
                // Chưa có chữ cho ảnh bìa: Claude gợi ý tiêu đề trước (một lần), lấy chữ ngắn của phương án đầu.
                if (EpisodeTitler::placeholder($this->text)) {
                    $title = $render->episode->title;
                    $this->text = self::suggestText($render->episode) ?? $this->text;
                    // Tên tập vừa được đặt: prompt vẽ dùng tên mới thay cho tên tạm.
                    $this->prompt = str_replace("\"{$title}\"", '"'.$render->episode->fresh()->title.'"', $this->prompt);
                }
                $render->update(['art_request_id' => $generator->start($references, $this->prompt)]);
                $this->release(15);

                return;
            }

            $result = $generator->poll($render->art_request_id, $references);
            if ($result === null) {
                $this->release(self::POLL_SECONDS);

                return;
            }
        } catch (ImageGeneratorException $e) {
            if ($e->busy) {
                $this->release(30);

                return;
            }
            if ($e->retryable && Cache::increment("muse-retries:thumb-art:{$render->id}") <= 2) {
                $render->update(['art_request_id' => null]);
                $this->release(30);

                return;
            }
            $this->fail($e);

            return;
        }

        $this->save($render, $result);
    }

    /** Chữ ảnh bìa từ tiêu đề Claude gợi ý (gợi ý mới nếu tập chưa có); tên tập còn trống / tạm thì đặt luôn theo phương án đầu. */
    public static function suggestText(Episode $episode): ?string
    {
        $ideas = $episode->title_ideas ?? [];
        if ($ideas === []) {
            try {
                $ideas = AiUsage::withContext(['user_id' => $episode->series->user_id, 'subject' => "series:{$episode->series_id}"], fn () => EpisodeTitler::suggest($episode));
            } catch (ScriptWriterException $e) {
                Log::warning('thumbnail_art.titles_failed', ['episode_id' => $episode->id, 'error' => $e->getMessage()]);

                return null;
            }
            $episode->update(['title_ideas' => $ideas, 'title_status' => 'ready', 'title_error' => null]);
        }
        EpisodeTitler::ensure($episode);

        return $ideas[0]['thumbnail_text'] ?: $ideas[0]['title'];
    }

    private function save(EpisodeRender $render, GeneratedImage $result): void
    {
        $episode = $render->episode;
        $path = preg_replace('/\.mp4$/', '', $render->path).'-art-'.bin2hex(random_bytes(3)).".{$result->extension}";
        Media::disk()->put($path, $result->contents);
        $old = $render->art_path;
        $render->update(['art_path' => $path, 'art_status' => 'ready', 'art_request_id' => null, 'art_error' => null]);
        $old !== null && $old !== $path && Media::delete($old);

        Credits::charge(
            $episode->series->user_id,
            CreditPricing::settings()['steps']['image'],
            'image',
            "thumbnail-art:{$render->id}:".basename($path),
            "Phim {$episode->series->title}: ảnh bìa tập {$episode->number}",
        );
        try {
            Thumbnail::forRender($render->fresh(), null, $this->text, true);
        } catch (RuntimeException $e) {
            $render->update(['art_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->render->fresh()?->update(['art_status' => 'failed', 'art_request_id' => null, 'art_error' => mb_substr($e?->getMessage() ?: 'Vẽ ảnh bìa thất bại.', 0, 500)]);
    }
}
