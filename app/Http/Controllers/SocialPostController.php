<?php

namespace App\Http\Controllers;

use App\Enums\RenderStatus;
use App\Jobs\PublishFacebookReelJob;
use App\Jobs\PublishTikTokJob;
use App\Models\SocialAccount;
use App\Models\VideoProject;
use App\Services\Social\SocialPostException;
use App\Services\Social\TikTokClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Đăng video đã xuất của dự án lên Trang Facebook / tài khoản TikTok đã kết nối. */
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
            'tiktok' => ['nullable', 'array'],
            'tiktok.mode' => ['nullable', Rule::in(['direct', 'inbox'])],
            'tiktok.privacy_level' => ['nullable', 'string', 'max:40'],
            'tiktok.allow_comment' => ['boolean'],
            'tiktok.allow_duet' => ['boolean'],
            'tiktok.allow_stitch' => ['boolean'],
            'tiktok.brand_organic' => ['boolean'],
            'tiktok.brand_content' => ['boolean'],
        ]);

        $render = $project->renders()->where('status', RenderStatus::Done)->whereNotNull('path')->latest('id')->first();
        if ($render === null) {
            return back()->with('status', 'Cần xuất video trước khi đăng.');
        }

        $accounts = $request->user()->socialAccounts()->whereIn('id', $validated['account_ids'])->get();
        $tiktok = $this->tiktokOptions($validated['tiktok'] ?? []);
        if ($accounts->contains('provider', 'tiktok') && $tiktok['mode'] === 'direct' && blank($tiktok['privacy_level'])) {
            throw ValidationException::withMessages(['tiktok.privacy_level' => 'Chọn ai được xem video trên TikTok.']);
        }
        if ($accounts->contains('provider', 'tiktok') && $tiktok['mode'] === 'direct' && $tiktok['brand_content'] && $tiktok['privacy_level'] === 'SELF_ONLY') {
            throw ValidationException::withMessages(['tiktok.privacy_level' => 'Nội dung có thương hiệu không đăng ở chế độ "Chỉ mình tôi" được.']);
        }
        if ($accounts->contains('provider', 'facebook') && $render->duration_ms > self::MAX_SECONDS * 1000) {
            return back()->with('status', 'Reels Facebook dài tối đa 90 giây.');
        }

        foreach ($accounts as $account) {
            $post = $project->socialPosts()->create([
                'render_id' => $render->id,
                'social_account_id' => $account->id,
                'provider' => $account->provider,
                'account_name' => $account->name,
                'caption' => $validated['caption'],
                'options' => $account->provider === 'tiktok' ? $tiktok : null,
            ]);
            $account->provider === 'tiktok' ? PublishTikTokJob::dispatch($post) : PublishFacebookReelJob::dispatch($post);
        }

        return back()->with('status', 'Đang đăng video lên '.$accounts->pluck('name')->implode(', ').'...');
    }

    /** Thông tin người đăng TikTok (quyền xem cho chọn, độ dài tối đa...) để hiện trên form trước khi đăng thẳng. */
    public function creator(Request $request, SocialAccount $account, TikTokClient $tiktok): JsonResponse
    {
        abort_unless($account->user_id === $request->user()->id && $account->provider === 'tiktok', 404);

        try {
            return response()->json($tiktok->creatorInfo($tiktok->accessToken($account)));
        } catch (SocialPostException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** @return array{mode: string, privacy_level: ?string, allow_comment: bool, allow_duet: bool, allow_stitch: bool, brand_organic: bool, brand_content: bool} */
    private function tiktokOptions(array $input): array
    {
        return [
            'mode' => $input['mode'] ?? 'inbox',
            'privacy_level' => $input['privacy_level'] ?? null,
            'allow_comment' => (bool) ($input['allow_comment'] ?? false),
            'allow_duet' => (bool) ($input['allow_duet'] ?? false),
            'allow_stitch' => (bool) ($input['allow_stitch'] ?? false),
            'brand_organic' => (bool) ($input['brand_organic'] ?? false),
            'brand_content' => (bool) ($input['brand_content'] ?? false),
        ];
    }
}
