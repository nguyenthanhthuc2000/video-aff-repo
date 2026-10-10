<?php

namespace App\Models;

use App\Enums\MotionStyle;
use App\Enums\RenderStatus;
use App\Enums\SceneClipStatus;
use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Enums\VideoType;
use App\Http\Controllers\SceneImageController;
use App\Http\Controllers\ScriptController;
use App\Jobs\GenerateSceneImageJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\RenderVideoJob;
use App\Support\CreditPricing;
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
        'before_fashion_model_id',
        'motion_style',
        'motion_styles',
        'autopilot',
        'status',
        'script',
        'selected_hook',
        'music',
        'audio_track_id',
        'sample_video_id',
        'background_ids',
        'error',
    ];

    protected $attributes = [
        'status' => 'draft',
        'video_type' => 'standard',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoProjectStatus::class,
            'motion_style' => MotionStyle::class,
            'video_type' => VideoType::class,
            'motion_styles' => 'array',
            'background_ids' => 'array',
            'autopilot' => 'boolean',
            'script' => 'array',
            'selected_hook' => 'integer',
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
        return $this->video_type === VideoType::Transformation && $this->before_fashion_model_id !== null;
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

        return collect($this->background_ids)->map(fn ($id) => $found[$id] ?? null)->filter()->values();
    }

    /** Các lần gọi Claude cho dự án (chi phí, chỉ admin xem). */
    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
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
            foreach ($script['scenes'] as $i => $scene) {
                $this->scenes()->create([
                    ...$scene,
                    'position' => $i + 1,
                    'phase' => $transformation ? ($scene['phase'] ?? 'after') : null,
                    'background_id' => $backgrounds->isEmpty() ? null : $backgrounds[$i % $backgrounds->count()]->id,
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

    /** Bật chạy ngầm và đưa bước tiếp theo vào hàng đợi. */
    public function startAutopilot(): void
    {
        $this->update(['autopilot' => true, 'error' => null]);
        $this->advanceAutopilot();
    }

    /**
     * Chạy bước tiếp theo khi đang chạy ngầm: kịch bản → tạo ảnh → duyệt ảnh mới nhất → xuất video (hook đang chọn).
     * Được gọi lại sau mỗi job.
     */
    public function advanceAutopilot(): void
    {
        $this->refresh();
        if (! $this->autopilot || $this->status->isBusy()) {
            return;
        }

        if ($this->script === null || $this->scenes()->doesntExist()) {
            $this->update(['status' => VideoProjectStatus::Scripting]);
            GenerateScriptJob::dispatch($this);

            return;
        }

        if (! $this->allScenesApproved()) {
            // Duyệt luôn ảnh đã tạo xong; cảnh chưa có ảnh (hoặc ảnh lỗi) thì tạo.
            foreach ($this->scenes()->with('latestImage')->get() as $scene) {
                if ($scene->latestImage?->status === SceneImageStatus::Ready) {
                    $scene->latestImage->update(['status' => SceneImageStatus::Approved]);
                }
            }

            if (! $this->allScenesApproved()) {
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
            if ($this->queueMissingClips() > 0) {
                return;
            }
        }

        $this->update(['status' => VideoProjectStatus::Rendering, 'error' => null]);
        RenderVideoJob::dispatch($this->renders()->create(['hook' => $this->selected_hook, 'status' => RenderStatus::Pending]));
    }

    /** @return Collection<int, Scene> Cảnh chưa có ảnh, hoặc ảnh gần nhất bị lỗi / đã bỏ. */
    public function scenesMissingImages(): Collection
    {
        return $this->scenes()->with('latestImage')->get()
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
        return VideoPreset::resolve($this->product);
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

    /** Có cách tạo chuyển động: kiểu có sẵn, hoặc video mẫu (của dự án hay của từng cảnh). */
    public function hasMotion(): bool
    {
        return $this->motionStyles() !== []
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
            VideoProjectStatus::Scripting => ['percent' => 10, 'label' => 'AI đang viết kịch bản'],
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
