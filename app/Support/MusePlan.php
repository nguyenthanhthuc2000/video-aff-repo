<?php

namespace App\Support;

use App\Enums\VoiceMode;
use App\Models\ProductCategory;
use App\Models\Scene;
use App\Models\VideoProject;
use RuntimeException;

/**
 * Phân cảnh do Muse lên: Muse xem video mẫu (cả tiếng), nghe nhạc nền rồi trả về đoạn nhạc dùng, lời bài hát kèm mốc thời gian
 * và danh sách shot (thời lượng 2–30 giây, động tác, máy quay, đoạn video mẫu cần sao chép, lúc nào nhép lời / ngậm miệng).
 * Kịch bản, ảnh và clip làm theo đúng phân cảnh này thay cho thuật toán phân tích (tách giọng, dò shot).
 */
final class MusePlan
{
    /** Shot dài nhất (giây); Muse làm mỗi lần tối đa PART_SECONDS nên shot dài được nối từ nhiều đoạn. */
    public const MAX_SHOT = 30;

    public const PART_SECONDS = 10;

    /** Có video mẫu hoặc nhạc nền để Muse phân tích, và hệ thống đang dùng Muse (trình duyệt ngầm). */
    public static function wanted(VideoProject $project): bool
    {
        return config('videoaff.image_driver') === 'browser'
            && ($project->sample_video_id !== null || Voice::musicPath($project) !== null);
    }

    /** @return ?array phân cảnh đã chuẩn hoá, null nếu chưa có / lỗi */
    public static function of(VideoProject $project): ?array
    {
        return $project->muse_plan_status === 'ready' && is_array($project->muse_plan) && ! empty($project->muse_plan['shots'])
            ? $project->muse_plan
            : null;
    }

    /** Shot của cảnh (theo thứ tự), null nếu không có phân cảnh hoặc số cảnh đã khác phân cảnh. */
    public static function shot(Scene $scene): ?array
    {
        $plan = self::of($scene->videoProject);
        if ($plan === null || $scene->videoProject->scenes()->count() !== count($plan['shots'])) {
            return null;
        }

        return $plan['shots'][$scene->position - 1] ?? null;
    }

    /** Nhạc nền là tiếng của chính video mẫu (không cần gửi riêng file nhạc). */
    public static function musicIsSampleAudio(VideoProject $project): bool
    {
        return $project->sampleVideo !== null && $project->audio_track_id !== null
            && $project->sampleVideo->audio_track_id === $project->audio_track_id;
    }

