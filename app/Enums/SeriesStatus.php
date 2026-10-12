<?php

namespace App\Enums;

/** Trạng thái bộ phim: draft → planning (Claude viết bible) → bible_ready → designing (Muse vẽ nhân vật / bối cảnh) → ready. */
enum SeriesStatus: string
{
    case Draft = 'draft';
    case Planning = 'planning';
    case BibleReady = 'bible_ready';
    case Designing = 'designing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Planning => 'Đang lên kế hoạch',
            self::BibleReady => 'Đã có kế hoạch',
            self::Designing => 'Đang thiết kế nhân vật',
            self::Ready => 'Sẵn sàng làm tập',
            self::Failed => 'Lỗi',
        };
    }
}
