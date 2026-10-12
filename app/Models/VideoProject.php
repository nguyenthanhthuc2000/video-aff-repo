<?php

namespace App\Models;

use App\Enums\MotionStyle;
use App\Enums\RenderStatus;
use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Enums\VideoType;
use App\Enums\VoiceMode;
use App\Http\Controllers\SceneImageController;
use App\Http\Controllers\ScriptController;
use App\Jobs\GeneratePostCaptionsJob;
use App\Jobs\GenerateSceneImageJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\PlanWithMuseJob;
use App\Jobs\RenderVideoJob;
use App\Support\CreditPricing;
use App\Support\MusePlan;
use App\Support\ProductCoverage;
use App\Support\VideoPreset;
use Database\Factories\VideoProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VideoProject extends Model
{
    /** @use HasFactory<VideoProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'fashion_model_id',
        'video_type',
        'one_take',
        'voice_mode',
        'before_fashion_model_id',
        'motion_style',
        'motion_styles',
        'autopilot',
        'status',
        'script',
        'selected_hook',
        'render_text',
        'music',
        'audio_track_id',
        'music_start_ms',
        'muse_plan_status',
        'muse_plan',
        'muse_plan_error',
        'muse_plan_request_id',
        'post_captions_status',
        'post_captions',
        'sample_video_id',
        'background_ids',
        'outfits',
        'error',
    ];

    protected $attributes = [
        'status' => 'draft',
        'video_type' => 'standard',
        'voice_mode' => 'music',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoProjectStatus::class,
            'motion_style' => MotionStyle::class,
            'video_type' => VideoType::class,
            'motion_styles' => 'array',
            'background_ids' => 'array',
            'outfits' => 'array',
            'one_take' => 'boolean',
            'voice_mode' => VoiceMode::class,
            'music_start_ms' => 'integer',
            'muse_plan' => 'array',
            'post_captions' => 'array',
            'autopilot' => 'boolean',
            'script' => 'array',
            'selected_hook' => 'integer',
            'render_text' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fashionModel(): BelongsTo
    {
        return $this->belongsTo(FashionModel::class);
    }

    /** Video mẫu mặc định để sao chép chuyển động cho các cảnh. */
    public function sampleVideo(): BelongsTo
    {
        return $this->belongsTo(SampleVideo::class);
    }

    /** Nhạc nền chọn từ thư viện Âm thanh. */
    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }

    /** Ảnh "trước" của video biến hình (đồ cũ, mặt mộc, tóc rối). */
    public function beforeFashionModel(): BelongsTo
    {
        return $this->belongsTo(FashionModel::class, 'before_fashion_model_id');
    }

    public function isTransformation(): bool
    {
        return ! $this->one_take && $this->video_type === VideoType::Transformation && $this->before_fashion_model_id !== null;
    }

    /** Số giây mỗi nhịp của video một cảnh quay liền (độ dài một clip Muse). */
    public const ONE_TAKE_BEAT_SECONDS = 5;

    /**
     * Đoạn video mẫu ứng với từng cảnh khi video mẫu có nhiều shot.
     * Một cảnh quay liền thì không cắt theo shot (các nhịp sao chép lần lượt theo thời gian).
     *
     * @return list<array{start: float, end: float, shot: int}>
     */
    public function shotPieces(): array
    {
        // Có phân cảnh Muse thì theo phân cảnh, không dò shot bằng thuật toán.
        return $this->one_take || MusePlan::of($this) !== null ? [] : ($this->sampleVideo?->pieces() ?? []);
    }

    /** Cảnh đầu tiên (một cảnh quay liền chỉ cảnh này tạo ảnh, các cảnh sau lấy khung cuối clip trước). */
    public function firstScene(): ?Scene
    {
        return $this->scenes()->orderBy('position')->first();
    }

    /**
     * Một cảnh quay liền nhiều bộ đồ: mỗi bộ là một đoạn quay liền riêng, giữa các đoạn cắt cảnh đổi đồ.
     * Cảnh mở đầu đoạn tạo ảnh riêng; các nhịp sau trong đoạn lấy khung cuối clip trước.
     *
     * @return Collection<int, Scene>
     */
    public function takeStarts(): Collection
    {
        $previous = null;

        return $this->scenes()->orderBy('position')->get()->filter(function (Scene $scene) use (&$previous) {
            $starts = $previous === null || ! $scene->sameOutfitAs($previous);
            $previous = $scene;

            return $starts;
        })->values();
    }

    /** Cảnh mở đầu một đoạn quay liền (cảnh 1, hoặc cảnh đổi sang bộ đồ khác). */
    public function startsTake(Scene $scene): bool
    {
        return $this->takeStarts()->contains('id', $scene->id);
    }

    /**
     * Cảnh thứ $index (0..$count-1) mặc bộ nào trong danh sách bộ đồ. Quay liền: chia thành các khối liền nhau (mỗi bộ một đoạn);
     * còn lại cùng shot video mẫu thì cùng bộ, không có shot thì lần lượt từng bộ.
     */
    public function outfitSlot(int $index, int $count, ?array $piece = null): int
    {
        $outfits = max(1, count($this->outfitList()));
        if ($this->one_take) {
            return min($outfits - 1, intdiv($index * $outfits, max(1, $count)));
        }

        return ($piece !== null ? $piece['shot'] : $index) % $outfits;
    }

    /** Đủ ảnh để sang bước clip: mọi cảnh có ảnh duyệt, hoặc (quay liền) cảnh mở đầu mỗi đoạn có ảnh duyệt. */
    public function imagesReady(): bool
    {
        return $this->one_take
            ? $this->takeStarts()->isNotEmpty() && $this->takeStarts()->every(fn (Scene $scene) => $scene->approvedImage()->exists())
            : $this->allScenesApproved();
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('position');
    }

    public function sceneImages(): HasManyThrough
    {
        return $this->hasManyThrough(SceneImage::class, Scene::class);
    }

    /** Dùng bối cảnh của người dùng (thay cho bối cảnh AI đề xuất). */
    public function usesCustomBackgrounds(): bool
    {
        return ! empty($this->background_ids);
    }

    /**
     * Bối cảnh người dùng đã chọn, đúng thứ tự chọn (bỏ bối cảnh đã xoá).
     *
     * @return Collection<int, Background>
     */
    public function chosenBackgrounds(): Collection
    {
        if (! $this->usesCustomBackgrounds()) {
            return collect();
        }
        $found = Background::where('user_id', $this->user_id)->whereIn('id', $this->background_ids)->get()->keyBy('id');
        $backgrounds = collect($this->background_ids)->map(fn ($id) => $found[$id] ?? null)->filter()->values();

        // Một cảnh quay liền: chỉ một địa điểm.
        return $this->one_take ? $backgrounds->take(1) : $backgrounds;
    }

    /** Các lần gọi Claude cho dự án (chi phí, chỉ admin xem). */
    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    /** Các lần đăng video lên mạng xã hội. */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class)->latest('id');
    }

    public function renders(): HasMany
    {
        return $this->hasMany(Render::class)->latest('id');
    }

    /** Video xuất xong gần nhất (xem nhanh ở danh sách dự án). */
    public function latestDoneRender(): HasOne
    {
        return $this->hasOne(Render::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('status', RenderStatus::Done)->whereNotNull('path'));
    }

    /** Chuyển sang bước duyệt khi không còn ảnh cảnh nào đang tạo. */
    public function syncImagingStatus(): void
    {
        $this->refresh();

        if ($this->status !== VideoProjectStatus::Imaging) {
            return;
        }

        $pending = $this->scenes()
            ->whereHas('latestImage', fn ($query) => $query->where('status', SceneImageStatus::Pending))
            ->exists();

        if (! $pending) {
            $this->update(['status' => VideoProjectStatus::Reviewing]);
        }
    }

    /** Mọi cảnh đều có ảnh đã duyệt (điều kiện để xuất video). */
    public function allScenesApproved(): bool
    {
        return $this->scenes()->exists()
            && $this->scenes()->whereDoesntHave('approvedImage')->doesntExist();
    }

    /**
     * Lưu kịch bản (đã chuẩn hoá bằng ScriptSchema) và thay toàn bộ cảnh cũ.
     *
     * @param  array{hooks: list<string>, hook_themes: list<string>, recommended_hook: int, scenes: list<array>, caption: string, hashtags: list<string>}  $script
     */
    public function applyScript(array $script): void
    {
        DB::transaction(function () use ($script) {
            $this->scenes()->delete();

            // Pha trước/sau chỉ có nghĩa với video biến hình.
            $transformation = $this->isTransformation();
            // Bối cảnh của người dùng: cảnh i dùng bối cảnh thứ i (vòng lại khi nhiều cảnh hơn), giống phần prompt đã gửi Claude.
            $backgrounds = $this->chosenBackgrounds();
            // Video mẫu nhiều shot: cảnh i dài đúng đoạn thứ i và mặc bộ đồ của shot đó.
            $pieces = $this->shotPieces();
            // Phân cảnh Muse: cảnh i dài đúng shot thứ i.
            $plan = MusePlan::of($this);
            $outfits = $this->outfitList();
            // Góc quay sản phẩm của từng cảnh, giống phần prompt đã gửi Claude.
            $angles = ProductCoverage::plan($this, count($script['scenes']));
            $worn = 0;
            foreach ($script['scenes'] as $i => $scene) {
                $phase = $transformation ? ($scene['phase'] ?? 'after') : null;
                $piece = $pieces[$i] ?? null;
                $outfit = null;
                if ($outfits !== [] && $phase !== 'before') {
                    $outfit = $outfits[$this->outfitSlot($worn, count($script['scenes']), $piece)];
                    $worn++;
                }
                $shot = $plan !== null && count($plan['shots']) === count($script['scenes']) ? $plan['shots'][$i] : null;
                $this->scenes()->create([
                    ...$scene,
                    ...($piece !== null ? ['duration_ms' => (int) round(($piece['end'] - $piece['start']) * 1000)] : []),
                    ...($shot !== null ? ['duration_ms' => (int) round($shot['duration'] * 1000)] : []),
                    'position' => $i + 1,
                    'phase' => $phase,
                    'angle' => $angles[$i] ?? null,
                    'background_id' => $backgrounds->isEmpty() ? null : $backgrounds[$i % $backgrounds->count()]->id,
                    'product_id' => $outfit['product_id'] ?? null,
                    'product_image_id' => $outfit['product_image_id'] ?? null,
                    'outfit_color' => $outfit['color'] ?? null,
                ]);
            }

            $this->update([
                'script' => [
                    'hooks' => $script['hooks'],
                    'hook_themes' => $script['hook_themes'] ?? [],
                    'recommended_hook' => $script['recommended_hook'] ?? 0,
                    'caption' => $script['caption'],
                    'hashtags' => $script['hashtags'],
                ],
                // Hook Claude đề xuất được chọn sẵn để xuất video.
                'selected_hook' => $script['recommended_hook'] ?? 0,
                'status' => VideoProjectStatus::ScriptReady,
                'error' => null,
            ]);
        });

        $this->queuePostCaptions();
    }

    /** Viết (lại) đề xuất caption đăng video theo kịch bản hiện tại. */
    public function queuePostCaptions(): void
    {
        $this->update(['post_captions_status' => 'pending']);
        GeneratePostCaptionsJob::dispatch($this);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => VideoProjectStatus::Failed, 'error' => $error, 'autopilot' => false]);
    }

    /**
     * Lý do không chạy ngầm được (hệ thống chưa kết nối AI cho bước còn lại), null nếu chạy được.
     * Bước nào đã xong (có kịch bản, mọi cảnh đã có ảnh duyệt) thì không cần bước đó.
     */
    public function autopilotBlocker(): ?string
    {
        $missing = [];
        if ($this->script === null && ScriptController::webMode()) {
            $missing[] = 'viết kịch bản';
        }
        if (! $this->allScenesApproved() && SceneImageController::webMode()) {
            $missing[] = 'tạo ảnh';
        }

        // Người dùng không cấu hình được: chỉ báo bước nào chưa sẵn sàng (admin bật ở /admin/settings).
        return $missing === [] ? null : 'Chạy ngầm chưa sẵn sàng (hệ thống chưa tự '.implode(' và ', $missing).' được). Liên hệ quản trị viên.';
    }

    /**
     * Viết kịch bản: có video mẫu / nhạc nền thì Muse phân tích và lên phân cảnh trước (PlanWithMuseJob viết kịch bản khi xong),
     * phân cảnh đã có (hoặc Muse lỗi) thì viết luôn.
     */
    public function queueScript(): void
    {
        $this->update(['status' => VideoProjectStatus::Scripting, 'error' => null]);
        if (MusePlan::wanted($this) && ! in_array($this->muse_plan_status, ['ready', 'failed', 'pending'], true)) {
            $this->update(['muse_plan_status' => 'pending', 'muse_plan' => null, 'muse_plan_error' => null, 'muse_plan_request_id' => null]);
            PlanWithMuseJob::dispatch($this);

            return;
        }
        if ($this->muse_plan_status !== 'pending') {
            GenerateScriptJob::dispatch($this);
        }
    }

    /** Video mẫu / nhạc nền / kiểu tiếng / quay liền đổi: phân cảnh cũ không còn đúng, lần viết kịch bản sau Muse phân tích lại. */
    public function forgetMusePlan(): void
    {
        if ($this->muse_plan_status !== null && $this->muse_plan_status !== 'pending') {
            $this->update(['muse_plan_status' => null, 'muse_plan' => null, 'muse_plan_error' => null, 'muse_plan_request_id' => null]);
        }
    }

    /** Bật chạy ngầm và đưa bước tiếp theo vào hàng đợi. */
    public function startAutopilot(): void
    {
        $this->update(['autopilot' => true, 'error' => null]);
        // Người dùng bấm chạy lại: clip lỗi lần trước cũng tạo lại (không thì một cảnh quay liền dừng ngay ở nhịp lỗi).
        $this->advanceAutopilot(retryFailed: true);
    }

    /**
     * Chạy bước tiếp theo khi đang chạy ngầm: kịch bản → tạo ảnh → duyệt ảnh mới nhất → xuất video (hook đang chọn).
     * Được gọi lại sau mỗi job.
     */
    public function advanceAutopilot(bool $retryFailed = false): void
    {
        $this->refresh();
        if (! $this->autopilot || $this->status->isBusy()) {
            return;
        }

        if ($this->script === null || $this->scenes()->doesntExist()) {
            $this->queueScript();

            return;
        }

        if (! $this->imagesReady()) {
            // Duyệt luôn ảnh đã tạo xong; cảnh chưa có ảnh (hoặc ảnh lỗi) thì tạo.
            foreach ($this->scenes()->with('latestImage')->get() as $scene) {
                if ($scene->latestImage?->status === SceneImageStatus::Ready) {
                    $scene->latestImage->update(['status' => SceneImageStatus::Approved]);
                }
            }

            if (! $this->imagesReady()) {
                if ($this->queueMissingImages() === 0) {
                    $this->markFailed('Không tạo được ảnh cho mọi cảnh.');
                }

                return;
            }
        }

        // Có kiểu chuyển động: chờ muse.ai tạo clip cho các cảnh rồi mới xuất video.
        if ($this->wantsClips()) {
            if ($this->clipsPending()) {
                return;
            }
            if ($this->queueMissingClips($retryFailed) > 0) {
                return;
            }
            // Một cảnh quay liền: clip lỗi giữa chừng thì các nhịp sau không có khung để nối tiếp.
            if ($this->one_take && ! $this->allScenesApproved()) {
                $broken = $this->scenes()->orderBy('position')->whereNull('clip_path')->value('position');
                $this->markFailed("Clip cảnh {$broken} chưa tạo được nên các nhịp sau chưa nối tiếp được. Tạo lại clip cảnh {$broken} rồi chạy ngầm lần nữa.");

                return;
            }
        }

        $this->update(['status' => VideoProjectStatus::Rendering, 'error' => null]);
        RenderVideoJob::dispatch($this->renders()->create(['hook' => $this->selected_hook, 'with_text' => $this->render_text ?? true, 'status' => RenderStatus::Pending]));
    }

    /** @return Collection<int, Scene> Cảnh chưa có ảnh, hoặc ảnh gần nhất bị lỗi / đã bỏ. */
    public function scenesMissingImages(): Collection
    {
        return $this->scenes()->with('latestImage')->orderBy('position')->get()
            // Một cảnh quay liền: chỉ cảnh mở đầu mỗi đoạn tạo ảnh, các nhịp sau lấy khung cuối clip trước.
            ->when($this->one_take, fn ($scenes) => $scenes->whereIn('id', $this->takeStarts()->pluck('id')))
            ->filter(fn (Scene $scene) => in_array($scene->latestImage?->status, [null, SceneImageStatus::Failed, SceneImageStatus::Rejected], true))
            ->values();
    }

    /** Tạo ảnh cho cảnh chưa có ảnh, hoặc ảnh gần nhất bị lỗi / đã bỏ. Trả về số ảnh đưa vào hàng đợi. */
    public function queueMissingImages(): int
    {
        $scenes = $this->scenesMissingImages();

        if ($scenes->isEmpty()) {
            return 0;
        }

        $this->update(['status' => VideoProjectStatus::Imaging, 'error' => null]);

        // Tạo hết các dòng "pending" trước rồi mới đưa job vào hàng đợi,
        // để dự án không chuyển sang bước duyệt khi ảnh đầu tiên xong.
        // Cảnh vừa bị Muse từ chối: job viết lại prompt trước khi tạo.
        $jobs = $scenes->map(fn (Scene $scene) => [$scene->images()->create(), $scene->latestImage?->museRejection()]);
        $jobs->each(fn (array $job) => GenerateSceneImageJob::dispatch(...$job));

        return $jobs->count();
    }

    /**
     * Số cảnh và tổng số giây cho kịch bản, theo menu Cài đặt video (đề xuất theo sản phẩm hoặc cố định).
     *
     * @return array{scenes: int, seconds: int, preset: ?string, mode: string, reason: string}
     */
    public function videoSettings(): array
    {
        $settings = VideoPreset::resolve($this->product);

        // Muse đã lên phân cảnh từ video mẫu và nhạc: số cảnh và độ dài theo đúng phân cảnh.
        if (($plan = MusePlan::of($this)) !== null) {
            return [
                ...$settings,
                'scenes' => count($plan['shots']),
                'seconds' => (int) round(array_sum(array_column($plan['shots'], 'duration'))),
                'reason' => 'Theo phân cảnh Muse lên từ video mẫu và nhạc nền: '.count($plan['shots']).' shot.',
            ];
        }

        // Video mẫu nhiều shot: số cảnh và độ dài theo các shot (mỗi shot dài được chia thành vài cảnh).
        if ($this->one_take) {
            $beats = max(2, min(6, (int) round($settings['seconds'] / self::ONE_TAKE_BEAT_SECONDS)));
            // Nhiều bộ đồ: mỗi bộ một đoạn quay liền, ít nhất 2 nhịp mỗi bộ.
            $takes = max(1, count($this->outfitList()));
            $beats = $takes > 1 ? min(12, max($beats, 2 * $takes)) : $beats;

            return [
                ...$settings,
                'scenes' => $beats,
                'seconds' => $beats * self::ONE_TAKE_BEAT_SECONDS,
                'reason' => $takes > 1
                    ? "Quay liền {$takes} bộ đồ: mỗi bộ một đoạn quay liền, cắt cảnh đổi đồ giữa các đoạn; tổng {$beats} nhịp, mỗi nhịp khoảng ".self::ONE_TAKE_BEAT_SECONDS.' giây.'
                    : "Một cảnh quay liền: {$beats} nhịp nối tiếp, mỗi nhịp khoảng ".self::ONE_TAKE_BEAT_SECONDS.' giây.',
            ];
        }

        $pieces = $this->shotPieces();
        if ($pieces !== []) {
            $shots = count(array_unique(array_column($pieces, 'shot')));

            return [
                ...$settings,
                'scenes' => count($pieces),
                'seconds' => (int) round(array_sum(array_map(fn (array $piece) => $piece['end'] - $piece['start'], $pieces))),
                'reason' => "Theo video mẫu {$this->sampleVideo->name}: {$shots} shot".(count($pieces) > $shots ? ', shot dài chia thành nhiều cảnh' : '').'.',
            ];
        }

        return $settings;
    }

    /**
     * Danh sách bộ đồ (mỗi cảnh một bộ đồ), chỉ giữ sản phẩm / ảnh còn tồn tại của chủ dự án.
     *
     * @return list<array{product_id: int, product_image_id: ?int, color: ?string, product: Product}>
     */
    public function outfitList(): array
    {
        $items = is_array($this->outfits) ? $this->outfits : [];
        if ($items === []) {
            return [];
        }

        $products = Product::query()->where('user_id', $this->user_id)
            ->whereIn('id', array_column($items, 'product_id'))->with('images')->get()->keyBy('id');

        return array_values(array_filter(array_map(function (array $item) use ($products) {
            $product = $products->get($item['product_id'] ?? 0);
            if ($product === null) {
                return null;
            }
            $imageId = $item['product_image_id'] ?? null;

            return [
                'product_id' => $product->id,
                'product_image_id' => $imageId && $product->images->contains('id', $imageId) ? (int) $imageId : null,
                'color' => filled($item['color'] ?? null) ? mb_substr(trim($item['color']), 0, 80) : null,
                'product' => $product,
            ];
        }, $items)));
    }

    /**
     * Các kiểu chuyển động đã chọn (dự án cũ chỉ có motion_style).
     *
     * @return list<MotionStyle>
     */
    public function motionStyles(): array
    {
        $values = $this->motion_styles ?: ($this->motion_style ? [$this->motion_style->value] : []);

        return array_values(array_filter(array_map(fn ($value) => MotionStyle::tryFrom((string) $value), $values)));
    }

    /** Kiểu chuyển động của một cảnh: các cảnh dùng lần lượt các kiểu đã chọn. */
    public function motionStyleFor(Scene $scene): ?MotionStyle
    {
        $styles = $this->motionStyles();

        return $styles === [] ? null : $styles[max(0, $scene->position - 1) % count($styles)];
    }

    /** Clip chuyển động tự động: chỉ khi tạo ảnh bằng muse.ai qua trình duyệt ngầm. */
    public static function clipsAvailable(): bool
    {
        return config('videoaff.image_driver') === 'browser';
    }

    /** Có cách tạo chuyển động: kiểu có sẵn, video mẫu (của dự án hay của từng cảnh), quay liền hoặc người mẫu nói / hát. */
    public function hasMotion(): bool
    {
        return $this->one_take
            || $this->voice_mode !== VoiceMode::Music
            || $this->motionStyles() !== []
            || $this->sample_video_id !== null
            || $this->scenes()->whereNotNull('sample_video_id')->exists();
    }

    public function wantsClips(): bool
    {
        return self::clipsAvailable() && $this->hasMotion();
    }

    public function clipsPending(): bool
    {
        return $this->scenes()->where('clip_status', SceneClipStatus::Pending)->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Scene> Cảnh có ảnh duyệt mà chưa có clip. */
    public function scenesMissingClips(bool $retryFailed = false): \Illuminate\Database\Eloquent\Collection
    {
        // Một cảnh quay liền: trong mỗi đoạn tạo lần lượt, chỉ nhịp đầu tiên chưa có clip (nhịp sau cần khung cuối của nhịp này);
        // các đoạn (mỗi bộ đồ một đoạn) chạy song song.
        if ($this->one_take) {
            $starts = $this->takeStarts()->pluck('position')->all();
            $next = $this->scenes()->with('approvedImage')->orderBy('position')->whereNull('clip_path')->get()
                ->groupBy(fn (Scene $scene) => max(array_filter($starts, fn ($start) => $start <= $scene->position)))
                ->map->first()
                ->filter(fn (Scene $scene) => $scene->approvedImage && ($scene->clip_status === null || ($retryFailed && $scene->clip_status === SceneClipStatus::Failed)))
                ->values();

            return new \Illuminate\Database\Eloquent\Collection($next->all());
        }

        return $this->scenes()
            ->whereHas('approvedImage')
            ->whereNull('clip_path')
            ->where(fn ($query) => $query->whereNull('clip_status')
                ->when($retryFailed, fn ($query) => $query->orWhere('clip_status', SceneClipStatus::Failed)))
            ->get();
    }

    /**
     * Credits cần cho một clip của cảnh (sao chép video mẫu đắt hơn kiểu có sẵn).
     */
    public function clipPrice(Scene $scene): int
    {
        return CreditPricing::settings()['steps'][$scene->motionReference() ? 'clip_copy' : 'clip_style'];
    }

    /**
     * Tạo clip cho cảnh đã có ảnh duyệt mà chưa có clip.
     * $retryFailed: tạo lại cả cảnh lỗi (người dùng bấm); chạy ngầm thì không, cảnh lỗi dùng ảnh tĩnh.
     */
    public function queueMissingClips(bool $retryFailed = false): int
    {
        $scenes = $this->scenesMissingClips($retryFailed);

        $scenes->each(fn (Scene $scene) => $scene->queueClip());

        return $scenes->count();
    }

    /**
     * Tiến độ ước tính (%) cho thanh tiến độ: kịch bản 0–20, ảnh 20–70, xuất video 70–100.
     *
     * @return array{percent: int, label: string}
     */
    public function progress(): array
    {
        $scenes = $this->scenes()->with('latestImage')->get();
        $total = max(1, $scenes->count());
        $withImage = $scenes->filter(fn (Scene $scene) => in_array($scene->latestImage?->status, [SceneImageStatus::Ready, SceneImageStatus::Approved], true))->count();
        $approved = $scenes->filter(fn (Scene $scene) => $scene->latestImage?->status === SceneImageStatus::Approved)->count();

        return match ($this->status) {
            VideoProjectStatus::Draft => ['percent' => 0, 'label' => 'Chưa bắt đầu'],
            VideoProjectStatus::Scripting => $this->muse_plan_status === 'pending'
                ? ['percent' => 5, 'label' => 'Muse đang xem video mẫu và nghe nhạc để lên phân cảnh (thường 5–30 phút)']
                : ['percent' => 10, 'label' => 'AI đang viết kịch bản'],
            VideoProjectStatus::ScriptReady => ['percent' => 20, 'label' => 'Có kịch bản, chờ tạo ảnh'],
            VideoProjectStatus::Imaging => ['percent' => 20 + (int) round(50 * $withImage / $total), 'label' => "Đang tạo ảnh {$withImage}/{$scenes->count()}"],
            VideoProjectStatus::Reviewing => $this->clipsPending()
                ? $this->clipProgress()
                : ['percent' => 60 + (int) round(10 * $approved / $total), 'label' => "Đã duyệt ảnh {$approved}/{$scenes->count()}"],
            VideoProjectStatus::Rendering => ['percent' => 70 + (int) round(0.3 * ($this->renders()->value('progress') ?? 0)), 'label' => 'Đang ghép video '.($this->renders()->value('progress') ?? 0).'%'],
            VideoProjectStatus::Done => ['percent' => 100, 'label' => 'Đã xong'],
            VideoProjectStatus::Failed => ['percent' => 0, 'label' => 'Lỗi'],
        };
    }

    /** @return array{percent: int, label: string} */
    private function clipProgress(): array
    {
        $scenes = $this->scenes()->whereNotNull('clip_status')->get();
        $done = $scenes->filter(fn (Scene $scene) => $scene->clip_status !== SceneClipStatus::Pending)->count();

        return [
            'percent' => 60 + (int) round(10 * $done / max(1, $scenes->count())),
            'label' => "AI đang tạo clip chuyển động {$done}/{$scenes->count()}",
        ];
    }
}
