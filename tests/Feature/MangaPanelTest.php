<?php

namespace Tests\Feature;

use App\Jobs\ProcessMangaScanJob;
use App\Jobs\WriteTopicShortJob;
use App\Models\MangaPanel;
use App\Models\TopicShort;
use App\Models\User;
use App\Services\Manga\PanelCutter;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

/** Kho khung manga thật: tải trang → cắt khung → Claude xem → video truyện ghép khung thật thay ảnh AI. */
class MangaPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
    }

    /** Trang giả: 1 khung trên rộng, 2 khung giữa, 2 khung dưới, số trang lẻ ở đáy. */
    private static function page(): string
    {
        $image = imagecreatetruecolor(1000, 1500);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $black = imagecolorallocate($image, 0, 0, 0);
        foreach ([[40, 40, 960, 500], [40, 530, 480, 1000], [510, 530, 960, 1000], [40, 1030, 600, 1460], [630, 1030, 960, 1460]] as [$x0, $y0, $x1, $y1]) {
            imagefilledrectangle($image, $x0, $y0, $x1, $y1, $black);
            imagefilledrectangle($image, $x0 + 6, $y0 + 6, $x1 - 6, $y1 - 6, imagecolorallocate($image, 250, 250, 250));
            imagefilledellipse($image, intdiv($x0 + $x1, 2), intdiv($y0 + $y1, 2), intdiv($x1 - $x0, 2), intdiv($y1 - $y0, 2), imagecolorallocate($image, 120, 120, 120));
        }
        imagestring($image, 5, 480, 1475, '12', $black);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_cutter_finds_panels_in_manga_reading_order()
    {
        $boxes = PanelCutter::boxes(imagecreatefromstring(self::page()));
        $this->assertCount(5, $boxes);
        // Hàng giữa đọc từ phải sang trái.
        $this->assertGreaterThan($boxes[2]['x'], $boxes[1]['x']);
        $this->assertEqualsWithDelta(928, $boxes[0]['width'], 10);
        // Trang không có khe trắng: cả trang là một khung.
        $plain = imagecreatetruecolor(400, 600);
        $this->assertSame([['x' => 0, 'y' => 0, 'width' => 400, 'height' => 600]], PanelCutter::boxes($plain));
    }

    public function test_upload_pages_and_zip_cut_and_tag_panels()
    {
        Queue::fake();
        $zipPath = sys_get_temp_dir().'/manga-test.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('ch1/002.png', self::page());
        $zip->addFromString('__MACOSX/ch1/._002.png', 'junk');
        $zip->addFromString('notes.txt', 'hi');
        $zip->close();

        $this->actingAs($this->user)->post(route('manga-panels.store'), [
            'chapter' => 'Chương 317',
            'pages' => [UploadedFile::fake()->createWithContent('001.png', self::page()), new UploadedFile($zipPath, 'ch1.zip', 'application/zip', null, true)],
        ])->assertSessionHasNoErrors();
        $scans = $this->user->mangaScans()->get();
        $this->assertSame(['001.png', '002.png'], $scans->pluck('name')->all());
        Queue::assertPushed(ProcessMangaScanJob::class, 2);

        (new ProcessMangaScanJob($scans[0]))->handle();
        $scan = $scans[0]->fresh();
        $this->assertSame('ready', $scan->status, (string) $scan->error);
        $panels = $scan->panels()->get();
        $this->assertCount(5, $panels);
        $this->assertSame(['goku', 'frieza'], $panels[0]->characters);
        $this->assertSame('tense', $panels[0]->mood);
        $this->assertNotNull($panels[0]->tagged_at);
        Storage::disk(config('videoaff.disk'))->assertExists($panels[0]->image_path);

        $this->actingAs($this->user)->get(route('manga-panels.index', ['character' => 'goku']))->assertInertia(fn (Assert $page) => $page
            ->component('manga-panels/index')->has('panels', 1)->where('panels.0.characters.0', 'Son Goku')->where('chapters.0', 'Chương 317'));
        $this->actingAs($this->user)->getJson(route('manga-panels.search', ['q' => 'Vegeta']))->assertJsonCount(4, 'panels');

        // Sửa, ẩn, xoá; người khác không đụng được.
        $other = User::factory()->create();
        $this->actingAs($other)->put(route('manga-panels.update', $panels[1]), ['description' => 'x'])->assertForbidden();
        $this->actingAs($other)->delete(route('manga-scans.destroy', $scan))->assertForbidden();
        $this->actingAs($this->user)->put(route('manga-panels.update', $panels[1]), ['characters' => 'Krillin, ai đó', 'description' => 'Krillin cười', 'mood' => 'funny', 'useful' => false])->assertSessionHasNoErrors();
        $this->assertSame(['krillin'], $panels[1]->fresh()->characters);
        $this->assertFalse($panels[1]->fresh()->useful);

        // Cắt sai: dùng nguyên trang làm một khung (ảnh trang không bị xoá).
        $this->actingAs($this->user)->post(route('manga-scans.whole', $scan))->assertSessionHasNoErrors();
        $this->assertSame([$scan->image_path], $scan->panels()->pluck('image_path')->all());
        Storage::disk(config('videoaff.disk'))->assertExists($scan->image_path);
        Storage::disk(config('videoaff.disk'))->assertMissing($panels[0]->image_path);
        $this->actingAs($this->user)->delete(route('manga-scans.destroy', $scan))->assertSessionHasNoErrors();
        Storage::disk(config('videoaff.disk'))->assertMissing($scan->image_path);
        $this->assertSame(0, MangaPanel::where('manga_scan_id', $scan->id)->count());
    }

    public function test_story_scenes_use_real_panels_chosen_by_claude_and_can_be_changed()
    {
        Queue::fake();
        $scan = $this->user->mangaScans()->create(['chapter' => 'Namek', 'name' => 'p1.png', 'image_path' => 'manga/p1.png', 'status' => 'processing']);
        Media::disk()->put('manga/p1.png', self::page());
        (new ProcessMangaScanJob($scan))->handle();
        $ids = $scan->panels()->pluck('id')->all();

        $short = TopicShort::factory()->for($this->user)->create(['status' => 'writing', 'topic' => 'Goku nổi giận trên Namek', 'language' => 'vi']);
        (new WriteTopicShortJob($short))->handle();
        $scenes = $short->fresh()->scenes()->get();
        // Claude (mẫu) chọn 2 khung đầu cho cảnh 1: ảnh cảnh là trang ghép từ khung thật.
        $this->assertCount(2, $scenes[0]->manga_panels);
        $this->assertSame('page', $scenes[0]->image_source);
        $this->assertSame('ready', $scenes[0]->image_status);
        [$width, $height] = getimagesizefromstring(Media::disk()->get($scenes[0]->image_path));
        $this->assertSame([1080, 1920], [$width, $height]);

        // Đổi khung ở bước Ảnh; khung của người khác bị bỏ qua.
        $stranger = User::factory()->create();
        $foreign = $stranger->mangaScans()->create(['image_path' => 'x.png'])->panels()->create(['user_id' => $stranger->id, 'position' => 1, 'image_path' => 'x.png', 'width' => 1, 'height' => 1]);
        $scene = $scenes[1];
        $this->actingAs($this->user)->put(route('topic-short-scenes.manga', $scene), ['ids' => [$ids[4], $foreign->id]])->assertSessionHasNoErrors();
        $scene->refresh();
        $this->assertSame([$ids[4]], $scene->manga_panels);
        $this->assertSame('page', $scene->image_source);
        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))->assertInertia(fn (Assert $page) => $page->where('short.scenes.1.manga_panels.0.id', $ids[4]));
        $this->actingAs($this->user)->put(route('topic-short-scenes.manga', $scene), ['ids' => [1, 2, 3, 4]])->assertSessionHasErrors('ids');
    }
}
