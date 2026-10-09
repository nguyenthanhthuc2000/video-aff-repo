<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Image\FakeImageGenerator;
use App\Services\Image\FalMuseImageGenerator;
use App\Services\Image\ImageGenerator;
use App\Services\Script\ClaudeScriptWriter;
use App\Services\Script\FakeScriptWriter;
use App\Services\Script\ScriptWriter;
use App\Services\Video\FfmpegVideoRenderer;
use App\Services\Video\VideoRenderer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ScriptWriter::class, function () {
            // "web" không chạy job viết kịch bản; người dùng dán câu trả lời từ claude.ai.
            if (config('videoaff.script_driver') !== 'claude') {
                return new FakeScriptWriter;
            }

            return new ClaudeScriptWriter(
                new Client(apiKey: config('videoaff.claude.api_key')),
                config('videoaff.claude.model'),
                config('videoaff.claude.max_tokens'),
            );
        });

        $this->app->bind(ImageGenerator::class, function () {
            return match (config('videoaff.image_driver')) {
                'fal' => new FalMuseImageGenerator(
                    (string) config('videoaff.fal.key'),
                    config('videoaff.fal.endpoint'),
                    config('videoaff.fal.cost_per_image'),
                ),
                // Chế độ web không gọi API; job tạo ảnh không được đưa vào hàng đợi.
                'fake', 'web' => new FakeImageGenerator,
                default => throw new \InvalidArgumentException('MUSE_DRIVER không hợp lệ: '.config('videoaff.image_driver')),
            };
        });

        $this->app->bind(VideoRenderer::class, fn () => new FfmpegVideoRenderer(
            config('videoaff.ffmpeg'),
            resource_path('fonts'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
