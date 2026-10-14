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

    /**
     * Ảnh chính nhân vật (tối đa 3) rồi ảnh bối cảnh; $anchor: thêm khung hình shot liền trước cùng cảnh (nếu còn chỗ) để giữ đạo cụ /
     * bối cảnh / ánh sáng khớp nhau. Muse nhận tối đa 4 ảnh.
     *
     * @return list<string>
     */
    public static function references(Shot $shot, bool $anchor = false): array
    {
        $people = self::characters($shot)->pluck('main_image_path')->filter()->take(3)->values()->all();
        $location = $shot->location?->image_path;
        $previous = $anchor ? self::anchor($shot) : null;

        return array_slice([...$people, ...array_filter([$location, $previous?->image_status === 'ready' ? $previous->image_path : null])], 0, ReferenceSheet::MAX_ATTACHMENTS);
    }

    /** Shot liền trước trong cùng phân đoạn, cùng bối cảnh: khung hình của nó làm mốc liên tục cho shot này. */
    public static function anchor(Shot $shot): ?Shot
    {
        return Shot::where('episode_id', $shot->episode_id)->where('sequence', $shot->sequence)->where('position', '<', $shot->position)
            ->where(fn ($q) => $shot->series_location_id === null ? $q->whereNull('series_location_id') : $q->where('series_location_id', $shot->series_location_id))
            ->orderByDesc('position')->first();
    }

    /** Lời dặn kèm khi có khung hình shot trước (ảnh đính kèm cuối cùng). */
    public const ANCHOR_LINE = 'SET CONTINUITY: the LAST attached image is the previous shot of this same scene. Keep the same place, set dressing, props '
        .'(same objects with the same size, shape, color and position), table, background, time of day, lighting and color palette; '
        .'change only the camera framing and what the characters do. Do not copy its composition exactly.';

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
        if ($series->silent()) {
            return self::short($shot, $lines, $notes);
        }

        return "Keyframe of an animated TV episode for kids. Frame: {$series->frameLine()}. Camera: {$shot->camera}.\n"
            ."What we see: {$shot->action}\n"
            .($emotions !== '' ? "Mood / expressions: {$emotions}.\n" : '')
            .($lines !== '' ? "Characters in this frame (draw only these, each looking exactly like their description; keep faces, hair, outfits and colors identical):\n{$lines}\n" : "No characters in this frame.\n")
            .($location ? "Location: {$location->name}: {$location->description}".($location->image_path ? ' (as in the attached location image)' : '')."\n" : '')
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            ."Art style: {$series->style}. Cinematic composition, clear readable poses, expressive faces.";
    }

    /** Truyện ngắn: ảnh đầu cảnh kiểu "film still" (tham chiếu nhân vật, cảnh, bố cục, giải phẫu tự nhiên, ràng buộc chung). */
    private static function short(Shot $shot, string $lines, ?string $notes): string
    {
        $series = $shot->episode->series;
        $location = $shot->location;

        return "Original animated short film still, first frame of a shot. Frame: {$series->frameLine()}.\n"
            .($lines !== '' ? "CHARACTER REFERENCE (preserve exact reference identity: same face, fur or hair, colors, proportions and accessories):\n{$lines}\n" : "No characters in this frame.\n")
            .'SCENE: '.($location ? "{$location->name}: {$location->description}".($location->image_path ? ' (as in the attached location image)' : '').'. ' : '')."{$shot->action}\n"
            .(($continuity = self::continuity($shot)) !== '' ? "CONTINUITY (identical in every shot of this episode):\n{$continuity}\n" : '')
            ."COMPOSITION: {$shot->camera}, characters clearly staged with readable silhouettes and expressions, soft background, "
            .'leave a little space at the top and bottom of the vertical frame.'."\n"
            .(filled($notes) ? 'Additional direction: '.trim($notes).".\n" : '')
            ."Art style: {$series->style}.\n"
            .'Natural anatomy: animals have exactly four legs and natural paws; no text, letters, logos or watermarks.'."\n"
            .'Avoid: '.self::NEGATIVE;
    }

    /** Danh sách giữ liên tục trong gói sản xuất của tập (đạo cụ, bối cảnh, phụ kiện...), mỗi ý một dòng. */
    public static function continuity(Shot $shot): string
    {
        return collect($shot->episode->package['continuity'] ?? [])->filter(fn ($c) => is_string($c) && trim($c) !== '')
            ->map(fn ($c) => '- '.trim($c))->take(10)->implode("\n");
    }

    /** Ràng buộc chung (chỉ là lời dặn, công cụ không có ô negative prompt riêng). */
    public const NEGATIVE = 'identity drift, extra limbs, duplicated paws, malformed faces, changing eye colors, changing clothes or accessories, '
        .'disappearing props, melting objects, impossible physics, new background characters, captions, logos or watermarks.';
}
