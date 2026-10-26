<?php

namespace App\Services\TopicShort;

use App\Models\TopicShort;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;
use Illuminate\Support\Facades\DB;

/** Chia chủ đề dài thành series N phần (mỗi phần một video ~60 giây), các phần dùng chung bản tư liệu đã duyệt. */
final class SeriesPlanner
{
    public const SYSTEM = <<<'PROMPT'
    You plan a series of short vertical videos (about 60 seconds each) for a Vietnamese anime recap channel.
    Split the topic into exactly the requested number of parts, in story order, using ONLY the approved research facts.
    Each part: title (Vietnamese, curiosity-sparking, under 70 characters), focus (one sentence in Vietnamese on what this part covers), fact_ids (ids of the facts this part tells; every fact used at most once when possible).
    Each part must stand on its own and end on a cliffhanger, except the last part, which answers the topic.
    PROMPT;

    /**
     * Lập dàn ý, tạo series, gắn short hiện tại làm phần 1 và tạo các phần còn lại (chung tư liệu).
     *
     * @return list<TopicShort> các short cần viết kịch bản
     *
     * @throws ScriptWriterException
     */
    public static function plan(TopicShort $short): array
    {
        $parts = $short->series_parts;
        $facts = $short->approvedFacts();
        $text = "Topic: {$short->topic}\nParts: {$parts}\nFacts (id [kind] era: text):\n"
            .collect($facts)->map(fn ($f) => "{$f['id']} [{$f['kind']}] {$f['era']}: {$f['text']}")->implode("\n");

        $data = FilmClaude::json(self::SYSTEM, $text, self::schema(), 'topic_research', "topic_research:{$short->id}", 3000, fn () => self::sample($short, $facts));
        $outline = self::normalize($data, $parts, array_column($facts, 'id'));

        return DB::transaction(function () use ($short, $outline, $parts) {
            $series = $short->user->topicSeries()->create(['topic' => $short->topic, 'parts' => $parts, 'outline' => $outline]);
            $short->update(['topic_series_id' => $series->id, 'part' => 1]);
            $shorts = [$short];
            for ($part = 2; $part <= $parts; $part++) {
                $shorts[] = $short->user->topicShorts()->create([
                    ...$short->only(['topic', 'language', 'style', 'image_style', 'target_seconds', 'wiki', 'use_research', 'research', 'research_notes', 'series_parts']),
                    'topic_series_id' => $series->id,
                    'part' => $part,
                    'status' => 'writing',
                ]);
            }

            return $shorts;
        });
    }

    /** @return list<array{title: string, focus: string, fact_ids: list<string>}> */
    public static function normalize(mixed $data, int $parts, array $factIds): array
    {
        $outline = [];
        foreach ((array) ($data['parts'] ?? []) as $part) {
            $title = mb_substr(trim((string) ($part['title'] ?? '')), 0, 120);
            if ($title !== '' && count($outline) < $parts) {
                $outline[] = [
                    'title' => $title,
                    'focus' => mb_substr(trim((string) ($part['focus'] ?? '')), 0, 500),
                    'fact_ids' => array_values(array_intersect(array_map('strval', (array) ($part['fact_ids'] ?? [])), $factIds)),
                ];
            }
        }
        if (count($outline) < $parts) {
            throw ScriptWriterException::retryable('Claude chỉ chia được '.count($outline)."/{$parts} phần, thử lại.");
        }

        return $outline;
    }

    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['parts'],
            'properties' => [
                'parts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'focus', 'fact_ids'],
                        'properties' => ['title' => $string, 'focus' => $string, 'fact_ids' => ['type' => 'array', 'items' => $string]],
                    ],
                ],
            ],
        ];
    }

    private static function sample(TopicShort $short, array $facts): array
    {
        $chunks = array_chunk(array_column($facts, 'id'), (int) max(1, ceil(count($facts) / $short->series_parts)));

        return ['parts' => array_map(fn ($i) => [
            'title' => "{$short->topic} (phần ".($i + 1).')',
            'focus' => 'Phần '.($i + 1).' của câu chuyện.',
            'fact_ids' => $chunks[$i] ?? [],
        ], range(0, $short->series_parts - 1))];
    }
}
