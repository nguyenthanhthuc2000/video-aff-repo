<?php

namespace App\Jobs;

use App\Models\CharacterPortrait;
use App\Models\TopicShortScene;
use App\Services\TopicShort\RemoteImage;
use App\Services\TopicShort\WikiImageSearch;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\DragonBall;
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

        // Wiki chính trước, rồi Commons; mỗi wiki thử từ khoá đầy đủ rồi bớt dần chữ cuối (tên file wiki thường ngắn).
        // Wiki lỗi / chặn thì bỏ qua; không đâu có ảnh thì Muse vẽ thay.
        $candidates = [];
        foreach (array_unique([$short->wiki, 'commons']) as $wiki) {
            foreach (self::queries((string) $scene->image_query, str_starts_with((string) $short->image_style, 'manga')) as $query) {
                try {
                    $candidates = $search->search($wiki, $query);
                } catch (RuntimeException) {
                    continue 2;
                }
                if ($candidates !== []) {
                    break 2;
                }
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

        // Không có ảnh wiki dùng được: lấy ảnh thư viện nhân vật (đúng biểu cảm) nếu có, không thì để Muse vẽ.
        if (self::useLibrary($scene)) {
            return;
        }
        // Muse vẽ theo prompt (nếu đủ Credits).
        if (filled($scene->image_prompt) && Credits::shortfall($short->user, CreditPricing::settings()['steps']['image'], 'vẽ ảnh') === null) {
            GenerateTopicSceneImageJob::resetCounters($scene);
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
     * Từ khoá đầy đủ, rồi bớt dần từ cuối, ít nhất 2 từ (1 từ nếu từ khoá chỉ có 1 từ).
     *
     * @return list<string>
     */
    public static function queries(string $query, bool $manga = false): array
    {
        // Truyện tranh: thử thêm chữ "manga" trước (tên file trang truyện thường có chữ này).
        if ($manga && trim($query) !== '' && ! str_contains(mb_strtolower($query), 'manga')) {
            return array_values(array_unique([trim($query).' manga', ...self::queries($query)]));
        }

        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $queries = [];
        for ($n = count($words); $n >= min(2, count($words)) && $n > 0; $n--) {
            $queries[] = implode(' ', array_slice($words, 0, $n));
        }

        return $queries;
    }

    /** Ảnh thư viện nhân vật Ngọc Rồng cho cảnh: nhân vật đầu tiên có ảnh, đúng biểu cảm hoặc mặt thường. */
    public static function libraryPortrait(TopicShortScene $scene): ?CharacterPortrait
    {
        foreach ($scene->characters ?? [] as $name) {
            if (($key = DragonBall::keyFor($name)) && ($portrait = CharacterPortrait::best($scene->topicShort->user_id, $key, $scene->expression))) {
                return $portrait;
            }
        }

        return null;
    }

    /** Gắn ảnh thư viện cho cảnh (sao chép file để xoá / vẽ lại thư viện không ảnh hưởng short). */
    public static function useLibrary(TopicShortScene $scene, ?CharacterPortrait $portrait = null): bool
    {
        $portrait ??= self::libraryPortrait($scene);
        $contents = $portrait?->image_path ? Media::disk()->get($portrait->image_path) : null;
        if ($contents === null) {
            return false;
        }
        $path = "topic-shorts/{$scene->topic_short_id}/scene-{$scene->id}-library-{$portrait->id}-".substr(md5($contents), 0, 8).'.'.(pathinfo($portrait->image_path, PATHINFO_EXTENSION) ?: 'png');
        Media::disk()->put($path, $contents);
        $old = $scene->image_path;
        $scene->update(['image_source' => 'library', 'image_status' => 'ready', 'image_path' => $path, 'image_url' => null, 'image_error' => null, 'request_id' => null]);
        $old && $old !== $path && Media::delete($old);

        return true;
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
