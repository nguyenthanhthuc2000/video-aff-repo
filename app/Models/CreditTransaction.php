<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một dòng trong sổ Credits của người dùng. Tạo bằng App\Support\Credits để số dư luôn khớp. */
class CreditTransaction extends Model
{
    use HasFactory;

    public const TYPES = [
        'topup' => 'Nạp tiền',
        'usage' => 'Dùng Credits',
        'refund' => 'Hoàn Credits',
        'bonus' => 'Tặng Credits',
        'adjustment' => 'Admin điều chỉnh',
    ];

    public const METHODS = [
        'bank' => 'Chuyển khoản / VietQR',
        'momo' => 'Ví MoMo',
        'zalopay' => 'ZaloPay',
        'card' => 'Thẻ ATM / Visa / Master',
        'manual' => 'Admin nạp thủ công',
    ];

    protected $fillable = [
        'user_id', 'type', 'status', 'credits', 'balance_after', 'amount_vnd', 'package', 'method',
        'action', 'reference', 'video_project_id', 'description', 'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
