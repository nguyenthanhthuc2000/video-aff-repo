<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ban nhạc / ca sĩ ảo (hư cấu) cho series Shorts nhạc: tên, thể loại, thời kỳ, câu chuyện, thành viên (vai trò, ngoại hình lúc trẻ và lúc già,
 * ảnh chính lấy từ MV đã chốt để các MV sau giữ đúng mặt). Không dùng tên / hình người thật.
 */
class VirtualBand extends Model
{
    protected $fillable = ['user_id', 'name', 'genre', 'era', 'lore', 'members', 'style_preset', 'status', 'error'];

    protected function casts(): array
    {
        return ['members' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function musicVideos(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    /**
     * Lấy ảnh chính của thành viên từ các MV của ban (nhân vật cùng tên đã có ảnh, ưu tiên ảnh đã chốt, mới nhất) để MV sau dùng lại.
     *
     * @return int số thành viên vừa có ảnh mới
     */
    public function syncImages(): int
    {
        $members = $this->members ?? [];
        $found = SeriesCharacter::whereIn('series_id', $this->musicVideos()->pluck('id'))->whereNotNull('main_image_path')
            ->orderByRaw('locked_at is null')->latest('id')->get()->groupBy(fn ($c) => mb_strtolower(trim($c->name)));
        $changed = 0;
        foreach ($members as &$member) {
            $character = $found->get(mb_strtolower(trim($member['name'])))?->first();
            if ($character && ($member['image_path'] ?? null) !== $character->main_image_path) {
                $member['image_path'] = $character->main_image_path;
                $changed++;
            }
        }
        unset($member);
        if ($changed > 0) {
            $this->update(['members' => $members]);
        }

        return $changed;
    }
}
