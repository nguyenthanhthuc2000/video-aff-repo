<?php

namespace App\Support;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sổ Credits: cộng / trừ và cập nhật số dư trong cùng một giao dịch DB (khoá dòng người dùng).
 * Mọi thao tác tính phí đều trừ, kể cả tài khoản admin. Chỉ trừ khi bước chạy thành công;
 * trước khi chạy thì kiểm tra số dư (ensure / shortfall).
 */
class Credits
{
    /** 1 Credit = 1đ (khớp resources/js/lib/pricing.ts). */
    public const VND_PER_CREDIT = CreditPricing::VND_PER_CREDIT;

    /**
     * @param  array{type: string, credits: int, amount_vnd?: ?int, package?: ?string, method?: ?string, action?: ?string, reference?: ?string, video_project_id?: ?int, description?: ?string, created_by?: ?int}  $data
     * @param  bool  $allowNegative  Trừ sau khi đã chạy xong (chi phí AI chỉ biết sau): cho phép âm nhẹ, bước sau sẽ bị chặn.
     */
    public static function record(User $user, array $data, bool $allowNegative = false): CreditTransaction
    {
        return DB::transaction(function () use ($user, $data, $allowNegative) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $balance = $locked->credit_balance + (int) $data['credits'];
            if ($balance < 0 && $data['type'] === 'usage' && ! $allowNegative) {
                throw new InsufficientCredits('Không đủ Credits.');
            }

            $locked->forceFill(['credit_balance' => $balance])->save();
            $user->credit_balance = $balance;

            return $locked->creditTransactions()->create([...$data, 'status' => 'completed', 'balance_after' => $balance]);
        });
    }

    /**
     * Trừ Credits cho một thao tác đã chạy xong. Cùng $reference chỉ trừ một lần. Không bao giờ làm hỏng việc chính.
     */
    public static function charge(?int $userId, int $credits, string $action, string $reference, string $description, ?int $projectId = null): void
    {
        if ($userId === null || $credits <= 0) {
            return;
        }
        $user = User::find($userId);
        if (! $user || $user->creditTransactions()->where('reference', $reference)->exists()) {
            return;
        }

        try {
            self::record($user, [
                'type' => 'usage',
                'credits' => -$credits,
                'action' => $action,
                'reference' => $reference,
                'video_project_id' => $projectId,
                'description' => mb_substr($description, 0, 500),
            ], allowNegative: true);
        } catch (QueryException) {
            // Trùng reference (hai job cùng lúc): đã trừ rồi.
        } catch (Throwable $e) {
            Log::error('credits.charge_failed', ['user_id' => $userId, 'reference' => $reference, 'error' => $e->getMessage()]);
        }
    }

    /** Thông báo khi không đủ Credits cho $needed, null nếu đủ. */
    public static function shortfall(?User $user, int $needed, string $what): ?string
    {
        $balance = (int) ($user?->fresh()?->credit_balance ?? 0);
        if ($needed <= 0 || $balance >= $needed) {
            return null;
        }

        return 'Không đủ Credits để '.$what.': cần khoảng '.self::format($needed).', số dư còn '.self::format($balance).'. Nạp thêm ở mục Nạp tiền.';
    }

    /** @throws InsufficientCredits */
    public static function ensure(?User $user, int $needed, string $what): void
    {
        if ($message = self::shortfall($user, $needed, $what)) {
            throw new InsufficientCredits($message);
        }
    }

    /** Tặng Credits khi đăng ký (số lượng do admin đặt ở Giá bán Credits). */
    public static function grantSignupBonus(User $user): void
    {
        $bonus = CreditPricing::settings()['signup_bonus'];
        if ($bonus > 0) {
            self::record($user, ['type' => 'bonus', 'credits' => $bonus, 'reference' => 'signup', 'description' => 'Tặng khi đăng ký']);
        }
    }

    public static function format(int $credits): string
    {
        return number_format($credits, 0, ',', '.').' Credits';
    }
}
