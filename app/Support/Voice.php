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
    /** @param  ?array{start: float, length: float}  $part  đoạn của shot dài (giây trong shot), null = cả cảnh */
    public static function clipLine(Scene $scene, ?array $part = null): string
    {
        $line = trim((string) $scene->voiceover);

        return match ($scene->videoProject->voice_mode) {
            VoiceMode::Speak => $line === ''
                ? self::SILENT
                : 'She talks directly to the camera like in a casual product review video, in Vietnamese, and says exactly this line and nothing else: "'.$line.'". '
                    .'Natural lip-sync with clear mouth movements matching every word, friendly natural intonation, small natural hand gestures while talking, '
                    .'standing in the same full-body shot (the camera stays a few meters away while she talks); before and after the line she just smiles at the camera. '
                    .'Use the same voice in every part of this video: a warm, natural young Vietnamese woman\'s voice with the same accent, pitch and pace. '
                    .'The audio has only her voice: no music, no other people, no extra words before or after the line. '
                    .'If the reference video shows different mouth movements, ignore them and lip-sync this line instead.',
            VoiceMode::Sing => 'The last attachment is an audio clip of the song for this part; use it as the soundtrack of the video. '
                .'She lip-syncs to it like a TikTok lip-sync video, with expressions that match the mood of the song and her body moving on the beat. '
                .(($timeline = self::singTimeline($scene, $part)) !== null
                    ? $timeline.' '
                    : 'Her mouth moves exactly in time with the sung words; when there are no vocals her lips are closed or smiling. ')
                .'In the parts with no vocals she does not move her lips: she smiles, glances playfully at the camera and poses gracefully. '
                .'Make the video exactly as long as the audio clip. '
                .'Do not add any other voice, speech or sound effects. If the reference video shows different mouth movements, follow the song instead.',
            default => self::SILENT,
        };
    }

    /** Phân tích giọng hát của nhạc nền (null nếu chưa có / lỗi). */
    public static function vocalAnalysis(VideoProject $project): ?array
    {
        $track = $project->audioTrack;
        if ($track?->vocal_status !== 'ready' || ! is_array($track->vocal_analysis)) {
            return null;
        }
        $analysis = $track->vocal_analysis;
        // Có lời chuẩn: thay chữ Whisper nghe nhầm, giữ mốc thời gian.
        if (filled($track->lyrics)) {
            $analysis['words'] = LyricsAligner::align($analysis['words'] ?? [], $track->lyrics);
        }

        return $analysis;
    }

    /** Dòng thời gian có lời / không lời của đoạn nhạc của cảnh: theo phân cảnh Muse, không có thì theo phân tích giọng hát. */
    private static function singTimeline(Scene $scene, ?array $part = null): ?string
    {
        $from = $part['start'] ?? 0.0;
        $muse = MusePlan::mouthTimeline($scene, $from, $part !== null ? $from + $part['length'] : null);
        if ($muse !== null || MusePlan::shot($scene) !== null) {
            return $muse;
        }

        $analysis = self::vocalAnalysis($scene->videoProject);
        if ($analysis === null) {
            return null;
        }
        $segment = self::segment($scene, $part);

        return SingPlan::timeline($analysis, $segment['start'] / 1000, $segment['length'] / 1000, $scene->position - 1);
    }

    /** Chỉ nhạc nền: không nhép miệng như đang nói (video sẽ ghép nhạc, không có lời), chỉ cười, đá mắt, làm dáng. */
    public const SILENT = 'She does not talk or sing: lips closed or smiling, no lip movement as if speaking, even if the reference video shows talking. '
        .self::CHARM.' '
        .'No voice, no speech and no music in the audio (the music is added later).';

    /** Biểu cảm khi không nói: cười, đá mắt, làm dáng làm duyên (vẫn lịch sự, toàn thân). */
    public const CHARM = 'Her face and pose do the talking: a soft natural smile that grows into a bright smile, playful glances at the camera '
        .'(looks away, then glances back with a smile, a quick cheeky side-glance or a light wink), small head tilts, tucking her hair behind her ear, '
        .'shifting her weight and changing poses gracefully (hand on hip, a light half turn, smoothing the outfit) in a charming, confident, modest way, '
        .'always in the full-body shot.';

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
    public static function segment(Scene $scene, ?array $part = null): array
    {
        $project = $scene->videoProject;
        $before = (int) $project->scenes()->where('position', '<', $scene->position)->sum('duration_ms');
        $start = (int) $project->music_start_ms + $before;

        return $part === null
            ? ['start' => $start, 'length' => $scene->duration_ms]
            : ['start' => $start + (int) round($part['start'] * 1000), 'length' => (int) round($part['length'] * 1000)];
    }

    /**
     * Cắt đoạn nhạc của cảnh để gửi kèm khi tạo clip hát nhép; lần đầu thì phân tích nhạc để chọn đoạn sôi động nhất.
     *
     * @return string đường dẫn disk media
     *
     * @throws RuntimeException
     */
    public static function prepareAudio(Scene $scene, MusicAnalyzer $analyzer, ?array $part = null): string
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
                $levels = $analyzer->levels($input);
                // Phân cảnh Muse đã chia cảnh theo nhạc: không dời chỗ cắt nữa.
                $vocals = MusePlan::of($project) !== null ? null : self::vocalAnalysis($project);
                if ($vocals === null) {
                    $project->update(['music_start_ms' => MusicAnalyzer::bestStart($levels, $seconds) * 1000]);
                } else {
                    // Bắt đầu ở đầu một câu hát và cắt cảnh vào khoảng nghỉ giữa các câu.
                    $start = SingPlan::bestStart($vocals, $levels, $seconds);
                    $project->update(['music_start_ms' => (int) round($start * 1000)]);
                    $scenes = $project->scenes()->orderBy('position')->get();
                    foreach (SingPlan::retime($scenes->pluck('duration_ms')->all(), $vocals, $start) as $i => $ms) {
                        $scenes[$i]->update(['duration_ms' => $ms]);
                    }
                    $scene->refresh();
                }
            }

            $segment = self::segment($scene, $part);
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
