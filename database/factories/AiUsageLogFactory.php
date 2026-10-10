<?php

namespace Database\Factories;

use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiUsageLog> */
class AiUsageLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'action' => 'script',
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-5-5',
            'success' => true,
            'input_tokens' => 1200,
            'output_tokens' => 800,
            'cost_usd' => 0.0156,
            'duration_ms' => 9000,
            'created_at' => now(),
        ];
    }
}
