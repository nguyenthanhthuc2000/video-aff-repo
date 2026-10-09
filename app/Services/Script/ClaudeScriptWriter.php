<?php

namespace App\Services\Script;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\VideoProject;
use App\Support\Media;
use Illuminate\Support\Facades\Log;

class ClaudeScriptWriter implements ScriptWriter
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
    Bạn là người viết kịch bản video affiliate ngắn (Shopee Video, dọc 9:16, 20–30 giây) cho sản phẩm thời trang.

    Viết bằng tiếng Việt tự nhiên, giọng người thật đang giới thiệu đồ mình thích, câu ngắn, dễ đọc thành lời.

    Đầu ra:
    - hooks: đúng 3 câu mở đầu khác nhau (mỗi câu dưới 12 từ), gây tò mò trong 2 giây đầu.
    - scenes: 5–7 cảnh, tổng thời lượng 20–30 giây. Mỗi cảnh có:
      - visual: mô tả khung hình bằng tiếng Việt.
      - on_screen_text: chữ trên màn hình, tối đa 8 từ.
      - voiceover: lời đọc cho cảnh, khớp thời lượng (khoảng 3 từ mỗi giây).
      - image_prompt: prompt tiếng Anh để AI chỉnh ảnh người mẫu mặc đúng sản phẩm trong ảnh tham chiếu. Giữ nguyên màu, hoạ tiết, kiểu dáng sản phẩm; ghi bối cảnh, góc máy, ánh sáng; khung dọc 9:16.
      - duration_ms: 2000–6000.
    - Cảnh cuối là kêu gọi mua: "xem màu, size, ưu đãi trong giỏ hàng".
    - caption: 1–2 câu cho bài đăng.
    - hashtags: 3–6 hashtag, có dấu #.

    Quy tắc:
    - Chỉ nói những gì có trong thông tin sản phẩm, ảnh và đánh giá. Không bịa chất liệu ("lụa cao cấp"), công dụng ("không lộ", "che bụng"), số liệu bán hàng hay giá cố định, giảm giá.
    - Không so sánh hạ thấp thương hiệu khác. Không dùng từ tuyệt đối ("nhất", "100%").
    - Nếu đánh giá có điểm chê (ví dụ form nhỏ), có thể khéo léo khuyên chọn size phù hợp, không giấu.
    PROMPT;

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function write(VideoProject $project): array
    {
        try {
            $message = $this->client->messages->create(
                maxTokens: $this->maxTokens,
                model: $this->model,
                system: [[
                    'type' => 'text',
                    'text' => self::SYSTEM_PROMPT,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                messages: [['role' => 'user', 'content' => $this->userContent($project)]],
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => ScriptSchema::schema()],
                ],
            );
        } catch (APIConnectionException $e) {
            throw ScriptWriterException::retryable('Không kết nối được Claude API.', $e);
        } catch (APIStatusException $e) {
            $retryable = $e->status === 429 || $e->status >= 500;
            $message = match (true) {
                $e->status === 401 => 'Khoá Claude API không hợp lệ (kiểm tra ANTHROPIC_API_KEY).',
                $e->status === 429 => 'Claude API đang giới hạn lượt gọi, thử lại sau.',
                $retryable => 'Claude API đang lỗi tạm thời, thử lại sau.',
                default => "Claude API từ chối yêu cầu (HTTP {$e->status}).",
            };

            throw $retryable ? ScriptWriterException::retryable($message, $e) : new ScriptWriterException($message, 0, $e);
        } catch (AnthropicException $e) {
            throw new ScriptWriterException('Gọi Claude API thất bại: '.$e->getMessage(), 0, $e);
        }

        Log::info('claude.script', [
            'project_id' => $project->id,
            'model' => $this->model,
            'stop_reason' => $message->stopReason,
            'input_tokens' => $message->usage->inputTokens ?? null,
            'output_tokens' => $message->usage->outputTokens ?? null,
        ]);

        if ($message->stopReason === 'refusal') {
            throw new ScriptWriterException('Claude từ chối viết kịch bản cho sản phẩm này.');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new ScriptWriterException('Kịch bản bị cắt giữa chừng (vượt giới hạn token).');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return ScriptSchema::normalize(json_decode($block->text, true));
            }
        }

        throw new ScriptWriterException('Claude không trả về nội dung kịch bản.');
    }

    private function userContent(VideoProject $project): array
    {
        $product = $project->product;
        $details = array_filter($product->details ?? []);

        $lines = ["Sản phẩm: {$product->name}"];
        if ($product->price) {
            $lines[] = 'Giá tham khảo (không ghi vào kịch bản): '.number_format($product->price, 0, ',', '.').'đ';
        }
        $labels = ['sizes' => 'Size', 'material' => 'Chất liệu', 'colors' => 'Màu', 'notes' => 'Ghi chú'];
        foreach ($labels as $key => $label) {
            if (! empty($details[$key])) {
                $lines[] = "{$label}: {$details[$key]}";
            }
        }
        if ($product->reviews) {
            $lines[] = "Đánh giá của người mua:\n{$product->reviews}";
        }
        $lines[] = 'Ảnh đính kèm là ảnh sản phẩm. Viết kịch bản theo đúng định dạng.';

        $content = [];
        foreach ($product->images()->orderByDesc('is_primary')->limit(3)->get() as $image) {
            $mime = Media::disk()->mimeType($image->path);
            $data = Media::disk()->get($image->path);
            if ($data === null || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                continue;
            }

            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'mediaType' => $mime,
                    'data' => base64_encode($data),
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => implode("\n", $lines)];

        return $content;
    }
}
