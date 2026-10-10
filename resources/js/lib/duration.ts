/** Hiển thị thời lượng ngắn gọn: 45s, 3p 20s, 1g 05p. */
export function formatDuration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '—';
    const seconds = Math.round(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}p ${String(seconds % 60).padStart(2, '0')}s`;
    return `${Math.floor(minutes / 60)}g ${String(minutes % 60).padStart(2, '0')}p`;
}
