<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AudioTrackController;
use App\Http\Controllers\BackgroundController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClaudeAuthController;
use App\Http\Controllers\CreditHistoryController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\FashionModelController;
use App\Http\Controllers\MuseAuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\RenderController;
use App\Http\Controllers\SampleVideoController;
use App\Http\Controllers\SceneAngleController;
use App\Http\Controllers\SceneClipController;
use App\Http\Controllers\SceneImageController;
use App\Http\Controllers\SceneOrderController;
use App\Http\Controllers\SceneOutfitController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\SeriesCharacterController;
use App\Http\Controllers\SeriesController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\SocialPostController;
use App\Http\Controllers\VideoProjectController;
use App\Http\Controllers\VideoSettingsController;
use App\Support\CreditPricing;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Trang công khai Meta yêu cầu khi cho người dùng kết nối Facebook (chính sách, điều khoản, xoá dữ liệu).
foreach (['privacy' => 'legal.privacy', 'terms' => 'legal.terms', 'data-deletion' => 'legal.data-deletion'] as $uri => $name) {
    Route::get($uri, fn () => Inertia::render('legal', ['page' => $uri, 'appName' => config('app.name'), 'contactEmail' => config('videoaff.contact_email'), 'updatedAt' => '2026-10-11']))->name($name);
}

