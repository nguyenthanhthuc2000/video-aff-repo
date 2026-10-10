<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AudioTrackController;
use App\Http\Controllers\BackgroundController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClaudeAuthController;
use App\Http\Controllers\CreditHistoryController;
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
use App\Http\Controllers\VideoProjectController;
use App\Http\Controllers\VideoSettingsController;
use App\Support\CreditPricing;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    Route::resource('backgrounds', BackgroundController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::put('scenes/{scene}/background', [BackgroundController::class, 'scene'])->name('scenes.background');
    Route::put('scenes/{scene}/outfit', [SceneOutfitController::class, 'update'])->name('scenes.outfit');
    Route::put('scenes/{scene}/angle', [SceneAngleController::class, 'update'])->name('scenes.angle');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
