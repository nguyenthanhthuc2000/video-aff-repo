<?php

namespace App\Services\TopicShort;

use App\Models\LoreFact;
use App\Models\TopicShort;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;
use App\Support\DragonBall;

/**
 * Claude tìm tư liệu thật trên web cho chủ đề (Claude Code CLI: WebSearch / WebFetch; API: server tool web search / web fetch),
 * đọc kho tư liệu đã duyệt trước để chỉ tìm phần còn thiếu, trả bản tư liệu: sự kiện / câu thoại / giả thuyết kèm nguồn.
 */
final class TopicResearcher
{
    /** Số chi tiết tối đa trong một bản tư liệu. */
    public const MAX_FACTS = 30;

    /** Số chi tiết kho tư liệu gửi kèm cho Claude. */
    private const LIBRARY_LIMIT = 40;

    public const SYSTEM = <<<'PROMPT'
    You are the researcher for a Vietnamese anime recap & analysis channel making 60-second vertical story videos (Dragon Ball first).
    Research the topic on the web, then return a research brief the writer will use as the ONLY source of truth.

    How to research:
    - Use web search and web fetch. Prefer: dragonball.fandom.com (Dragon Ball Wiki), kanzenshuu.com (translations, Toriyama interviews, guidebooks), en.wikipedia.org. Read the actual pages, do not rely on memory.
    - Never use scripts, titles or narration of other YouTube / TikTok channels.
    - Library facts we already verified are given below: reuse them (put their id in library_ids) and only search for what is missing.

    What to return:
    - summary: 2–3 sentences (Vietnamese) answering the topic.
    - facts: 8–25 items, in story order. Each item:
      - kind: "fact" (event that happened in the work), "quote" (a short line a character says, max 25 words, translated to Vietnamese, original wording in English in the text after " / "), or "theory" (fan theory or interpretation, clearly an opinion).
      - era: arc / saga and chapter or episode when known (e.g. "Namek Saga, DBZ ep 85" or "Manga ch. 327").
      - text: one sentence in Vietnamese, precise, no exaggeration.
      - source_url and source_title: the page that supports it (must be a page you actually read or a search result you saw).
      - characters: official English names of the characters involved.
    - Mark anime-only (filler) or Super / movie details in era so the writer can tell canon apart.
    - If sources disagree or you are not sure, leave the detail out or make it a theory.
    - sources: every page you used (url, title).
    PROMPT;

    /**
     * @return array{summary: string, facts: list<array>, sources: list<array{url: string, title: string}>}
     *
     * @throws ScriptWriterException
     */
    public static function research(TopicShort $short): array
    {
        $library = self::library($short);
        $text = "Topic: {$short->topic}\nFranchise: Dragon Ball (manga, Z, Super)\nVideo length: about {$short->target_seconds} seconds";
        if ($short->series_parts > 1) {
            $text .= "\nThis topic becomes a series of {$short->series_parts} videos: research enough facts to fill every part.";
        }
        if (filled($short->research_notes)) {
            $text .= "\nNotes from the channel owner (follow them): {$short->research_notes}";
        }
        $text .= "\n\nLibrary facts already verified (id: [kind] era — text — source):\n".($library->isEmpty() ? '(none)' : $library->map(
            fn (LoreFact $fact) => "L{$fact->id}: [{$fact->kind}] {$fact->era} — {$fact->text} — {$fact->source_url}",
        )->implode("\n"));

        $data = FilmClaude::json(self::SYSTEM, $text, self::schema(), 'topic_research', "topic_research:{$short->id}", 16000, fn () => self::sample($short), web: true);

        return self::normalize($data, $library->keyBy('id')->all());
    }

