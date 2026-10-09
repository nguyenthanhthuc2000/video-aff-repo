<?php

namespace Database\Factories;

use App\Models\Render;
use App\Models\VideoProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Render>
 */
class RenderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            'hook' => 0,
            'status' => 'pending',
        ];
    }
}
