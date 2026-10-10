<?php

namespace Database\Factories;

use App\Models\Background;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Background> */
class BackgroundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Phòng thay đồ',
            'description' => 'gương lớn, rèm be, đèn vàng',
            'photo_path' => 'backgrounds/'.fake()->uuid().'.jpg',
        ];
    }
}
