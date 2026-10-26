<?php

namespace App\Services\TopicShort;

use App\Models\TopicShort;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;

/** Claude viết bộ đăng bài cho short: 3 tiêu đề gợi ý, chữ ảnh bìa, mô tả, hashtag (YouTube Shorts / TikTok / Facebook Reels). */
final class PublishWriter
{
    public const SYSTEM = <<<'PROMPT'
    You write the posting package for a vertical anime recap short (YouTube Shorts, TikTok, Facebook Reels) of a Vietnamese channel about Dragon Ball.
    - titles: exactly 3 different titles in the video's language, each under 70 characters, curiosity-driven (question, bold claim, or number), no clickbait lies, no emojis spam (at most one emoji), include the main character name.
    - cover_text: 2–6 words in capitals for the thumbnail, punchy, readable at a glance (e.g. "VEGETA ĐÃ KHÓC?").
    - description: 3–5 short lines in the video's language: one line summarising the story hook, one line inviting viewers to comment with a direct question, one line asking them to follow for the next part (if a series) or more stories. If sources are given, add a line "Nguồn: " with the site names (not URLs). Always end with the line "Video có sử dụng AI (giọng đọc, hình minh hoạ). Dragon Ball © Akira Toriyama / Shueisha / Toei Animation."
    - hashtags: 5–8 tags starting with #, always #shorts and #dragonball, plus Vietnamese tags like #7vienngocrong and character tags.
    Never copy titles from other channels. Do not invent facts that are not in the narration.
    PROMPT;

    /**
     * @return array{titles: list<string>, cover_text: string, description: string, hashtags: list<string>}
     *
     * @throws ScriptWriterException
     */
    public static function write(TopicShort $short): array
    {
        $narration = $short->scenes()->orderBy('position')->pluck('narration')->implode("\n");
        $sources = collect($short->research['sources'] ?? [])->map(fn ($s) => parse_url($s['url'], PHP_URL_HOST))->filter()->unique()->values()->implode(', ');
        $text = "Topic: {$short->topic}\nLanguage: ".($short->language === 'en' ? 'English' : 'Vietnamese')
            ."\nCurrent title: {$short->title}\nNarration:\n{$narration}"
            .($sources !== '' ? "\nSources used: {$sources}" : '')
            .($short->part ? "\nThis is part {$short->part} of {$short->series_parts} of a series." : '');

        $data = FilmClaude::json(self::SYSTEM, $text, self::schema(), 'topic_short', "topic_short:{$short->id}", 2000, fn () => self::sample($short));

        return self::normalize($data, $short);
    }

    public static function normalize(mixed $data, TopicShort $short): array
    {
        $titles = collect((array) ($data['titles'] ?? []))->map(fn ($t) => mb_substr(trim((string) $t), 0, 100))->filter()->unique()->take(3)->values()->all();
        if ($titles === []) {
            throw ScriptWriterException::retryable('Claude chưa viết được tiêu đề, thử lại.');
        }
        $tags = collect((array) ($data['hashtags'] ?? []))->map(fn ($t) => '#'.ltrim(preg_replace('/\s+/u', '', (string) $t), '#'))
            ->filter(fn ($t) => mb_strlen($t) > 1)->unique()->take(10)->values()->all();

        return [
            'titles' => $titles,
            'cover_text' => mb_substr(trim((string) ($data['cover_text'] ?? '')), 0, 80) ?: mb_strtoupper($short->topic),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 3000),
            'hashtags' => $tags,
        ];
    }

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['titles', 'cover_text', 'description', 'hashtags'],
            'properties' => [
                'titles' => ['type' => 'array', 'items' => $string],
                'cover_text' => $string,
                'description' => $string,
                'hashtags' => ['type' => 'array', 'items' => $string],
            ],
        ];
    }

    private static function sample(TopicShort $short): array
    {
        return [
            'titles' => ["{$short->topic}", "Sự thật về: {$short->topic}", "3 lý do: {$short->topic}"],
            'cover_text' => mb_strtoupper($short->topic),
            'description' => "Câu chuyện: {$short->topic}.\nBạn nghĩ sao? Comment cho mình biết nhé!\nFollow để xem thêm truyện Ngọc Rồng.\nVideo có sử dụng AI (giọng đọc, hình minh hoạ). Dragon Ball © Akira Toriyama / Shueisha / Toei Animation.",
            'hashtags' => ['#shorts', '#dragonball', '#7vienngocrong'],
        ];
    }
}
