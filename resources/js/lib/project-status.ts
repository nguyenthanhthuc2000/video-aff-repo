import { type VideoProjectStatus } from '@/types/models';

export const projectStatusLabels: Record<VideoProjectStatus, string> = {
    draft: 'Mới tạo',
    scripting: 'Đang viết kịch bản',
    script_ready: 'Kịch bản sẵn sàng',
    imaging: 'Đang tạo ảnh',
    reviewing: 'Chờ duyệt ảnh',
    rendering: 'Đang ghép video',
    done: 'Đã xong',
    failed: 'Lỗi',
};
