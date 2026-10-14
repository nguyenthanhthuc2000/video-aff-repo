<?php

namespace App\Console\Commands;

use App\Services\Film\SoundLibrary;
use Illuminate\Console\Command;

/** Dựng kho âm thanh hiệu ứng miễn phí cho phim (gói CC0 của Kenney + hiệu ứng hoạt hình tự tạo + tiếng nhân vật CC0 của BigSoundBank). */
class InstallSoundLibrary extends Command
{
    protected $signature = 'film:sound-library {--vocals : Chỉ thêm / tải lại tiếng nhân vật (cười, khóc, tiếng kêu của thú)}';

    protected $description = 'Tải và dựng kho âm thanh hiệu ứng cho phim hoạt hình (CC0 + tự tạo)';

    public function handle(): int
    {
        $count = SoundLibrary::install(fn (string $line) => $this->line($line), (bool) $this->option('vocals'));
        $this->info("Xong: {$count} mục.");

        return self::SUCCESS;
    }
}
