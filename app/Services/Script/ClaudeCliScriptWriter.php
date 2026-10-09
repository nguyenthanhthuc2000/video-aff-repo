<?php

namespace App\Services\Script;

use App\Models\VideoProject;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeCliException;
use App\Support\Media;

/** Viết kịch bản bằng Claude Code CLI (gói Claude Pro/Max), không cần API key. */
class ClaudeCliScriptWriter implements ScriptWriter
{
    public function __construct(private readonly ClaudeCli $cli) {}

    public function write(VideoProject $project): array
    {
        $product = $project->product;

        $content = [];
        foreach ($product->images()->orderByDesc('is_primary')->limit(3)->get() as $image) {
            $block = ClaudeCli::imageBlock(Media::disk()->get($image->path), Media::disk()->mimeType($image->path) ?: null);
            if ($block) {
                $content[] = $block;
            }
        }
        $content[] = ['type' => 'text', 'text' => ScriptPrompt::productInfo($product)."\nẢnh đính kèm là ảnh sản phẩm. Viết kịch bản theo đúng định dạng."];

        try {
            $script = $this->cli->json(ScriptPrompt::SYSTEM, $content, ScriptSchema::schema(), "script:{$project->id}");
        } catch (ClaudeCliException $e) {
            throw $e->retryable ? ScriptWriterException::retryable($e->getMessage(), $e) : new ScriptWriterException($e->getMessage(), 0, $e);
        }

        return ScriptSchema::normalize($script);
    }
}
