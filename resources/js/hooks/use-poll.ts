import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/** Làm mới các prop chỉ định mỗi `ms` khi `active` (job nền đang chạy). */
export function usePoll(active: boolean, only: string[], ms = 3000) {
    const key = only.join(',');

    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = setInterval(() => router.reload({ only: key.split(',') }), ms);

        return () => clearInterval(timer);
    }, [active, key, ms]);
}
