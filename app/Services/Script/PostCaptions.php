<?php

namespace App\Services\Script;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Models\VideoProject;
use App\Services\Claude\AiUsage;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeCliException;
use App\Support\ClaudeModels;
use App\Support\PromoText;
use App\Support\ScriptDriver;

/**
 * Đề xuất caption đăng video (TikTok / Shopee Video): 3 bản bán hàng (chốt đơn, dẫn về giỏ hàng)
 * và 3 bản câu view (gây tò mò, kéo bình luận / chia sẻ). Claude viết theo sản phẩm và kịch bản đã có.
 */
final class PostCaptions
{
    public const COUNT = 3;

    public const SYSTEM = <<<'PROMPT'
    Bạn viết caption đăng video affiliate thời trang ngắn (TikTok / Shopee Video) bằng tiếng Việt, giọng con gái thật đang khoe đồ với bạn bè.

    Viết 2 nhóm, mỗi nhóm đúng 3 caption khác kiểu nhau:
    - sale (đăng bán hàng): dòng đầu là câu mở gây chú ý; 2–3 dòng ngắn nêu điểm đáng mua có thật trong thông tin / đánh giá sản phẩm
      (form, chất vải, màu, mặc đi đâu, khuyên chọn size), có thể mở dòng bằng emoji; dòng cuối kêu gọi xem màu, size trong giỏ hàng
      (sản phẩm không có size: "xem chi tiết trong giỏ hàng"). Ba bản theo 3 hướng: cảm xúc khi mặc, chi tiết đáng tiền, dịp mặc / cách phối.
    - view (câu view, tăng tương tác): ngắn 1–2 dòng, gây tò mò hoặc hỏi người xem để họ bình luận, xem hết, chia sẻ, ví dụ cho chọn
      ("màu này hay màu kia?"), chấm điểm ("outfit này mấy điểm?"), tag bạn bè ("tag đứa bạn hợp mặc váy này"), câu chuyện đời thường
      (cảm xúc khi mặc, ví dụ "mặc lên tự nhiên muốn đi cà phê liền"), đố vui ("đoán xem váy này mặc đi đâu hợp nhất?"), xem đến cuối.
      Không bịa trải nghiệm hay con số (bị hỏi link mấy lần, bán bao nhiêu). Không bán thẳng, không nhắc giỏ hàng.
    - Mỗi caption kết thúc bằng 3–5 hashtag (có dấu #) hợp nội dung: hashtag sản phẩm / phong cách, kèm hashtag phổ biến như #outfit #ootd #review.

    Quy tắc:
    - Chỉ nói điều có trong thông tin sản phẩm, ảnh và đánh giá; không bịa chất liệu, công dụng, số liệu bán hàng.
    - KHÔNG nhắc giá, ưu đãi, khuyến mãi, giảm giá, voucher, sale, deal, freeship.
    - Không từ tuyệt đối ("nhất", "100%"), không hạ thấp thương hiệu khác, không gợi cảm hay nói về cơ thể.
    - Mỗi caption dưới 300 ký tự (kể cả hashtag), xuống dòng bằng \n.
    PROMPT;

    public static function schema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['sale', 'view'],
            'properties' => ['sale' => $list, 'view' => $list],
        ];
    }

    /** Thông tin sản phẩm và nội dung video để caption khớp với video. */
    public static function userText(VideoProject $project): string
    {
        $script = $project->script ?? [];
        $hooks = array_values($script['hooks'] ?? []);
        $hook = $hooks[$project->selected_hook ?? 0] ?? $hooks[0] ?? '';
        $lines = $project->scenes()->orderBy('position')->get()
            ->map(fn ($scene) => '- '.trim(($scene->on_screen_text ? "{$scene->on_screen_text}: " : '').$scene->voiceover))
            ->implode("\n");

        return ScriptPrompt::productInfo($project->product)."\n\n"
            .($hook !== '' ? "Hook mở video: {$hook}\n" : '')
            .($lines !== '' ? "Nội dung các cảnh (chữ trên màn: lời):\n{$lines}\n" : '')
            ."\nViết caption đăng cho video này theo đúng định dạng.";
    }

    /** @return array{sale: list<string>, view: list<string>} */
    public static function normalize(mixed $data): array
    {
        if (! is_array($data) || empty($data['sale']) || empty($data['view'])) {
            throw new ScriptWriterException('Claude không trả về đề xuất caption.');
        }
        $clean = fn ($list) => array_values(array_filter(array_map(
            fn ($text) => mb_substr(trim(PromoText::strip((string) $text)), 0, 600),
            array_slice((array) $list, 0, self::COUNT),
        ), 'filled'));

        return ['sale' => $clean($data['sale']), 'view' => $clean($data['view'])];
    }

    /**
     * Viết đề xuất bằng Claude đang dùng cho kịch bản (CLI / API); chưa kết nối Claude thì dùng mẫu sẵn.
     *
     * @return array{sale: list<string>, view: list<string>}
     *
     * @throws ScriptWriterException
     */
    public static function write(VideoProject $project): array
    {
        $content = [['type' => 'text', 'text' => self::userText($project)]];

        return match (ScriptDriver::current()) {
            'claude_cli' => self::viaCli($project, $content),
            'claude' => self::viaApi($content),
            default => self::sample($project),
        };
    }

    private static function viaCli(VideoProject $project, array $content): array
    {
        try {
            // Tính chung vào thao tác viết kịch bản (cùng model, cùng dòng chi phí).
            return self::normalize(app(ClaudeCli::class)->json(self::SYSTEM, $content, self::schema(), "script:captions:{$project->id}"));
        } catch (ClaudeCliException $e) {
            throw $e->retryable ? ScriptWriterException::retryable($e->getMessage(), $e) : new ScriptWriterException($e->getMessage(), 0, $e);
        }
    }

    private static function viaApi(array $content): array
    {
        $model = ClaudeModels::for('script', config('videoaff.claude.model'));
        try {
            $message = AiUsage::api('script', $model, fn () => (new Client(apiKey: config('videoaff.claude.api_key')))->messages->create(
                maxTokens: 2000,
                model: $model,
                system: [['type' => 'text', 'text' => self::SYSTEM]],
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            ));
        } catch (AnthropicException $e) {
            throw ScriptWriterException::retryable('Gọi Claude API thất bại: '.$e->getMessage(), $e);
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return self::normalize(json_decode($block->text, true));
            }
        }

        throw new ScriptWriterException('Claude không trả về đề xuất caption.');
    }

    /** Mẫu khi chưa kết nối Claude (và trong test). */
    public static function sample(VideoProject $project): array
    {
        $name = $project->product->name;
        $cta = PromoText::callToAction($project->product);

        return [
            'sale' => [
                "Mặc {$name} lên là thấy thương liền 💙\n✨ Form dễ mặc, đi đâu cũng hợp\n👉 {$cta} nha\n#outfit #ootd #review",
                "Chi tiết nhỏ mà xinh xỉu của {$name}\n✨ Nhìn kỹ là mê\n👉 {$cta}\n#thoitrang #review #ootd",
                "Đi chơi, đi cà phê đều hợp với {$name}\n👉 {$cta} nha\n#outfit #phoido #review",
            ],
            'view' => [
                "Outfit này mọi người chấm mấy điểm? 👀\n#outfit #ootd #review",
                "Tag ngay đứa bạn hợp mặc mẫu này 💙\n#outfit #thoitrang #ootd",
                "Xem đến cuối để thấy dáng xoay nha ✨\n#outfit #review #ootd",
            ],
        ];
    }
}
