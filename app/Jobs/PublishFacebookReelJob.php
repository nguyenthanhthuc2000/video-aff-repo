<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\FacebookClient;
use App\Services\Social\FacebookException;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Đăng video đã xuất lên Trang Facebook dạng Reels: tải lên, đăng, rồi hỏi lại đến khi Facebook xử lý xong (không giữ hàng đợi). */
class PublishFacebookReelJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 20;

    public int $timeout = 900;

    public function __construct(public SocialPost $post) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(FacebookClient $facebook): void
    {
        $post = $this->post->fresh();
        if ($post === null || ! $post->isBusy()) {
            return;
        }
        $account = $post->socialAccount;
        if ($account === null) {
            $this->fail(new FacebookException('Trang Facebook đã bị ngắt kết nối.'));

            return;
        }

        try {
            if ($post->external_id === null) {
                $contents = $post->render?->path ? Media::disk()->get($post->render->path) : null;
                if ($contents === null) {
                    throw new FacebookException('Không tìm thấy file video đã xuất.');
                }
                $post->update(['status' => 'uploading']);
                $videoId = $facebook->startReel($account->external_id, $account->access_token);
                $post->update(['external_id' => $videoId]);
                $facebook->uploadReel($videoId, $account->access_token, $contents);
                $facebook->publishReel($account->external_id, $account->access_token, $videoId, $post->caption);
                $post->update(['status' => 'processing']);
                $this->release(self::POLL_SECONDS);

                return;
            }

            $status = $facebook->reelStatus($post->external_id, $account->access_token);
        } catch (FacebookException $e) {
            if ($e->retryable && $this->attempts() < 5) {
                // Chưa đăng xong thì làm lại từ đầu (phiên tải cũ bỏ).
                if ($post->status === 'uploading') {
                    $post->update(['external_id' => null, 'status' => 'pending']);
                }
                $this->release(60);

                return;
            }
            $this->fail($e);

            return;
        }

        if ($status['state'] === 'processing') {
            $post->update(['permalink' => $status['permalink'] ?? $post->permalink]);
            $this->release(self::POLL_SECONDS);

            return;
        }
        if ($status['state'] === 'failed') {
            $this->fail(new FacebookException((string) $status['error']));

            return;
        }
        $post->update(['status' => 'published', 'permalink' => $status['permalink'], 'error' => null, 'published_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        $this->post->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?? 'Lỗi không rõ khi đăng Facebook.', 0, 500)]);
    }
}
