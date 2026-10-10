<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Hàng đợi: job đang chờ / đang chạy và job lỗi (chạy lại, xoá). */
class JobController extends Controller
{
    public function index(): Response
    {
        $pending = DB::table('jobs')->orderBy('id')->limit(50)->get()->map(fn ($job) => [
            'id' => $job->id,
            'queue' => $job->queue,
            'name' => self::jobName($job->payload),
            'attempts' => $job->attempts,
            'reserved' => $job->reserved_at !== null,
            'available_at' => date(DATE_ATOM, $job->available_at),
            'created_at' => date(DATE_ATOM, $job->created_at),
        ]);

        $failed = DB::table('failed_jobs')->orderByDesc('id')->paginate(20)->through(fn ($job) => [
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'name' => self::jobName($job->payload),
            'error' => mb_substr(strtok((string) $job->exception, "\n") ?: '', 0, 300),
            'failed_at' => $job->failed_at,
        ]);

        return Inertia::render('admin/jobs', [
            'pending' => $pending,
            'pendingTotal' => DB::table('jobs')->count(),
            'failed' => $failed,
        ]);
    }

    public function retry(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', 'Đã đưa job vào hàng đợi chạy lại.');
    }

    public function destroy(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('status', 'Đã xoá job lỗi.');
    }

    public function flush(): RedirectResponse
    {
        Artisan::call('queue:flush');

        return back()->with('status', 'Đã xoá toàn bộ job lỗi.');
    }

    /** Tên lớp job trong payload (App\Jobs\RenderVideoJob → RenderVideoJob). */
    private static function jobName(string $payload): string
    {
        $name = json_decode($payload, true)['displayName'] ?? 'Job';

        return class_basename($name);
    }
}
