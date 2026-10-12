import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

export type CharacterFormData = {
    name: string;
    role: string;
    age: string;
    personality: string;
    appearance: string;
    voice_type: string;
};

export const emptyCharacter: CharacterFormData = { name: '', role: 'side', age: '', personality: '', appearance: '', voice_type: 'kid_boy' };

/** Các ô thông tin nhân vật (dùng khi thêm nhân vật và trên trang nhân vật). */
export default function CharacterFields({
    data,
    setData,
    errors,
    roles,
    voiceTypes,
    appearanceLocked = false,
}: {
    data: CharacterFormData;
    setData: <K extends keyof CharacterFormData>(key: K, value: CharacterFormData[K]) => void;
    errors: Partial<Record<keyof CharacterFormData, string>>;
    roles: Record<string, string>;
    voiceTypes: Record<string, string>;
    appearanceLocked?: boolean;
}) {
    return (
        <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label htmlFor="name">Tên</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="age">Tuổi</Label>
                    <Input id="age" type="number" min={0} max={120} value={data.age} onChange={(e) => setData('age', e.target.value)} />
                    <InputError message={errors.age} />
                </div>
                <div className="space-y-1">
                    <Label>Vai trò</Label>
                    <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(roles).map(([key, label]) => (
                                <SelectItem key={key} value={key}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1">
                    <Label>Giọng</Label>
                    <Select value={data.voice_type} onValueChange={(value) => setData('voice_type', value)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(voiceTypes).map(([key, label]) => (
                                <SelectItem key={key} value={key}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <div className="space-y-1">
                <Label htmlFor="personality">Tính cách</Label>
                <Textarea id="personality" rows={2} value={data.personality} onChange={(e) => setData('personality', e.target.value)} />
            </div>
            <div className="space-y-1">
                <Label htmlFor="appearance">Ngoại hình (tiếng Anh, cố định)</Label>
                <Textarea
                    id="appearance"
                    rows={4}
                    value={data.appearance}
                    disabled={appearanceLocked}
                    onChange={(e) => setData('appearance', e.target.value)}
                    placeholder="A 9-year-old girl, short curly red hair, freckles, blue eyes, green raincoat with yellow buttons, white rubber boots, small brown satchel."
                />
                <p className="text-muted-foreground text-xs">
                    {appearanceLocked
                        ? 'Nhân vật đã chốt: bỏ chốt để sửa ngoại hình.'
                        : 'Tóc, mắt, dáng người, quần áo kèm màu, 1–2 món đồ đặc trưng. Mô tả này được gửi kèm mọi ảnh có nhân vật.'}
                </p>
                <InputError message={errors.appearance} />
            </div>
        </div>
    );
}
