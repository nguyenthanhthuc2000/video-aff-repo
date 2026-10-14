<?php

namespace App\Jobs;

use App\Enums\SeriesStatus;
use App\Models\Series;
use App\Services\Claude\AiUsage;
use App\Services\Film\SeriesPlanner;
use App\Services\Film\Voices;
use App\Services\Script\ScriptWriterException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Claude lên kế hoạch bộ phim (bible, nhân vật, bối cảnh, dàn ý các tập); thay toàn bộ phần chưa chốt. */
class PlanSeriesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Series $series) {}

    public function handle(): void
    {
        $series = $this->series->fresh();
        if ($series === null || $series->status !== SeriesStatus::Planning) {
            return;
        }

        try {
            $plan = AiUsage::withContext(
                ['user_id' => $series->user_id, 'subject' => "series:{$series->id}"],
                fn () => SeriesPlanner::write($series),
            );
        } catch (ScriptWriterException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }
            $this->failed($e);

            return;
        }

        DB::transaction(function () use ($series, $plan) {
            // Chỉ lên kế hoạch lại khi chưa chốt nhân vật / bối cảnh (controller đã chặn), nên thay hết.
            $series->characters()->delete();
            $series->locations()->delete();
            $series->episodes()->delete();
            // Nhân vật có sẵn ảnh: khớp theo tên, ảnh thành ảnh chính; Claude bỏ sót thì vẫn thêm (ngoại hình theo ghi chú).
            $seeds = collect($series->seed_characters ?? [])->keyBy(fn ($c) => mb_strtolower(trim($c['name'])));
            $characters = collect($plan['characters']);
            foreach ($seeds as $key => $seed) {
                if (! $characters->contains(fn ($c) => mb_strtolower(trim($c['name'])) === $key)) {
                    $characters->prepend(['name' => $seed['name'], 'role' => 'main', 'age' => null, 'personality' => '', 'appearance' => $seed['note'] ?: $seed['name'],
                        'voice' => Voices::forType('kid_boy', $characters->pluck('voice.voice')->all())]);
                }
            }
            foreach ($characters->values() as $i => $character) {
                $created = $series->characters()->create([...$character, 'position' => $i]);
                if ($seed = $seeds->get(mb_strtolower(trim($character['name'])))) {
                    $created->images()->create(['kind' => 'main', 'source' => 'upload', 'status' => 'ready', 'path' => $seed['path']]);
                    $created->update(['main_image_path' => $seed['path']]);
                }
            }
            foreach ($plan['locations'] as $i => $location) {
                $series->locations()->create([...$location, 'position' => $i]);
            }
            foreach ($plan['episodes'] as $i => $episode) {
                $series->episodes()->create([...$episode, 'number' => $i + 1]);
            }
            $series->update([
                'title' => $plan['bible']['title'] ?: $series->title,
                'bible' => $plan['bible'],
                'status' => SeriesStatus::BibleReady,
                'error' => null,
            ]);
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->series->fresh()?->update([
            'status' => SeriesStatus::Failed,
            'error' => mb_substr('Chưa lên được kế hoạch bộ phim: '.($e?->getMessage() ?? 'lỗi không rõ'), 0, 500),
        ]);
    }
}
