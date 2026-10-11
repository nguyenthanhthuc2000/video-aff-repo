import { type VoiceMode } from '@/types/models';

export const voiceModes: { value: VoiceMode; label: string; hint: string }[] = [
    { value: 'music', label: 'Chỉ nhạc nền', hint: 'Người mẫu không nói, lời đọc hiện thành phụ đề.' },
    { value: 'speak', label: 'Người mẫu nói (review)', hint: 'Người mẫu tự nói lời review, các cảnh nối thành một bài nói liền mạch.' },
    { value: 'sing', label: 'Hát nhép theo nhạc', hint: 'Muse nghe nhạc, chọn đoạn nhạc và ghi từng giây có lời / không lời; người mẫu chỉ nhép khi có lời, khúc không lời thì làm duyên.' },
];
