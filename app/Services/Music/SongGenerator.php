<?php

namespace App\Services\Music;

use RuntimeException;

/**
 * Tạo bài hát hoàn chỉnh (có giọng hát) từ lời + prompt phong cách. Không bao giờ gửi nhạc tham khảo hay tên ca sĩ / bài tham khảo.
 * Gửi rồi hỏi lại theo mã.
 */
interface SongGenerator
{
    /** @throws RuntimeException */
    public function start(string $lyrics, string $style, int $seconds): string;

    /**
     * @return array{contents: string, extension: string}|null null nếu chưa xong
     *
     * @throws RuntimeException
     */
    public function poll(string $id): ?array;
}
