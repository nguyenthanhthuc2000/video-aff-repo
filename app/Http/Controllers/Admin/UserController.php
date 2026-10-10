<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RenderStatus;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Support\Credits;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Quản lý người dùng: tìm kiếm, cấp / bỏ quyền admin, khoá / mở khoá; trang chi tiết (Credits, video, sản phẩm). */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q'));
        $filter = $request->query('filter');

        $users = User::query()
            ->withCount(['products', 'videoProjects'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($filter === 'admin', fn ($query) => $query->where('is_admin', true))
            ->when($filter === 'blocked', fn ($query) => $query->whereNotNull('blocked_at'))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'blocked_at' => $user->blocked_at,
                'products_count' => $user->products_count,
                'video_projects_count' => $user->video_projects_count,
                'credit_balance' => $user->credit_balance,
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('admin/users', [
            'users' => $users,
            'filters' => ['q' => $search, 'filter' => $filter],
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $tab = in_array($request->query('tab'), ['topups', 'usage', 'videos', 'products'], true) ? $request->query('tab') : 'topups';
        $topups = $user->creditTransactions()->where('type', 'topup')->where('status', 'completed');
        $used = (int) -$user->creditTransactions()->where('type', 'usage')->sum('credits');
        $refunded = (int) $user->creditTransactions()->where('type', 'refund')->sum('credits');
        $byStatus = $user->videoProjects()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $transaction = fn (CreditTransaction $row) => [
            'id' => $row->id,
            'type' => $row->type,
            'type_label' => CreditTransaction::TYPES[$row->type] ?? $row->type,
            'status' => $row->status,
            'credits' => $row->credits,
            'balance_after' => $row->balance_after,
            'amount_vnd' => $row->amount_vnd,
            'package' => $row->package,
            'method' => $row->method ? (CreditTransaction::METHODS[$row->method] ?? $row->method) : null,
            'action' => $row->action,
            'project' => $row->video_project_id ? ['id' => $row->video_project_id, 'product' => $row->videoProject?->product?->name] : null,
            'description' => $row->description,
            'creator' => $row->creator?->email,
            'created_at' => $row->created_at,
        ];

        // Chỉ tải dữ liệu của tab đang mở; mỗi tab phân trang riêng.
        $list = match ($tab) {
            'topups' => $user->creditTransactions()->with('creator:id,email')->whereIn('type', ['topup', 'adjustment'])
                ->latest('id')->paginate(15)->withQueryString()->through($transaction),
            'usage' => $user->creditTransactions()->with(['videoProject.product:id,name', 'creator:id,email'])->whereIn('type', ['usage', 'refund'])
                ->latest('id')->paginate(15)->withQueryString()->through($transaction),
            'videos' => $user->videoProjects()->with(['product:id,name', 'product.primaryImage', 'latestDoneRender'])
                ->withSum('aiUsageLogs as ai_cost_usd', 'cost_usd')->withCount(['renders' => fn ($q) => $q->where('status', RenderStatus::Done)])
                ->latest()->paginate(12)->withQueryString()
                ->through(fn (VideoProject $project) => [
                    'id' => $project->id,
                    'product' => $project->product?->name,
                    'image_url' => Media::url($project->product?->primaryImage?->path),
                    'status' => $project->status->value,
                    'video_type' => $project->video_type->value,
                    'progress' => $project->progress(),
                    'error' => $project->error,
                    'video_url' => $project->latestDoneRender ? Media::url($project->latestDoneRender->path) : null,
                    'renders_count' => $project->renders_count,
                    'ai_cost_usd' => (float) $project->ai_cost_usd,
                    'created_at' => $project->created_at,
                ]),
            'products' => $user->products()->with('primaryImage')->withCount('videoProjects')->latest()->paginate(12)->withQueryString()
                ->through(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'shopee_url' => $product->shopee_url,
                    'image_url' => Media::url($product->primaryImage?->path),
                    'video_projects_count' => $product->video_projects_count,
                    'created_at' => $product->created_at,
                ]),
        };

        return Inertia::render('admin/user-show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'blocked_at' => $user->blocked_at,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'credit_balance' => $user->credit_balance,
            ],
            'stats' => [
                'topup_vnd' => (int) (clone $topups)->sum('amount_vnd'),
                'topup_credits' => (int) (clone $topups)->sum('credits'),
                'topup_count' => (clone $topups)->count(),
                'last_topup_at' => (clone $topups)->max('created_at'),
                'used_credits' => $used,
                'refunded_credits' => $refunded,
                'adjusted_credits' => (int) $user->creditTransactions()->where('type', 'adjustment')->sum('credits'),
                'products' => $user->products()->count(),
                'projects' => $user->videoProjects()->count(),
                'projects_by_status' => $byStatus,
                'renders_done' => $user->videoProjects()->join('renders', 'renders.video_project_id', '=', 'video_projects.id')
                    ->where('renders.status', RenderStatus::Done)->count(),
                'ai_cost_usd' => (float) $user->aiUsageLogs()->sum('cost_usd'),
                'ai_calls' => $user->aiUsageLogs()->count(),
            ],
            'tab' => $tab,
            'list' => $list,
            'usdVnd' => AiUsage::settings()['usd_vnd'],
            'methods' => CreditTransaction::METHODS,
        ]);
    }

    /** Admin nạp thủ công (đã nhận tiền ngoài hệ thống) hoặc cộng / trừ Credits điều chỉnh. */
    public function storeCredits(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:topup,adjustment'],
            'credits' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'amount_vnd' => ['nullable', 'required_if:type,topup', 'integer', 'min:0', 'max:1000000000'],
            'method' => ['nullable', 'in:'.implode(',', array_keys(CreditTransaction::METHODS))],
            'description' => ['nullable', 'required_if:type,adjustment', 'string', 'max:500'],
        ], [
            'credits.not_in' => 'Số Credits phải khác 0.',
            'amount_vnd.required_if' => 'Nhập số tiền đã nhận.',
            'description.required_if' => 'Ghi lý do điều chỉnh.',
        ], ['credits' => 'Credits', 'amount_vnd' => 'số tiền', 'description' => 'ghi chú']);

        if ($validated['type'] === 'topup' && $validated['credits'] < 0) {
            return back()->withErrors(['credits' => 'Nạp tiền phải cộng Credits (số dương).']);
        }
        if ($user->credit_balance + $validated['credits'] < 0) {
            return back()->withErrors(['credits' => "Số dư hiện tại {$user->credit_balance} Credits, không trừ quá số dư."]);
        }

        Credits::record($user, [
            'type' => $validated['type'],
            'credits' => (int) $validated['credits'],
            'amount_vnd' => $validated['type'] === 'topup' ? (int) $validated['amount_vnd'] : null,
            'method' => $validated['type'] === 'topup' ? ($validated['method'] ?? 'manual') : null,
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', ($validated['credits'] > 0 ? 'Đã cộng ' : 'Đã trừ ').number_format(abs($validated['credits']), 0, ',', '.')." Credits cho {$user->email}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'is_admin' => ['sometimes', 'boolean'],
            'blocked' => ['sometimes', 'boolean'],
        ]);

        // Không tự bỏ quyền / tự khoá chính mình (tránh mất quyền quản trị).
        if ($user->is($request->user())) {
            return back()->with('status', 'Không thể tự bỏ quyền admin hoặc tự khoá tài khoản của mình.');
        }

        if (array_key_exists('is_admin', $validated)) {
            $user->is_admin = $validated['is_admin'];
        }
        if (array_key_exists('blocked', $validated)) {
            $user->blocked_at = $validated['blocked'] ? now() : null;
        }
        $user->save();

        return back()->with('status', "Đã cập nhật {$user->email}.");
    }
}
