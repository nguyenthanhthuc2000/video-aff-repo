<?php

namespace App\Http\Controllers;

use App\Enums\RenderStatus;
use App\Jobs\PublishFacebookReelJob;
use App\Models\VideoProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Đăng video đã xuất của dự án lên Trang Facebook đã kết nối. */
class SocialPostController extends Controller
{
    /** Reels trên Facebook dài 3–90 giây. */
    public const MAX_SECONDS = 90;

    public function store(Request $request, VideoProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', Rule::exists('social_accounts', 'id')->where('user_id', $request->user()->id)],
            'caption' => ['required', 'string', 'max:2200'],
        ]);

        $render = $project->renders()->where('status', RenderStatus::Done)->whereNotNull('path')->latest('id')->first();
        if ($render === null) {
            return back()->with('status', 'Cần xuất video trước khi đăng.');
        }
        if ($render->duration_ms > self::MAX_SECONDS * 1000) {
            return back()->with('status', 'Reels Facebook dài tối đa 90 giây.');
        }

        $accounts = $request->user()->socialAccounts()->whereIn('id', $validated['account_ids'])->get();
        foreach ($accounts as $account) {
            $post = $project->socialPosts()->create([
                'render_id' => $render->id,
                'social_account_id' => $account->id,
                'provider' => $account->provider,
                'account_name' => $account->name,
                'caption' => $validated['caption'],
            ]);
            PublishFacebookReelJob::dispatch($post);
        }

        return back()->with('status', 'Đang đăng video lên '.$accounts->pluck('name')->implode(', ').'...');
    }
}
