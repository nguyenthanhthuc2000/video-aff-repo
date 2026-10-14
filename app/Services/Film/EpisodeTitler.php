<?php

namespace App\Services\Film;

use App\Models\Episode;
use App\Services\Script\ScriptWriterException;
use Illuminate\Support\Str;

/** Claude gợi ý tiêu đề video cho tập (YouTube / TikTok / Reels) theo nội dung đã viết, kèm chữ ngắn để in lên ảnh bìa. */
final class EpisodeTitler
{
    public const COUNT = 5;

    public const SYSTEM = <<<'PROMPT'
    You name episodes of an animated series for YouTube, YouTube Shorts, TikTok and Facebook Reels.
    Write title options that make a viewer curious enough to tap, while staying honest to what really happens in the episode
    (no clickbait lies, no ALL CAPS whole titles, no hashtags, no emoji spam; at most one emoji). Each option uses a different angle:
    curiosity gap, emotion, a funny twist, the cute character moment, a question. Keep the series' language (English), simple words
    an international audience understands, 30–60 characters, wholesome and safe for all ages, no brand names.
    For each title also give thumbnail_text: 2–4 punchy words to print big on the thumbnail (it must add to the title, not repeat it),
    and vi: a short Vietnamese explanation of the title for the creator.
    PROMPT;

    /** Tên tập còn trống hoặc là chữ tạm (kiểu "[Episode Title]", "Untitled", "Episode 3"). */
    public static function placeholder(?string $title): bool
    {
        $title = trim((string) $title);

        return $title === '' || (bool) preg_match('/^(\[.*\]|untitled|episode\s*\d*|tập\s*\d*|tbd|title)$/iu', $title);
    }

    /**
     * Tên tập còn là chữ tạm: lấy tiêu đề trong gói đăng bài, rồi tiêu đề Claude đã gợi ý; $ask thì nhờ Claude gợi ý (tốn một lượt Claude).
     * Đổi cả tiêu đề trong gói đăng bài. Trả về true nếu đã đổi tên.
     *
     * @throws ScriptWriterException
     */
    public static function ensure(Episode $episode, bool $ask = false): bool
    {
        if (! self::placeholder($episode->title)) {
            return false;
        }
        $title = collect([$episode->package['title'] ?? null, $episode->title_ideas[0]['title'] ?? null])->first(fn ($t) => filled($t) && ! self::placeholder($t));
        if ($title === null && $ask && $episode->shots()->exists()) {
            $ideas = self::suggest($episode);
            $episode->update(['title_ideas' => $ideas, 'title_status' => 'ready', 'title_error' => null]);
            $title = $ideas[0]['title'];
        }
        if ($title === null) {
            return false;
        }
        $episode->update([
            'title' => $title,
            ...(is_array($episode->package) ? ['package' => [...$episode->package, 'title' => $title]] : []),
        ]);

        return true;
    }

    /**
     * @return list<array{title: string, thumbnail_text: string, vi: string}>
     *
     * @throws ScriptWriterException
     */
    public static function suggest(Episode $episode): array
    {
        $series = $episode->series;
        $characters = $series->characters()->get();
        $shots = $episode->shots()->get()->map(fn ($shot, $i) => ($i + 1).'. '.EpisodeWriter::describe($shot->only(['camera', 'action', 'dialogue']), $characters))->implode("\n");
        $text = "Series: {$series->title}".($series->silent() ? ' (silent 9:16 short, no dialogue)' : '')."\n"
            .(filled($series->idea) ? 'Series idea: '.Str::limit($series->idea, 600)."\n" : '')
            ."Episode {$episode->number} working title: {$episode->title}\n"
            .($episode->synopsis ? "Synopsis: {$episode->synopsis}\n" : '')
            .(filled($episode->package['logline'] ?? null) ? "Logline: {$episode->package['logline']}\n" : '')
            .($shots !== '' ? 'Shots: '."\n".Str::limit($shots, 6000)."\n" : '')
            .'Give exactly '.self::COUNT.' options.';

        $item = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'thumbnail_text', 'vi'], 'properties' => [
            'title' => ['type' => 'string'], 'thumbnail_text' => ['type' => 'string'], 'vi' => ['type' => 'string'],
        ]];
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['ideas'], 'properties' => ['ideas' => ['type' => 'array', 'items' => $item]]];
        $data = FilmClaude::json(self::SYSTEM, $text, $schema, 'film_episode', "episode_titles:{$episode->id}", 1500, fn () => ['ideas' => [
            ['title' => "{$episode->title}: The Surprise Nobody Expected", 'thumbnail_text' => 'WAIT FOR IT', 'vi' => 'Gây tò mò về bất ngờ ở cuối.'],
            ['title' => "Can They Fix It in Time? | {$series->title}", 'thumbnail_text' => 'UH OH!', 'vi' => 'Câu hỏi tạo hồi hộp.'],
        ]]);

        $ideas = collect($data['ideas'] ?? [])
            ->map(fn ($idea) => [
                'title' => Str::limit(trim((string) ($idea['title'] ?? '')), 100, ''),
                'thumbnail_text' => Str::limit(trim((string) ($idea['thumbnail_text'] ?? '')), 40, ''),
                'vi' => Str::limit(trim((string) ($idea['vi'] ?? '')), 300, ''),
            ])
            ->filter(fn ($idea) => $idea['title'] !== '')->unique('title')->take(self::COUNT)->values()->all();
        if ($ideas === []) {
            throw new ScriptWriterException('Claude không trả về tiêu đề nào.');
        }

        return $ideas;
    }
}
