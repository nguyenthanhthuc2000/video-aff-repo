<?php

namespace Database\Factories;

use App\Models\FashionModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FashionModel>
 */
class FashionModelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->firstName(),
            'photo_path' => 'models/'.fake()->uuid().'.jpg',
            'consent_at' => now(),
        ];
    }

    public function withoutConsent(): static
    {
        return $this->state(['consent_at' => null]);
    }
}
