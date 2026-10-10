<?php

namespace App\Support;

use App\Enums\VoiceMode;
use App\Models\Scene;
use App\Models\VideoProject;
use App\Services\Audio\MusicAnalyzer;
use RuntimeException;

/**
 * Tiếng trong clip: AI làm video tự thêm tiếng và nhép miệng lung tung, mỗi clip nói một kiểu.
 * Chỉ nhạc nền: cấm nói; người mẫu nói: đọc đúng lời voiceover của cảnh, cùng một giọng; hát nhép: khớp đoạn nhạc gửi kèm.
 */
final class Voice
{
    public static function clipLine(Scene $scene): string
    {
        $line = trim((string) $scene->voiceover);

        return match ($scene->videoProject->voice_mode) {
            VoiceMode::Speak => $line === ''
                ? self::SILENT
                : 'She talks directly to the camera like in a casual product review video, in Vietnamese, and says exactly this line and nothing else: "'.$line.'". '
                    .'Natural lip-sync with clear mouth movements matching every word, friendly natural intonation, small natural hand gestures while talking. '
                    .'Use the same voice in every part of this video: a warm, natural young Vietnamese woman\'s voice with the same accent, pitch and pace. '
                    .'The audio has only her voice: no music, no other people, no extra words before or after the line. '
                    .'If the reference video shows different mouth movements, ignore them and lip-sync this line instead.',
            VoiceMode::Sing => 'The last attachment is an audio clip of the song for this part. She lip-syncs to the vocals of that song like a TikTok lip-sync video: '
                .'her mouth moves exactly in time with the sung words, with expressions that match the mood of the song, and her body moves on the beat; '
                .'when there are no vocals her lips are closed or smiling. Make the video exactly as long as the audio clip. '
                .'Do not add any other voice, speech or sound effects. If the reference video shows different mouth movements, follow the song instead.',
            default => self::SILENT,
        };
    }

    /** Chỉ nhạc nền: không nhép miệng như đang nói (video sẽ ghép nhạc, không có lời). */
    public const SILENT = 'She does not talk or sing: lips relaxed, closed or smiling, no lip movement as if speaking, even if the reference video shows talking. '
        .'No voice, no speech and no music in the audio (the music is added later).';

    /** File nhạc nền của dự án (disk media). */
    public static function musicPath(VideoProject $project): ?string
    {
        return $project->audioTrack?->path ?? $project->music;
    }

    /**
     * Đoạn nhạc của cảnh (ms) theo thời gian trong video: cảnh nối thẳng nên cảnh k bắt đầu sau tổng độ dài các cảnh trước.
     *
     * @return array{start: int, length: int}
     */
    public static function segment(Scene $scene): array
    {
        $project = $scene->videoProject;
        $before = (int) $project->scenes()->where('position', '<', $scene->position)->sum('duration_ms');

        return ['start' => (int) $project->music_start_ms + $before, 'length' => $scene->duration_ms];
    }

    /**
     * Cắt đoạn nhạc của cảnh để gửi kèm khi tạo clip hát nhép; lần đầu thì phân tích nhạc để chọn đoạn sôi động nhất.
     *
     * @return string đường dẫn disk media
     *
     * @throws RuntimeException
     */
    public static function prepareAudio(Scene $scene, MusicAnalyzer $analyzer): string
    {
        $project = $scene->videoProject;
        $music = self::musicPath($project) ?? throw new RuntimeException('Hát nhép cần chọn nhạc nền cho dự án.');
        $contents = Media::disk()->get($music) ?? throw new RuntimeException('Không đọc được file nhạc nền.');

        $dir = sys_get_temp_dir().'/sing-'.uniqid();
        mkdir($dir);
        $input = "{$dir}/music.".(pathinfo($music, PATHINFO_EXTENSION) ?: 'mp3');
        $output = "{$dir}/part.m4a";

        try {
            file_put_contents($input, $contents);
            if ($project->music_start_ms === null) {
                $seconds = (int) ceil($project->scenes()->sum('duration_ms') / 1000);
                $project->update(['music_start_ms' => MusicAnalyzer::bestStart($analyzer->levels($input), $seconds) * 1000]);
            }

            $segment = self::segment($scene);
            $analyzer->segment($input, $segment['start'], $segment['length'], $output);
            $path = "audio-parts/{$project->id}/{$scene->id}-{$segment['start']}-{$segment['length']}.m4a";
            Media::disk()->put($path, (string) file_get_contents($output));

            return $path;
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($dir);
        }
    }
}
