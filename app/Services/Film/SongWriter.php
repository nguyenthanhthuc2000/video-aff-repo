<?php

namespace App\Services\Film;

use App\Models\SongDraft;
use App\Services\Script\ScriptWriterException;

/**
 * Sáng tác bài mới từ bài tham khảo trên YouTube, hai bước Claude:
 * 1. Hồ sơ tham khảo: thể loại, nhịp, không khí, giọng hát, nhạc cụ, cấu trúc, chủ đề, cách viết hook / vần (chỉ phân tích, không chép).
 * 2. Bài mới: tên, lời GỐC chia đoạn, prompt phong cách nhạc cho công cụ tạo nhạc (không tên ca sĩ / bài tham khảo), ý tưởng MV.
 * Claude không nghe được nhạc: dựa vào tên bài, mô tả, thẻ, lời có mốc thời gian (phụ đề).
 */
final class SongWriter
{
    public const PROFILE_SYSTEM = <<<'PROMPT'
    You are a music producer studying a reference song that will only INSPIRE a brand-new original song (new lyrics, new melody).
    You cannot hear the audio: work from the metadata, description, tags and the timed lyrics (captions; may contain errors).

    Return a JSON "reference profile" describing what makes the song work, never reproducing its lyrics:
    - genre, tempo_bpm (integer estimate), tempo_feel (slow / mid-tempo / upbeat / fast), mood (one sentence),
    - vocal: likely vocal type and delivery (e.g. "soft female vocal, breathy, high register"),
    - instruments: 3–6 likely instruments / production elements,
    - structure: the sections in order with their approximate length in seconds (from the caption timestamps; gaps without lyrics
      at the start are the intro, repeated lyric blocks are the chorus), section is intro, verse, pre-chorus, chorus, post-chorus, bridge, instrumental or outro,
    - themes: 3–5 themes / images of the lyrics, described in your own words,
    - hook: how the hook works (repetition, title placement, short phrase, call-and-response...) in your own words, no quotes,
    - rhyme: the rhyme and line-length pattern (e.g. "AABB, 6–8 syllables per line, rhymes on open vowels"),
    - summary: 2–3 sentences in Vietnamese on why this song works and what a new song can borrow (feel, structure, energy) without copying.
    PROMPT;

    public const WRITE_SYSTEM = <<<'PROMPT'
    You are a professional songwriter. Write a brand-new ORIGINAL song inspired only by the feel of a reference profile (genre, energy,
    structure, mood, hook technique) and the creator's direction. It will be produced with an AI music generator and turned into an AI music video.

    Strict originality rules:
    - Never copy, translate or closely paraphrase any line, title, hook phrase or distinctive image of the reference lyrics. New title, new hook.
    - Never mention the reference artist, song title or channel anywhere, including the style prompt.
    - Melody and arrangement must be new: the style prompt describes genre, mood, tempo, instruments and vocal only.

