<?php

namespace Database\Factories;

use App\Models\TopicShort;
use App\Models\TopicShortScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TopicShortScene>
 */
class TopicShortSceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'topic_short_id' => TopicShort::factory(),
            'position' => 1,
            'narration' => 'Frieza vừa giết Krillin ngay trước mắt Goku.',
            'on_screen_text' => 'Krillin gục ngã',
            'image_query' => 'Krillin death Frieza Namek',
            'image_prompt' => 'anime style, a bald monk exploding in the sky',
            'image_status' => 'none',
        ];
    }

    public function withImage(): static
    {
        return $this->state(['image_source' => 'upload', 'image_status' => 'ready', 'image_path' => 'topic-shorts/test/scene.png']);
    }
}
