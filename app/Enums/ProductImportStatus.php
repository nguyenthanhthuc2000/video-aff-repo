<?php

namespace App\Enums;

/** Trạng thái lấy sản phẩm từ trang Shopee (bookmarklet) chạy ngầm. */
enum ProductImportStatus: string
{
    case Importing = 'importing';
    /** Chế độ Claude bản web: ảnh đã tải xong, chờ dán câu trả lời từ claude.ai. */
    case AwaitingReply = 'awaiting_reply';
    case Done = 'done';
    case Failed = 'failed';
}
