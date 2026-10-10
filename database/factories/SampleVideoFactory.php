<?php

namespace Database\Factories;

use App\Models\SampleVideo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SampleVideo>
 */
class SampleVideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Trend '.fake()->words(2, true),
            'path' => 'sample-videos/'.fake()->uuid().'.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 1024,
            'duration_ms' => 8000,
            'width' => 720,
            'height' => 1280,
            'rights_confirmed_at' => now(),
        ];
    }
}
