<?php

namespace App\Models;

use App\Enums\RenderStatus;
use Database\Factories\RenderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Render extends Model
{
    /** @use HasFactory<RenderFactory> */
    use HasFactory;

    protected $fillable = [
        'hook',
        'status',
        'path',
        'duration_ms',
        'error',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => RenderStatus::class,
            'hook' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }
}