    Return JSON:
    - title: the new song title. language: the language code of the lyrics (follow the creator's direction, default: the reference language).
    - concept: 2–3 sentences in Vietnamese: what the new song is about and how it differs from the reference.
    - genre, tempo_bpm (integer), vocal (vocal type and delivery).
    - style_prompt: in English, max 200 characters, comma-separated tags for a music generator (genre, mood, tempo in BPM, key instruments,
      vocal type, production), no artist or song names.
    - lyrics: the full lyrics with section tags on their own lines ([Intro], [Verse 1], [Pre-Chorus], [Chorus], [Verse 2], [Bridge], [Outro]),
      following a structure similar to the reference profile, a strong repeated chorus, natural singable lines in the chosen language,
      about 2.5–4 minutes when sung.
    - mv_idea: 3–5 sentences in Vietnamese: an original music video story for the new song (few characters, simple actions, feasible with AI video).
    - singer: the look of an original fictional singer in Vietnamese (age range, hair, outfit with colors, one signature item).
    PROMPT;

    public static function profileSchema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['genre', 'tempo_bpm', 'tempo_feel', 'mood', 'vocal', 'instruments', 'structure', 'themes', 'hook', 'rhyme', 'summary'],
            'properties' => [
                'genre' => $string, 'tempo_bpm' => ['type' => 'integer'], 'tempo_feel' => $string, 'mood' => $string, 'vocal' => $string,
                'instruments' => ['type' => 'array', 'items' => $string],
                'structure' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['section', 'seconds'],
                    'properties' => ['section' => ['type' => 'string', 'enum' => SongAnalysis::SECTIONS], 'seconds' => ['type' => 'number']],
                ]],
                'themes' => ['type' => 'array', 'items' => $string],
                'hook' => $string, 'rhyme' => $string, 'summary' => $string,
            ],
        ];
    }

    public static function songSchema(): array
    {
        $string = ['type' => 'string'];
        $keys = ['title', 'language', 'concept', 'genre', 'tempo_bpm', 'vocal', 'style_prompt', 'lyrics', 'mv_idea', 'singer'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $keys,
            'properties' => [...array_fill_keys($keys, $string), 'tempo_bpm' => ['type' => 'integer']],
        ];
    }

    /** Lời bài tham khảo (phụ đề), mỗi câu một dòng. */
    public static function referenceLyrics(SongDraft $draft): string
    {
        return implode("\n", array_column($draft->reference['captions'] ?? [], 'text'));
    }

    public static function profileText(SongDraft $draft): string
    {
        $ref = $draft->reference ?? [];
        $timed = collect($ref['captions'] ?? [])->map(fn ($c) => sprintf('[%.1f–%.1f] %s', $c['start'], $c['end'], $c['text']))->implode("\n");

        return 'Title: '.($ref['title'] ?? '')."\n"
            .(filled($ref['artist'] ?? null) ? "Artist: {$ref['artist']}\n" : '')
            .(! empty($ref['duration']) ? "Duration: {$ref['duration']} seconds\n" : '')
            .(filled($ref['language'] ?? null) ? "Language: {$ref['language']}\n" : '')
            .(! empty($ref['tags']) ? 'Tags: '.implode(', ', $ref['tags'])."\n" : '')
            .(filled($ref['description'] ?? null) ? "\nDescription:\n".mb_substr($ref['description'], 0, 3000)."\n" : '')
            .($timed !== '' ? "\nTimed lyrics (".(($ref['captions_kind'] ?? null) === 'manual' ? 'uploaded captions' : 'automatic captions')."):\n{$timed}\n" : "\nNo lyrics available.\n")
            ."\nWrite the reference profile.";
    }

    /** @param list<string> $avoid câu lần trước bị trùng lời tham khảo */
    public static function songText(SongDraft $draft, array $avoid = []): string
    {
        $profile = $draft->profile ?? [];
        $structure = collect($profile['structure'] ?? [])->map(fn ($s) => "{$s['section']} ~".round($s['seconds']).'s')->implode(', ');

        return "Reference profile (do not copy the reference song itself):\n"
            ."Genre: {$profile['genre']}; tempo: {$profile['tempo_feel']} ~{$profile['tempo_bpm']} BPM; mood: {$profile['mood']}\n"
            ."Vocal: {$profile['vocal']}; instruments: ".implode(', ', $profile['instruments'] ?? [])."\n"
            ."Structure: {$structure}\n"
            .'Themes: '.implode(', ', $profile['themes'] ?? [])."\n"
            ."Hook technique: {$profile['hook']}\nRhyme pattern: {$profile['rhyme']}\n"
            .'Reference language: '.($draft->reference['language'] ?? 'unknown')."\n\n"
            .(filled($draft->notes) ? "Creator's direction (may be in Vietnamese; follow it):\n{$draft->notes}\n\n" : '')
            .($avoid !== [] ? "Your previous draft was too close to the reference in these lines; write completely different lines and images:\n- "
                .implode("\n- ", $avoid)."\n\n" : '')
            .'Write the new original song.';
    }

    /** @throws ScriptWriterException */
    public static function profile(SongDraft $draft): array
    {
        $data = (array) FilmClaude::json(self::PROFILE_SYSTEM, self::profileText($draft), self::profileSchema(), 'film_plan', "song_profile:{$draft->id}", 6000, fn () => self::sampleProfile());
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max);
        $bpm = (int) ($data['tempo_bpm'] ?? 0);

        return [
            'genre' => $text($data['genre'] ?? '', 100),
            'tempo_bpm' => $bpm >= 40 && $bpm <= 220 ? $bpm : null,
            'tempo_feel' => $text($data['tempo_feel'] ?? '', 40),
            'mood' => $text($data['mood'] ?? '', 300),
            'vocal' => $text($data['vocal'] ?? '', 200),
            'instruments' => array_values(array_filter(array_map(fn ($i) => $text($i, 80), array_slice((array) ($data['instruments'] ?? []), 0, 8)))),
            'structure' => collect((array) ($data['structure'] ?? []))->filter(fn ($s) => is_array($s) && in_array($s['section'] ?? null, SongAnalysis::SECTIONS, true))
                ->map(fn ($s) => ['section' => $s['section'], 'seconds' => round(max(0, (float) ($s['seconds'] ?? 0)))])->values()->take(20)->all(),
            'themes' => array_values(array_filter(array_map(fn ($t) => $text($t, 100), array_slice((array) ($data['themes'] ?? []), 0, 6)))),
            'hook' => $text($data['hook'] ?? '', 400),
            'rhyme' => $text($data['rhyme'] ?? '', 300),
            'summary' => $text($data['summary'] ?? '', 1000),
        ];
    }

    /**
     * @param  list<string>  $avoid
     *
     * @throws ScriptWriterException
     */
    public static function write(SongDraft $draft, array $avoid = []): array
    {
        $data = (array) FilmClaude::json(self::WRITE_SYSTEM, self::songText($draft, $avoid), self::songSchema(), 'film_plan', "song_write:{$draft->id}", 8000, fn () => self::sampleSong());
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max);
        if ($text($data['lyrics'] ?? '', 10) === '') {
            throw new ScriptWriterException('Claude không trả về lời bài hát.');
        }
        $bpm = (int) ($data['tempo_bpm'] ?? 0);

        return [
            'title' => $text($data['title'] ?? '', 200),
            'language' => $text($data['language'] ?? '', 10),
            'concept' => $text($data['concept'] ?? '', 1000),
            'genre' => $text($data['genre'] ?? '', 100),
            'tempo_bpm' => $bpm >= 40 && $bpm <= 220 ? $bpm : null,
            'vocal' => $text($data['vocal'] ?? '', 200),
            'style_prompt' => self::cleanStyle($text($data['style_prompt'] ?? '', 300), $draft),
            'lyrics' => $text($data['lyrics'], 20000),
            'mv_idea' => $text($data['mv_idea'] ?? '', 2000),
            'singer' => $text($data['singer'] ?? '', 600),
        ];
    }

    /** Bỏ tên ca sĩ / bài / kênh tham khảo nếu Claude lỡ ghi vào prompt phong cách. */
    public static function cleanStyle(string $style, SongDraft $draft): string
    {
        foreach (array_filter([$draft->reference['artist'] ?? null, $draft->reference['title'] ?? null, $draft->reference['channel'] ?? null]) as $name) {
            $style = str_ireplace($name, '', $style);
        }

        return mb_substr(trim(preg_replace('/\s*,\s*(,\s*)+/u', ', ', $style) ?? $style, ' ,'), 0, 200);
    }

    private static function sampleProfile(): array
    {
        return [
            'genre' => 'V-pop ballad', 'tempo_bpm' => 72, 'tempo_feel' => 'slow', 'mood' => 'Buồn, hoài niệm, cuối bài nhẹ nhõm.',
            'vocal' => 'soft female vocal, breathy', 'instruments' => ['piano', 'strings', 'soft drums'],
            'structure' => [['section' => 'intro', 'seconds' => 10], ['section' => 'verse', 'seconds' => 30], ['section' => 'chorus', 'seconds' => 25], ['section' => 'outro', 'seconds' => 10]],
            'themes' => ['chia tay', 'mưa', 'kỷ niệm'], 'hook' => 'Short title phrase repeated at the start of each chorus.', 'rhyme' => 'AABB, 7–8 syllables per line',
            'summary' => 'Bài hát thành công nhờ điệp khúc ngắn dễ nhớ và cảm xúc dồn dần.',
        ];
    }

    private static function sampleSong(): array
    {
        return [
            'title' => 'Nắng Sau Cơn Mưa', 'language' => 'vi', 'concept' => 'Bài mới về việc tự đứng dậy sau đổ vỡ, sáng hơn bài tham khảo.',
            'genre' => 'V-pop ballad', 'tempo_bpm' => 76, 'vocal' => 'soft female vocal',
            'style_prompt' => 'V-pop ballad, emotional, 76 BPM, piano, warm strings, soft female vocal, cinematic build',
            'lyrics' => "[Verse 1]\nSáng nay phố vắng nắng lên chậm rãi\nEm nghe tim mình thôi không còn đau\n[Chorus]\nNắng sau cơn mưa, em lại mỉm cười\nNắng sau cơn mưa, ta vẫn còn đây",
            'mv_idea' => 'Cô gái mở cửa sổ sau một đêm mưa, đạp xe qua phố. Điệp khúc nắng tràn khắp con đường.',
            'singer' => 'Cô gái khoảng 23 tuổi, tóc dài buộc thấp, áo sơ mi vàng nhạt, đeo khăn lụa xanh.',
        ];
    }
}
