import { router } from '@inertiajs/react';
import { useEffect } from 'react';

// Số lượt gửi form / chuyển trang đang chạy (không tính lượt làm mới của usePoll).
let busy = 0;
if (typeof window !== 'undefined') {
    router.on('start', (event) => {
        if (event.detail.visit.method !== 'get' || event.detail.visit.only.length === 0) busy++;
    });
    router.on('finish', (event) => {
        if (event.detail.visit.method !== 'get' || event.detail.visit.only.length === 0) busy = Math.max(0, busy - 1);
    });
}

/** Làm mới các prop chỉ định mỗi `ms` khi `active` (job nền đang chạy). Bỏ qua lượt làm mới khi đang gửi form, để hai yêu cầu không chồng nhau. */
export function usePoll(active: boolean, only: string[], ms = 3000) {
    const key = only.join(',');

    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = setInterval(() => busy === 0 && router.reload({ only: key.split(',') }), ms);

        return () => clearInterval(timer);
    }, [active, key, ms]);
}
