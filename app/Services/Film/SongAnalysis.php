<?php

namespace App\Services\Film;

use App\Models\AudioTrack;
use App\Services\Script\ScriptWriterException;

/**
 * Claude phân tích bài hát cho MV từ thông tin bài (tên, ca sĩ, mô tả video) và lời có mốc thời gian: thể loại, nhịp (ước lượng),
 * không khí, cấu trúc có mốc (intro / verse / chorus / bridge / outro), chủ đề lời, lời đã chia đoạn. Claude không nghe được nhạc:
 * cấu trúc suy ra từ lời lặp lại và chỗ không có lời, nhịp chỉ là ước lượng; người dùng sửa được.
 */
final class SongAnalysis
{
    public const SECTIONS = ['intro', 'verse', 'pre-chorus', 'chorus', 'post-chorus', 'bridge', 'instrumental', 'outro'];

    public const SYSTEM = <<<'PROMPT'
    You are a music producer preparing a song for an AI-made music video. You cannot hear the audio: work from the song metadata,
    the video description and the lyrics with timestamps (from captions or speech recognition, which may contain errors).

    Return JSON:
    - title, artist: clean names (remove "Official Video", "MV", "Lyrics" and channel noise). Empty string when unknown.
    - genre: the most likely genre (e.g. V-pop ballad, pop, R&B, indie, rap, EDM, bolero).
    - tempo_bpm: your estimate of the tempo (integer) from the genre and the pace of the lyric lines; tempo_feel: slow / mid-tempo / upbeat / fast.
    - mood: the emotion and energy in one short sentence.
    - structure: the sections in order with start and end in seconds (covering the whole song from 0 to its duration). Use the timestamps:
      repeated lyric blocks are the chorus; long gaps without lyrics at the start are the intro, in the middle an instrumental, at the end the outro.
      section is one of: intro, verse, pre-chorus, chorus, post-chorus, bridge, instrumental, outro.
    - themes: 3–5 themes or images from the lyrics.
    - lyrics: the full lyrics in the original language with fixed spelling, one line per sung line, with section headers like [Verse 1], [Chorus].
      Use the official lyrics or the description when they contain lyrics; otherwise clean up the timed lyrics.
    - summary: 2–3 sentences in Vietnamese about what the song tells and how a music video could feel.
    PROMPT;

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'artist', 'genre', 'tempo_bpm', 'tempo_feel', 'mood', 'structure', 'themes', 'lyrics', 'summary'],
            'properties' => [
                'title' => $string, 'artist' => $string, 'genre' => $string, 'tempo_bpm' => ['type' => 'integer'], 'tempo_feel' => $string, 'mood' => $string,
                'structure' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['section', 'start', 'end'],
                    'properties' => ['section' => ['type' => 'string', 'enum' => self::SECTIONS], 'start' => ['type' => 'number'], 'end' => ['type' => 'number']],
                ]],
                'themes' => ['type' => 'array', 'items' => $string],
                'lyrics' => $string,
                'summary' => $string,
            ],
        ];
    }

    public static function text(AudioTrack $track): string
    {
        $info = $track->song_info ?? [];
        $lines = collect(MusicVideoWriter::lines($track->vocal_analysis['words'] ?? []))
            ->map(fn ($l) => sprintf('[%.1f–%.1f] %s', $l['start'], $l['end'], $l['text']))->implode("\n");
        $seconds = $track->duration_ms ? round($track->duration_ms / 1000, 1) : null;

        return 'Video / file title: '.($info['title'] ?? $track->name)."\n"
            .(filled($info['artist'] ?? null) ? "Artist: {$info['artist']}\n" : '')
            .(filled($info['channel'] ?? null) ? "Channel: {$info['channel']}\n" : '')
            .($seconds ? "Duration: {$seconds} seconds\n" : '')
            .(filled($track->language) ? "Language: {$track->language}\n" : '')
            .(filled($info['description'] ?? null) ? "\nVideo description:\n".mb_substr($info['description'], 0, 3000)."\n" : '')
            .(filled($track->lyrics) ? "\nLyrics from the creator:\n{$track->lyrics}\n" : '')
            .($lines !== '' ? "\nTimed lyrics (".(($track->vocal_analysis['source'] ?? null) === 'captions' ? 'from YouTube captions' : 'speech recognition, may contain errors')."):\n{$lines}\n" : "\nNo timed lyrics available.\n")
            ."\nAnalyze the song.";
    }

    /**
     * @return array{title: string, artist: string, genre: string, tempo_bpm: ?int, tempo_feel: string, mood: string, structure: list<array{section: string, start: float, end: float}>, themes: list<string>, lyrics: string, summary: string}
     *
     * @throws ScriptWriterException
     */
    public static function analyze(AudioTrack $track): array
    {
        $data = FilmClaude::json(self::SYSTEM, self::text($track), self::schema(), 'film_plan', "song_analysis:{$track->id}", 8000, fn () => self::sample($track));

        return self::normalize((array) $data, $track->duration_ms ? $track->duration_ms / 1000 : null);
    }

    public static function normalize(array $data, ?float $duration = null): array
    {
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max);
        $structure = collect((array) ($data['structure'] ?? []))
            ->filter(fn ($s) => is_array($s) && in_array($s['section'] ?? null, self::SECTIONS, true))
            ->map(fn ($s) => ['section' => $s['section'], 'start' => round(max(0, (float) ($s['start'] ?? 0)), 1), 'end' => round(max(0, (float) ($s['end'] ?? 0)), 1)])
            ->filter(fn ($s) => $s['end'] > $s['start'] && ($duration === null || $s['start'] < $duration))
            ->map(fn ($s) => $duration === null ? $s : [...$s, 'end' => min($s['end'], round($duration, 1))])
            ->sortBy('start')->values()->take(30)->all();
        $bpm = (int) ($data['tempo_bpm'] ?? 0);

        return [
            'title' => $text($data['title'] ?? '', 200),
            'artist' => $text($data['artist'] ?? '', 200),
            'genre' => $text($data['genre'] ?? '', 100),
            'tempo_bpm' => $bpm >= 40 && $bpm <= 220 ? $bpm : null,
            'tempo_feel' => $text($data['tempo_feel'] ?? '', 40),
            'mood' => $text($data['mood'] ?? '', 300),
            'structure' => $structure,
            'themes' => array_values(array_filter(array_map(fn ($t) => $text($t, 100), array_slice((array) ($data['themes'] ?? []), 0, 6)))),
            'lyrics' => $text($data['lyrics'] ?? '', 20000),
            'summary' => $text($data['summary'] ?? '', 1000),
        ];
    }

    /** Một dòng tóm tắt cho prompt khác (đề xuất ý tưởng, lên kế hoạch). */
    public static function brief(?array $analysis): string
    {
        if (empty($analysis)) {
            return '';
        }

        return trim(collect([
            filled($analysis['genre'] ?? null) ? "Genre: {$analysis['genre']}" : null,
            filled($analysis['tempo_feel'] ?? null) || filled($analysis['tempo_bpm'] ?? null)
                ? 'Tempo: '.trim(($analysis['tempo_feel'] ?? '').(filled($analysis['tempo_bpm'] ?? null) ? " (~{$analysis['tempo_bpm']} BPM)" : '')) : null,
            filled($analysis['mood'] ?? null) ? "Mood: {$analysis['mood']}" : null,
            ! empty($analysis['themes']) ? 'Themes: '.implode(', ', $analysis['themes']) : null,
        ])->filter()->implode("\n"));
    }

    /**
     * Cấu trúc bài trong đoạn MV (giây tính từ đầu đoạn).
     *
     * @return list<array{section: string, start: float, end: float}>
     */
    public static function structureIn(?array $analysis, float $from, float $to): array
    {
        return collect($analysis['structure'] ?? [])
            ->filter(fn ($s) => $s['end'] > $from && $s['start'] < $to)
            ->map(fn ($s) => ['section' => $s['section'], 'start' => round(max(0, $s['start'] - $from), 1), 'end' => round(min($to, $s['end']) - $from, 1)])
            ->values()->all();
    }

    /** Mẫu khi chưa kết nối Claude (và trong test). */
    private static function sample(AudioTrack $track): array
    {
        $end = $track->duration_ms ? $track->duration_ms / 1000 : 200;

        return [
            'title' => $track->song_info['title'] ?? $track->name, 'artist' => $track->song_info['artist'] ?? '', 'genre' => 'V-pop ballad',
            'tempo_bpm' => 76, 'tempo_feel' => 'slow', 'mood' => 'Buồn, hoài niệm rồi nhẹ nhõm.',
            'structure' => [
                ['section' => 'intro', 'start' => 0, 'end' => 3], ['section' => 'verse', 'start' => 3, 'end' => 12],
                ['section' => 'chorus', 'start' => 12, 'end' => 21], ['section' => 'outro', 'start' => 21, 'end' => $end],
            ],
            'themes' => ['mưa đêm', 'cô đơn', 'ánh sáng'],
            'lyrics' => "[Verse]\nWalking alone tonight, the rain falls slow\n[Chorus]\nLight me up, light me up again",
            'summary' => 'Bài hát kể về một người đi qua nỗi buồn sau chia tay và tìm lại ánh sáng.',
        ];
    }
}
