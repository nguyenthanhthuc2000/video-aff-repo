<?php

namespace App\Services\TopicShort;

use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;

/** Claude so lời kể từng cảnh với tư liệu đã duyệt: câu nào có nguồn, câu nào chỉ suy ra, câu nào không có trong tư liệu. */
final class FactChecker
{
    public const STATUSES = ['supported', 'inferred', 'unsupported'];

    public const SYSTEM = <<<'PROMPT'
    You fact-check the narration of a short anime recap video against an approved research brief.
    For each scene, decide:
    - "supported": every factual claim is stated in the brief (opinions and questions to the viewers are fine).
    - "inferred": the claims follow from the brief but add a small interpretation or wording that is not literally there.
    - "unsupported": at least one event, quote, number or name is NOT in the brief (or contradicts it).
    note: one short sentence in Vietnamese; for "unsupported" quote the exact words that are not backed by the brief. fact_ids: ids of the facts that back the scene.
    Facts of kind "theory" only back a claim that is told as an opinion or theory.
    PROMPT;

    /**
     * Ghi fact_check {status, note} và fact_ids vào từng cảnh, trả số cảnh không có nguồn.
     *
     * @throws ScriptWriterException
     */
    public static function check(TopicShort $short): int
    {
        $facts = $short->approvedFacts();
        $scenes = $short->scenes()->get();
        if ($facts === [] || $scenes->isEmpty()) {
            return 0;
        }

        $text = "Research brief facts (id [kind] era: text):\n"
            .collect($facts)->map(fn ($f) => "{$f['id']} [{$f['kind']}] {$f['era']}: {$f['text']}")->implode("\n")
            ."\n\nScenes (position: narration):\n"
            .$scenes->map(fn (TopicShortScene $scene) => "{$scene->position}: {$scene->narration}")->implode("\n");

        $data = FilmClaude::json(self::SYSTEM, $text, self::schema(), 'topic_research', "topic_research:{$short->id}", 4000, fn () => self::sample($scenes->all(), $facts));

        $ids = array_column($facts, 'id');
        $unsupported = 0;
        $results = collect((array) ($data['scenes'] ?? []))->keyBy(fn ($row) => (int) ($row['position'] ?? 0));
        foreach ($scenes as $scene) {
            $row = $results->get($scene->position);
            if ($row === null) {
                continue;
            }
            $status = in_array($row['status'] ?? null, self::STATUSES, true) ? $row['status'] : 'inferred';
            $unsupported += $status === 'unsupported' ? 1 : 0;
            $scene->update([
                'fact_check' => ['status' => $status, 'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500)],
                'fact_ids' => array_values(array_unique([...$scene->fact_ids ?? [], ...array_intersect(array_map('strval', (array) ($row['fact_ids'] ?? [])), $ids)])),
            ]);
        }

        return $unsupported;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['scenes'],
            'properties' => [
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['position', 'status', 'note', 'fact_ids'],
                        'properties' => [
                            'position' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => self::STATUSES],
                            'note' => ['type' => 'string'],
                            'fact_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Kết quả mẫu cho driver fake: cảnh có fact_ids là có nguồn, còn lại là suy ra. */
    private static function sample(array $scenes, array $facts): array
    {
        return ['scenes' => array_map(fn (TopicShortScene $scene) => [
            'position' => $scene->position,
            'status' => ($scene->fact_ids ?? []) !== [] ? 'supported' : 'inferred',
            'note' => ($scene->fact_ids ?? []) !== [] ? 'Khớp tư liệu.' : 'Không gắn tư liệu nào.',
            'fact_ids' => $scene->fact_ids ?? [],
        ], $scenes)];
    }
}
