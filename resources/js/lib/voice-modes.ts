import { type VoiceMode } from '@/types/models';

export const voiceModes: { value: VoiceMode; label: string; hint: string }[] = [
    { value: 'music', label: 'Chỉ nhạc nền', hint: 'Người mẫu không nói, lời đọc hiện thành phụ đề.' },
    { value: 'speak', label: 'Người mẫu nói (review)', hint: 'Người mẫu tự nói lời review, các cảnh nối thành một bài nói liền mạch.' },
    { value: 'sing', label: 'Hát nhép theo nhạc', hint: 'AI chọn đoạn sôi động nhất của nhạc nền, người mẫu nhép miệng theo từng đoạn.' },
];
