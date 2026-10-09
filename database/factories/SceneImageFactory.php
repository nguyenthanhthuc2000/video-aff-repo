<?php

namespace Database\Factories;

use App\Models\Scene;
use App\Models\SceneImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SceneImage>
 */
class SceneImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scene_id' => Scene::factory(),
            'status' => 'ready',
            'path' => 'scenes/'.fake()->uuid().'.png',
            'provider' => 'fake',
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved']);
    }
}
