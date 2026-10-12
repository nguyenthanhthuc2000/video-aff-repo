<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Audio\MusicAnalyzer;
use App\Services\Claude\ClaudeCli;
use App\Services\Film\FakeFilmImageGenerator;
use App\Services\Film\FilmMuseImageGenerator;
use App\Services\Image\FakeImageGenerator;
use App\Services\Image\FalMuseImageGenerator;
use App\Services\Image\ImageGenerator;
use App\Services\Image\MuseBrowserClient;
use App\Services\Image\MuseBrowserImageGenerator;
use App\Services\Image\Prompt\ClaudeApiScenePromptRewriter;
use App\Services\Image\Prompt\ClaudeCliScenePromptRewriter;
use App\Services\Image\Prompt\RuleScenePromptRewriter;
use App\Services\Image\Prompt\ScenePromptRewriter;
use App\Services\ProductImport\ClaudeCliProductExtractor;
use App\Services\ProductImport\ClaudeProductExtractor;
use App\Services\ProductImport\FakeProductExtractor;
use App\Services\ProductImport\ProductExtractor;
use App\Services\Script\ClaudeCliScriptWriter;
use App\Services\Script\ClaudeScriptWriter;
use App\Services\Script\FakeScriptWriter;
use App\Services\Script\ScriptWriter;
use App\Services\Social\FacebookClient;
use App\Services\Social\TikTokClient;
use App\Services\Video\Analysis\ClaudeApiMotionAnalyzer;
use App\Services\Video\Analysis\ClaudeCliMotionAnalyzer;
use App\Services\Video\Analysis\FakeMotionAnalyzer;
use App\Services\Video\Analysis\MotionAnalyzer;
use App\Services\Video\ClipGenerator;
use App\Services\Video\FakeClipGenerator;
use App\Services\Video\FfmpegVideoRenderer;
use App\Services\Video\MuseBrowserClipGenerator;
use App\Services\Video\ReferenceClip;
use App\Services\Video\SampleAudio;
use App\Services\Video\ShotDetector;
use App\Services\Video\VideoProbe;
use App\Services\Video\VideoRenderer;
use App\Support\ScriptDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ClaudeCli::class, fn () => ClaudeCli::fromConfig());
        $this->app->bind(FacebookClient::class, fn () => FacebookClient::fromConfig());
        $this->app->bind(TikTokClient::class, fn () => TikTokClient::fromConfig());

        $this->app->bind(ScriptWriter::class, function () {
            return match (ScriptDriver::current()) {
                'claude' => new ClaudeScriptWriter(
                    new Client(apiKey: config('videoaff.claude.api_key')),
                    config('videoaff.claude.model'),
                    config('videoaff.claude.max_tokens'),
                ),
                'claude_cli' => new ClaudeCliScriptWriter($this->app->make(ClaudeCli::class)),
                // "web" không chạy job viết kịch bản; người dùng dán câu trả lời từ claude.ai.
                default => new FakeScriptWriter,
            };
        });

        // Chế độ "web" không gọi API; người dùng dán câu trả lời từ claude.ai.
        $this->app->bind(ProductExtractor::class, function () {
            return match (ScriptDriver::current()) {
                'claude' => new ClaudeProductExtractor(
                    new Client(apiKey: config('videoaff.claude.api_key')),
                    config('videoaff.claude.model'),
                ),
                'claude_cli' => new ClaudeCliProductExtractor($this->app->make(ClaudeCli::class)),
                default => new FakeProductExtractor,
            };
        });

        $this->app->bind(ScenePromptRewriter::class, fn () => match (ScriptDriver::current()) {
            'claude' => new ClaudeApiScenePromptRewriter(new Client(apiKey: config('videoaff.claude.api_key')), config('videoaff.claude.model')),
            'claude_cli' => new ClaudeCliScenePromptRewriter($this->app->make(ClaudeCli::class)),
            default => new RuleScenePromptRewriter,
        });

        $this->app->bind(MuseBrowserClient::class, fn () => MuseBrowserClient::fromConfig());

        $this->app->bind(ImageGenerator::class, function () {
            return match (config('videoaff.image_driver')) {
                'fal' => new FalMuseImageGenerator(
                    (string) config('videoaff.fal.key'),
                    config('videoaff.fal.endpoint'),
                    config('videoaff.fal.cost_per_image'),
                ),
                'browser' => new MuseBrowserImageGenerator($this->app->make(MuseBrowserClient::class)),
                // Chế độ web không gọi API; job tạo ảnh không được đưa vào hàng đợi.
                'fake', 'web' => new FakeImageGenerator,
                default => throw new \InvalidArgumentException('MUSE_DRIVER không hợp lệ: '.config('videoaff.image_driver')),
            };
        });

        // Ảnh phim hoạt hình: Muse với lời nhắn riêng cho phim; driver khác dùng ảnh giả.
        $this->app->bind(FilmMuseImageGenerator::class, fn () => new FilmMuseImageGenerator($this->app->make(MuseBrowserClient::class)));
        $this->app->bind('film.image', fn () => config('videoaff.image_driver') === 'browser'
            ? $this->app->make(FilmMuseImageGenerator::class)
            : new FakeFilmImageGenerator);

        // Clip chuyển động tự động chỉ có khi tạo ảnh bằng muse.ai qua trình duyệt ngầm.
        $this->app->bind(ClipGenerator::class, fn () => match (config('videoaff.image_driver')) {
            'browser' => new MuseBrowserClipGenerator($this->app->make(MuseBrowserClient::class)),
            default => new FakeClipGenerator,
        });

        $this->app->bind(VideoProbe::class, fn () => VideoProbe::fromConfig());
        $this->app->bind(ReferenceClip::class, fn () => ReferenceClip::fromConfig());
        $this->app->bind(ShotDetector::class, fn () => ShotDetector::fromConfig());
        $this->app->bind(SampleAudio::class, fn () => SampleAudio::fromConfig());
        $this->app->bind(MusicAnalyzer::class, fn () => MusicAnalyzer::fromConfig());

        // Phân tích chuyển động video mẫu: cùng nguồn Claude với viết kịch bản.
        $this->app->bind(MotionAnalyzer::class, fn () => match (ScriptDriver::current()) {
            'claude' => new ClaudeApiMotionAnalyzer(new Client(apiKey: config('videoaff.claude.api_key')), config('videoaff.claude.model')),
            'claude_cli' => new ClaudeCliMotionAnalyzer($this->app->make(ClaudeCli::class)),
            default => new FakeMotionAnalyzer,
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
