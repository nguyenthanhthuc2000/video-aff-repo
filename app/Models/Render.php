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
        'with_text',
        'status',
        'progress',
        'path',
        'duration_ms',
        'error',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'with_text' => true,
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'status' => RenderStatus::class,
            'hook' => 'integer',
            'with_text' => 'boolean',
            'duration_ms' => 'integer',
            'progress' => 'integer',
        ];
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }
}
