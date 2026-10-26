<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/** Nhạc nền miễn phí bản quyền có sẵn trong public/nhac: ai cũng chọn được khi dựng video, không cần tải lên. */
final class BuiltinMusic
{
    public const EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

    public static function dir(): string
    {
        return public_path('nhac');
    }

    /** @return list<array{file: string, name: string}> */
    public static function all(): array
    {
        if (! is_dir(self::dir())) {
            return [];
        }

        return collect(File::files(self::dir()))
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), self::EXTENSIONS, true))
            ->sortBy(fn ($file) => $file->getFilename())
            ->map(fn ($file) => ['file' => $file->getFilename(), 'name' => trim(preg_replace('/[-_]+/', ' ', pathinfo($file->getFilename(), PATHINFO_FILENAME)))])
            ->values()->all();
    }

    /** Đường dẫn file nhạc có sẵn (chỉ nhận đúng tên file trong thư mục, không nhận đường dẫn khác). */
    public static function path(?string $file): ?string
    {
        return $file !== null && in_array($file, array_column(self::all(), 'file'), true) ? self::dir().'/'.$file : null;
    }
}
