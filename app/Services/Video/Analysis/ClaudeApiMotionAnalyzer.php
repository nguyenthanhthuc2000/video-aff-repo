<?php

namespace App\Services\Video\Analysis;

use Anthropic\Client;
use App\Services\Claude\AiUsage;
use App\Support\ClaudeModels;
use RuntimeException;

/** Phân tích chuyển động bằng Claude API (ANTHROPIC_API_KEY). */
class ClaudeApiMotionAnalyzer implements MotionAnalyzer
{
    public function __construct(private readonly Client $client, private readonly string $model) {}

    public function analyze(array $frames, float $duration): array
    {
        $model = ClaudeModels::for('motion_analysis', $this->model);
        $content = [['type' => 'text', 'text' => MotionAnalysisPrompt::intro($duration, count($frames))]];
        foreach ($frames as $frame) {
            $content[] = ['type' => 'text', 'text' => sprintf('t=%.1fs', $frame['time'])];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($frame['contents'])]];
        }

        $message = AiUsage::api('motion_analysis', $model, fn () => $this->client->messages->create(
            maxTokens: 4096,
            model: $model,
            system: MotionAnalysisPrompt::SYSTEM,
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => MotionAnalysisPrompt::schema()]],
        ));

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return MotionAnalysisPrompt::normalize(json_decode($block->text, true));
            }
        }

        throw new RuntimeException('Claude không trả về phân tích.');
    }
}
