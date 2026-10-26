<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Series nhiều phần của một chủ đề dài: các phần dùng chung bản tư liệu, outline = [{title, focus}]. */
class TopicSeries extends Model
{
    protected $table = 'topic_series';

    protected $fillable = ['topic', 'parts', 'outline'];

    protected function casts(): array
    {
        return ['outline' => 'array', 'parts' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shorts(): HasMany
    {
        return $this->hasMany(TopicShort::class)->orderBy('part');
    }
}
