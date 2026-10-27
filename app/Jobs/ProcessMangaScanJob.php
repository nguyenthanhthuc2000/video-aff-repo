<?php

namespace App\Jobs;

use App\Models\MangaScan;
use App\Services\Manga\PanelCutter;
use App\Services\Manga\PanelTagger;
use App\Services\Script\ScriptWriterException;
use App\Support\Media;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Cắt trang truyện thành từng khung (lần đầu), rồi Claude xem từng khung. Claude lỗi thì khung vẫn dùng được, chỉ thiếu mô tả. */
class ProcessMangaScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public MangaScan $scan, public bool $recut = false) {}

    public function handle(): void
    {
        $scan = $this->scan->fresh();
        if ($scan === null) {
            return;
        }
        if ($this->recut || $scan->panels()->doesntExist()) {
            if (! self::cut($scan)) {
                $scan->update(['status' => 'failed', 'error' => 'Không đọc được ảnh trang truyện.']);

                return;
            }
        }

        try {
            PanelTagger::tag($scan);
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $scan->update(['status' => 'ready', 'error' => 'Claude chưa xem được các khung: '.mb_substr($e->getMessage(), 0, 300)]);

            return;
        }
        $scan->update(['status' => 'ready', 'error' => null]);
    }

    /** Cắt trang, lưu từng khung thành jpg (xoá khung cũ khi cắt lại). */
    public static function cut(MangaScan $scan): bool
    {
        $contents = Media::disk()->get($scan->image_path);
        $image = $contents ? @imagecreatefromstring($contents) : false;
        if (! $image instanceof GdImage) {
            return false;
        }
        foreach ($scan->panels()->get() as $old) {
            $old->image_path !== $scan->image_path && Media::delete($old->image_path);
            $old->delete();
        }
        foreach (PanelCutter::boxes($image) as $i => $box) {
            $crop = imagecrop($image, $box);
            if (! $crop instanceof GdImage) {
                continue;
            }
            ob_start();
            imagejpeg($crop, null, 90);
            $jpg = (string) ob_get_clean();
            imagedestroy($crop);
            $path = "manga/{$scan->user_id}/scan-{$scan->id}-panel-".($i + 1).'-'.substr(md5($jpg), 0, 8).'.jpg';
            Media::disk()->put($path, $jpg);
            $scan->panels()->create([
                'user_id' => $scan->user_id,
                'position' => $i + 1,
                'image_path' => $path,
                'width' => $box['width'],
                'height' => $box['height'],
            ]);
        }
        imagedestroy($image);

        return true;
    }

    public function failed(?Throwable $e): void
    {
        $this->scan->fresh()?->update(['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?: 'Xử lý trang truyện thất bại.', 0, 500)]);
    }
}
