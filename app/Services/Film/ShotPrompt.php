<?php

namespace App\Services\Film;

use App\Models\SeriesCharacter;
use App\Models\Shot;
use App\Support\ReferenceSheet;
use Illuminate\Support\Collection;

/** Prompt và ảnh tham chiếu cho khung hình (keyframe) của shot: ảnh chính các nhân vật trong khung + ảnh bối cảnh (nếu có). */
final class ShotPrompt
{
    /** @return Collection<int, SeriesCharacter> Nhân vật trong khung, đúng thứ tự của shot. */
    public static function characters(Shot $shot): Collection
    {
        $ids = $shot->character_ids ?? [];
        $found = SeriesCharacter::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $found[$id] ?? null)->filter()->values();
    }

    /** @return list<string> Ảnh chính nhân vật (tối đa 3) rồi ảnh bối cảnh; Muse nhận tối đa 4 ảnh. */
    public static function references(Shot $shot): array
    {
        $people = self::characters($shot)->pluck('main_image_path')->filter()->take(3)->values()->all();
        $location = $shot->location?->image_path;

        return array_slice([...$people, ...array_filter([$location])], 0, ReferenceSheet::MAX_ATTACHMENTS);
    }

    public static function build(Shot $shot, ?string $notes = null): string
    {
        $series = $shot->episode->series;
        $n = 0;
        $lines = self::characters($shot)->map(function (SeriesCharacter $c) use (&$n) {
            $ref = $c->main_image_path && $n < 3 ? ' (exactly as in attached image '.(++$n).')' : '';

            return "- {$c->name}{$ref}: {$c->appearance}";
        })->implode("\n");
        $emotions = collect($shot->dialogue ?? [])->pluck('emotion')->filter()->unique()->implode(', ');
        $location = $shot->location;

        return "Keyframe of an animated TV episode for kids. Camera: {$shot->camera}.\n"
            ."What we see: {$shot->action}\n"
            .($emotions !== '' ? "Mood / expressions: {$emotions}.\n" : '')
            .($lines !== '' ? "Characters in this frame (draw only these, each looking exactly like their description; keep faces, hair, outfits and colors identical):\n{$lines}\n" : "No characters in this frame.\n")
            .($location ? "Location: {$location->name}: {$location->description}".($location->image_path ? ' (as in the attached location image)' : '')."\n" : '')
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            ."Art style: {$series->style}. Cinematic composition, clear readable poses, expressive faces.";
    }
}
