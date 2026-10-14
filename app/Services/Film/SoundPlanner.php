<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Models\SeriesCharacter;
use App\Models\Shot;
use App\Services\Script\ScriptWriterException;

/**
 * Claude làm "sound design" cho tập: chọn âm thanh hiệu ứng trong kho (Kenney CC0 + hiệu ứng tự tạo) cho từng shot theo hành động,
 * tiếng "vút" ở chỗ chuyển phân đoạn, đoạn nhạc ngắn nhấn cảm xúc ở đầu / cuối phân đoạn, nhạc hiệu ở thẻ tiêu đề và thẻ kết.
 */
final class SoundPlanner
{
    public const SYSTEM = <<<'PROMPT'
    You are the sound designer of an English 2D animated TV series for kids. Pick sound effects from the given library to make the episode
    lively, like a TV cartoon: a whoosh on scene changes, cute foley for actions (footsteps, rustles, knocks, pops), a sparkle for magic or
    wonder, a ding for an idea, a short jingle to punch an emotional moment, a jingle on the title card and on the end card.
    Rules:
    - Only use sound ids from the library. Gentle and fun for young children; nothing scary or harsh.
    - Do not overload: on average about one sound per shot, many shots have none. Never more than 3 in one shot.
    - Dialogue must stay clear: put sounds in the gaps of the shot's speech times when possible, and use a lower volume (0.2–0.4) when
      a sound plays under speech. Typical volumes: foley 0.4–0.7, whoosh 0.5, jingles 0.5–0.7.
    - "at" is seconds from the start of the shot (0 to the shot length). For a transition into a new sequence use the first shot of that
      sequence with at = 0.
    - Use place "title" for the title card jingle (at 0.2) and "end" for the end card jingle (at 0.3); shot is null for those.
    - Character sounds bring the characters to life: when a character laughs, giggles, cries, gasps, sighs, yawns, sneezes, snores, eats
      or reacts, add the matching voice_* sound and set "character" to that character's name (each character gets its own pitch
      automatically). Animal characters also make their own species call (cat_*, dog_*, duck_*...) when they react, greet, beg or are
      surprised; only use a species sound for a character of that species (listed per character). Use "character": null for other sounds.
      Do not put a character sound on top of that character's own spoken line.
    PROMPT;

    /**
     * @return list<array{shot_id: ?int, place: string, sound: string, at_ms: int, volume: float}>
     *
     * @throws ScriptWriterException
     */
    public static function plan(Episode $episode): array
    {
        $library = SoundLibrary::catalog();
        if ($library === []) {
            throw new ScriptWriterException('Chưa có kho âm thanh. Chạy: php artisan film:sound-library');
        }
        $shots = $episode->shots()->with('location:id,name')->get()->values();
        $characters = $episode->series->characters()->get();
        $catalog = collect($library)->map(fn ($s) => "{$s['id']} [{$s['group']}, ".round($s['duration_ms'] / 1000, 1)."s]: {$s['description']}")->implode("\n");
        $lines = $shots->map(function (Shot $shot, int $i) use ($characters) {
            $speech = collect($shot->synced_audio_path ? [] : ($shot->audio_lines ?? []))->map(fn ($l) => round($l['start_ms'] / 1000, 1).'-'.round($l['end_ms'] / 1000, 1).'s')->implode(', ');

            return 'Shot '.($i + 1)." (sequence {$shot->sequence}, ".round($shot->duration_ms / 1000, 1).'s'
                .($shot->location ? ", {$shot->location->name}" : '').($speech !== '' ? ", speech at {$speech}" : '').'): '
                .EpisodeWriter::describe($shot->only(['camera', 'action', 'dialogue']), $characters)
                .($shot->sound_cue ? " Sound cue from the script: {$shot->sound_cue}" : '');
        })->implode("\n");
        $byId = collect($library)->keyBy('id');
        $cast = $characters->map(fn ($c) => "- {$c->name}".(($species = CharacterSounds::species($c)) ? " ({$species})" : '').': '
            .collect(CharacterSounds::allowed($c, $library))->filter(fn ($id) => ($byId[$id]['group'] ?? '') === 'animal')->whenEmpty(fn ($ids) => $ids->push('voice_* only'), fn ($ids) => $ids->prepend('voice_*'))->implode(', '))->implode("\n");
        $text = "Episode {$episode->number}: {$episode->title}\n{$episode->synopsis}\n\nSound library:\n{$catalog}\n\nCharacters and the character sounds they can use:\n{$cast}\n\nShots:\n{$lines}"
            // Truyện ngắn không thoại: âm thanh kể chuyện thay lời, nên dày hơn, theo gợi ý âm thanh của kịch bản.
            .($episode->series->silent() ? "\n\nThis is a short with NO dialogue for an international audience: sound tells the story. Follow the sound cues "
                .'of each shot when the library has a fitting sound, give most shots 1–3 sounds (reactions, foley, a small sting on the twist), '
                .'volumes 0.4–0.8, no title or end card.' : '');

        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['sounds'],
            'properties' => ['sounds' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['place', 'shot', 'sound', 'character', 'at', 'volume'],
                'properties' => [
                    'place' => ['type' => 'string', 'enum' => ['shot', 'title', 'end']],
                    'shot' => ['type' => ['integer', 'null']],
                    'sound' => ['type' => 'string'],
                    'character' => ['type' => ['string', 'null']],
                    'at' => ['type' => 'number'],
                    'volume' => ['type' => 'number'],
                ],
            ]]],
        ];
        $data = FilmClaude::json(self::SYSTEM, $text, $schema, 'film_episode', "film_sound:{$episode->id}", 8000, fn () => self::sample($shots->count()));

        return self::normalize((array) ($data['sounds'] ?? []), $shots->all(), collect($library)->keyBy('id')->all(), $characters->all());
    }

    /**
     * Bỏ âm thanh không có trong kho / shot không tồn tại; thời điểm trong shot, âm lượng 0,05–1; tối đa 3 âm thanh mỗi shot.
     * Tiếng nhân vật: gắn id nhân vật theo tên (để đổi cao độ khi ghép); tiếng kêu sai loài với nhân vật thì bỏ.
     *
     * @param  list<Shot>  $shots
     * @param  list<SeriesCharacter>  $characters
     */
    public static function normalize(array $sounds, array $shots, array $library, array $characters = []): array
    {
        $result = [];
        $perShot = [];
        $names = collect($characters)->keyBy(fn ($c) => mb_strtolower(trim($c->name)));
        foreach ($sounds as $s) {
            $sound = (string) ($s['sound'] ?? '');
            if (! isset($library[$sound])) {
                continue;
            }
            $character = $names[mb_strtolower(trim((string) ($s['character'] ?? '')))] ?? null;
            $species = $library[$sound]['species'] ?? null;
            if ($character && $species !== null && CharacterSounds::species($character) !== $species) {
                continue;
            }
            // Tiếng người không gắn nhân vật thì vẫn dùng (giọng gốc); tiếng nhân vật chỉ gắn với nhóm voice / animal.
            $character = $character && in_array($library[$sound]['group'], ['voice', 'animal'], true) ? $character : null;
            $place = in_array($s['place'] ?? '', ['title', 'end'], true) ? $s['place'] : 'shot';
            $shot = $place === 'shot' ? ($shots[((int) ($s['shot'] ?? 0)) - 1] ?? null) : null;
            if ($place === 'shot' && ($shot === null || ($perShot[$shot->id] = ($perShot[$shot->id] ?? 0) + 1) > 3)) {
                continue;
            }
            $limit = $shot ? max(0, $shot->duration_ms - 100) : 2000;
            $result[] = [
                'shot_id' => $shot?->id,
                'place' => $place,
                'sound' => $sound,
                'character_id' => $character?->id,
                'at_ms' => (int) max(0, min($limit, round((float) ($s['at'] ?? 0) * 1000))),
                'volume' => round(max(0.05, min(1.0, (float) ($s['volume'] ?? 0.5))), 2),
            ];
        }

        return $result;
    }

    private static function sample(int $shots): array
    {
        $sounds = [['place' => 'title', 'shot' => null, 'sound' => 'jingles_steel_1', 'at' => 0.2, 'volume' => 0.6]];
        for ($i = 1; $i <= $shots; $i += 2) {
            $sounds[] = ['place' => 'shot', 'shot' => $i, 'sound' => $i === 1 ? 'cartoon_whoosh_short' : 'cartoon_pop', 'at' => 0.2, 'volume' => 0.5];
        }
        $sounds[] = ['place' => 'end', 'shot' => null, 'sound' => 'cartoon_tada', 'at' => 0.3, 'volume' => 0.6];

        return ['sounds' => $sounds];
    }
}
