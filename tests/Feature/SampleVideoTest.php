<?php

namespace Tests\Feature;

use App\Models\SampleVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SampleVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function mp4(string $name = 'trend-vay-tay.mp4'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/tiny.mp4'), $name, 'video/mp4', null, true);
    }

    private function hasFfmpeg(): bool
    {
        return Process::run(['ffprobe', '-version'])->successful();
    }

    public function test_user_uploads_sample_video_with_metadata_and_thumbnail()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sample-videos.store'), [
            'video' => $this->mp4(),
            'source_url' => 'https://www.tiktok.com/@a/video/1',
            'rights' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $video = $user->sampleVideos()->sole();
        $this->assertSame('trend-vay-tay', $video->name);
        $this->assertSame('video/mp4', $video->mime);
        $this->assertNotNull($video->rights_confirmed_at);
        Storage::disk('public')->assertExists($video->path);

        if ($this->hasFfmpeg()) {
            $this->assertSame([64, 112], [$video->width, $video->height]);
            $this->assertEqualsWithDelta(400, $video->duration_ms, 100);
            Storage::disk('public')->assertExists($video->thumbnail_path);
        }

        $this->actingAs($user)->get(route('sample-videos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('sample-videos/index')
            ->has('sampleVideos', 1)
            ->where('sampleVideos.0.name', 'trend-vay-tay'));
    }

    public function test_upload_requires_rights_and_a_real_video()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sample-videos.store'), ['video' => $this->mp4()])->assertSessionHasErrors('rights');
        $this->actingAs($user)->post(route('sample-videos.store'), [
            'video' => UploadedFile::fake()->image('a.jpg'),
            'rights' => true,
        ])->assertSessionHasErrors('video');
        $this->assertSame(0, SampleVideo::count());
    }

    public function test_user_can_edit_and_delete_only_own_videos()
    {
        $video = SampleVideo::factory()->create(['thumbnail_path' => 'sample-videos/t.jpg']);
        Storage::disk('public')->put($video->path, 'x');
        Storage::disk('public')->put($video->thumbnail_path, 'x');
        $other = User::factory()->create();

        $this->actingAs($other)->put(route('sample-videos.update', $video), ['name' => 'Hack'])->assertForbidden();
        $this->actingAs($other)->delete(route('sample-videos.destroy', $video))->assertForbidden();
        $this->actingAs($other)->get(route('sample-videos.index'))->assertInertia(fn (Assert $page) => $page->has('sampleVideos', 0));

        $this->actingAs($video->user)->put(route('sample-videos.update', $video), ['name' => 'Trend mới', 'notes' => 'Nhịp nhanh'])->assertSessionHasNoErrors();
        $this->assertSame(['Trend mới', 'Nhịp nhanh'], [$video->fresh()->name, $video->fresh()->notes]);

        $this->actingAs($video->user)->delete(route('sample-videos.destroy', $video));
        $this->assertModelMissing($video);
        Storage::disk('public')->assertMissing($video->path);
        Storage::disk('public')->assertMissing('sample-videos/t.jpg');
    }
}
