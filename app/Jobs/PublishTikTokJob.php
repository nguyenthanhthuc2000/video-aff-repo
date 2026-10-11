<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\SocialPostException;
use App\Services\Social\TikTokClient;
use App\Support\Media;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Đăng video đã xuất lên TikTok: đăng thẳng (theo quyền xem người dùng chọn) hoặc gửi vào hộp nháp TikTok
 * để người dùng tự bấm đăng trong app. Tải lên rồi hỏi lại trạng thái đến khi xong (không giữ hàng đợi).
 */
class PublishTikTokJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 15;

    public int $timeout = 900;

    public function __construct(public SocialPost $post) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(TikTokClient $tiktok): void
    {
        $post = $this->post->fresh();
        if ($post === null || ! $post->isBusy()) {
            return;
        }
        $account = $post->socialAccount;
        if ($account === null) {
            $this->fail(new SocialPostException('Tài khoản TikTok đã bị ngắt kết nối.'));

            return;
        }

        try {
            $token = $tiktok->accessToken($account);
            if ($post->external_id === null) {
                $contents = $post->render?->path ? Media::disk()->get($post->render->path) : null;
                if ($contents === null) {
                    throw new SocialPostException('Không tìm thấy file video đã xuất.');
                }
                $post->update(['status' => 'uploading']);
                $session = $tiktok->initUpload($token, strlen($contents), $this->postInfo($post, $tiktok, $token));
                $post->update(['external_id' => $session['publish_id']]);
                $tiktok->upload($session, $contents);
                $post->update(['status' => 'processing']);
                $this->release(self::POLL_SECONDS);

                return;
            }

            $status = $tiktok->status($token, $post->external_id);
        } catch (SocialPostException $e) {
            if ($e->retryable && $this->attempts() < 5) {
                if ($post->status === 'uploading') {
                    $post->update(['external_id' => null, 'status' => 'pending']);
                }
                $this->release(60);

                return;
            }
            $this->fail($e);

            return;
        }

        match ($status['state']) {
            'processing' => $this->release(self::POLL_SECONDS),
            'failed' => $this->fail(new SocialPostException((string) $status['error'])),
            // Hộp nháp: video đã nằm trong hộp thư TikTok, người dùng mở app để sửa và đăng.
            'inbox' => $post->update(['status' => 'inbox', 'error' => null, 'published_at' => now()]),
            'published' => $post->update([
                'status' => 'published',
                'error' => null,
                'published_at' => now(),
                'permalink' => $status['post_id'] ? "https://www.tiktok.com/@{$account->name}/video/{$status['post_id']}" : null,
            ]),
        };
    }

    /** Thông tin bài khi đăng thẳng (null = gửi vào hộp nháp). Kiểm tra lại với cài đặt hiện tại của người đăng. */
    private function postInfo(SocialPost $post, TikTokClient $tiktok, string $token): ?array
    {
        $options = $post->options ?? [];
        if (($options['mode'] ?? 'inbox') !== 'direct') {
            return null;
        }
        $creator = $tiktok->creatorInfo($token);
        $privacy = (string) ($options['privacy_level'] ?? '');
        if (! in_array($privacy, $creator['privacy_level_options'], true)) {
            throw new SocialPostException('Quyền xem đã chọn không còn hợp lệ với tài khoản TikTok này, hãy chọn lại.');
        }
        $seconds = (int) ceil(($post->render?->duration_ms ?? 0) / 1000);
        if ($creator['max_video_post_duration_sec'] > 0 && $seconds > $creator['max_video_post_duration_sec']) {
            throw new SocialPostException("Tài khoản TikTok này chỉ đăng được video tối đa {$creator['max_video_post_duration_sec']} giây.");
        }

        return [
            'title' => mb_substr($post->caption, 0, 2200),
            'privacy_level' => $privacy,
            'disable_comment' => $creator['comment_disabled'] || ! ($options['allow_comment'] ?? false),
            'disable_duet' => $creator['duet_disabled'] || ! ($options['allow_duet'] ?? false),
            'disable_stitch' => $creator['stitch_disabled'] || ! ($options['allow_stitch'] ?? false),
            'brand_organic_toggle' => (bool) ($options['brand_organic'] ?? false),
            'brand_content_toggle' => (bool) ($options['brand_content'] ?? false),
            // Video có người do AI tạo: gắn nhãn "Nội dung do AI tạo".
            'is_aigc' => true,
        ];
    }

    public function failed(?Throwable $e): void
    {
        $this->post->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?? 'Lỗi không rõ khi đăng TikTok.', 0, 500)]);
    }
}