    public static function prompt(VideoProject $project, bool $video, bool $music, ?float $musicSeconds): string
    {
        $settings = $project->videoSettings();
        $product = $project->product;
        $category = ProductCategory::detect($product);
        $oneTake = $project->one_take;
        $sampleAudio = $video && self::musicIsSampleAudio($project);
        $hasSong = $music || $sampleAudio;
        // Chỉ hát nhép mới cần lời và mốc từng câu hát (Muse phải chạy nhận giọng, rất lâu); còn lại chỉ cần nhịp, cao trào.
        $lyricsNeeded = $hasSong && $project->voice_mode === VoiceMode::Sing;

        $attachments = [];
        if ($video) {
            $attachments[] = 'the SAMPLE VIDEO to copy (watch it frame by frame'.($project->sampleVideo?->has_audio ? ' and listen to its audio' : '').')';
        }
        if ($music) {
            $attachments[] = 'the BACKGROUND SONG of the new video (full track'.($musicSeconds ? ', '.round($musicSeconds).' seconds' : '').')';
        }
        if ($product?->primaryImage !== null) {
            $attachments[] = 'the last attachment is a PHOTO OF THE PRODUCT (describe its real color, shape and details from this photo, not from the sample video)';
        }
        // Lời chuẩn người dùng dán ở trang Âm thanh: Muse dóng mốc thời gian theo đúng lời này.
        $lyrics = trim((string) $project->audioTrack?->lyrics);

        $voice = match ($project->voice_mode) {
            VoiceMode::Sing => 'The model LIP-SYNCS to the song: her lips move only on the sung words and stay closed (smiling, winking, swaying) when there are no vocals.',
            VoiceMode::Speak => 'The model TALKS to the camera reviewing the product (the lines are written later): plan calm full-body talking shots of 4–10 seconds where she stands still enough to talk, with small hand gestures toward the part she talks about; the song is only quiet background music.',
            default => 'The model does NOT talk or sing (lips closed or smiling): she only smiles, gives playful glances at the camera (looks away then back, a cheeky side-glance, a light wink) '
                .'and changes graceful, charming poses (hand on hip, head tilt, touching her hair, light half turns); the song is only background music: plan the cuts and pose changes on the beat.',
        };

        return "Do not create any image or video in this chat. Reply with ONE JSON object only (no markdown, no explanation).\n\n"
            ."You are the director planning the shot list of a {$settings['seconds']}-second vertical 9:16 TikTok / Shopee product video "
            ."where a young Vietnamese woman shows this product: {$product?->name}".($category ? " ({$category->name})" : '').".\n"
            .$voice."\n"
            .'Attachments: '.implode('; ', $attachments).".\n\n"
            .'Analyse the attachments carefully first (use your tools if you need to, but keep the whole analysis under about '.($lyricsNeeded ? 10 : 5)." minutes):\n"
            .($lyricsNeeded ? "- the song: tempo, mood, every sung line with exact start / end times (0.1 s) and the exact Vietnamese lyrics, and the parts with no vocals;\n" : '')
            .($hasSong && ! $lyricsNeeded ? "- the song: tempo (BPM), mood and the seconds of the strong beats / drops; do NOT transcribe the lyrics (song_lines can be []);\n" : '')
            .($video ? "- the sample video: every shot (cut), the camera framing and movement, the person's moves, gestures, expression and mouth, and the rhythm.\n" : '')
            .($lyricsNeeded && $lyrics !== '' ? "The correct lyrics of the song (use these exact words, only find their timing):\n".mb_substr($lyrics, 0, 3000)."\n" : '')
            ."\nThen plan the new video:\n"
            .($lyricsNeeded ? '- music_start: the second in the '.($sampleAudio ? "sample video's audio" : 'song')." where the new video's music begins: start right on the first word of a strong line (chorus preferred) so the video is full of singing;\n" : '')
            .($hasSong && ! $lyricsNeeded ? '- music_start: the second in the '.($sampleAudio ? "sample video's audio" : 'song')." where the new video's music begins: the most catchy, energetic part (chorus or drop), on a strong beat;\n" : '')
            .($oneTake
                ? "- it is ONE continuous take without cuts: split it into consecutive beats of 5–10 seconds that continue each other (each beat starts in the last pose of the previous one);\n"
                : '- about '.$settings['scenes']." shots that together last exactly {$settings['seconds']} seconds; a shot lasts 2–".self::MAX_SHOT." seconds: use long continuous shots of 10–30 seconds when the sample video has a long take or a sung phrase continues, short shots for quick cuts on the beat; cut only in pauses between sung lines or on a beat;\n")
            ."- EVERY shot is a wide full-body shot from about 3 meters away: her whole body from head to feet is always in frame, no close-ups, no half-body framing, no zoom-in (even if the sample video is framed closer);\n"
            ."- show the product fully across the shots (front, side, back, in motion; details shown by her hands touching / pointing while the full body stays visible), the last shot invites to buy;\n"
            .($video ? "- for each shot say which part of the sample video it copies (sample_start / sample_end in seconds of the sample video) and describe those moves and that camera exactly;\n" : '')
            .($hasSong && $project->voice_mode === VoiceMode::Sing ? "- for each shot give the mouth timeline in seconds from the start of the shot: \"sing\" with the exact words sung, or \"closed\" when there are no vocals;\n" : '')
            ."- write camera, action, expression and product_focus in English, concrete enough to animate a photo.\n\n"
            ."JSON format:\n"
            .'{"music_start": number|null, "tempo_bpm": number|null, "mood": string, "summary": "1-2 sentences in Vietnamese for the user", '
            .'"song_lines": [{"start": number, "end": number, "text": string}], '
            .'"shots": [{"duration": number, "camera": string, "action": string, "expression": string, "product_focus": string, '
            .'"sample_start": number|null, "sample_end": number|null, '
            .'"mouth": [{"start": number, "end": number, "state": "sing"|"closed", "words": string}]}]}'."\n"
            .'song_lines use seconds of the full '.($sampleAudio ? 'sample video audio' : 'song').'; the shot durations must add up to '.$settings['seconds'].' seconds.';
    }

