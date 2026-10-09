<?php

namespace App\Services\Script;

use App\Models\VideoProject;

interface ScriptWriter
{
    /**
     * Viết kịch bản cho dự án.
     *
     * @return array{hooks: list<string>, scenes: list<array{visual: string, on_screen_text: string, voiceover: string, image_prompt: string, duration_ms: int}>, caption: string, hashtags: list<string>}
     *
     * @throws ScriptWriterException
     */
    public function write(VideoProject $project): array;
}
