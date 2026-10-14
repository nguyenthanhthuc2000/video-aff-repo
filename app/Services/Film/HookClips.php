<?php

namespace App\Services\Film;

use App\Models\AudioTrack;
use App\Services\Audio\MusicAnalyzer;
use App\Support\Media;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Gợi ý đoạn hook 15–40 giây của bài hát để làm Shorts: điệp khúc theo phân tích bài (cấu trúc), câu hát lặp lại nhiều nhất
 * (lời theo thời gian), đoạn sôi động nhất (độ lớn âm thanh, cần ffmpeg). Bắt đầu sớm 0,5 giây trước câu hát, không vượt cuối bài.
 */
final class HookClips
{
    public const MIN_SECONDS = 15;

    public const MAX_SECONDS = 40;

    /** @return list<array{start_ms: int, end_ms: int, kind: string, label: string}> */
    public static function suggest(AudioTrack $track, int $seconds = 25, bool $loudness = true): array
    {
        $length = max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds)) * 1000;
        $duration = (int) ($track->duration_ms ?? (($track->vocal_analysis['duration'] ?? 0) * 1000));
        $candidates = [];

        // 1. Điệp khúc theo cấu trúc bài (Claude phân tích / người dùng sửa): điệp khúc đầu và điệp khúc cuối (thường to nhất).
        $choruses = collect($track->song_info['analysis']['structure'] ?? [])->where('section', 'chorus')->values();
        if ($choruses->isNotEmpty()) {
            $candidates[] = [(int) round($choruses->first()['start'] * 1000) - 500, 'chorus', 'Điệp khúc đầu'];
            if ($choruses->count() > 1) {
                $candidates[] = [(int) round($choruses->last()['start'] * 1000) - 500, 'chorus', 'Điệp khúc cuối'];
            }
        }

        // 2. Câu hát lặp lại nhiều nhất (hook): lần xuất hiện đầu tiên.
        $repeated = collect(MusicVideoWriter::lines($track->vocal_analysis['words'] ?? []))
            ->groupBy(fn ($l) => trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $l['text']))))
            ->filter(fn ($group, $text) => $group->count() >= 2 && count(explode(' ', $text)) >= 2)
            ->sortByDesc(fn ($group) => $group->count());
        if ($repeated->isNotEmpty()) {
            $first = $repeated->first()->first();
            $candidates[] = [(int) round($first['start'] * 1000) - 500, 'hook', 'Câu hook lặp lại: "'.mb_strimwidth($first['text'], 0, 40, '…').'"'];
        }

        // 3. Đoạn sôi động nhất (cần ffmpeg trên máy chạy app).
        if ($loudness && $track->path !== null) {
            $file = sys_get_temp_dir().'/hook-'.bin2hex(random_bytes(4)).'.'.(pathinfo($track->path, PATHINFO_EXTENSION) ?: 'mp3');
            try {
                File::put($file, (string) Media::disk()->get($track->path));
                $levels = MusicAnalyzer::fromConfig()->levels($file);
                $candidates[] = [MusicAnalyzer::bestStart($levels, (int) ($length / 1000)) * 1000, 'energy', 'Đoạn sôi động nhất'];
            } catch (Throwable) {
                // Không đo được độ lớn: bỏ gợi ý này.
            } finally {
                @unlink($file);
            }
        }

        $result = [];
        foreach ($candidates as [$start, $kind, $label]) {
            $start = max(0, $start);
            if ($duration > 0) {
                $start = max(0, min($start, $duration - $length));
            }
            if (collect($result)->contains(fn ($r) => abs($r['start_ms'] - $start) < 3000)) {
                continue;
            }
            $result[] = ['start_ms' => $start, 'end_ms' => $duration > 0 ? min($duration, $start + $length) : $start + $length, 'kind' => $kind, 'label' => $label];
        }

        return array_slice($result, 0, 3);
    }
}
