<?php

namespace App\Services\Video;

use App\Services\Image\ImageGeneratorException;
use App\Services\Image\MuseBrowserClient;
use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Image\MuseTask;
use App\Support\Media;

/** Tạo clip chuyển động bằng muse.ai (trình duyệt ngầm): gửi ảnh cảnh + mô tả động tác, tải video Muse tạo. */
class MuseBrowserClipGenerator implements ClipGenerator
{
    private const EXTENSIONS = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];

    public function __construct(private readonly MuseBrowserClient $client) {}

    public function start(string $imagePath, string $prompt): string
    {
        $contents = Media::disk()->get($imagePath);
        if ($contents === null) {
            throw new ImageGeneratorException('Không đọc được ảnh cảnh.');
        }

        $code = MuseTask::code();
        MuseBrowserImageGenerator::call(fn () => $this->client->startTask(
            $code,
            'video',
            self::message($code, $prompt),
            [['name' => basename($imagePath), 'base64' => base64_encode($contents)]],
            (int) config('videoaff.muse_browser.clip_timeout') * 1000,
        ));

        return $code;
    }

    public function poll(string $code): ?array
    {
        $result = MuseBrowserImageGenerator::pollTask($this->client, $code, 'video');
        if ($result === null) {
            return null;
        }

        $contents = base64_decode($result['base64'] ?? '', true);
        $type = $contents ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) : null;
        if (! $contents || ! isset(self::EXTENSIONS[$type])) {
            throw new ImageGeneratorException('muse.ai không trả về video hợp lệ.');
        }

        return ['contents' => $contents, 'extension' => self::EXTENSIONS[$type]];
    }

    public static function message(string $code, string $prompt): string
    {
        return MuseTask::header($code, 'video')."\n\n{$prompt}";
    }
}
