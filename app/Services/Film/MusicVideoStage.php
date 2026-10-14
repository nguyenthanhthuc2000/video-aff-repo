<?php

namespace App\Services\Film;

use App\Enums\SeriesStatus;
use App\Models\Episode;
use App\Models\Series;

/**
 * Bước hiện tại của một MV trong luồng làm (cho trang danh sách MV): lên ý tưởng → chốt ca sĩ → cắt shot → vẽ khung hình
 * → làm clip → ghép → xong, kèm % tiến độ, việc cần làm tiếp và trang để làm tiếp.
 */
final class MusicVideoStage
{
    /** Nhóm để lọc: đang chạy, chờ bạn làm tiếp, xong, lỗi. */
    public const GROUPS = ['busy' => 'Đang chạy', 'action' => 'Chờ bạn làm tiếp', 'done' => 'Đã xong', 'failed' => 'Lỗi'];

    /** @return array{key: string, label: string, group: string, progress: int, episode_id: ?int} */
    public static function of(Series $series): array
    {
        $episode = $series->episodes->first();
        $stage = fn (string $key, string $label, string $group, int $progress) => ['key' => $key, 'label' => $label, 'group' => $group, 'progress' => $progress, 'episode_id' => null];

        if ($series->status === SeriesStatus::Failed) {
            return $stage('failed', 'Lỗi lên ý tưởng', 'failed', 0);
        }
        if ($series->status === SeriesStatus::Planning || $series->status === SeriesStatus::Draft) {
            return $stage('idea', 'Claude đang lên ý tưởng', 'busy', 5);
        }
        $singer = $series->characters->first();
        if ($singer === null || $singer->main_image_path === null) {
            return $stage('singer', 'Chờ duyệt ý tưởng và ảnh ca sĩ', 'action', 15);
        }
        if ($episode === null) {
            return $stage('shots', 'Chưa có tập MV', 'action', 20);
        }

        return ['episode_id' => $episode->id, ...self::episode($episode)];
    }

    /** @return array{key: string, label: string, group: string, progress: int} */
    private static function episode(Episode $episode): array
    {
        $total = (int) ($episode->shots_count ?? 0);
        $images = (int) ($episode->images_ready_count ?? 0);
        $videos = (int) ($episode->videos_ready_count ?? 0);
        $stage = fn (string $key, string $label, string $group, int $progress) => ['key' => $key, 'label' => $label, 'group' => $group, 'progress' => $progress];
        $ratio = fn (int $done) => $total > 0 ? $done / $total : 0;

        return match (true) {
            $episode->render_status === 'rendering' => $stage('render', 'Đang ghép MV '.(int) $episode->render_progress.'%', 'busy', 90 + (int) round((int) $episode->render_progress / 10)),
            $episode->render_path !== null && $episode->render_status === 'done' => $stage('done', 'Đã xong', 'done', 100),
            $episode->status === 'failed' || $episode->render_status === 'failed' => $stage('failed', $episode->render_status === 'failed' ? 'Ghép MV lỗi' : 'Cắt shot lỗi', 'failed', 20),
            in_array($episode->status, ['draft'], true) => $stage('shots', 'Chờ viết phân cảnh (cắt shot theo bài hát)', 'action', 20),
            $episode->status === 'writing' => $stage('shots', 'Claude đang cắt shot theo bài hát', 'busy', 22),
            $episode->status === 'keyframing' => $stage('keyframes', "Đang vẽ khung hình {$images}/{$total}", 'busy', 25 + (int) round(25 * $ratio($images))),
            $episode->status === 'storyboard' => $stage('keyframes', "Chờ vẽ khung hình ({$images}/{$total})", 'action', 25 + (int) round(25 * $ratio($images))),
            $episode->status === 'animating' => $stage('clips', "Đang làm clip {$videos}/{$total}", 'busy', 50 + (int) round(40 * $ratio($videos))),
            $episode->status === 'keyframed' => $stage('clips', "Chờ làm clip ({$videos}/{$total})", 'action', 50 + (int) round(40 * $ratio($videos))),
            default => $stage('render', 'Chờ ghép MV', 'action', 90),
        };
    }
}
