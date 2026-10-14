<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Models\Shot;
use App\Services\Script\ScriptWriterException;

/**
 * Claude dịch lời thoại của tập sang tiếng Việt cho bản lồng tiếng (phụ đề vẫn tiếng Anh): lời tự nhiên cho trẻ em, xưng hô đúng
 * quan hệ nhân vật, câu ngắn vừa thời lượng câu tiếng Anh (để lồng tiếng khớp nhịp hình). Khoá = lời tiếng Anh: đổi lời thì dịch lại.
 */
final class EpisodeTranslator
{
    public const SYSTEM = <<<'PROMPT'
    You translate the dialogue of an English 2D animated TV series for kids into natural spoken Vietnamese for a Vietnamese dub.
    Rules:
    - Natural, warm, simple Vietnamese that children understand; keep the meaning, jokes and emotion, not a word-for-word translation.
    - Use Vietnamese forms of address that fit the characters (bạn/tớ/cậu between kids, cháu/bà, con/mẹ, etc.) and keep them
      consistent through the episode. Keep character and place names as they are.
    - Each line must be speakable in about the same time as the English line (given in seconds): Vietnamese speech is about
      4 syllables per second for kids' TV, so keep each line within the given syllable budget. Shorter is better than longer.
    - Write numbers as words. No emoji, no stage directions, no quotes.
    - Translate the episode title too.
    PROMPT;

    /** Khoá lời tiếng Anh của tập (tiêu đề + mọi câu). */
    public static function key(Episode $episode): string
    {
        $lines = $episode->shots()->get()->map(fn (Shot $shot) => self::english($shot))->all();

        return sha1(json_encode([$episode->title, $lines]));
    }

    /** Bản dịch còn đúng với lời tiếng Anh hiện tại. */
    public static function isCurrent(Episode $episode): bool
    {
        return ($episode->dub_vi['key'] ?? null) === self::key($episode);
    }

    /** @return list<string> */
    public static function english(Shot $shot): array
    {
        return collect($shot->dialogue ?? [])->map(fn ($d) => trim((string) ($d['line'] ?? '')))->filter(fn ($l) => $l !== '')->values()->all();
    }

    /**
     * @return array{key: string, title: string, shots: array<int, list<string>>}
     *
     * @throws ScriptWriterException
     */
    public static function translate(Episode $episode): array
    {
        $shots = $episode->shots()->get()->values();
        $characters = $episode->series->characters()->get()->keyBy('id');
        $items = [];
        $text = "Series: {$episode->series->title}\nEpisode {$episode->number} title: {$episode->title}\n{$episode->synopsis}\n\nCharacters:\n"
            .$characters->map(fn ($c) => "- {$c->name}: ".trim("{$c->role}, {$c->age}. ".mb_substr((string) $c->personality, 0, 140)))->implode("\n")."\n\nLines:\n";
        foreach ($shots as $i => $shot) {
            $times = $shot->audio_lines ?? [];
            foreach (collect($shot->dialogue ?? [])->filter(fn ($d) => trim((string) ($d['line'] ?? '')) !== '')->values() as $j => $d) {
                $seconds = isset($times[$j]) ? max(0.6, ($times[$j]['end_ms'] - $times[$j]['start_ms']) / 1000) : max(1.0, mb_strlen($d['line']) / 15);
                $speaker = $characters->get($d['character_id'] ?? 0)?->name ?? 'Narrator';
                $items[] = ['shot' => $shot->id, 'index' => $j];
                $text .= count($items).'. [shot '.($i + 1).", {$speaker}".(filled($d['emotion'] ?? null) ? ", {$d['emotion']}" : '').', '
                    .round($seconds, 1).'s, max '.max(4, (int) round($seconds * 4.2)).' syllables] '.trim((string) $d['line'])."\n";
            }
        }

        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'lines'],
            'properties' => [
                'title' => ['type' => 'string'],
                'lines' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['n', 'vi'],
                    'properties' => ['n' => ['type' => 'integer'], 'vi' => ['type' => 'string']],
                ]],
            ],
        ];
        $data = $items === [] ? ['title' => $episode->title, 'lines' => []] : FilmClaude::json(
            self::SYSTEM, $text, $schema, 'film_episode', "film_dub_vi:{$episode->id}", 12000,
            fn () => ['title' => "Tập {$episode->number}", 'lines' => array_map(fn ($n) => ['n' => $n, 'vi' => "Câu thoại số {$n}."], range(1, count($items)))],
        );

        $byNumber = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['n'] => trim((string) $l['vi'])]);
        $result = [];
        foreach ($items as $n => $item) {
            $vi = $byNumber->get($n + 1, '');
            if ($vi === '') {
                throw ScriptWriterException::retryable('Claude dịch thiếu câu '.($n + 1).'.');
            }
            $result[$item['shot']][$item['index']] = $vi;
        }

        return ['key' => self::key($episode), 'title' => trim((string) ($data['title'] ?? '')) ?: $episode->title, 'shots' => $result];
    }
}