Route::get('/', function () {
    return Inertia::render('welcome', ['creditRates' => CreditPricing::publicRates()]);
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return to_route('products.index');
    })->name('dashboard');

    Route::post('products/import', [ProductImportController::class, 'store'])->name('products.import');
    Route::post('products/{product}/import/reply', [ProductImportController::class, 'reply'])->name('products.import.reply');
    Route::post('products/{product}/import/retry', [ProductImportController::class, 'retry'])->name('products.import.retry');
    Route::resource('products', ProductController::class)->except('show');
    Route::post('products/{product}/images/approve', [ProductImageController::class, 'approve'])->name('products.images.approve');
    Route::post('product-images/{image}/primary', [ProductImageController::class, 'makePrimary'])->name('product-images.primary');
    Route::delete('product-images/{image}', [ProductImageController::class, 'destroy'])->name('product-images.destroy');

    Route::resource('projects', VideoProjectController::class)
        ->only(['index', 'store', 'show', 'destroy'])
        ->parameters(['projects' => 'project']);
    Route::post('projects/{project}/autopilot', [VideoProjectController::class, 'autopilot'])->name('projects.autopilot');
    Route::put('projects/{project}/voice', [VideoProjectController::class, 'voice'])->name('projects.voice');
    Route::post('projects/{project}/script', [ScriptController::class, 'store'])->name('projects.script.store');
    Route::post('projects/{project}/muse-plan', [ScriptController::class, 'replan'])->name('projects.muse-plan');
    Route::post('projects/{project}/post-captions', [ScriptController::class, 'postCaptions'])->name('projects.post-captions');
    Route::post('projects/{project}/social-posts', [SocialPostController::class, 'store'])->name('projects.social-posts.store');
    Route::get('settings/social', [SocialAccountController::class, 'index'])->name('social.index');
    Route::post('social/facebook/connect', [SocialAccountController::class, 'facebookConnect'])->name('social.facebook.connect');
    Route::get('social/facebook/callback', [SocialAccountController::class, 'facebookCallback'])->name('social.facebook.callback');
    Route::post('social/tiktok/connect', [SocialAccountController::class, 'tiktokConnect'])->name('social.tiktok.connect');
    Route::get('social/tiktok/callback', [SocialAccountController::class, 'tiktokCallback'])->name('social.tiktok.callback');
    Route::get('social/accounts/{account}/creator', [SocialPostController::class, 'creator'])->name('social.accounts.creator');
    Route::delete('social/accounts/{account}', [SocialAccountController::class, 'destroy'])->name('social.accounts.destroy');
    Route::put('projects/{project}/script', [ScriptController::class, 'update'])->name('projects.script.update');
    Route::put('projects/{project}/scenes/order', [SceneOrderController::class, 'update'])->name('projects.scenes.order');
    Route::post('projects/{project}/script/import', [ScriptController::class, 'import'])->name('projects.script.import');
    Route::post('projects/{project}/images', [SceneImageController::class, 'store'])->name('projects.images.store');
    Route::post('scenes/{scene}/image', [SceneImageController::class, 'regenerate'])->name('scenes.image.regenerate');
    Route::post('scenes/{scene}/upload', [SceneImageController::class, 'upload'])->name('scenes.image.upload');
    Route::post('scenes/{scene}/clip', [SceneClipController::class, 'store'])->name('scenes.clip.store');
    Route::delete('scenes/{scene}/clip', [SceneClipController::class, 'destroy'])->name('scenes.clip.destroy');
    Route::post('scenes/{scene}/clip/generate', [SceneClipController::class, 'generate'])->name('scenes.clip.generate');
    Route::post('projects/{project}/clips', [SceneClipController::class, 'generateAll'])->name('projects.clips.store');
    Route::put('projects/{project}/motion-reference', [SceneClipController::class, 'projectReference'])->name('projects.motion-reference');
    Route::put('scenes/{scene}/motion-reference', [SceneClipController::class, 'sceneReference'])->name('scenes.motion-reference');
    Route::post('scene-images/{image}/approve', [SceneImageController::class, 'approve'])->name('scene-images.approve');
    Route::post('projects/{project}/renders', [RenderController::class, 'store'])->name('projects.renders.store');
    Route::get('renders/{render}/download', [RenderController::class, 'download'])->name('renders.download');

    // Phim hoạt hình nhiều tập (docs/phim-hoat-hinh.md).
    Route::get('series', [SeriesController::class, 'index'])->name('series.index');
    Route::get('series/create', [SeriesController::class, 'create'])->name('series.create');
    Route::post('series', [SeriesController::class, 'store'])->name('series.store');
    Route::get('series/{series}', [SeriesController::class, 'show'])->name('series.show');
    Route::put('series/{series}', [SeriesController::class, 'update'])->name('series.update');
    Route::delete('series/{series}', [SeriesController::class, 'destroy'])->name('series.destroy');
    Route::post('series/{series}/plan', [SeriesController::class, 'plan'])->name('series.plan');
    // Tập và nhân vật thuộc đúng bộ phim trong URL (scopeBindings).
    Route::scopeBindings()->group(function () {
        Route::get('series/{series}/episodes/{episode}', [EpisodeController::class, 'show'])->name('series.episodes.show');
        Route::put('series/{series}/episodes/{episode}', [EpisodeController::class, 'update'])->name('series.episodes.update');
        Route::post('series/{series}/episodes/{episode}/write', [EpisodeController::class, 'write'])->name('series.episodes.write');
        Route::post('series/{series}/episodes/{episode}/keyframes', [EpisodeController::class, 'keyframes'])->name('series.episodes.keyframes');
        Route::put('series/{series}/episodes/{episode}/shots/{shot}', [EpisodeController::class, 'updateShot'])->name('series.episodes.shots.update');
        Route::post('series/{series}/episodes/{episode}/shots/{shot}/image', [EpisodeController::class, 'regenerateShot'])->name('series.episodes.shots.image');
        Route::post('series/{series}/characters', [SeriesCharacterController::class, 'store'])->name('series.characters.store');
        Route::get('series/{series}/characters/{character}', [SeriesCharacterController::class, 'show'])->name('series.characters.show');
        Route::put('series/{series}/characters/{character}', [SeriesCharacterController::class, 'update'])->name('series.characters.update');
        Route::delete('series/{series}/characters/{character}', [SeriesCharacterController::class, 'destroy'])->name('series.characters.destroy');
        Route::post('series/{series}/characters/{character}/generate', [SeriesCharacterController::class, 'generate'])->name('series.characters.generate');
        Route::post('series/{series}/characters/{character}/upload', [SeriesCharacterController::class, 'upload'])->name('series.characters.upload');
        Route::put('series/{series}/characters/{character}/lock', [SeriesCharacterController::class, 'lock'])->name('series.characters.lock');
        Route::put('series/{series}/characters/{character}/images/{image}/main', [SeriesCharacterController::class, 'setMain'])->name('series.characters.images.main');
        Route::delete('series/{series}/characters/{character}/images/{image}', [SeriesCharacterController::class, 'destroyImage'])->name('series.characters.images.destroy');
    });

    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('credits', [CreditHistoryController::class, 'index'])->name('credits.index');

    // Quản trị: chỉ admin. Cài đặt hệ thống (video, Claude, Muse) và các màn hình quản lý.
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/', [Admin\DashboardController::class, 'index'])->name('admin.dashboard');
        Route::get('users', [Admin\UserController::class, 'index'])->name('admin.users.index');
        Route::put('users/{user}', [Admin\UserController::class, 'update'])->name('admin.users.update');
        Route::get('users/{user}', [Admin\UserController::class, 'show'])->name('admin.users.show');
        Route::post('users/{user}/credits', [Admin\UserController::class, 'storeCredits'])->name('admin.users.credits.store');
        Route::get('projects', [Admin\ProjectController::class, 'index'])->name('admin.projects.index');
        Route::get('projects/{project}', [Admin\ProjectController::class, 'show'])->name('admin.projects.show');
        Route::get('projects/{project}/ai-calls', [Admin\ProjectController::class, 'aiCalls'])->name('admin.projects.ai-calls');
        Route::get('ai-costs', [Admin\AiCostController::class, 'index'])->name('admin.ai-costs.index');
        Route::put('ai-costs/pricing', [Admin\AiCostController::class, 'updatePricing'])->name('admin.ai-costs.pricing');
        Route::get('jobs', [Admin\JobController::class, 'index'])->name('admin.jobs.index');
        Route::post('jobs/failed/{uuid}/retry', [Admin\JobController::class, 'retry'])->name('admin.jobs.retry');
        Route::delete('jobs/failed/{uuid}', [Admin\JobController::class, 'destroy'])->name('admin.jobs.destroy');
        Route::delete('jobs/failed', [Admin\JobController::class, 'flush'])->name('admin.jobs.flush');
        Route::resource('product-categories', Admin\ProductCategoryController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('admin.product-categories');
        Route::redirect('settings', '/admin/settings/video')->name('admin.settings');

        Route::get('settings/video', [VideoSettingsController::class, 'show'])->name('video-settings.show');
        Route::put('settings/video', [VideoSettingsController::class, 'update'])->name('video-settings.update');

        Route::get('settings/credit-pricing', [Admin\CreditPricingController::class, 'show'])->name('admin.credit-pricing.show');
        Route::put('settings/credit-pricing', [Admin\CreditPricingController::class, 'update'])->name('admin.credit-pricing.update');
        Route::get('settings/ai-models', [Admin\AiModelController::class, 'show'])->name('admin.ai-models.show');
        Route::put('settings/ai-models', [Admin\AiModelController::class, 'update'])->name('admin.ai-models.update');

        Route::get('settings/claude', [ClaudeAuthController::class, 'show'])->name('claude.auth');
        Route::post('settings/claude/start', [ClaudeAuthController::class, 'start'])->name('claude.start');
        Route::post('settings/claude/code', [ClaudeAuthController::class, 'code'])->name('claude.code');
        Route::post('settings/claude/token', [ClaudeAuthController::class, 'token'])->name('claude.token');
        Route::post('settings/claude/test', [ClaudeAuthController::class, 'test'])->name('claude.test');
        Route::delete('settings/claude/token', [ClaudeAuthController::class, 'destroy'])->name('claude.destroy');

        Route::get('settings/muse', [MuseAuthController::class, 'show'])->name('muse.auth');
        Route::post('settings/muse/refresh', [MuseAuthController::class, 'refresh'])->name('muse.refresh');
        Route::post('settings/muse/login', [MuseAuthController::class, 'login'])->name('muse.login');
        Route::post('settings/muse/code', [MuseAuthController::class, 'code'])->name('muse.code');
        Route::post('settings/muse/password', [MuseAuthController::class, 'password'])->name('muse.password');
        Route::post('settings/muse/logout', [MuseAuthController::class, 'logout'])->name('muse.logout');
        Route::get('settings/muse/screenshot', [MuseAuthController::class, 'screenshot'])->name('muse.screenshot');
    });

    Route::resource('fashion-models', FashionModelController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('sample-videos', SampleVideoController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('sample-videos/{sampleVideo}/analyze', [SampleVideoController::class, 'reanalyze'])->name('sample-videos.analyze');
    Route::post('sample-videos/{sampleVideo}/audio', [SampleVideoController::class, 'extractAudio'])->name('sample-videos.audio');
    Route::resource('audio-tracks', AudioTrackController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('audio-tracks/{audioTrack}/vocals', [AudioTrackController::class, 'vocals'])->name('audio-tracks.vocals');
    Route::put('audio-tracks/{audioTrack}/lyrics', [AudioTrackController::class, 'lyrics'])->name('audio-tracks.lyrics');
    Route::resource('backgrounds', BackgroundController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::put('scenes/{scene}/background', [BackgroundController::class, 'scene'])->name('scenes.background');
    Route::put('scenes/{scene}/outfit', [SceneOutfitController::class, 'update'])->name('scenes.outfit');
    Route::put('scenes/{scene}/angle', [SceneAngleController::class, 'update'])->name('scenes.angle');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
