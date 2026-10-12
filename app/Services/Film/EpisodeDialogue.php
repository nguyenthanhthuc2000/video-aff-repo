<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Models\Shot;
use App\Support\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/** Gói lời thoại của tập để tải về: tiếng từng shot, tiếng cả tập (đúng nhịp shot) và kịch bản lời kèm mốc thời gian. */
final class EpisodeDialogue
{
    public static function fileName(Episode $episode): string
    {
        return Str::slug($episode->series->title).'-tap-'.$episode->number.'-loi-thoai';
    }

    /** Số shot dạng phân đoạn.vị trí (vd 3.02), khớp thứ tự trên trang tập. */
    public static function shotNumber(Shot $shot): string
    {
        return $shot->sequence.'.'.str_pad((string) $shot->position, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Tạo file zip tạm (người gọi xoá sau khi gửi).
     *
     * @throws RuntimeException
     */
    public static function zip(Episode $episode): string
    {
        $dir = sys_get_temp_dir().'/dialogue-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $zipPath = sys_get_temp_dir().'/'.self::fileName($episode).'-'.bin2hex(random_bytes(3)).'.zip';

        try {
            $shots = $episode->shots()->get();
            $characters = $episode->series->characters()->get()->keyBy('id');
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Không tạo được file zip.');
            }

            $script = ["{$episode->series->title} – Tập {$episode->number}: {$episode->title}", ''];
            $concat = [];
            $offset = 0;
            foreach ($shots as $i => $shot) {
                $number = self::shotNumber($shot);
                $seconds = number_format($shot->duration_ms / 1000, 3, '.', '');
                $piece = "{$dir}/piece-{$i}.wav";
                $current = $shot->audio_path !== null && ShotAudio::isCurrent($shot, $characters);
                $script[] = 'Shot '.$number.' ['.self::clock($offset).' – '.self::clock($offset + $shot->duration_ms).']'
                    .($shot->audio_path === null && ShotAudio::key($shot, $characters) !== null ? ' (chưa đọc)' : '')
                    .($shot->audio_path !== null && ! $current ? ' (bản đọc cũ, lời / giọng đã đổi)' : '');

                if ($shot->audio_path !== null) {
                    $source = "{$dir}/shot-{$number}.wav";
                    File::put($source, (string) Media::disk()->get($shot->audio_path));
                    $zip->addFile($source, "shots/shot-{$number}.wav");
                    // Kéo / cắt đúng thời lượng shot để file cả tập khớp nhịp hình.
                    self::ffmpeg(['-i', $source, '-af', "aresample=24000,apad=whole_dur={$seconds}", '-t', $seconds, '-ac', '1', $piece]);
                } else {
                    self::ffmpeg(['-f', 'lavfi', '-i', 'anullsrc=r=24000:cl=mono', '-t', $seconds, $piece]);
                }
                $concat[] = "file '{$piece}'";

                $lines = collect($shot->dialogue ?? [])->filter(fn ($d) => trim((string) ($d['line'] ?? '')) !== '')->values();
                foreach ($lines as $j => $line) {
                    $speaker = isset($line['character_id']) ? ($characters->get($line['character_id'])?->name ?? '?') : 'Dẫn chuyện';
                    $time = $shot->audio_lines[$j] ?? null;
                    $script[] = '  '.($time ? self::clock($offset + $time['start_ms']).' ' : '').$speaker.': '.trim((string) $line['line']);
                }
                $offset += $shot->duration_ms;
            }

            File::put("{$dir}/list.txt", implode("\n", $concat)."\n");
            self::ffmpeg(['-f', 'concat', '-safe', '0', '-i', "{$dir}/list.txt", '-c:a', 'pcm_s16le', "{$dir}/episode.wav"]);
            $zip->addFile("{$dir}/episode.wav", 'ca-tap.wav');
            $zip->addFromString('loi-thoai.txt', implode("\n", $script)."\n");
            $zip->close();

            return $zipPath;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private static function clock(int $ms): string
    {
        return sprintf('%02d:%02d.%d', intdiv($ms, 60000), intdiv($ms % 60000, 1000), intdiv($ms % 1000, 100));
    }

    /** @param list<string> $args */
    private static function ffmpeg(array $args): void
    {
        $result = Process::timeout(120)->run([config('videoaff.ffmpeg'), '-v', 'error', '-y', ...$args]);
        if ($result->failed()) {
            throw new RuntimeException('Gộp lời thoại lỗi: '.mb_substr(trim($result->errorOutput()), -300));
        }
    }
}
