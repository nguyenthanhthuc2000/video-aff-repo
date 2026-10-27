<?php

namespace App\Services\Manga;

use App\Models\MangaPanel;
use App\Models\TopicShort;
use App\Support\DragonBall;
use Illuminate\Support\Collection;

/** Danh sách khung manga thật gửi cho Claude khi viết lời kể: ưu tiên khung có nhân vật của chủ đề / tư liệu. */
final class MangaCatalog
{
    /** Số khung tối đa gửi kèm (giữ prompt gọn). */
    public const LIMIT = 150;

    /** @return Collection<int, MangaPanel> */
    public static function for(TopicShort $short): Collection
    {
        $base = MangaPanel::where('user_id', $short->user_id)->where('useful', true)->whereNotNull('tagged_at')->with('scan:id,chapter');
        $text = $short->topic.' '.collect($short->approvedFacts())->pluck('text')->implode(' ');
        $keys = DragonBall::charactersIn($text);
        $matching = $keys === [] ? collect() : (clone $base)->where(function ($q) use ($keys) {
            foreach ($keys as $key) {
                $q->orWhereJsonContains('characters', $key);
            }
        })->latest('id')->limit(self::LIMIT)->get();
        $rest = $matching->count() >= self::LIMIT ? collect() : (clone $base)->whereNotIn('id', $matching->pluck('id'))->latest('id')->limit(self::LIMIT - $matching->count())->get();

        return $matching->concat($rest)->values();
    }

    public static function line(MangaPanel $panel): string
    {
        $names = collect($panel->characters ?? [])->map(fn ($key) => DragonBall::CHARACTERS[$key][0] ?? $key)->implode(', ');

        return "#{$panel->id} [".($panel->scan?->chapter ?? '?')."] {$names} | {$panel->mood}: {$panel->description}";
    }
}
