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

    public function start(string $imagePath, string $prompt, ?string $referenceVideoPath = null, ?string $audioPath = null): string
    {
        $contents = Media::disk()->get($imagePath);
        if ($contents === null) {
            throw new ImageGeneratorException('Không đọc được ảnh cảnh.');
        }

        $attachments = [['name' => basename($imagePath), 'base64' => base64_encode($contents)]];
        if ($referenceVideoPath !== null) {
            $video = Media::disk()->get($referenceVideoPath);
            if ($video === null) {
                throw new ImageGeneratorException('Không đọc được video mẫu.');
            }
            $attachments[] = ['name' => 'reference-motion.mp4', 'base64' => base64_encode($video)];
        }
        if ($audioPath !== null) {
            $audio = Media::disk()->get($audioPath);
            if ($audio === null) {
                throw new ImageGeneratorException('Không đọc được đoạn nhạc.');
            }
            $attachments[] = ['name' => 'song-part.'.(pathinfo($audioPath, PATHINFO_EXTENSION) ?: 'm4a'), 'base64' => base64_encode($audio)];
        }

        $code = MuseTask::code();
        MuseBrowserImageGenerator::call(fn () => $this->client->startTask(
            $code,
            'video',
            self::message($code, $prompt),
            $attachments,
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