    /** Tư liệu đã duyệt trong kho liên quan tới các nhân vật của chủ đề (không nhận ra nhân vật nào thì lấy mới nhất). */
    public static function library(TopicShort $short)
    {
        $keys = DragonBall::charactersIn($short->topic);
        $query = LoreFact::where('user_id', $short->user_id)->where('franchise', 'dragonball')->latest('id');
        if ($keys !== []) {
            $query->where(function ($q) use ($keys) {
                foreach ($keys as $key) {
                    $q->orWhereJsonContains('characters', $key);
                }
            });
        }

        return $query->limit(self::LIBRARY_LIMIT)->get();
    }

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'facts', 'library_ids', 'sources'],
            'properties' => [
                'summary' => $string,
                'facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['kind', 'era', 'text', 'source_url', 'source_title', 'characters'],
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => array_keys(LoreFact::KINDS)],
                            'era' => $string,
                            'text' => $string,
                            'source_url' => $string,
                            'source_title' => $string,
                            'characters' => ['type' => 'array', 'items' => $string],
                        ],
                    ],
                ],
                'library_ids' => ['type' => 'array', 'items' => $string],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['url', 'title'],
                        'properties' => ['url' => $string, 'title' => $string],
                    ],
                ],
            ],
        ];
    }

    /**
     * Gán id F1, F2... cho chi tiết mới; chi tiết kho được dùng lại giữ nguyên nội dung kho (lore_id).
     *
     * @param  array<int, LoreFact>  $library
     *
     * @throws ScriptWriterException
     */
    public static function normalize(mixed $data, array $library = []): array
    {
        $facts = [];
        foreach ((array) ($data['library_ids'] ?? []) as $id) {
            $fact = $library[(int) ltrim((string) $id, 'Ll')] ?? null;
            if ($fact && ! collect($facts)->contains('lore_id', $fact->id)) {
                $facts[] = [
                    'kind' => $fact->kind, 'era' => (string) $fact->era, 'text' => $fact->text, 'source_url' => (string) $fact->source_url,
                    'source_title' => (string) $fact->source_title, 'characters' => $fact->characters ?? [], 'lore_id' => $fact->id,
                ];
            }
        }
        foreach ((array) ($data['facts'] ?? []) as $fact) {
            $text = trim((string) ($fact['text'] ?? ''));
            $url = trim((string) ($fact['source_url'] ?? ''));
            if ($text === '' || collect($facts)->contains(fn ($f) => mb_strtolower($f['text']) === mb_strtolower($text))) {
                continue;
            }
            $facts[] = [
                'kind' => array_key_exists($fact['kind'] ?? '', LoreFact::KINDS) ? $fact['kind'] : 'fact',
                'era' => mb_substr(trim((string) ($fact['era'] ?? '')), 0, 120),
                'text' => mb_substr($text, 0, 600),
                // Chỉ nhận link http(s); không có nguồn thì để trống, giao diện đánh dấu "chưa có nguồn".
                'source_url' => preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 500) : '',
                'source_title' => mb_substr(trim((string) ($fact['source_title'] ?? '')), 0, 300),
                'characters' => array_values(array_slice(array_filter(array_map(fn ($n) => mb_substr(trim((string) $n), 0, 80), (array) ($fact['characters'] ?? []))), 0, 8)),
                'lore_id' => null,
            ];
        }
        $facts = array_slice($facts, 0, self::MAX_FACTS);
        if (count($facts) < 3) {
            throw ScriptWriterException::retryable('Claude tìm được quá ít tư liệu, thử tìm lại.');
        }
        foreach ($facts as $i => &$fact) {
            $fact = ['id' => 'F'.($i + 1), ...$fact, 'enabled' => true];
        }
        unset($fact);

        $sources = collect((array) ($data['sources'] ?? []))
            ->map(fn ($s) => ['url' => trim((string) ($s['url'] ?? '')), 'title' => mb_substr(trim((string) ($s['title'] ?? '')), 0, 300)])
            ->merge(collect($facts)->map(fn ($f) => ['url' => $f['source_url'], 'title' => $f['source_title']]))
            ->filter(fn ($s) => preg_match('#^https?://#i', $s['url']))->unique('url')->values()->take(40)->all();

        return ['summary' => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 1500), 'facts' => $facts, 'sources' => $sources];
    }

    /** Lưu các chi tiết người dùng giữ lại vào kho tư liệu (bỏ qua chi tiết đã có trong kho). */
    public static function remember(TopicShort $short): int
    {
        $saved = 0;
        $research = $short->research ?? [];
        foreach ($research['facts'] ?? [] as $i => $fact) {
            if (! ($fact['enabled'] ?? true) || ($fact['lore_id'] ?? null)) {
                continue;
            }
            $keys = array_values(array_unique(array_filter([
                ...array_map(fn ($name) => DragonBall::keyFor((string) $name), $fact['characters'] ?? []),
                ...DragonBall::charactersIn(implode(' ', $fact['characters'] ?? []).' '.$fact['text']),
            ])));
            $lore = $short->user->loreFacts()->firstOrCreate(
                ['franchise' => 'dragonball', 'text' => $fact['text']],
                ['characters' => $keys, 'era' => $fact['era'] ?: null, 'kind' => $fact['kind'], 'source_url' => $fact['source_url'] ?: null, 'source_title' => $fact['source_title'] ?: null],
            );
            $research['facts'][$i]['lore_id'] = $lore->id;
            $saved += $lore->wasRecentlyCreated ? 1 : 0;
        }
        $short->update(['research' => $research]);

        return $saved;
    }

    /** Bản tư liệu mẫu cho driver fake (test, dev không có Claude). */
    private static function sample(TopicShort $short): array
    {
        $wiki = 'https://dragonball.fandom.com/wiki/Vegeta';

        return [
            'summary' => "Tư liệu mẫu cho: {$short->topic}.",
            'facts' => [
                ['kind' => 'fact', 'era' => 'Saiyan Saga', 'text' => 'Vegeta đến Trái Đất để tìm ngọc rồng và đánh với Goku.', 'source_url' => $wiki, 'source_title' => 'Vegeta | Dragon Ball Wiki', 'characters' => ['Vegeta', 'Goku']],
                ['kind' => 'fact', 'era' => 'Namek Saga', 'text' => 'Vegeta bị Frieza giết và khóc kể chuyện tộc Saiyan bị diệt.', 'source_url' => $wiki, 'source_title' => 'Vegeta | Dragon Ball Wiki', 'characters' => ['Vegeta', 'Frieza']],
                ['kind' => 'quote', 'era' => 'Buu Saga', 'text' => 'Vegeta thừa nhận Goku là số một. / "You are number one."', 'source_url' => $wiki, 'source_title' => 'Vegeta | Dragon Ball Wiki', 'characters' => ['Vegeta', 'Goku']],
                ['kind' => 'theory', 'era' => 'Buu Saga', 'text' => 'Nhiều fan cho rằng gia đình là lý do lớn nhất khiến Vegeta thay đổi.', 'source_url' => '', 'source_title' => '', 'characters' => ['Vegeta']],
            ],
            'library_ids' => [],
            'sources' => [['url' => $wiki, 'title' => 'Vegeta | Dragon Ball Wiki']],
        ];
    }
}
