<?php

use App\Http\Controllers\ClaudeAuthController;
use App\Http\Controllers\FashionModelController;
use App\Http\Controllers\MuseAuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\RenderController;
use App\Http\Controllers\SceneClipController;
use App\Http\Controllers\SceneImageController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\VideoProjectController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
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
    Route::post('projects/{project}/script', [ScriptController::class, 'store'])->name('projects.script.store');
    Route::put('projects/{project}/script', [ScriptController::class, 'update'])->name('projects.script.update');
    Route::post('projects/{project}/script/import', [ScriptController::class, 'import'])->name('projects.script.import');
    Route::post('projects/{project}/images', [SceneImageController::class, 'store'])->name('projects.images.store');
    Route::post('scenes/{scene}/image', [SceneImageController::class, 'regenerate'])->name('scenes.image.regenerate');
    Route::post('scenes/{scene}/upload', [SceneImageController::class, 'upload'])->name('scenes.image.upload');
    Route::post('scenes/{scene}/clip', [SceneClipController::class, 'store'])->name('scenes.clip.store');
    Route::delete('scenes/{scene}/clip', [SceneClipController::class, 'destroy'])->name('scenes.clip.destroy');
    Route::post('scenes/{scene}/clip/generate', [SceneClipController::class, 'generate'])->name('scenes.clip.generate');
    Route::post('projects/{project}/clips', [SceneClipController::class, 'generateAll'])->name('projects.clips.store');
    Route::post('scene-images/{image}/approve', [SceneImageController::class, 'approve'])->name('scene-images.approve');
    Route::post('projects/{project}/renders', [RenderController::class, 'store'])->name('projects.renders.store');
    Route::get('renders/{render}/download', [RenderController::class, 'download'])->name('renders.download');

    Route::get('claude', [ClaudeAuthController::class, 'show'])->name('claude.auth');
    Route::post('claude/start', [ClaudeAuthController::class, 'start'])->name('claude.start');
    Route::post('claude/code', [ClaudeAuthController::class, 'code'])->name('claude.code');
    Route::post('claude/token', [ClaudeAuthController::class, 'token'])->name('claude.token');
    Route::post('claude/test', [ClaudeAuthController::class, 'test'])->name('claude.test');
    Route::delete('claude/token', [ClaudeAuthController::class, 'destroy'])->name('claude.destroy');

    Route::get('muse', [MuseAuthController::class, 'show'])->name('muse.auth');
    Route::post('muse/refresh', [MuseAuthController::class, 'refresh'])->name('muse.refresh');
    Route::post('muse/login', [MuseAuthController::class, 'login'])->name('muse.login');
    Route::post('muse/code', [MuseAuthController::class, 'code'])->name('muse.code');
    Route::post('muse/password', [MuseAuthController::class, 'password'])->name('muse.password');
    Route::post('muse/logout', [MuseAuthController::class, 'logout'])->name('muse.logout');
    Route::get('muse/screenshot', [MuseAuthController::class, 'screenshot'])->name('muse.screenshot');

    Route::resource('fashion-models', FashionModelController::class)->only(['index', 'store', 'destroy']);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
