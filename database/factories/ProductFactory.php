<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Chân váy '.fake()->words(3, true),
            'shopee_url' => 'https://shopee.vn/product/'.fake()->randomNumber(9).'/'.fake()->randomNumber(9),
            'price' => fake()->numberBetween(100, 500) * 1000,
            'details' => ['sizes' => 'XS–XL', 'material' => 'lụa', 'colors' => 'be, đen'],
            'reviews' => 'Vải mềm, mặc tôn dáng.',
            'images_approved_at' => now(),
        ];
    }

    /** Ảnh vừa lấy từ Shopee, chưa duyệt. */
    public function imagesNotApproved(): static
    {
        return $this->state(['images_approved_at' => null]);
    }
}
