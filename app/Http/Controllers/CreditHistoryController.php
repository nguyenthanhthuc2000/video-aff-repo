<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Support\ProjectCredits;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Menu Lịch sử Credits: người dùng tự xem mọi lần cộng / trừ Credits của mình. */
class CreditHistoryController extends Controller
{
    private const PERIODS = ['7d' => 6, '30d' => 29, '90d' => 89, 'all' => null];

    /** Tên thao tác hiển thị cho người dùng (dòng trừ Credits). */
    private const ACTIONS = [
        ...ProjectCredits::LABELS,
        'image' => 'Tạo ảnh cảnh',
        'clip' => 'Tạo clip chuyển động',
        'render' => 'Xuất video',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $type = array_key_exists((string) $request->query('type'), CreditTransaction::TYPES) ? (string) $request->query('type') : null;
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'all';
        $from = self::PERIODS[$period] === null ? null : now()->subDays(self::PERIODS[$period])->startOfDay();

        $base = fn () => $user->creditTransactions()->where('status', 'completed')->when($from, fn ($query) => $query->where('created_at', '>=', $from));

        $transactions = $base()->with('videoProject.product:id,name')
            ->when($type, fn ($query) => $query->where('type', $type))
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (CreditTransaction $row) => [
                'id' => $row->id,
                'type' => $row->type,
                'type_label' => self::types()[$row->type] ?? $row->type,
                'title' => $this->title($row),
                'detail' => $this->detail($row),
                'project' => $row->video_project_id ? ['id' => $row->video_project_id, 'product' => $row->videoProject?->product?->name] : null,
                'credits' => $row->credits,
                'balance_after' => $row->balance_after,
                'amount_vnd' => $row->type === 'topup' ? $row->amount_vnd : null,
                'created_at' => $row->created_at,
            ]);

        $sum = fn (string $type) => (int) $base()->where('type', $type)->sum('credits');

        return Inertia::render('credits/index', [
            'balance' => $user->credit_balance,
            'summary' => [
                'topup' => $sum('topup'),
                'bonus' => $sum('bonus') + max(0, $sum('adjustment')),
                'used' => -$sum('usage'),
                'refund' => $sum('refund'),
                'adjustment' => $sum('adjustment'),
            ],
            'transactions' => $transactions,
            'filters' => ['type' => $type, 'period' => $period],
            'types' => self::types(),
        ]);
    }

    /** Tên loại giao dịch cho khách ("Admin điều chỉnh" → "Điều chỉnh"). */
    private static function types(): array
    {
        return [...CreditTransaction::TYPES, 'adjustment' => 'Điều chỉnh'];
    }

    private function title(CreditTransaction $row): string
    {
        return match ($row->type) {
            'usage', 'refund' => ($row->type === 'refund' ? 'Hoàn: ' : '').(self::ACTIONS[$row->action] ?? 'Thao tác tính phí'),
            'topup' => 'Nạp tiền',
            'bonus' => $row->description ?: 'Tặng Credits',
            'adjustment' => $row->credits >= 0 ? 'Quản trị viên cộng Credits' : 'Quản trị viên trừ Credits',
            default => CreditTransaction::TYPES[$row->type] ?? $row->type,
        };
    }

    /** Chi tiết phụ: phương thức nạp, cảnh nào... Ghi chú nội bộ của admin (nạp tay, điều chỉnh) không hiện. */
    private function detail(?CreditTransaction $row): ?string
    {
        return match ($row?->type) {
            // Nạp tay qua quản trị viên: không ghi "Admin nạp thủ công" cho khách.
            'topup' => match ($row->method) {
                null => null,
                'manual' => 'Chuyển khoản',
                default => CreditTransaction::METHODS[$row->method] ?? $row->method,
            },
            // Cảnh nào, sản phẩm / video mẫu nào (mô tả do hệ thống ghi khi trừ).
            'usage', 'refund' => in_array($row->action, ['image', 'clip', 'product_import', 'motion_analysis'], true) ? $row->description : null,
            default => null,
        };
    }
}
