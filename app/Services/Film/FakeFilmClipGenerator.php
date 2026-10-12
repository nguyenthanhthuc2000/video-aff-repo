<?php

namespace App\Services\Film;

use App\Services\Image\MuseTask;
use App\Services\Video\ClipGenerator;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Không dùng Muse (test / máy chưa nối Muse): clip màu 3 giây bằng ffmpeg để đi hết luồng ghép video shot. */
class FakeFilmClipGenerator implements ClipGenerator
{
    public function start(string $imagePath, string $prompt, ?string $referenceVideoPath = null, ?string $audioPath = null): string
    {
        return MuseTask::code();
    }

    public function poll(string $code): ?array
    {
        $file = sys_get_temp_dir().'/fake-film-clip-'.bin2hex(random_bytes(4)).'.mp4';
        try {
            $result = Process::timeout(60)->run([
                config('videoaff.ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=skyblue:s=320x180:d=3:r=24',
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $file,
            ]);
            if ($result->failed()) {
                throw new RuntimeException('ffmpeg không tạo được clip giả.');
            }

            return ['contents' => (string) file_get_contents($file), 'extension' => 'mp4'];
        } finally {
            @unlink($file);
        }
    }
}
