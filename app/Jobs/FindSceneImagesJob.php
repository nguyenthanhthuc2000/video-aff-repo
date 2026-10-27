<?php

namespace App\Jobs;

use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Services\Claude\AiUsage;
use App\Services\Manga\SceneImageFinder;
use App\Services\Manga\WebImage;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\MangaPage;
use App\Services\TopicShort\ShortWriter;
use App\Support\DragonBall;
use App\Support\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Claude tìm ảnh thật trên mạng cho các cảnh, máy tải về (lưu vào Kho khung manga để dùng lại) và gắn tối đa 3 ảnh vào mỗi cảnh
 * (kiểu cắt nhanh: mỗi ảnh một cảnh quay). Cảnh không tìm được ảnh giữ ảnh cũ.
 */
class FindSceneImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Tìm web nhiều lượt + tải ảnh. */
    public int $timeout = 1200;

    /** @param list<int> $sceneIds */
    public function __construct(public TopicShort $short, public array $sceneIds) {}

    public function handle(): void
    {
        $short = $this->short->fresh();
        $scenes = $this->scenes();
        if ($short === null || $scenes->isEmpty()) {
            return;
        }

        try {
            $found = AiUsage::withContext(['user_id' => $short->user_id, 'subject' => "topic_short:{$short->id}"], fn () => SceneImageFinder::find($short, $scenes));
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(60);

                return;
            }
            $this->fail($e);

            return;
        }

        $chapter = mb_substr('Claude tìm: '.$short->topic, 0, 120);
        foreach ($scenes as $scene) {
            $ids = [];
            foreach ($found[$scene->id] ?? [] as $image) {
                try {
                    $file = WebImage::download($image['url']);
                } catch (RuntimeException) {
                    continue;
                }
                $path = "manga/{$short->user_id}/web/".substr(md5($file['contents']), 0, 16).'.'.$file['extension'];
                Media::disk()->put($path, $file['contents']);
                $scan = $short->user->mangaScans()->create(['chapter' => $chapter, 'name' => parse_url($image['url'], PHP_URL_HOST), 'image_path' => $path, 'status' => 'ready']);
                $ids[] = $scan->panels()->create([
                    'user_id' => $short->user_id,
                    'position' => 1,
                    'image_path' => $path,
                    'width' => $file['width'],
                    'height' => $file['height'],
                    'characters' => array_values(array_unique(array_filter(array_map(fn ($name) => DragonBall::keyFor($name), $image['characters'])))),
                    'description' => $image['description'] ?: null,
                    'mood' => $scene->mood,
                    'useful' => true,
                    'tagged_at' => now(),
                ])->id;
                if (count($ids) >= ShortWriter::MAX_PANELS) {
                    break;
                }
            }
            if ($ids !== []) {
                $scene->update(['manga_panels' => $ids, 'image_error' => null]);
                if (MangaPage::compose($scene->fresh())) {
                    continue;
                }
            }
            self::restore($scene, 'Claude chưa tìm được ảnh tải về được cho cảnh này. Bấm tìm lại, chọn trong Kho khung manga hoặc tải ảnh lên.');
        }
    }

    /** @return Collection<int, TopicShortScene> */
    private function scenes(): Collection
    {
        return TopicShortScene::where('topic_short_id', $this->short->id)->whereIn('id', $this->sceneIds)->orderBy('position')->get();
    }

    /** Trả cảnh về trạng thái trước khi tìm (còn ảnh thì vẫn dùng được), kèm lời nhắn. */
    private static function restore(TopicShortScene $scene, string $message): void
    {
        $scene->update(['image_status' => $scene->image_path ? 'ready' : 'failed', 'image_error' => $message]);
    }

    public function failed(?Throwable $e): void
    {
        foreach ($this->scenes() as $scene) {
            $scene->image_status === 'searching' && self::restore($scene, 'Claude tìm ảnh lỗi: '.mb_substr($e?->getMessage() ?: 'không rõ', 0, 300));
        }
    }
}
