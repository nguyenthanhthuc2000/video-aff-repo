import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export default function FlashStatus() {
    const { flash } = usePage<SharedData & { flash: { status?: string | null } }>().props;

    if (!flash?.status) {
        return null;
    }

    return (
        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {flash.status}
        </div>
    );
}
