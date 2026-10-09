<?php

namespace Database\Factories;

use App\Models\Scene;
use App\Models\VideoProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            'position' => 1,
            'duration_ms' => 4000,
            'visual' => 'Người mẫu bước chậm về phía máy quay',
            'on_screen_text' => 'Đổi đúng chân váy là khác ngay',
            'voiceover' => 'Chỉ cần đổi chân váy là tổng thể khác hẳn.',
            'image_prompt' => 'Người mẫu mặc chân váy, đứng ở hành lang quán cà phê, ánh sáng dịu.',
        ];
    }
}
