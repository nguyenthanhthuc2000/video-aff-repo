<?php

namespace App\Services\Image\Prompt;

use App\Models\Scene;
use App\Services\Claude\ClaudeCli;

/** Viết lại prompt bằng Claude Code CLI (gói Claude Pro/Max). */
class ClaudeCliScenePromptRewriter implements ScenePromptRewriter
{
    public function __construct(private readonly ClaudeCli $cli) {}

    public function rewrite(Scene $scene, string $reason): string
    {
        $result = $this->cli->json(
            RewritePrompt::SYSTEM,
            [['type' => 'text', 'text' => RewritePrompt::userText($scene, $reason)]],
            RewritePrompt::schema(),
            'scene_prompt_rewrite',
        );

        return RewritePrompt::clean($result['image_prompt'] ?? null);
    }
}