    /**
     * Đọc JSON trong câu trả lời của Muse (có thể kèm ```json hoặc lời dẫn) và chuẩn hoá.
     *
     * @throws RuntimeException
     */
    public static function parse(string $text, VideoProject $project): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($data) || empty($data['shots']) || ! is_array($data['shots'])) {
            throw new RuntimeException('Muse không trả về phân cảnh dạng JSON: '.mb_substr(trim($text), 0, 300));
        }

        $max = $project->one_take ? self::PART_SECONDS : self::MAX_SHOT;
        $clean = fn ($value, int $length = 400) => mb_substr(trim((string) $value), 0, $length);
        $number = fn ($value) => is_numeric($value) ? round((float) $value, 2) : null;

        $shots = [];
        foreach (array_slice($data['shots'], 0, 12) as $shot) {
            if (! is_array($shot)) {
                continue;
            }
            $duration = round(max(2.0, min($max, (float) ($shot['duration'] ?? 5))), 1);
            $mouth = [];
            foreach (is_array($shot['mouth'] ?? null) ? $shot['mouth'] : [] as $part) {
                $a = max(0.0, (float) ($part['start'] ?? 0));
                $b = min($duration, (float) ($part['end'] ?? 0));
                if (is_array($part) && $b - $a >= 0.2) {
                    $mouth[] = ['start' => round($a, 1), 'end' => round($b, 1), 'state' => ($part['state'] ?? '') === 'sing' ? 'sing' : 'closed', 'words' => $clean($part['words'] ?? '', 300)];
                }
            }
            $sampleStart = $number($shot['sample_start'] ?? null);
            $sampleEnd = $number($shot['sample_end'] ?? null);
            $shots[] = [
                'duration' => $duration,
                'camera' => $clean($shot['camera'] ?? ''),
                'action' => $clean($shot['action'] ?? '', 600),
                'expression' => $clean($shot['expression'] ?? '', 200),
                'product_focus' => $clean($shot['product_focus'] ?? '', 200),
                'sample_start' => $sampleStart !== null && $sampleEnd !== null && $sampleEnd > $sampleStart ? max(0.0, $sampleStart) : null,
                'sample_end' => $sampleStart !== null && $sampleEnd !== null && $sampleEnd > $sampleStart ? $sampleEnd : null,
                'mouth' => $mouth,
            ];
        }
        if ($shots === []) {
            throw new RuntimeException('Phân cảnh của Muse không có shot nào.');
        }

        $lines = [];
        foreach (is_array($data['song_lines'] ?? null) ? $data['song_lines'] : [] as $line) {
            if (is_array($line) && filled($line['text'] ?? null) && is_numeric($line['start'] ?? null)) {
                $lines[] = ['start' => round((float) $line['start'], 1), 'end' => round((float) ($line['end'] ?? $line['start']), 1), 'text' => $clean($line['text'], 300)];
            }
        }

        return [
            'music_start' => $number($data['music_start'] ?? null) !== null ? max(0.0, $number($data['music_start'])) : null,
            'tempo_bpm' => $number($data['tempo_bpm'] ?? null),
            'mood' => $clean($data['mood'] ?? '', 200),
            'summary' => $clean($data['summary'] ?? '', 600),
            'song_lines' => array_slice($lines, 0, 80),
            'shots' => $shots,
        ];
    }

    /** Phần phân cảnh gửi kèm khi viết kịch bản: số cảnh, thời lượng và nội dung từng cảnh phải theo đúng. */
    public static function scriptText(VideoProject $project): string
    {
        $plan = self::of($project);
        if ($plan === null) {
            return '';
        }

        $lines = [];
        foreach ($plan['shots'] as $i => $shot) {
            $sung = implode(' / ', array_filter(array_map(fn ($part) => $part['state'] === 'sing' ? $part['words'] : null, $shot['mouth'])));
            $lines[] = 'Cảnh '.($i + 1).' ('.(int) round($shot['duration'] * 1000).' ms): '
                .trim("{$shot['action']} Camera: {$shot['camera']}. Biểu cảm: {$shot['expression']}. Sản phẩm: {$shot['product_focus']}.")
                .($sung !== '' ? " Lời đang hát: {$sung}." : '');
        }

        return "\n\nPHÂN CẢNH MUSE ĐÃ LÊN từ video mẫu và nhạc nền (BẮT BUỘC theo đúng): đúng ".count($plan['shots']).' cảnh theo thứ tự này, '
            .'duration_ms của mỗi cảnh đúng như ghi; visual và image_prompt tả đúng hành động, góc máy và biểu cảm của cảnh đó '
            .'(image_prompt là khoảnh khắc mở đầu của shot); chỉ viết thêm chữ trên màn, lời đọc, hook và caption.'
            .($plan['summary'] !== '' ? "\nTóm tắt của Muse: {$plan['summary']}" : '')
            ."\n".implode("\n", $lines);
    }

    /** Câu tả shot cho prompt clip (động tác, máy quay, biểu cảm theo phân cảnh). */
    public static function shotLine(Scene $scene): string
    {
        $shot = self::shot($scene);
        if ($shot === null) {
            return '';
        }

        return 'Shot plan: '.trim("{$shot['action']} Camera: {$shot['camera']}. Expression: {$shot['expression']}. Product focus: {$shot['product_focus']}.");
    }

    /**
     * Dòng thời gian nhép miệng của đoạn [from, to) giây trong shot (tính lại từ 0), null nếu phân cảnh không có.
     */
    public static function mouthTimeline(Scene $scene, float $from = 0.0, ?float $to = null): ?string
    {
        $shot = self::shot($scene);
        if ($shot === null || $shot['mouth'] === []) {
            return null;
        }
        $to ??= $shot['duration'];

        $parts = [];
        foreach ($shot['mouth'] as $part) {
            $a = max($from, $part['start']);
            $b = min($to, $part['end']);
            if ($b - $a < 0.2) {
                continue;
            }
            $span = number_format($a - $from, 1, '.', '').'–'.number_format($b - $from, 1, '.', '').'s';
            $parts[] = $part['state'] === 'sing'
                ? "{$span}: singing".($part['words'] !== '' ? ' "'.str_replace('"', '', $part['words']).'" — lip-sync these words, mouth shapes clearly following each syllable' : ' — lip-sync the vocals')
                : "{$span}: no singing — lips closed, ".SingPlan::IDLE_ACTIONS[count($parts) % count(SingPlan::IDLE_ACTIONS)];
        }
        if ($parts === []) {
            return null;
        }

        return 'Singing timeline of the attached audio, follow it exactly: '.implode('; ', $parts).'. '
            .'Move the lips ONLY during the singing parts; whenever there is no singing her mouth stays closed or smiling (no mouthing, no talking).';
    }

    /**
     * Shot dài được làm thành mấy đoạn nối tiếp (mỗi đoạn tối đa PART_SECONDS); một cảnh quay liền thì mỗi nhịp một đoạn.
     *
     * @return list<array{start: float, length: float}> giây trong shot
     */
    public static function parts(Scene $scene): array
    {
        $seconds = $scene->duration_ms / 1000;
        $count = $scene->videoProject->one_take ? 1 : max(1, (int) ceil($seconds / self::PART_SECONDS - 0.05));
        $length = $seconds / $count;

        return array_map(fn (int $i) => ['start' => round($i * $length, 2), 'length' => round($length, 2)], range(0, $count - 1));
    }

    /** Câu nói cho Muse biết đây là đoạn thứ mấy của shot dài. */
    public static function partLine(Scene $scene, int $index): string
    {
        $parts = self::parts($scene);
        if (count($parts) < 2) {
            return '';
        }
        $seconds = rtrim(rtrim(number_format($parts[$index]['length'], 1, '.', ''), '0'), '.');
        $total = rtrim(rtrim(number_format($scene->duration_ms / 1000, 1, '.', ''), '0'), '.');

        return 'This video is part '.($index + 1).' of '.count($parts)." of ONE continuous {$total}-second shot (no cuts): make this part exactly {$seconds} seconds long. "
            .($index === 0
                ? 'End it on a steady, natural moment (no motion blur, face visible) so the next part continues from the last frame.'
                : 'The photo is the exact last frame of the previous part: start in exactly this pose with the same camera position, framing, lighting and location and continue the movement seamlessly; no jump, no reset.'
                    .($index < count($parts) - 1 ? ' End it on a steady, natural moment so the next part can continue.' : ''));
    }

    /** Đoạn video mẫu cảnh này sao chép theo phân cảnh (giây trong video mẫu), null nếu phân cảnh không ghi. */
    public static function sampleRange(Scene $scene): ?array
    {
        $shot = self::shot($scene);

        return $shot && $shot['sample_start'] !== null ? ['start' => $shot['sample_start'], 'end' => $shot['sample_end']] : null;
    }
}
