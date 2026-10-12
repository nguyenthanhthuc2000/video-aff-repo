<?php

namespace App\Http\Controllers;

use App\Enums\FashionModelKind;
use App\Enums\MotionStyle;
use App\Enums\VideoProjectStatus;
use App\Enums\VideoType;
use App\Enums\VoiceMode;
use App\Http\Resources\FashionModelResource;
use App\Http\Resources\VideoProjectResource;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Video\SampleAudio;
use App\Support\CreditPricing;
use App\Support\Credits;
use App\Support\ProjectCredits;
use App\Support\Voice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VideoProjectController extends Controller
{
    /** Danh sách dự án, lọc theo trạng thái (?status=...), kèm số dự án mỗi trạng thái. */
    public function index(Request $request): Response
    {
        $status = VideoProjectStatus::tryFrom((string) $request->query('status'));

        $projects = $request->user()->videoProjects()
            ->with(['product.primaryImage', 'fashionModel', 'beforeFashionModel', 'latestDoneRender'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        $counts = $request->user()->videoProjects()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        return Inertia::render('projects/index', [
            'projects' => VideoProjectResource::collection($projects)->resolve(),
            'filters' => ['status' => $status?->value],
            'statusCounts' => (object) $counts->all(),
            // Chi phí sản xuất từng video (Credits, theo bảng giá bán).
            'credits' => (object) ProjectCredits::forProjects($projects),
            'total' => $counts->sum(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('user_id', $userId)],
            // Ảnh chính (sau) phải là người mẫu loại "sau"; ảnh "trước" của video biến hình là loại "trước".
            'fashion_model_id' => [
                'required',
                Rule::exists('fashion_models', 'id')->where('user_id', $userId)->whereNotNull('consent_at')->where('kind', FashionModelKind::After->value),
            ],
            // motion_style: một kiểu (bản cũ); motion_styles: nhiều kiểu, các cảnh dùng lần lượt.
            'motion_style' => ['nullable', Rule::enum(MotionStyle::class)],
            'motion_styles' => ['nullable', 'array', 'max:'.count(MotionStyle::cases())],
            'motion_styles.*' => ['distinct', Rule::enum(MotionStyle::class)],
            'autopilot' => ['boolean'],
            // Một cảnh quay liền (không cắt cảnh): các nhịp nối nhau bằng khung cuối clip trước.
            'one_take' => ['boolean'],
            // Tiếng: chỉ nhạc nền / người mẫu nói lời review / hát nhép theo nhạc nền.
            'voice_mode' => ['nullable', Rule::enum(VoiceMode::class)],
            'video_type' => ['nullable', Rule::enum(VideoType::class)],
            'audio_track_id' => ['nullable', Rule::exists('audio_tracks', 'id')->where('user_id', $userId)->whereNotNull('path')],
            // Sao chép chuyển động từ video mẫu cho mọi cảnh.
            'sample_video_id' => ['nullable', Rule::exists('sample_videos', 'id')->where('user_id', $userId)],
            // "Nhạc như video mẫu": tách tiếng của video mẫu đã chọn làm nhạc nền.
            'use_sample_audio' => ['boolean'],
            // Bối cảnh: trống = AI tự đề xuất theo sản phẩm; có danh sách = dùng bối cảnh của người dùng (các cảnh dùng lần lượt).
            'background_ids' => ['nullable', 'array', 'max:20'],
            'background_ids.*' => ['integer', 'distinct', Rule::exists('backgrounds', 'id')->where('user_id', $userId)],
            // Mỗi cảnh một bộ đồ: các cảnh mặc lần lượt (video mẫu nhiều shot thì mỗi shot một bộ).
            'outfits' => ['nullable', 'array', 'max:12'],
            'outfits.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('user_id', $userId)->whereNotNull('images_approved_at')],
            'outfits.*.product_image_id' => ['nullable', 'integer'],
            'outfits.*.color' => ['nullable', 'string', 'max:80'],
            // Biến hình: ảnh "trước" (đồ cũ, mặt mộc, tóc rối) là một người mẫu đã đồng ý của chính người dùng.
            'before_fashion_model_id' => [
                Rule::requiredIf($request->input('video_type') === VideoType::Transformation->value),
                'nullable',
                Rule::exists('fashion_models', 'id')->where('user_id', $userId)->whereNotNull('consent_at')->where('kind', FashionModelKind::Before->value),
            ],
        ], [
            'before_fashion_model_id.required' => 'Chọn ảnh "trước" cho video biến hình.',
        ], [
            'before_fashion_model_id' => 'ảnh trước',
            'audio_track_id' => 'nhạc nền',
            'sample_video_id' => 'video mẫu',
            'background_ids' => 'bối cảnh',
            'background_ids.*' => 'bối cảnh',
            'outfits' => 'bộ đồ',
            'outfits.*.product_id' => 'sản phẩm của bộ đồ',
            'outfits.*.color' => 'màu',
            'product_id' => 'sản phẩm',
            'fashion_model_id' => 'người mẫu',
            'motion_style' => 'kiểu chuyển động',
            'motion_styles' => 'kiểu chuyển động',
            'motion_styles.*' => 'kiểu chuyển động',
        ]);

        if (! $request->user()->products()->findOrFail($validated['product_id'])->imagesApproved()) {
            throw ValidationException::withMessages(['product_id' => 'Duyệt ảnh sản phẩm trước khi tạo video.']);
        }

        if ($request->boolean('one_take')) {
            $message = match (true) {
                ! VideoProject::clipsAvailable() => 'Một cảnh quay liền cần hệ thống tự tạo clip. Liên hệ quản trị viên.',
                ($validated['video_type'] ?? null) === VideoType::Transformation->value => 'Video biến hình cần cắt cảnh trước / sau, chọn "Nhiều cảnh".',
                default => null,
            };
            if ($message) {
                throw ValidationException::withMessages(['one_take' => $message]);
            }
        }

        $validated['video_type'] ??= VideoType::Standard->value;
        if ($validated['video_type'] !== VideoType::Transformation->value) {
            $validated['before_fashion_model_id'] = null;
        }

        if ($request->boolean('use_sample_audio')) {
            $sample = $request->user()->sampleVideos()->find($validated['sample_video_id'] ?? 0)
                ?? throw ValidationException::withMessages(['audio_track_id' => 'Chọn video mẫu để dùng nhạc của video mẫu.']);
            try {
                $validated['audio_track_id'] = app(SampleAudio::class)->extract($sample)->id;
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['audio_track_id' => $e->getMessage()]);
            }
        }
        unset($validated['use_sample_audio']);
        $validated['voice_mode'] ??= VoiceMode::Music->value;
        if ($message = self::voiceBlocker(VoiceMode::from($validated['voice_mode']), ($validated['audio_track_id'] ?? null) !== null)) {
            throw ValidationException::withMessages(['voice_mode' => $message]);
        }
        $validated['background_ids'] = array_values(array_map('intval', $validated['background_ids'] ?? [])) ?: null;
        $validated['outfits'] = self::outfits($validated['outfits'] ?? []);

        $autopilot = (bool) ($validated['autopilot'] ?? false);
        $styles = array_values($validated['motion_styles'] ?? array_filter([$validated['motion_style'] ?? null]));
        $validated = [...$validated, 'motion_style' => $styles[0] ?? null, 'motion_styles' => $styles ?: null];
        unset($validated['autopilot']);
        $project = $request->user()->videoProjects()->create($validated);

        // Chạy ngầm: cần đủ Credits ít nhất cho bước viết kịch bản (các bước sau tự kiểm tra, thiếu thì dừng).
        if ($autopilot && ($message = Credits::shortfall($request->user(), CreditPricing::aiEstimates()['script'], 'chạy ngầm'))) {
            return to_route('projects.show', $project)->with('status', $message);
        }

        if ($autopilot && $project->autopilotBlocker() === null) {
            $project->startAutopilot();

            return to_route('projects.show', $project)->with('status', 'Đang chạy ngầm: viết kịch bản → tạo ảnh → xuất video.');
        }

        return to_route('projects.show', $project);
    }

    /**
     * Chuẩn hoá danh sách bộ đồ; ảnh phải thuộc đúng sản phẩm.
     *
     * @return list<array{product_id: int, product_image_id: ?int, color: ?string}>|null
     */
    public static function outfits(array $items): ?array
    {
        $outfits = [];
        foreach (array_values($items) as $i => $item) {
            $imageId = isset($item['product_image_id']) ? (int) $item['product_image_id'] : null;
            if ($imageId !== null && ! ProductImage::query()->whereKey($imageId)->where('product_id', $item['product_id'])->exists()) {
                throw ValidationException::withMessages(["outfits.{$i}.product_image_id" => 'Ảnh không thuộc sản phẩm của bộ đồ.']);
            }
            $outfits[] = ['product_id' => (int) $item['product_id'], 'product_image_id' => $imageId, 'color' => filled($item['color'] ?? null) ? trim($item['color']) : null];
        }

        return $outfits ?: null;
    }

    /** Sản phẩm đã duyệt ảnh để chọn làm bộ đồ. */
    public static function outfitProducts(User $user): array
    {
        return $user->products()->whereNotNull('images_approved_at')->with('images')->latest()->get()
            ->map->outfitOption()->all();
    }

    public function show(Request $request, VideoProject $project): Response
    {
        $this->authorize('view', $project);

        $project->load(['product.primaryImage', 'fashionModel', 'beforeFashionModel', 'audioTrack', 'sampleVideo', 'scenes.latestImage', 'scenes.sampleVideo', 'scenes.background', 'renders', 'socialPosts']);

        return Inertia::render('projects/show', [
            'credits' => ProjectCredits::forProjects(collect([$project]))[$project->id],
            'project' => VideoProjectResource::make($project)->resolve(),
            // Cho nút "Tạo thêm video" (cùng sản phẩm).
            ...self::createFormProps($request->user()),
        ]);
    }

    /** Dữ liệu cho form tạo video: người mẫu, video mẫu, nhạc, bối cảnh, sản phẩm làm bộ đồ. @return array<string, mixed> */
    public static function createFormProps(User $user): array
    {
        return [
            'autopilotAvailable' => self::autopilotAvailable(),
            // Video mẫu để sao chép chuyển động.
            'sampleVideos' => $user->sampleVideos()->latest()->get()->map(fn ($video) => SampleVideoController::present($video)),
            // Nhạc trong thư viện (chỉ bài có file) để chọn khi tạo video.
            'audioTracks' => $user->audioTracks()->whereNotNull('path')->latest()->get()->map(fn ($track) => AudioTrackController::present($track)),
            'fashionModels' => FashionModelResource::collection($user->fashionModels()->whereNotNull('consent_at')->latest()->get())->resolve(),
            'backgrounds' => $user->backgrounds()->latest()->get()->map->present(),
            'socialAccounts' => $user->socialAccounts()->orderBy('name')->get()->map->present(),
            'outfitProducts' => self::outfitProducts($user),
        ];
    }

    /** Chạy ngầm các bước còn lại (kịch bản, ảnh, xuất video). */
    public function autopilot(VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($project->status->isBusy()) {
            return back()->with('status', 'Dự án đang xử lý.');
        }

        if ($blocker = $project->autopilotBlocker()) {
            return back()->with('status', $blocker);
        }

        if ($message = Credits::shortfall($project->user, CreditPricing::aiEstimates()[$project->script ? 'scene_prompt_rewrite' : 'script'], 'chạy ngầm')) {
            return back()->with('status', $message);
        }

        $project->startAutopilot();

        return back()->with('status', 'Đang chạy ngầm, bạn có thể rời trang này.');
    }

    /** Đổi tiếng của video (dự án đã tạo); clip làm lại mới áp dụng. */
    public function voice(Request $request, VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $mode = VoiceMode::from($request->validate(['voice_mode' => ['required', Rule::enum(VoiceMode::class)]])['voice_mode']);
        if ($message = self::voiceBlocker($mode, Voice::musicPath($project) !== null)) {
            throw ValidationException::withMessages(['voice_mode' => $message]);
        }
        $project->update(['voice_mode' => $mode, 'music_start_ms' => null]);
        // Phân cảnh Muse lên theo kiểu tiếng cũ: viết lại kịch bản thì Muse lên lại.
        $project->forgetMusePlan();

        return back()->with('status', match ($mode) {
            VoiceMode::Speak => 'Đã chọn "Người mẫu nói". Viết lại kịch bản để lời nói nối thành một bài review, rồi tạo lại clip.',
            VoiceMode::Sing => 'Đã chọn "Hát nhép theo nhạc". Viết lại kịch bản để Muse nghe nhạc và lên lại phân cảnh hát nhép, rồi tạo lại clip.',
            VoiceMode::Music => 'Đã chọn "Chỉ nhạc nền". Tạo lại clip để người mẫu không nhép miệng.',
        });
    }

    private static function voiceBlocker(VoiceMode $mode, bool $hasMusic): ?string
    {
        return match (true) {
            $mode !== VoiceMode::Music && ! VideoProject::clipsAvailable() => 'Người mẫu nói / hát nhép cần hệ thống tự tạo clip. Liên hệ quản trị viên.',
            $mode === VoiceMode::Sing && ! $hasMusic => 'Hát nhép cần chọn nhạc nền (thư viện Âm thanh hoặc nhạc của video mẫu).',
            default => null,
        };
    }

    /** Có khoá API cho cả Claude và Muse: dự án mới chạy ngầm được từ đầu đến cuối. */
    public static function autopilotAvailable(): bool
    {
        return ! ScriptController::webMode() && ! SceneImageController::webMode();
    }

    public function destroy(VideoProject $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return to_route('projects.index')->with('status', 'Đã xoá dự án video.');
    }
}
