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
import { FileUp, ImagePlus, Info, Loader2, Plus, Sparkles, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Phim hoạt hình', href: '/series' },
    { title: 'Tạo phim', href: '/series/create' },
];

type SeedCharacter = { name: string; note: string; image: File | null };

const lengthLabel = (seconds: number) =>
    seconds < 60 ? `${seconds} giây (YouTube Shorts)` : `${seconds / 60} phút${seconds >= 480 ? ' (có quảng cáo giữa video)' : ''}`;

type Brief = { number: number; text: string };

/**
 * Đọc file kế hoạch kênh (.md): phần đầu tới mục 2 làm ý tưởng (concept, nhân vật, visual bible, công thức kể),
 * bảng danh sách tập của mục 2, kịch bản chi tiết "### Episode 001 — ..." của mục 3 gắn vào đúng tập.
 */
function parsePlan(md: string) {
    const lines = md.split(/\r?\n/);
    const heading = (n: number) => lines.findIndex((line) => new RegExp(`^##\\s+${n}[.)\\s]`).test(line));
    const s2 = heading(2);
    const s3 = heading(3);
    const after3 = s3 >= 0 ? lines.findIndex((line, i) => i > s3 && /^##\s/.test(line)) : -1;
    const idea = lines
        .slice(0, s2 >= 0 ? s2 : lines.length)
        .join('\n')
        .trim()
        .slice(0, 8000);
    const table = (s2 >= 0 ? lines.slice(s2, s3 >= 0 ? s3 : undefined) : lines).filter((line) => line.trim().startsWith('|')).join('\n');
    const briefs: Brief[] = [];
    if (s3 >= 0) {
        let current: Brief | null = null;
        for (const line of lines.slice(s3 + 1, after3 >= 0 ? after3 : undefined)) {
            const match = line.match(/^###\s+(?:Episode|Tập)\s+0*(\d+)/i);
            if (match) {
                current = { number: Number(match[1]), text: line.replace(/^###\s+/, '') };
                briefs.push(current);
            } else if (current) {
                current.text += `\n${line}`;
            }
        }
    }
    return {
        idea,
        table,
        briefs: briefs.map((b) => ({ ...b, text: b.text.trim().slice(0, 8000) })),
        silent: /không thoại|no dialogue|without dialogue/i.test(md),
        style3d: /\b3D\b/.test(idea),
    };
}

type Form = {
    format: string;
    audience: string;
    clip_budget: string;
    style_preset: string;
    episode_list: string;
    briefs: Brief[];
    idea: string;
    genre: string;
    age_min: string;
    age_max: string;
    episode_count: string;
    episode_seconds: string;
    style: string;
    subtitle_languages: string[];
    characters: SeedCharacter[];
};

export default function SeriesCreate({
    genres,
    languages,
    ages,
    defaultStyle,
    estimate,
    lengths,
    formats,
    stylePresets,
    clipBudgets,
    shortAudience,
}: {
    formats: Record<string, string>;
    stylePresets: Record<string, string>;
    clipBudgets: Record<string, string>;
    kinds: Record<string, string>;
    shortAudience: string;
    genres: Record<string, string>;
    languages: Record<string, string>;
    ages: { min: number; max: number };
    defaultStyle: string;
    estimate: number;
    lengths: number[];
}) {
    const { data, setData, post, processing, errors } = useForm<Form>({
        format: 'episodic',
        audience: '',
        clip_budget: '3',
        style_preset: '2d',
        episode_list: '',
        briefs: [],
        idea: '',
        genre: 'adventure',
        age_min: '6',
        age_max: '12',
        episode_count: '6',
        episode_seconds: '300',
        style: '',
        subtitle_languages: [],
        characters: [],
    });
    const [ownCharacters, setOwnCharacters] = useState(false);
    const setCharacter = (index: number, patch: Partial<SeedCharacter>) =>
        setData(
            'characters',
            data.characters.map((c, i) => (i === index ? { ...c, ...patch } : c)),
        );
    const errorsOf = errors as Record<string, string | undefined>;
    const ageOptions = Array.from({ length: ages.max - ages.min + 1 }, (_, i) => String(ages.min + i));
    const madeForKids = Number(data.age_min) < 13;
    const short = data.format === 'silent_short';
    const listed = data.episode_list.split(/\n/).filter((line) => line.trim() && !/^\s*\|?\s*(#|:?-{2,}|tựa đề|tên tập|title)/i.test(line)).length;
    const [importNote, setImportNote] = useState<string | null>(null);
    const chooseFormat = (format: string) =>
        setData((current) => ({
            ...current,
            format,
            style_preset: format === 'silent_short' ? '3d' : '2d',
            episode_seconds: format === 'silent_short' ? '30' : current.episode_seconds,
        }));
    const importPlan = async (file: File | undefined) => {
        if (file) {
            loadPlan(file.name, await file.text());
        }
    };
    const loadSample = async (url: string, name: string) => {
        const response = await fetch(url);
        if (response.ok) {
            loadPlan(name, await response.text());
        }
    };
    const loadPlan = (name: string, text: string) => {
        const plan = parsePlan(text);
        setData((current) => ({
            ...current,
            idea: plan.idea || current.idea,
            episode_list: plan.table || current.episode_list,
            briefs: plan.briefs,
            ...(plan.silent ? { format: 'silent_short', episode_seconds: '30' } : {}),
            ...(plan.style3d ? { style_preset: '3d' } : {}),
        }));
        const count = plan.table.split('\n').filter((line) => /^\|\s*#?\d/.test(line.trim())).length;
        setImportNote(
            `Đã đọc "${name}": ý tưởng ${plan.idea.length.toLocaleString('vi-VN')} ký tự, ${count} tập` +
                (plan.briefs.length ? `, kịch bản có sẵn cho tập ${plan.briefs.map((b) => b.number).join(', ')}` : '') +
                (plan.silent ? ', loại truyện ngắn không thoại 9:16' : '') +
                (plan.style3d ? ', phong cách 3D' : '') +
                '. Xem lại rồi bấm "Lên kế hoạch bộ phim".',
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('series.store'), { forceFormData: data.characters.length > 0 });
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

                <div className="flex flex-wrap items-center gap-3 rounded-lg border border-dashed p-4 text-sm">
                    <FileUp className="text-muted-foreground size-5 shrink-0" />
                    <span className="min-w-0 flex-1">
                        Có sẵn file kế hoạch kênh (.md)? Nhập file để điền sẵn ý tưởng, nhân vật, danh sách tập và kịch bản chi tiết của các tập đã
                        viết.
                    </span>
                    <Button type="button" variant="outline" size="sm" asChild>
                        <label className="cursor-pointer">
                            <FileUp /> Nhập file kế hoạch
                            <input
                                type="file"
                                accept=".md,.markdown,.txt,text/markdown,text/plain"
                                className="hidden"
                                onChange={(e) => {
                                    importPlan(e.target.files?.[0]);
                                    e.target.value = '';
                                }}
                            />
                        </label>
                    </Button>
                    <div className="flex w-full flex-wrap gap-x-4 gap-y-1 text-xs">
                        <button
                            type="button"
                            className="text-primary underline-offset-2 hover:underline"
                            onClick={() => loadSample('/samples/milos-little-world.md', 'milos-little-world.md')}
                        >
                            Dùng kế hoạch Milo's Little World (30 tập)
                        </button>
                        <a href="/samples/ke-hoach-kenh-mau.md" download className="text-primary underline-offset-2 hover:underline">
                            Tải file mẫu trống (.md) để soạn kênh mới
                        </a>
                    </div>
                    {importNote && <p className="text-primary w-full text-xs">{importNote}</p>}
                </div>

                <div className="space-y-2">
                    <Label>Loại phim</Label>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {Object.entries(formats).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => chooseFormat(key)}
                                className={`rounded-lg border p-3 text-left text-sm transition ${data.format === key ? 'border-primary bg-primary/5' : 'hover:bg-muted'}`}
                            >
                                <span className="font-medium">{label}</span>
                                <span className="text-muted-foreground mt-1 block text-xs">
                                    {key === 'silent_short'
                                        ? 'Khoảng 30 giây, khung dọc 1080×1920, kể bằng hành động, biểu cảm, nhạc và âm thanh. Mỗi tập khoảng 5 shot, chỉ vài shot làm clip AI, còn lại là ảnh tĩnh có chuyển động máy.'
                                        : 'Tập dài 30 giây đến 15 phút, có lời thoại tiếng Anh, giọng riêng từng nhân vật, phụ đề, khung ngang 16:9.'}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="idea">Ý tưởng</Label>
                    <Textarea
                        id="idea"
                        rows={short ? 10 : 6}
                        value={data.idea}
                        onChange={(e) => setData('idea', e.target.value)}
                        placeholder="Ví dụ: Cậu bé 10 tuổi Milo cùng cô bạn thân mở câu lạc bộ thám tử nhí trong thị trấn. Mỗi tập giải một bí ẩn nhỏ (mất đồ, dấu chân lạ...) và học một điều về tình bạn, sự trung thực. Viết tiếng Việt hay tiếng Anh đều được."
                    />
                    <p className="text-muted-foreground text-xs">
                        {short
                            ? 'Ghi concept, nhân vật cố định (ngoại hình, phụ kiện, tính cách) và cách kể chuyện. Claude giữ đúng nhân vật bạn mô tả. '
                            : 'Phim tiếng Anh. '}
                        Không dùng nhân vật có bản quyền (Doraemon, Conan, Pikachu...): hãy tạo nhân vật riêng, phong cách tương tự thì được.
                    </p>
                    <InputError message={errors.idea} />
                </div>

                {short && (
                    <div className="space-y-4 rounded-lg border p-4">
                        <div className="space-y-2">
                            <Label htmlFor="episode_list">Danh sách tập (không bắt buộc)</Label>
                            <Textarea
                                id="episode_list"
                                rows={8}
                                value={data.episode_list}
                                onChange={(e) => setData('episode_list', e.target.value)}
                                placeholder={
                                    'Mỗi dòng một tập: Tên tập | ý tưởng ngắn | thể loại\nThe Last Cookie | Milo chia chiếc bánh cuối cùng cho Bobo; Bobo có một bất ngờ. | Cảm động\nThe Stolen Fish | Milo tưởng Bobo lấy trộm cá; thủ phạm là chim. | Hài hước\n(dán thẳng bảng markdown cũng được)'
                                }
                                className="font-mono text-xs"
                            />
                            <p className="text-muted-foreground text-xs">
                                {listed > 0
                                    ? `${listed} tập: Claude giữ đúng tên, thứ tự và thể loại (Cảm động, Hài hước, Kỳ diệu, Phiêu lưu), chỉ viết tóm tắt. Số tập theo danh sách.`
                                    : 'Để trống thì Claude tự nghĩ các tập theo ý tưởng.'}
                            </p>
                            <InputError message={errors.episode_list} />
                        </div>
                        {data.briefs.length > 0 && (
                            <div className="space-y-1">
                                <Label>Kịch bản có sẵn (Claude viết shot bám theo)</Label>
                                <div className="flex flex-wrap gap-2">
                                    {data.briefs.map((brief) => (
                                        <span
                                            key={brief.number}
                                            className="bg-muted inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs"
                                            title={brief.text}
                                        >
                                            Tập {brief.number}: {brief.text.split('\n')[0].slice(0, 40)}
                                            <button
                                                type="button"
                                                aria-label="Bỏ kịch bản"
                                                onClick={() =>
                                                    setData(
                                                        'briefs',
                                                        data.briefs.filter((b) => b.number !== brief.number),
                                                    )
                                                }
                                            >
                                                <X className="size-3" />
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Số clip AI mỗi tập</Label>
                                <Select value={data.clip_budget} onValueChange={(value) => setData('clip_budget', value)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(clipBudgets).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-muted-foreground text-xs">
                                    Claude chọn shot cần chuyển động thật để làm clip. Bạn bật / tắt lại được ở từng shot.
                                </p>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="audience">Người xem</Label>
                                <Input
                                    id="audience"
                                    value={data.audience}
                                    onChange={(e) => setData('audience', e.target.value)}
                                    placeholder={shortAudience}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Để trống: khán giả quốc tế, chủ yếu 16–34, nội dung lành mạnh mọi lứa tuổi.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

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

                    <div className={short ? 'hidden' : 'space-y-2'}>
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

                    <div className={short && listed > 0 ? 'hidden' : 'space-y-2'}>
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

                    <div className={short ? 'hidden' : 'space-y-2'}>
                        <Label>Độ dài mỗi tập</Label>
                        <Select value={data.episode_seconds} onValueChange={(value) => setData('episode_seconds', value)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {lengths.map((seconds) => (
                                    <SelectItem key={seconds} value={String(seconds)}>
                                        {lengthLabel(seconds)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-muted-foreground text-xs">
                            30 giây hợp với Shorts (khi ghép tập chọn khung dọc 9:16). Từ 8 phút YouTube cho chèn quảng cáo giữa video.
                        </p>
                        <InputError message={errors.episode_seconds} />
                    </div>
                </div>

                <div className="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    {short
                        ? 'Truyện ngắn cho khán giả quốc tế 16–34: không đánh dấu "Dành cho trẻ em". Video AI không tự động đủ điều kiện kiếm tiền; bật nhãn nội dung AI khi nền tảng yêu cầu.'
                        : madeForKids
                          ? 'Tuổi nhỏ nhất dưới 13: video sẽ đăng YouTube ở chế độ "Dành cho trẻ em" (bắt buộc): tắt bình luận, quảng cáo không cá nhân hoá, có thể lên YouTube Kids.'
                          : 'Tuổi nhỏ nhất từ 13: không đánh dấu "Dành cho trẻ em", bật được bình luận và quảng cáo cá nhân hoá.'}
                </div>

                <div className="space-y-3 rounded-lg border p-4">
                    <Label>Nhân vật</Label>
                    <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                checked={!ownCharacters}
                                onChange={() => {
                                    setOwnCharacters(false);
                                    setData('characters', []);
                                }}
                            />
                            AI tạo nhân vật (Claude nghĩ ra, Muse vẽ)
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                checked={ownCharacters}
                                onChange={() => {
                                    setOwnCharacters(true);
                                    if (data.characters.length === 0) {
                                        setData('characters', [{ name: '', note: '', image: null }]);
                                    }
                                }}
                            />
                            Tôi có ảnh nhân vật
                        </label>
                    </div>
                    {ownCharacters && (
                        <div className="space-y-3">
                            <p className="text-muted-foreground text-xs">
                                Claude xem ảnh để viết ngoại hình của nhân vật, ảnh thành ảnh chính (không cần vẽ lại). Thiếu nhân vật phụ thì Claude
                                tự thêm. Chỉ dùng ảnh nhân vật của bạn, không dùng nhân vật có bản quyền.
                            </p>
                            {data.characters.map((character, i) => (
                                <div key={i} className="flex flex-wrap items-start gap-3 rounded-lg border p-3">
                                    <label className="hover:bg-muted/50 flex size-24 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-md border border-dashed">
                                        {character.image ? (
                                            <img src={URL.createObjectURL(character.image)} alt="" className="size-full object-cover" />
                                        ) : (
                                            <ImagePlus className="text-muted-foreground size-6" />
                                        )}
                                        <input
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            className="hidden"
                                            onChange={(e) => setCharacter(i, { image: e.target.files?.[0] ?? null })}
                                        />
                                    </label>
                                    <div className="min-w-0 flex-1 space-y-2">
                                        <Input
                                            placeholder="Tên nhân vật (ví dụ: Milo)"
                                            value={character.name}
                                            onChange={(e) => setCharacter(i, { name: e.target.value })}
                                        />
                                        <Input
                                            placeholder="Ghi chú (không bắt buộc): vai trò, tuổi, tính cách..."
                                            value={character.note}
                                            onChange={(e) => setCharacter(i, { note: e.target.value })}
                                        />
                                        <InputError message={errorsOf[`characters.${i}.name`] || errorsOf[`characters.${i}.image`]} />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Bỏ nhân vật"
                                        onClick={() =>
                                            setData(
                                                'characters',
                                                data.characters.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            {data.characters.length < 6 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setData('characters', [...data.characters, { name: '', note: '', image: null }])}
                                >
                                    <Plus /> Thêm nhân vật
                                </Button>
                            )}
                            <InputError message={errors.characters} />
                        </div>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="style">Phong cách hình ảnh</Label>
                    <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        {Object.entries(stylePresets).map(([key, label]) => (
                            <label key={key} className="flex items-center gap-2">
                                <input type="radio" checked={data.style_preset === key} onChange={() => setData('style_preset', key)} />
                                {label}
                            </label>
                        ))}
                    </div>
                    <Input
                        id="style"
                        value={data.style}
                        onChange={(e) => setData('style', e.target.value)}
                        placeholder={data.style_preset === '3d' ? 'Hoặc tự viết câu phong cách (tiếng Anh)' : defaultStyle}
                    />
                    <p className="text-muted-foreground text-xs">
                        Ô trống thì dùng phong cách đã chọn ở trên. Luôn tạo thiết kế gốc, không sao chép nhân vật có bản quyền.
                    </p>
                    <InputError message={errors.style} />
                </div>

                <div className={short ? 'hidden' : 'space-y-2'}>
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
