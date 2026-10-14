<?php

namespace App\Services\English;

/**
 * Lộ trình luyện nói A2 → B1 trong 12 tuần (84 bài): mỗi tuần một chủ đề + một điểm ngữ pháp để nói;
 * 6 ngày "5 câu mỗi ngày" (nghe mẫu, đọc lại, chấm phát âm) và ngày thứ 7 "Nói 30 giây" (nói tự do, Claude sửa lỗi).
 * Tuần 1–4: A2, tuần 5–8: A2+, tuần 9–12: B1 (câu dài hơn, kể chuyện, nêu ý kiến và lý do).
 */
final class Curriculum
{
    public const WEEKS = [
        ['theme' => 'About me', 'theme_vi' => 'Giới thiệu bản thân', 'grammar' => 'present simple, be / have', 'days' => [
            ['Introduce yourself', 'Giới thiệu bản thân'], ['My family', 'Gia đình mình'], ['My job or studies', 'Công việc / việc học'],
            ['Where I live', 'Nơi mình sống'], ['My hobbies', 'Sở thích'], ['My personality', 'Tính cách của mình'],
        ], 'speaking' => ['Tell us about yourself in 30 seconds.', 'Giới thiệu bản thân trong 30 giây.']],
        ['theme' => 'Daily life', 'theme_vi' => 'Cuộc sống hằng ngày', 'grammar' => 'present simple + adverbs of frequency', 'days' => [
            ['My morning routine', 'Buổi sáng của mình'], ['At work or school', 'Ở chỗ làm / trường'], ['Housework', 'Việc nhà'],
            ['My evening', 'Buổi tối của mình'], ['Weekends', 'Cuối tuần'], ['Sleep and rest', 'Ngủ và nghỉ ngơi'],
        ], 'speaking' => ['Describe a normal day in your life.', 'Kể về một ngày bình thường của bạn.']],
        ['theme' => 'Food and drinks', 'theme_vi' => 'Đồ ăn, thức uống', 'grammar' => 'countable / uncountable, some / any, would like', 'days' => [
            ['Ordering at a café', 'Gọi đồ ở quán cà phê'], ['At a restaurant', 'Ở nhà hàng'], ['Vietnamese food', 'Món ăn Việt Nam'],
            ['Cooking at home', 'Nấu ăn ở nhà'], ['Healthy eating', 'Ăn uống lành mạnh'], ['Street food', 'Đồ ăn đường phố'],
        ], 'speaking' => ['What is your favorite dish and why?', 'Món bạn thích nhất là gì, vì sao?']],
        ['theme' => 'Out and about', 'theme_vi' => 'Ra ngoài', 'grammar' => 'there is / are, prepositions of place, imperatives', 'days' => [
            ['Asking for directions', 'Hỏi đường'], ['Taking a taxi or Grab', 'Đi taxi / Grab'], ['Shopping for clothes', 'Mua quần áo'],
            ['At the supermarket', 'Ở siêu thị'], ['At the doctor', 'Đi khám bệnh'], ['My neighborhood', 'Khu mình ở'],
        ], 'speaking' => ['Describe your neighborhood to a visitor.', 'Giới thiệu khu bạn ở cho một vị khách.']],
        ['theme' => 'The past', 'theme_vi' => 'Chuyện đã qua', 'grammar' => 'past simple (regular and irregular verbs)', 'days' => [
            ['Yesterday', 'Hôm qua'], ['Last weekend', 'Cuối tuần trước'], ['My childhood', 'Tuổi thơ'],
            ['A memorable trip', 'Một chuyến đi đáng nhớ'], ['My first job or first school day', 'Ngày đầu đi làm / đi học'], ['A funny mistake', 'Một lần nhầm lẫn buồn cười'],
        ], 'speaking' => ['Tell a short story about your last weekend.', 'Kể ngắn về cuối tuần vừa rồi của bạn.']],
        ['theme' => 'Plans and future', 'theme_vi' => 'Kế hoạch, tương lai', 'grammar' => 'be going to, will, present continuous for plans', 'days' => [
            ['Plans for tonight', 'Kế hoạch tối nay'], ['Making an appointment', 'Đặt lịch hẹn'], ['Inviting a friend', 'Rủ bạn đi chơi'],
            ['My goals this year', 'Mục tiêu năm nay'], ['Holiday plans', 'Kế hoạch kỳ nghỉ'], ['Predictions', 'Dự đoán tương lai'],
        ], 'speaking' => ['What are your plans for the next three months?', 'Kế hoạch 3 tháng tới của bạn là gì?']],
        ['theme' => 'Comparing things', 'theme_vi' => 'So sánh', 'grammar' => 'comparatives and superlatives, as ... as', 'days' => [
            ['City or countryside', 'Thành phố hay nông thôn'], ['Phones and apps', 'Điện thoại và ứng dụng'], ['Then and now', 'Ngày xưa và bây giờ'],
            ['Weather and seasons', 'Thời tiết và các mùa'], ['Choosing a gift', 'Chọn quà'], ['The best place I know', 'Nơi tuyệt nhất mình biết'],
        ], 'speaking' => ['Compare your life now and five years ago.', 'So sánh cuộc sống của bạn bây giờ và 5 năm trước.']],
        ['theme' => 'Experiences', 'theme_vi' => 'Trải nghiệm', 'grammar' => 'present perfect (ever, never, already, yet)', 'days' => [
            ['Have you ever...?', 'Bạn đã từng...?'], ['Things I have learned', 'Điều mình đã học được'], ['Places I have visited', 'Nơi mình đã đến'],
            ['Food I have never tried', 'Món mình chưa thử'], ['Changes in my life', 'Thay đổi trong cuộc sống'], ['My English journey', 'Hành trình học tiếng Anh'],
        ], 'speaking' => ['Talk about something new you have tried recently.', 'Kể về một điều mới bạn vừa thử gần đây.']],
        ['theme' => 'Feelings and opinions', 'theme_vi' => 'Cảm xúc, ý kiến', 'grammar' => 'I think / I feel / because / so; agreeing and disagreeing', 'days' => [
            ['How I feel today', 'Hôm nay mình thấy thế nào'], ['Giving my opinion', 'Nêu ý kiến'], ['Agreeing and disagreeing', 'Đồng ý và không đồng ý'],
            ['Social media', 'Mạng xã hội'], ['Stress and relaxing', 'Căng thẳng và thư giãn'], ['What makes me happy', 'Điều làm mình vui'],
        ], 'speaking' => ['Is social media good or bad for us? Give your reasons.', 'Mạng xã hội tốt hay xấu? Nêu lý do.']],
        ['theme' => 'Work and money', 'theme_vi' => 'Công việc, tiền bạc', 'grammar' => 'can / could / should / have to; polite requests', 'days' => [
            ['A job interview', 'Phỏng vấn xin việc'], ['Talking to a customer', 'Nói chuyện với khách hàng'], ['Asking for help politely', 'Nhờ giúp đỡ lịch sự'],
            ['Saving money', 'Tiết kiệm tiền'], ['Working from home', 'Làm việc ở nhà'], ['My dream job', 'Công việc mơ ước'],
        ], 'speaking' => ['Describe your dream job and why you want it.', 'Kể về công việc mơ ước và lý do.']],
        ['theme' => 'Stories', 'theme_vi' => 'Kể chuyện', 'grammar' => 'past continuous + past simple; first, then, after that, finally', 'days' => [
            ['A lucky day', 'Một ngày may mắn'], ['A problem I solved', 'Một vấn đề mình đã giải quyết'], ['A person who helped me', 'Người đã giúp mình'],
            ['A movie I like', 'Bộ phim mình thích'], ['A surprise', 'Một bất ngờ'], ['A difficult day', 'Một ngày khó khăn'],
        ], 'speaking' => ['Tell the story of a day that did not go as planned.', 'Kể về một ngày không như dự định.']],
        ['theme' => 'Me in English', 'theme_vi' => 'Tổng kết B1', 'grammar' => 'first conditional (if ... will), review of tenses', 'days' => [
            ['If I had more time', 'Nếu có nhiều thời gian hơn'], ['Advice for beginners', 'Lời khuyên cho người mới học'], ['My town in the future', 'Thành phố mình trong tương lai'],
            ['A habit I want to change', 'Thói quen muốn thay đổi'], ['What I learned this year', 'Điều mình học được năm nay'], ['Thank you, viewers', 'Cảm ơn người xem'],
        ], 'speaking' => ['How has your English changed since day one?', 'Tiếng Anh của bạn đã thay đổi thế nào từ ngày đầu?']],
    ];

    public const TOTAL_DAYS = 84;

    /**
     * Thông tin bài của ngày $day (1–84).
     *
     * @return array{day: int, week: int, kind: string, level: string, topic: string, topic_vi: string, theme: string, theme_vi: string, grammar: string}
     */
    public static function day(int $day): array
    {
        $day = max(1, min(self::TOTAL_DAYS, $day));
        $weekIndex = intdiv($day - 1, 7);
        $week = self::WEEKS[$weekIndex];
        $position = ($day - 1) % 7;
        [$topic, $topicVi] = $position === 6 ? $week['speaking'] : $week['days'][$position];

        return [
            'day' => $day,
            'week' => $weekIndex + 1,
            'kind' => $position === 6 ? 'speaking' : 'sentences',
            'level' => match (true) {
                $weekIndex < 4 => 'A2',
                $weekIndex < 8 => 'A2+',
                default => 'B1',
            },
            'topic' => $topic,
            'topic_vi' => $topicVi,
            'theme' => $week['theme'],
            'theme_vi' => $week['theme_vi'],
            'grammar' => $week['grammar'],
        ];
    }
}
