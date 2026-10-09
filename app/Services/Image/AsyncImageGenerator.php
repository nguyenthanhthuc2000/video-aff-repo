<?php

namespace App\Services\Image;

/** Tạo ảnh kiểu gửi rồi hỏi lại: job không giữ hàng đợi trong lúc chờ, nhiều ảnh chạy song song. */
interface AsyncImageGenerator
{
    /**
     * Gửi yêu cầu, trả mã yêu cầu.
     *
     * @param  list<string>  $referencePaths
     *
     * @throws ImageGeneratorException
     */
    public function start(array $referencePaths, string $prompt): string;

    /**
     * Kết quả của yêu cầu; null nếu chưa xong.
     *
     * @param  list<string>  $referencePaths  Để chặn trường hợp trả nhầm ảnh tham chiếu.
     *
     * @throws ImageGeneratorException
     */
    public function poll(string $code, array $referencePaths): ?GeneratedImage;
}
