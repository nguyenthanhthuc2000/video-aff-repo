<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AudioTrackController;
use App\Http\Controllers\BackgroundController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CharacterLibraryController;
use App\Http\Controllers\ClaudeAuthController;
use App\Http\Controllers\CreditHistoryController;
use App\Http\Controllers\EnglishController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\FashionModelController;
use App\Http\Controllers\LoreFactController;
use App\Http\Controllers\MangaPanelController;
use App\Http\Controllers\MuseAuthController;
use App\Http\Controllers\MusicStudioController;
use App\Http\Controllers\MusicVideoController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\PronunciationController;
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
use App\Http\Controllers\ShortsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\SocialPostController;
use App\Http\Controllers\SongDraftController;
use App\Http\Controllers\TopicShortController;
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

    // Shorts theo chủ đề (Dragon Ball, anime...): lời kể Claude, ảnh wiki / Muse / tải lên, giọng AI.
    Route::get('dragon-ball', [CharacterLibraryController::class, 'index'])->name('character-library.index');
    Route::post('dragon-ball/draw', [CharacterLibraryController::class, 'draw'])->name('character-library.draw');
    Route::post('dragon-ball/{character}/{expression}/upload', [CharacterLibraryController::class, 'upload'])->name('character-library.upload');
    Route::get('manga-panels', [MangaPanelController::class, 'index'])->name('manga-panels.index');
    Route::post('manga-panels', [MangaPanelController::class, 'store'])->name('manga-panels.store');
    Route::get('manga-panels/search', [MangaPanelController::class, 'search'])->name('manga-panels.search');
    Route::put('manga-panels/{panel}', [MangaPanelController::class, 'update'])->name('manga-panels.update');
    Route::delete('manga-panels/{panel}', [MangaPanelController::class, 'destroy'])->name('manga-panels.destroy');
    Route::post('manga-scans/{scan}/reprocess', [MangaPanelController::class, 'reprocess'])->name('manga-scans.reprocess');
    Route::post('manga-scans/{scan}/whole', [MangaPanelController::class, 'whole'])->name('manga-scans.whole');
    Route::delete('manga-scans/{scan}', [MangaPanelController::class, 'destroyScan'])->name('manga-scans.destroy');
    Route::get('lore-facts', [LoreFactController::class, 'index'])->name('lore-facts.index');
    Route::put('lore-facts/{fact}', [LoreFactController::class, 'update'])->name('lore-facts.update');
    Route::delete('lore-facts/{fact}', [LoreFactController::class, 'destroy'])->name('lore-facts.destroy');
    Route::get('story-videos', [TopicShortController::class, 'stories'])->name('story-videos.index');
    Route::get('topic-shorts', [TopicShortController::class, 'index'])->name('topic-shorts.index');
    Route::post('topic-shorts', [TopicShortController::class, 'store'])->name('topic-shorts.store');
    Route::get('topic-shorts/{short}', [TopicShortController::class, 'show'])->name('topic-shorts.show');
    Route::put('topic-shorts/{short}', [TopicShortController::class, 'update'])->name('topic-shorts.update');
    Route::delete('topic-shorts/{short}', [TopicShortController::class, 'destroy'])->name('topic-shorts.destroy');
    Route::post('topic-shorts/{short}/research', [TopicShortController::class, 'research'])->name('topic-shorts.research');
    Route::post('topic-shorts/{short}/research/approve', [TopicShortController::class, 'approveResearch'])->name('topic-shorts.research.approve');
    Route::post('topic-shorts/{short}/hook', [TopicShortController::class, 'hook'])->name('topic-shorts.hook');
    Route::post('topic-shorts/{short}/fact-check', [TopicShortController::class, 'factCheck'])->name('topic-shorts.fact-check');
    Route::post('topic-shorts/{short}/compose-all', [TopicShortController::class, 'composeAll'])->name('topic-shorts.compose-all');
    Route::post('topic-shorts/{short}/draw-missing', [TopicShortController::class, 'drawMissing'])->name('topic-shorts.draw-missing');
    Route::post('topic-shorts/{short}/rewrite', [TopicShortController::class, 'rewrite'])->name('topic-shorts.rewrite');
    Route::post('topic-shorts/{short}/render', [TopicShortController::class, 'render'])->name('topic-shorts.render');
    Route::post('topic-shorts/{short}/characters/{index}/image', [TopicShortController::class, 'characterImage'])->whereNumber('index')->name('topic-shorts.character-image');
    Route::post('topic-shorts/{short}/retry-failed', [TopicShortController::class, 'retryFailed'])->name('topic-shorts.retry-failed');
    Route::post('topic-shorts/{short}/muse-all', [TopicShortController::class, 'museAll'])->name('topic-shorts.muse-all');
    Route::post('topic-shorts/{short}/publish', [TopicShortController::class, 'publish'])->name('topic-shorts.publish');
    Route::put('topic-shorts/{short}/publish', [TopicShortController::class, 'savePublish'])->name('topic-shorts.publish.save');
    Route::post('topic-shorts/{short}/cover', [TopicShortController::class, 'cover'])->name('topic-shorts.cover');
    Route::get('topic-shorts/{short}/cover', [TopicShortController::class, 'coverDownload'])->name('topic-shorts.cover.download');
    Route::post('pronunciations', [PronunciationController::class, 'store'])->name('pronunciations.store');
    Route::delete('pronunciations', [PronunciationController::class, 'destroy'])->name('pronunciations.destroy');
    Route::get('pronunciations/preview', [PronunciationController::class, 'preview'])->name('pronunciations.preview');
    Route::get('topic-shorts/{short}/download', [TopicShortController::class, 'download'])->name('topic-shorts.download');
    Route::post('topic-short-scenes/{scene}/search', [TopicShortController::class, 'search'])->name('topic-short-scenes.search');
    Route::post('topic-short-scenes/{scene}/pick', [TopicShortController::class, 'pick'])->name('topic-short-scenes.pick');
    Route::post('topic-short-scenes/{scene}/library', [TopicShortController::class, 'library'])->name('topic-short-scenes.library');
    Route::post('topic-short-scenes/{scene}/compose', [TopicShortController::class, 'compose'])->name('topic-short-scenes.compose');
    Route::post('topic-short-scenes/{scene}/muse', [TopicShortController::class, 'muse'])->name('topic-short-scenes.muse');
    Route::post('topic-short-scenes/{scene}/find-images', [TopicShortController::class, 'findSceneImages'])->name('topic-short-scenes.find-images');
    Route::post('topic-short-scenes/{scene}/add-images', [TopicShortController::class, 'addImages'])->name('topic-short-scenes.add-images');
    Route::post('topic-shorts/{short}/find-images', [TopicShortController::class, 'findImages'])->name('topic-shorts.find-images');
    Route::put('topic-short-scenes/{scene}/manga', [TopicShortController::class, 'manga'])->name('topic-short-scenes.manga');
    Route::post('topic-short-scenes/{scene}/clip', [TopicShortController::class, 'clip'])->name('topic-short-scenes.clip');
    Route::delete('topic-short-scenes/{scene}/clip', [TopicShortController::class, 'clipDestroy'])->name('topic-short-scenes.clip.destroy');
    Route::post('topic-short-scenes/{scene}/voice', [TopicShortController::class, 'voice'])->name('topic-short-scenes.voice');
    Route::delete('topic-short-scenes/{scene}/voice', [TopicShortController::class, 'voiceDestroy'])->name('topic-short-scenes.voice.destroy');
    Route::post('topic-short-scenes/{scene}/upload', [TopicShortController::class, 'upload'])->name('topic-short-scenes.upload');

    // Học tiếng Anh mỗi ngày (A2 → B1) + video Shorts từ bài luyện.
    Route::get('english', [EnglishController::class, 'index'])->name('english.index');
    Route::post('english/prepare', [EnglishController::class, 'prepareAll'])->name('english.prepare');
    Route::put('english/profile', [EnglishController::class, 'profile'])->name('english.profile');
    Route::post('english/days/{day}', [EnglishController::class, 'start'])->whereNumber('day')->name('english.start');
    Route::get('english/{lesson}', [EnglishController::class, 'show'])->name('english.show');
    Route::post('english/{lesson}/recordings', [EnglishController::class, 'record'])->name('english.recordings.store');
    Route::delete('english/{lesson}/recordings/{recording}', [EnglishController::class, 'destroyRecording'])->name('english.recordings.destroy');
    Route::post('english/{lesson}/correct', [EnglishController::class, 'correct'])->name('english.correct');
    Route::post('english/{lesson}/video', [EnglishController::class, 'render'])->name('english.video');
    Route::get('english/{lesson}/video', [EnglishController::class, 'download'])->name('english.video.download');
    Route::get('english/{lesson}/thumbnail', [EnglishController::class, 'thumbnail'])->name('english.thumbnail');

    // MV ca nhạc: tạo ở trang riêng, sau đó dùng chung các trang của phim (kế hoạch, nhân vật, tập, ghép).
    Route::get('music-videos', [MusicVideoController::class, 'index'])->name('music-videos.index');
    Route::get('music-videos/create', [MusicVideoController::class, 'create'])->name('music-videos.create');
    // Nhạc cho Shorts: kho bài hát của ban nhạc ảo, nhiều bản nhạc, đoạn hook.
    Route::get('music-studio', [MusicStudioController::class, 'index'])->name('music-studio.index');
    Route::post('music-studio/songs', [MusicStudioController::class, 'storeSongs'])->name('music-studio.songs.store');
    Route::post('music-studio/songs/{songDraft}/takes/{take}', [MusicStudioController::class, 'chooseTake'])->name('music-studio.takes.choose');
    Route::post('music-studio/bands/{band}/generate', [MusicStudioController::class, 'generateAll'])->name('music-studio.generate-all');
    Route::get('music-studio/tracks/{audioTrack}/suggest', [MusicStudioController::class, 'suggestClips'])->name('music-studio.clips.suggest');
    Route::post('music-studio/tracks/{audioTrack}/clips', [MusicStudioController::class, 'storeClip'])->name('music-studio.clips.store');
    Route::delete('music-studio/clips/{clip}', [MusicStudioController::class, 'destroyClip'])->name('music-studio.clips.destroy');

    // Series Shorts nhạc: ban nhạc ảo + mẫu Shorts → tạo hàng loạt MV ngắn.
    Route::get('music-videos/shorts', [ShortsController::class, 'index'])->name('shorts.index');
    Route::post('music-videos/shorts/bands', [ShortsController::class, 'storeBand'])->name('shorts.bands.store');
    Route::post('music-videos/shorts/bands/examples', [ShortsController::class, 'seedBands'])->name('shorts.bands.examples');
    Route::put('music-videos/shorts/bands/{band}', [ShortsController::class, 'updateBand'])->name('shorts.bands.update');
    Route::delete('music-videos/shorts/bands/{band}', [ShortsController::class, 'destroyBand'])->name('shorts.bands.destroy');
    Route::post('music-videos/shorts/preview', [ShortsController::class, 'preview'])->name('shorts.preview');
    Route::post('music-videos/shorts/batch', [ShortsController::class, 'batch'])->name('shorts.batch');
    Route::post('music-videos/{series}/autopilot', [MusicVideoController::class, 'autopilot'])->name('music-videos.autopilot');
    Route::put('music-videos/{series}/title', [MusicVideoController::class, 'rename'])->name('music-videos.rename');
    Route::post('music-videos/{series}/duplicate', [MusicVideoController::class, 'duplicate'])->name('music-videos.duplicate');
    Route::delete('music-videos/{series}', [MusicVideoController::class, 'destroy'])->name('music-videos.destroy');
    Route::post('music-videos', [MusicVideoController::class, 'store'])->name('music-videos.store');
    Route::post('music-videos/songs/{audioTrack}/analysis', [MusicVideoController::class, 'analyzeSong'])->name('music-videos.songs.analyze');
    Route::put('music-videos/songs/{audioTrack}/analysis', [MusicVideoController::class, 'updateAnalysis'])->name('music-videos.songs.analysis');
    Route::post('song-drafts', [SongDraftController::class, 'store'])->name('song-drafts.store');
    Route::put('song-drafts/{songDraft}', [SongDraftController::class, 'update'])->name('song-drafts.update');
    Route::post('song-drafts/{songDraft}/rewrite', [SongDraftController::class, 'rewrite'])->name('song-drafts.rewrite');
    Route::post('song-drafts/{songDraft}/song', [SongDraftController::class, 'upload'])->name('song-drafts.upload');
    Route::post('song-drafts/{songDraft}/generate', [SongDraftController::class, 'generate'])->name('song-drafts.generate');
    Route::post('music-videos/ideas', [MusicVideoController::class, 'ideas'])->name('music-videos.ideas');
    Route::get('music-videos/ideas/{token}', [MusicVideoController::class, 'ideasStatus'])->name('music-videos.ideas.show');

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
        Route::post('series/{series}/characters/{character}/voice-sample', [SeriesCharacterController::class, 'voiceSample'])->name('series.characters.voice-sample');
        Route::post('series/{series}/episodes/{episode}/sounds', [EpisodeController::class, 'planSounds'])->name('series.episodes.sounds');
        Route::delete('series/{series}/episodes/{episode}/sounds/{index}', [EpisodeController::class, 'removeSound'])->whereNumber('index')->name('series.episodes.sounds.destroy');
        Route::post('series/{series}/episodes/{episode}/render', [EpisodeController::class, 'render'])->name('series.episodes.render');
        Route::get('series/{series}/episodes/{episode}/render', [EpisodeController::class, 'downloadRender'])->name('series.episodes.render.download');
        Route::post('series/{series}/episodes/{episode}/score', [EpisodeController::class, 'composeScore'])->name('series.episodes.score');
        Route::post('series/{series}/episodes/{episode}/renders/{render}/thumbnail', [EpisodeController::class, 'thumbnail'])->name('series.episodes.renders.thumbnail');
        Route::post('series/{series}/episodes/{episode}/renders/{render}/thumbnail-art', [EpisodeController::class, 'thumbnailArt'])->name('series.episodes.renders.thumbnail-art');
        Route::post('series/{series}/episodes/{episode}/titles', [EpisodeController::class, 'suggestTitles'])->name('series.episodes.titles');
        Route::post('series/{series}/episodes/{episode}/titles/use', [EpisodeController::class, 'useTitle'])->name('series.episodes.titles.use');
        Route::get('series/{series}/episodes/{episode}/renders/{render}/thumbnail', [EpisodeController::class, 'downloadThumbnail'])->name('series.episodes.renders.thumbnail.download');
        Route::get('series/{series}/episodes/{episode}/renders/{render}', [EpisodeController::class, 'downloadVersion'])->name('series.episodes.renders.download');
        Route::get('series/{series}/episodes/{episode}/renders/{render}/subtitles', [EpisodeController::class, 'versionSubtitles'])->name('series.episodes.renders.subtitles');
        Route::delete('series/{series}/episodes/{episode}/renders/{render}', [EpisodeController::class, 'destroyVersion'])->name('series.episodes.renders.destroy');
        Route::get('series/{series}/episodes/{episode}/subtitles', [EpisodeController::class, 'downloadSubtitles'])->name('series.episodes.subtitles');
        Route::get('series/{series}/episodes/{episode}/dialogue', [EpisodeController::class, 'downloadDialogue'])->name('series.episodes.dialogue');
        Route::get('series/{series}/episodes/{episode}/shots/{shot}/audio', [EpisodeController::class, 'downloadShotAudio'])->name('series.episodes.shots.audio');
        Route::post('series/{series}/episodes/{episode}/voices', [EpisodeController::class, 'voices'])->name('series.episodes.voices');
        Route::post('series/{series}/episodes/{episode}/clips', [EpisodeController::class, 'clips'])->name('series.episodes.clips');
        Route::post('series/{series}/episodes/{episode}/shots/{shot}/clip', [EpisodeController::class, 'clip'])->name('series.episodes.shots.clip');
        Route::post('series/{series}/episodes/{episode}/shots/{shot}/resync', [EpisodeController::class, 'resync'])->name('series.episodes.shots.resync');
        Route::post('series/{series}/episodes/{episode}/shots/{shot}/rewrite', [EpisodeController::class, 'rewriteShot'])->name('series.episodes.shots.rewrite');
        Route::post('series/{series}/episodes/{episode}/shots/{shot}/clip-rewrite', [EpisodeController::class, 'rewriteClip'])->name('series.episodes.shots.clip-rewrite');
        Route::put('series/{series}/episodes/{episode}/shots/{shot}/animate', [EpisodeController::class, 'toggleAnimate'])->name('series.episodes.shots.animate');
        Route::post('series/{series}/episodes-batch', [SeriesController::class, 'writeBatch'])->name('series.episodes.batch');
        Route::post('series/{series}/characters', [SeriesCharacterController::class, 'store'])->name('series.characters.store');
        Route::get('series/{series}/characters/{character}', [SeriesCharacterController::class, 'show'])->name('series.characters.show');
        Route::get('series/{series}/characters/{character}/sounds/{sound}', [SeriesCharacterController::class, 'sound'])->where('sound', '[a-z0-9_]+')->name('series.characters.sound');
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
        // Kết nối AI: khoá MiniMax / fal / Claude API, kiểm tra kết nối, công cụ cho từng bước.
        Route::get('settings/ai-connections', [Admin\AiConnectionController::class, 'show'])->name('admin.ai-connections.show');
        Route::put('settings/ai-connections/keys', [Admin\AiConnectionController::class, 'updateKeys'])->name('admin.ai-connections.keys');
        Route::post('settings/ai-connections/{provider}/check', [Admin\AiConnectionController::class, 'check'])->name('admin.ai-connections.check');
        Route::put('settings/ai-connections/providers', [Admin\AiConnectionController::class, 'updateProviders'])->name('admin.ai-connections.providers');
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
