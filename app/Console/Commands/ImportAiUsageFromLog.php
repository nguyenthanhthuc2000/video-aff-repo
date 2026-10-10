<?php

namespace App\Console\Commands;

use App\Models\AiUsageLog;
use App\Models\Product;
use App\Models\SampleVideo;
use App\Models\Scene;
use App\Models\VideoProject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nhập chi phí các lần gọi Claude Code CLI trước khi có bảng ai_usage_logs, từ dòng log "claude_cli" trong laravel.log.
 * Log cũ chỉ có thời gian, thao tác, thời gian chạy và chi phí ước tính (không có token, model).
 * Gắn dự án theo "script:{id}" và dòng "scene_prompt_rewrite" ngay sau; sản phẩm / video mẫu theo thời gian tạo gần nhất.
 * Chạy lại an toàn: bỏ qua lượt đã có (cùng thao tác, cùng thời điểm).
 */
class ImportAiUsageFromLog extends Command
{
    protected $signature = 'ai-usage:import-log {path? : Mặc định storage/logs/laravel.log} {--dry-run : Chỉ đếm, không ghi}';

    protected $description = 'Nhập chi phí Claude cũ từ laravel.log vào màn hình Chi phí AI';

    private const LINE = '/^\[(?<time>[\d\- :]+)\] (?<env>\w+)\.INFO: (?<name>claude_cli|scene_prompt_rewrite) (?<json>\{.*\})/';

    public function handle(): int
    {
        $path = $this->argument('path') ?? storage_path('logs/laravel.log');
        if (! is_readable($path)) {
            $this->error("Không đọc được {$path}.");

            return self::FAILURE;
        }

        $env = app()->environment();
        $pending = null;
        $created = 0;
        $skipped = 0;

        $flush = function (?array $call, ?int $sceneId) use (&$created, &$skipped) {
            if ($call === null) {
                return;
            }
            if (AiUsageLog::where('action', $call['action'])->where('created_at', $call['time'])->exists()) {
                $skipped++;

                return;
            }

            $attributes = $this->link($call, $sceneId);
            if (! $this->option('dry-run')) {
                $log = new AiUsageLog([
                    ...$attributes,
                    'action' => $call['action'],
                    'provider' => 'claude_cli',
                    'success' => $call['success'],
                    'cost_usd' => $call['cost'],
                    'duration_ms' => $call['duration'],
                    'error' => $call['success'] ? null : 'Lỗi (nhập từ log cũ).',
                ]);
                $log->created_at = $call['time'];
                $log->save();
            }
            $created++;
        };

        $handle = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            if (! preg_match(self::LINE, $line, $m) || $m['env'] !== $env) {
                continue;
            }
            $data = json_decode($m['json'], true) ?: [];

            if ($m['name'] === 'scene_prompt_rewrite') {
                // Dòng kết quả viết lại prompt đi ngay sau lần gọi CLI tương ứng.
                if ($pending !== null && $pending['action'] === 'scene_prompt_rewrite') {
                    $flush($pending, isset($data['scene_id']) ? (int) $data['scene_id'] : null);
                    $pending = null;
                }

                continue;
            }

            $flush($pending, null);
            $context = (string) ($data['context'] ?? '');
            $pending = [
                'action' => strtok($context, ':') ?: 'unknown',
                'project_id' => str_starts_with($context, 'script:') ? (int) substr($context, 7) : null,
                'time' => Carbon::parse($m['time']),
                'success' => ($data['subtype'] ?? 'success') === 'success',
                'duration' => isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
                'cost' => (float) ($data['notional_cost_usd'] ?? 0),
            ];
        }
        $flush($pending, null);
        fclose($handle);

        $this->info(($this->option('dry-run') ? 'Sẽ nhập' : 'Đã nhập')." {$created} lượt gọi, bỏ qua {$skipped} lượt đã có.");

        return self::SUCCESS;
    }

    /** @return array{user_id: ?int, video_project_id: ?int, subject: ?string} */
    private function link(array $call, ?int $sceneId): array
    {
        $none = ['user_id' => null, 'video_project_id' => null, 'subject' => null];
        $window = fn ($query) => $query->whereBetween('created_at', [$call['time']->copy()->subMinutes(15), $call['time']])->latest()->first();

        switch ($call['action']) {
            case 'script':
                $project = $call['project_id'] ? VideoProject::find($call['project_id']) : null;

                return [...$none, 'user_id' => $project?->user_id, 'video_project_id' => $project?->id];
            case 'scene_prompt_rewrite':
                $scene = $sceneId ? Scene::with('videoProject')->find($sceneId) : null;

                return ['user_id' => $scene?->videoProject?->user_id, 'video_project_id' => $scene?->video_project_id, 'subject' => $scene ? "scene:{$scene->id}" : null];
            case 'product_import':
                $product = $window(Product::query()->whereNotNull('shopee_url'));

                return [...$none, 'user_id' => $product?->user_id, 'subject' => $product ? "product:{$product->id}" : null];
            case 'motion_analysis':
                $video = $window(SampleVideo::query());

                return [...$none, 'user_id' => $video?->user_id, 'subject' => $video ? "sample_video:{$video->id}" : null];
            default:
                return $none;
        }
    }
}
