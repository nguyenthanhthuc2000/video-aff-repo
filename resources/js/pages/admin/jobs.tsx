import FlashStatus from '@/components/flash-status';
import Heading from '@/components/heading';
import Pagination, { type Paginated } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePoll } from '@/hooks/use-poll';
import AdminLayout from '@/layouts/admin-layout';
import { Head, router } from '@inertiajs/react';
import { RotateCcw, Trash2 } from 'lucide-react';

interface PendingJob {
    id: number;
    queue: string;
    name: string;
    attempts: number;
    reserved: boolean;
    available_at: string;
    created_at: string;
}

interface FailedJob {
    uuid: string;
    queue: string;
    name: string;
    error: string;
    failed_at: string;
}

/** Tên job dễ đọc. */
const jobLabels: Record<string, string> = {
    GenerateScriptJob: 'Viết kịch bản',
    GenerateSceneImageJob: 'Tạo ảnh cảnh',
    GenerateSceneClipJob: 'Tạo clip chuyển động',
    RenderVideoJob: 'Xuất video',
    ImportProductJob: 'Lấy sản phẩm',
    AnalyzeSampleVideoJob: 'Phân tích video mẫu',
};

export default function AdminJobs({ pending, pendingTotal, failed }: { pending: PendingJob[]; pendingTotal: number; failed: Paginated<FailedJob> }) {
    // Tự làm mới khi còn job đang chờ.
    usePoll(pendingTotal > 0, ['pending', 'pendingTotal', 'failed'], 5000);

    const retry = (job: FailedJob) => router.post(route('admin.jobs.retry', job.uuid), {}, { preserveScroll: true });
    const remove = (job: FailedJob) => confirm('Xoá job lỗi này?') && router.delete(route('admin.jobs.destroy', job.uuid), { preserveScroll: true });
    const flush = () => confirm('Xoá toàn bộ job lỗi?') && router.delete(route('admin.jobs.flush'), { preserveScroll: true });

    return (
        <AdminLayout title="Hàng đợi">
            <Head title="Hàng đợi" />
            <div className="space-y-6">
                <Heading title="Hàng đợi" description="Các việc chạy ngầm: viết kịch bản, tạo ảnh, clip, xuất video." />
                <FlashStatus />

                <section className="space-y-3">
                    <h2 className="font-medium">
                        Đang chờ / đang chạy <span className="text-muted-foreground">({pendingTotal})</span>
                    </h2>
                    <div className="divide-y rounded-xl border text-sm">
                        {pending.length === 0 && <p className="text-muted-foreground p-4 text-center">Hàng đợi trống.</p>}
                        {pending.map((job) => (
                            <div key={job.id} className="flex flex-wrap items-center gap-3 px-4 py-2.5">
                                <span className="font-medium">{jobLabels[job.name] ?? job.name}</span>
                                {job.reserved ? <Badge>Đang chạy</Badge> : <Badge variant="secondary">Chờ</Badge>}
                                <span className="text-muted-foreground text-xs">lần {job.attempts}</span>
                                <span className="text-muted-foreground ml-auto text-xs">
                                    Chạy lúc {new Date(job.available_at).toLocaleTimeString('vi-VN')}
                                </span>
                            </div>
                        ))}
                    </div>
                    {pendingTotal > pending.length && <p className="text-muted-foreground text-xs">Hiện {pending.length} job đầu tiên.</p>}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="font-medium">
                            Job lỗi <span className="text-muted-foreground">({failed.total})</span>
                        </h2>
                        {failed.total > 0 && (
                            <Button size="sm" variant="ghost" className="text-destructive" onClick={flush}>
                                <Trash2 /> Xoá tất cả
                            </Button>
                        )}
                    </div>
                    <div className="divide-y rounded-xl border text-sm">
                        {failed.data.length === 0 && <p className="text-muted-foreground p-4 text-center">Không có job lỗi.</p>}
                        {failed.data.map((job) => (
                            <div key={job.uuid} className="flex flex-wrap items-start gap-3 px-4 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">{jobLabels[job.name] ?? job.name}</p>
                                    <p className="text-muted-foreground line-clamp-2 text-xs">{job.error}</p>
                                    <p className="text-muted-foreground text-xs">{new Date(job.failed_at).toLocaleString('vi-VN')}</p>
                                </div>
                                <div className="flex gap-1">
                                    <Button size="sm" variant="outline" onClick={() => retry(job)}>
                                        <RotateCcw /> Chạy lại
                                    </Button>
                                    <Button size="sm" variant="ghost" onClick={() => remove(job)} aria-label="Xoá">
                                        <Trash2 />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                    <Pagination page={failed} />
                </section>
            </div>
        </AdminLayout>
    );
}
