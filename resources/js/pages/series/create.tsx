import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Info, Loader2, Sparkles } from 'lucide-react';
import { type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Phim hoạt hình', href: '/series' },
    { title: 'Tạo phim', href: '/series/create' },
];

type Form = {
    idea: string;
    genre: string;
    age_min: string;
    age_max: string;
    episode_count: string;
    episode_minutes: string;
    style: string;
    subtitle_languages: string[];
};

export default function SeriesCreate({
    genres,
    languages,
    ages,
    defaultStyle,
    estimate,
}: {
    genres: Record<string, string>;
    languages: Record<string, string>;
    ages: { min: number; max: number };
    defaultStyle: string;
    estimate: number;
}) {
    const { data, setData, post, processing, errors } = useForm<Form>({
        idea: '',
        genre: 'adventure',
        age_min: '6',
        age_max: '12',
        episode_count: '6',
        episode_minutes: '5',
        style: '',
        subtitle_languages: [],
    });
    const ageOptions = Array.from({ length: ages.max - ages.min + 1 }, (_, i) => String(ages.min + i));
    const madeForKids = Number(data.age_min) < 13;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('series.store'));
    };

    const toggleLanguage = (code: string, checked: boolean) =>
        setData('subtitle_languages', checked ? [...data.subtitle_languages, code] : data.subtitle_languages.filter((item) => item !== code));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tạo phim hoạt hình" />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <Heading
                    title="Tạo phim hoạt hình"
                    description="Claude đọc ý tưởng rồi lên kế hoạch: nhân vật, bối cảnh, dàn ý từng tập. Bạn sửa được trước khi vẽ nhân vật."
                />

                <div className="space-y-2">
                    <Label htmlFor="idea">Ý tưởng</Label>
                    <Textarea
                        id="idea"
                        rows={6}
                        value={data.idea}
                        onChange={(e) => setData('idea', e.target.value)}
                        placeholder="Ví dụ: Cậu bé 10 tuổi Milo cùng cô bạn thân mở câu lạc bộ thám tử nhí trong thị trấn. Mỗi tập giải một bí ẩn nhỏ (mất đồ, dấu chân lạ...) và học một điều về tình bạn, sự trung thực. Viết tiếng Việt hay tiếng Anh đều được."
                    />
                    <p className="text-muted-foreground text-xs">
                        Phim tiếng Anh. Không dùng nhân vật có bản quyền (Doraemon, Conan, Pikachu...): hãy tạo nhân vật riêng, phong cách tương tự
                        thì được.
                    </p>
                    <InputError message={errors.idea} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label>Thể loại</Label>
                        <Select value={data.genre} onValueChange={(value) => setData('genre', value)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(genres).map(([key, label]) => (
                                    <SelectItem key={key} value={key}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.genre} />
                    </div>

                    <div className="space-y-2">
                        <Label>Độ tuổi người xem</Label>
                        <div className="flex items-center gap-2 text-sm">
                            Từ
                            <AgeSelect value={data.age_min} options={ageOptions} onChange={(value) => setData('age_min', value)} />
                            đến
                            <AgeSelect value={data.age_max} options={ageOptions} onChange={(value) => setData('age_max', value)} />
                            tuổi
                        </div>
                        <InputError message={errors.age_min || errors.age_max} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="episode_count">Số tập</Label>
                        <Input
                            id="episode_count"
                            type="number"
                            min={1}
                            max={52}
                            value={data.episode_count}
                            onChange={(e) => setData('episode_count', e.target.value)}
                        />
                        <InputError message={errors.episode_count} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="episode_minutes">Độ dài mỗi tập (phút)</Label>
                        <Input
                            id="episode_minutes"
                            type="number"
                            min={1}
                            max={15}
                            value={data.episode_minutes}
                            onChange={(e) => setData('episode_minutes', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">Từ 8 phút YouTube cho chèn quảng cáo giữa video.</p>
                        <InputError message={errors.episode_minutes} />
                    </div>
                </div>

                <div className="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    {madeForKids
                        ? 'Tuổi nhỏ nhất dưới 13: video sẽ đăng YouTube ở chế độ "Dành cho trẻ em" (bắt buộc): tắt bình luận, quảng cáo không cá nhân hoá, có thể lên YouTube Kids.'
                        : 'Tuổi nhỏ nhất từ 13: không đánh dấu "Dành cho trẻ em", bật được bình luận và quảng cáo cá nhân hoá.'}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="style">Phong cách hình ảnh</Label>
                    <Input id="style" value={data.style} onChange={(e) => setData('style', e.target.value)} placeholder={defaultStyle} />
                    <p className="text-muted-foreground text-xs">Để trống: 2D anime kiểu phim truyền hình (nét viền rõ, màu phẳng).</p>
                    <InputError message={errors.style} />
                </div>

                <div className="space-y-2">
                    <Label>Phụ đề / tiêu đề thêm thứ tiếng (cho người xem nước ngoài)</Label>
                    <div className="flex flex-wrap gap-x-5 gap-y-2">
                        {Object.entries(languages).map(([code, label]) => (
                            <label key={code} className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.subtitle_languages.includes(code)}
                                    onCheckedChange={(checked) => toggleLanguage(code, checked === true)}
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={processing}>
                        {processing ? <Loader2 className="animate-spin" /> : <Sparkles />} Lên kế hoạch bộ phim
                    </Button>
                    <span className="text-muted-foreground text-xs">Khoảng {formatNumber(estimate)} Credits cho bước này.</span>
                </div>
            </form>
        </AppLayout>
    );
}

function AgeSelect({ value, options, onChange }: { value: string; options: string[]; onChange: (value: string) => void }) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="w-20">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((age) => (
                    <SelectItem key={age} value={age}>
                        {age}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
