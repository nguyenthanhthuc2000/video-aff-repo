<?php

namespace App\Services\Image\Prompt;

use Anthropic\Client;
use App\Models\Scene;

/** Viết lại prompt bằng Claude API (ANTHROPIC_API_KEY). */
class ClaudeApiScenePromptRewriter implements ScenePromptRewriter
{
    public function __construct(private readonly Client $client, private readonly string $model) {}

    public function rewrite(Scene $scene, string $reason): string
    {
        $message = $this->client->messages->create(
            maxTokens: 1024,
            model: $this->model,
            system: RewritePrompt::SYSTEM,
            messages: [['role' => 'user', 'content' => RewritePrompt::userText($scene, $reason)]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => RewritePrompt::schema()]],
        );

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return RewritePrompt::clean(json_decode($block->text, true)['image_prompt'] ?? null);
            }
        }

        throw new \RuntimeException('Claude không trả prompt mới.');
    }
}
