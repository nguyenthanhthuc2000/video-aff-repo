<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Support\CreditPricing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Nạp tiền: gói nạp, bảng Credits, số dư và lịch sử giao dịch của người dùng.
 * Thanh toán online chưa có; trước mắt admin nạp thủ công ở trang chi tiết người dùng.
 */
class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('billing/index', [
            'selectedPackage' => $request->query('package'),
            'balance' => $user->credit_balance,
            'creditRates' => CreditPricing::publicRates(),
            'transactions' => $user->creditTransactions()->latest('id')->limit(10)->get()
                ->map(fn (CreditTransaction $row) => [
                    'id' => $row->id,
                    'type_label' => CreditTransaction::TYPES[$row->type] ?? $row->type,
                    'credits' => $row->credits,
                    'amount_vnd' => $row->amount_vnd,
                    // Ghi chú nội bộ của admin không hiện cho người dùng.
                    'created_at' => $row->created_at,
                ]),
        ]);
    }
}
