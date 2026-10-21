<?php

namespace Database\Factories;

use App\Models\TopicShort;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TopicShort>
 */
class TopicShortFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'topic' => 'Lần đầu Goku biến thành Super Saiyan',
            'language' => 'vi',
            'target_seconds' => 45,
            'wiki' => 'dragonball',
            'status' => 'ready',
            'title' => 'Goku hoá Super Saiyan',
            'content' => ['caption' => 'Caption', 'hashtags' => ['#dragonball']],
        ];
    }
}
