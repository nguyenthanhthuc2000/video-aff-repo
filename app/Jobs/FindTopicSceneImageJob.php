<?php

namespace App\Jobs;

use App\Models\TopicShortScene;
use App\Services\TopicShort\RemoteImage;
use App\Services\TopicShort\WikiImageSearch;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Tìm ảnh wiki theo từ khoá của cảnh, lưu danh sách ảnh gợi ý và tải ảnh đầu tiên tải được. */
class FindTopicSceneImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public int $backoff = 20;

    public function __construct(public TopicShortScene $scene) {}

    public function handle(WikiImageSearch $search): void
    {
        $scene = $this->scene->fresh();
        if ($scene === null || $scene->image_status !== 'searching') {
            return;
        }
        $short = $scene->topicShort;

        try {
            $candidates = $search->search($short->wiki, (string) $scene->image_query);
        } catch (RuntimeException) {
            // Wiki chính chặn / lỗi: vẫn thử Commons.
            $candidates = [];
        }
        // Wiki chính không có ảnh: thử Wikimedia Commons; Commons cũng lỗi thì coi như không có ảnh (Muse vẽ thay).
        if ($candidates === [] && $short->wiki !== 'commons') {
            try {
                $candidates = $search->search('commons', (string) $scene->image_query);
            } catch (RuntimeException) {
                $candidates = [];
            }
        }
        $scene->update(['candidates' => $candidates]);

        foreach ($candidates as $candidate) {
            try {
                self::store($scene, $candidate['url'], 'web');

                return;
            } catch (RuntimeException) {
                continue;
            }
        }

        // Không có ảnh wiki dùng được: để Muse vẽ theo prompt (nếu đủ Credits).
        if (filled($scene->image_prompt) && Credits::shortfall($short->user, CreditPricing::settings()['steps']['image'], 'vẽ ảnh') === null) {
            $scene->update(['image_status' => 'pending', 'request_id' => null, 'image_error' => null]);
            GenerateTopicSceneImageJob::dispatch($scene);

            return;
        }

        $scene->update([
            'image_status' => 'failed',
            'image_error' => $candidates === [] ? 'Không tìm thấy ảnh trên wiki. Sửa từ khoá, cho Muse vẽ hoặc tải ảnh lên.' : 'Không tải được ảnh nào trong kết quả tìm.',
        ]);
    }

    /**
     * Tải ảnh về và gắn cho cảnh.
     *
     * @throws RuntimeException
     */
    public static function store(TopicShortScene $scene, string $url, string $source): void
    {
        $image = RemoteImage::download($url);
        $path = "topic-shorts/{$scene->topic_short_id}/scene-{$scene->id}-".substr(md5($url), 0, 8).".{$image['extension']}";
        Media::disk()->put($path, $image['contents']);
        $old = $scene->image_path;
        $scene->update(['image_source' => $source, 'image_status' => 'ready', 'image_path' => $path, 'image_url' => $url, 'image_error' => null, 'request_id' => null]);
        $old && $old !== $path && Media::delete($old);
    }

    public function failed(?Throwable $e): void
    {
        $this->scene->fresh()?->update(['image_status' => 'failed', 'image_error' => mb_substr($e?->getMessage() ?: 'Tìm ảnh thất bại.', 0, 500)]);
    }
}
