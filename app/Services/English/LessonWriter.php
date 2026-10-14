<?php

namespace App\Services\English;

use App\Models\EnglishLesson;
use App\Services\Film\FilmClaude;
use App\Services\Script\ScriptWriterException;

/**
 * Claude soạn bài của ngày theo lộ trình (Curriculum): 5 câu nói thông dụng đúng trình độ, hoặc đề nói 30 giây kèm cụm từ gợi ý;
 * kèm tiêu đề / mô tả / hashtag để đăng Shorts. Không lặp câu của các bài trước.
 */
final class LessonWriter
{
    public const SYSTEM = <<<'PROMPT'
    You are an English speaking coach for a Vietnamese adult learner (level A2, goal B1 by the end of the year) who also posts a daily
    "learn English with me" YouTube Shorts video made from the lesson and their own voice.
    Rules:
    - Make the sentences personal and true to the learner profile (first person: their name, hometown, job, family, city) whenever
      the topic allows, so the learner says things about their own life; never invent facts that contradict the profile
      (other details can be simple and believable).
    - Natural, useful spoken English for real life (not textbook), right for the given level: A2 = 6–10 words per sentence, simple tenses;
      A2+ = 8–12 words, one linking word; B1 = 10–15 words, reasons, opinions, past and future.
    - Use the grammar focus of the week naturally. Never repeat sentences from previous lessons.
    - Vietnamese (vi) is a natural Vietnamese translation, not word for word.
    - key_word: the one word or short phrase in the sentence that Vietnamese speakers most often mispronounce; key_ipa: its IPA (US).
    - tip_vi: one short tip in Vietnamese (pronunciation, stress, linking or usage), at most 20 words.
    - sound_focus_vi: one sound or pattern to practice today, explained in Vietnamese in at most 25 words.
    - question: one short, friendly question in English inviting viewers to answer in the comments.
    - post: a YouTube Shorts title (at most 60 characters, Vietnamese with the English topic, starts with "Day N"), a description of
      2–3 lines in Vietnamese with the 5 sentences, and 4–6 hashtags (for example #hoctienganh #englishspeaking #shorts).
    PROMPT;

    public const SPEAKING_SYSTEM = <<<'PROMPT'
    You are an English speaking coach for a Vietnamese adult learner (level A2, goal B1 by the end of the year) who posts a weekly
    "I speak for 30 seconds, then fix my mistakes" YouTube Shorts video.
    Prepare the speaking task of the day:
    - prompt: the speaking question in English; prompt_vi: in Vietnamese.
    - phrases: 5 useful phrases (with vi) the learner can use to answer, right for the level and the grammar focus of the week.
    - sample_answer: a natural 60–80 word sample answer at the given level, first person, true to the learner profile.
    - post: a YouTube Shorts title (at most 60 characters, Vietnamese, starts with "Day N"), a description of 2–3 lines in Vietnamese,
      and 4–6 hashtags.
    PROMPT;

    public static function schema(string $kind): array
    {
        $string = ['type' => 'string'];
        $post = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'description', 'hashtags'], 'properties' => [
            'title' => $string, 'description' => $string, 'hashtags' => ['type' => 'array', 'items' => $string],
        ]];
        if ($kind === 'speaking') {
            return ['type' => 'object', 'additionalProperties' => false, 'required' => ['prompt', 'prompt_vi', 'phrases', 'sample_answer', 'post'], 'properties' => [
                'prompt' => $string, 'prompt_vi' => $string, 'sample_answer' => $string, 'post' => $post,
                'phrases' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['en', 'vi'], 'properties' => ['en' => $string, 'vi' => $string]]],
            ]];
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['sentences', 'sound_focus_vi', 'question', 'post'], 'properties' => [
            'sound_focus_vi' => $string, 'question' => $string, 'post' => $post,
            'sentences' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['en', 'vi', 'key_word', 'key_ipa', 'tip_vi'],
                'properties' => ['en' => $string, 'vi' => $string, 'key_word' => $string, 'key_ipa' => $string, 'tip_vi' => $string]]],
        ]];
    }

    /**
     * @throws ScriptWriterException
     */
    public static function write(EnglishLesson $lesson): array
    {
        $info = Curriculum::day($lesson->day);
        $previous = EnglishLesson::where('user_id', $lesson->user_id)->where('day', '<', $lesson->day)->where('kind', 'sentences')
            ->orderByDesc('day')->limit(10)->get()
            ->flatMap(fn (EnglishLesson $l) => collect($l->content['sentences'] ?? [])->pluck('en'))->implode("\n");
        $text = self::profile($lesson)."Day {$lesson->day} of ".Curriculum::TOTAL_DAYS.", week {$info['week']}: {$info['theme']} ({$info['theme_vi']}).\n"
            ."Level today: {$info['level']}. Grammar focus of the week: {$info['grammar']}.\n"
            ."Topic: {$info['topic']} ({$info['topic_vi']}).\n"
            .($previous !== '' ? "Sentences used in previous lessons (do not repeat):\n{$previous}\n" : '')
            .($lesson->kind === 'speaking' ? 'Prepare the speaking task.' : 'Write exactly 5 sentences a person would really say about this topic, from easier to harder.');
        $data = FilmClaude::json(
            $lesson->kind === 'speaking' ? self::SPEAKING_SYSTEM : self::SYSTEM, $text, self::schema($lesson->kind), 'english_lesson',
            "english_lesson:{$lesson->id}", 4000, fn () => self::sample($lesson),
        );

        return self::normalize($lesson, $data);
    }

    /** Hồ sơ người học (nếu có) đưa vào đầu lời nhắn. */
    public static function profile(EnglishLesson $lesson): string
    {
        $profile = trim((string) $lesson->user?->english_profile);

        return $profile !== '' ? "Learner profile (may be in Vietnamese):\n{$profile}\n\n" : '';
    }

    public static function normalize(EnglishLesson $lesson, array $data): array
    {
        $text = fn ($v, int $max = 500) => mb_substr(trim((string) $v), 0, $max);
        $post = [
            'title' => $text($data['post']['title'] ?? "Day {$lesson->day}: {$lesson->topic}", 100),
            'description' => $text($data['post']['description'] ?? '', 2000),
            'hashtags' => array_values(array_filter(array_map(fn ($h) => '#'.ltrim($text($h, 40), '#'), (array) ($data['post']['hashtags'] ?? [])), fn ($h) => $h !== '#')),
        ];
        if ($lesson->kind === 'speaking') {
            if (trim((string) ($data['prompt'] ?? '')) === '') {
                throw new ScriptWriterException('Claude không trả về đề bài nói.');
            }

            return [
                'prompt' => $text($data['prompt']), 'prompt_vi' => $text($data['prompt_vi'] ?? ''), 'sample_answer' => $text($data['sample_answer'] ?? '', 1500),
                'phrases' => collect($data['phrases'] ?? [])->take(6)->map(fn ($p) => ['en' => $text($p['en'] ?? ''), 'vi' => $text($p['vi'] ?? '')])->filter(fn ($p) => $p['en'] !== '')->values()->all(),
                'post' => $post,
            ];
        }
        $sentences = collect($data['sentences'] ?? [])->take(5)->map(fn ($s) => [
            'en' => $text($s['en'] ?? '', 200), 'vi' => $text($s['vi'] ?? '', 300), 'key_word' => $text($s['key_word'] ?? '', 60),
            'key_ipa' => $text($s['key_ipa'] ?? '', 80), 'tip_vi' => $text($s['tip_vi'] ?? '', 300),
        ])->filter(fn ($s) => $s['en'] !== '')->values()->all();
        if (count($sentences) < 3) {
            throw new ScriptWriterException('Claude trả về quá ít câu.');
        }

        return ['sentences' => $sentences, 'sound_focus_vi' => $text($data['sound_focus_vi'] ?? ''), 'question' => $text($data['question'] ?? ''), 'post' => $post];
    }

    /** Mẫu khi chưa kết nối Claude (và trong test). */
    private static function sample(EnglishLesson $lesson): array
    {
        $post = ['title' => "Day {$lesson->day}: {$lesson->topic}", 'description' => 'Học cùng mình mỗi ngày.', 'hashtags' => ['hoctienganh', 'shorts']];
        if ($lesson->kind === 'speaking') {
            return ['prompt' => 'Tell us about yourself.', 'prompt_vi' => 'Giới thiệu bản thân.', 'sample_answer' => 'Hi, my name is Nam. I live in Hanoi.',
                'phrases' => [['en' => 'I am from...', 'vi' => 'Mình đến từ...'], ['en' => 'In my free time, I...', 'vi' => 'Lúc rảnh mình...']], 'post' => $post];
        }

        return ['sound_focus_vi' => 'Âm /θ/: đặt lưỡi giữa hai răng.', 'question' => 'What about you?', 'post' => $post, 'sentences' => [
            ['en' => 'Hi, my name is Nam.', 'vi' => 'Chào, mình tên Nam.', 'key_word' => 'name', 'key_ipa' => '/neɪm/', 'tip_vi' => 'Đọc rõ âm /m/ cuối.'],
            ['en' => 'I live in Hanoi with my family.', 'vi' => 'Mình sống ở Hà Nội với gia đình.', 'key_word' => 'live', 'key_ipa' => '/lɪv/', 'tip_vi' => 'Âm /ɪ/ ngắn.'],
            ['en' => 'I work as a designer.', 'vi' => 'Mình làm thiết kế.', 'key_word' => 'designer', 'key_ipa' => '/dɪˈzaɪnər/', 'tip_vi' => 'Nhấn âm thứ hai.'],
            ['en' => 'I like reading and playing games.', 'vi' => 'Mình thích đọc sách và chơi game.', 'key_word' => 'reading', 'key_ipa' => '/ˈriːdɪŋ/', 'tip_vi' => 'Âm /iː/ dài.'],
            ['en' => 'I want to speak English better.', 'vi' => 'Mình muốn nói tiếng Anh tốt hơn.', 'key_word' => 'better', 'key_ipa' => '/ˈbetər/', 'tip_vi' => 'Âm t giữa đọc nhẹ như d.'],
        ]];
    }
}
