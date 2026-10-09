<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Truy cập disk lưu media của ứng dụng (config videoaff.disk). */
class Media
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('videoaff.disk'));
    }

    public static function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, config('videoaff.disk'));
    }

    public static function url(?string $path): ?string
    {
        return $path === null ? null : self::disk()->url($path);
    }

    public static function delete(?string $path): void
    {
        if ($path !== null) {
            self::disk()->delete($path);
        }
    }
}
