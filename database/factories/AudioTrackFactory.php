<?php

namespace Database\Factories;

use App\Models\AudioTrack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudioTrack>
 */
class AudioTrackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Nhạc '.fake()->words(2, true),
            'path' => 'audio/'.fake()->uuid().'.mp3',
            'mime' => 'audio/mpeg',
            'size_bytes' => 2048,
            'duration_ms' => 30000,
            'rights_confirmed_at' => now(),
        ];
    }

    public function linkOnly(): static
    {
        return $this->state(['path' => null, 'mime' => null, 'size_bytes' => 0, 'duration_ms' => null, 'source_url' => 'https://www.tiktok.com/music/abc']);
    }
}
