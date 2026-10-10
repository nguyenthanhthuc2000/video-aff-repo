<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RenderStatus;
use App\Enums\VideoProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\AudioTrack;
use App\Models\Product;
use App\Models\Render;
use App\Models\SampleVideo;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Services\Image\MuseBrowserClient;
use App\Support\ScriptDriver;
use App\Support\Workflow;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Tổng quan hệ thống cho quản trị viên. */
class DashboardController extends Controller
{
    public function index(MuseBrowserClient $muse): Response
    {
        $week = now()->subDays(7);
        $byStatus = VideoProject::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'users' => User::count(),
                'users_week' => User::where('created_at', '>=', $week)->count(),
                'admins' => User::where('is_admin', true)->count(),
                'blocked' => User::whereNotNull('blocked_at')->count(),
                'products' => Product::count(),
                'projects' => VideoProject::count(),
                'projects_week' => VideoProject::where('created_at', '>=', $week)->count(),
                'renders_done' => Render::where('status', RenderStatus::Done)->count(),
                'renders_week' => Render::where('status', RenderStatus::Done)->where('created_at', '>=', $week)->count(),
                'sample_videos' => SampleVideo::count(),
                'audio_tracks' => AudioTrack::count(),
                'jobs_pending' => DB::table('jobs')->count(),
                'jobs_failed' => DB::table('failed_jobs')->count(),
            ],
            'aiCost' => [
                'month_usd' => (float) AiUsageLog::where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'),
                'month_calls' => AiUsageLog::where('created_at', '>=', now()->startOfMonth())->count(),
                'usd_vnd' => AiUsage::settings()['usd_vnd'],
            ],
            'projectsByStatus' => collect(VideoProjectStatus::cases())->mapWithKeys(fn ($status) => [$status->value => (int) ($byStatus[$status->value] ?? 0)]),
            'services' => [
                'script_driver' => ScriptDriver::current(),
                'image_driver' => config('videoaff.image_driver'),
                'workflow' => Workflow::mode(),
                'muse' => $this->museStatus($muse),
            ],
            'recentErrors' => VideoProject::with(['user:id,name,email', 'product:id,name'])
                ->whereNotNull('error')->latest('updated_at')->limit(6)->get()
                ->map(fn (VideoProject $project) => [
                    'id' => $project->id,
                    'product' => $project->product?->name,
                    'user' => $project->user?->email,
                    'error' => mb_substr((string) $project->error, 0, 200),
                    'updated_at' => $project->updated_at,
                ]),
        ]);
    }

    /** Trạng thái đăng nhập muse.ai (dịch vụ trình duyệt); lỗi kết nối thì báo "không kết nối được". */
    private function museStatus(MuseBrowserClient $muse): array
    {
        if (config('videoaff.image_driver') !== 'browser') {
            return ['state' => 'disabled', 'message' => 'Không dùng trình duyệt ngầm (MUSE_DRIVER khác browser).'];
        }

        try {
            $status = $muse->status();

            return ['state' => $status['state'] ?? 'unknown', 'message' => $status['message'] ?? ''];
        } catch (Throwable) {
            return ['state' => 'offline', 'message' => 'Không kết nối được dịch vụ trình duyệt.'];
        }
    }
}
