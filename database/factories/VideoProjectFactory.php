<?php

namespace Database\Factories;

use App\Models\FashionModel;
use App\Models\Product;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProject>
 */
class VideoProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => fn (array $attributes) => Product::factory()->create(['user_id' => $attributes['user_id']]),
            'fashion_model_id' => fn (array $attributes) => FashionModel::factory()->create(['user_id' => $attributes['user_id']]),
            'status' => 'draft',
        ];
    }
}
