<?php

namespace App\Services\English;

use App\Models\EnglishLesson;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;

/** Claude sửa bài nói 30 giây (từ lời Whisper chép): tối đa 4 lỗi đáng sửa nhất, câu nói tự nhiên hơn, giải thích bằng tiếng Việt. */
final class SpeakingCoach
{
    public const MAX_CORRECTIONS = 4;

    public const SYSTEM = <<<'PROMPT'
    You are a kind English speaking coach for a Vietnamese adult learner (A2 going to B1). You get the automatic transcript of what the
    learner said for a speaking task (the transcript may contain recognition errors: ignore small ones that are clearly not the learner's).
    - Pick at most 4 of the most useful corrections: grammar, wrong word, unnatural phrasing. said = the learner's words (short excerpt),
      better = a natural version at B1 level, explain_vi = why, in Vietnamese, at most 20 words.
    - Use the learner profile to understand names and places they mention (do not "correct" their real name, hometown or job).
    - If the answer is already good, give fewer corrections and suggest a more natural or richer way to say one idea.
    - praise_vi: one encouraging sentence in Vietnamese about what was good. next_vi: one thing to practice next time (Vietnamese).
    PROMPT;

    /**
     * @return array{corrections: list<array{said: string, better: string, explain_vi: string}>, praise_vi: string, next_vi: string}
     *
     * @throws ScriptWriterException
     */
    public static function correct(EnglishLesson $lesson, string $transcript): array
    {
        $string = ['type' => 'string'];
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['corrections', 'praise_vi', 'next_vi'], 'properties' => [
            'praise_vi' => $string, 'next_vi' => $string,
            'corrections' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['said', 'better', 'explain_vi'],
                'properties' => ['said' => $string, 'better' => $string, 'explain_vi' => $string]]],
        ]];
        $text = LessonWriter::profile($lesson)."Speaking task: {$lesson->content['prompt']}\nLevel: {$lesson->level}. Grammar focus: ".Curriculum::day($lesson->day)['grammar']
            ."\n\nTranscript of what the learner said:\n{$transcript}";
        $data = FilmClaude::json(self::SYSTEM, $text, $schema, 'english_lesson', "english_lesson:{$lesson->id}", 2500, fn () => [
            'corrections' => [['said' => 'I am live in Hanoi', 'better' => 'I live in Hanoi', 'explain_vi' => 'Không dùng "am" trước động từ thường.']],
            'praise_vi' => 'Bạn nói rất tự tin!', 'next_vi' => 'Luyện thì hiện tại đơn.',
        ]);
        $text = fn ($v, int $max = 300) => mb_substr(trim((string) $v), 0, $max);

        return [
            'corrections' => collect($data['corrections'] ?? [])->take(self::MAX_CORRECTIONS)
                ->map(fn ($c) => ['said' => $text($c['said'] ?? ''), 'better' => $text($c['better'] ?? ''), 'explain_vi' => $text($c['explain_vi'] ?? '')])
                ->filter(fn ($c) => $c['better'] !== '')->values()->all(),
            'praise_vi' => $text($data['praise_vi'] ?? ''),
            'next_vi' => $text($data['next_vi'] ?? ''),
        ];
    }
}
