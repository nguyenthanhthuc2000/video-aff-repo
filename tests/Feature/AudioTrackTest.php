<?php

namespace Tests\Feature;

use App\Enums\SceneImageStatus;
use App\Enums\VideoProjectStatus;
use App\Jobs\RenderVideoJob;
use App\Models\AudioTrack;
use App\Models\FashionModel;
use App\Models\Product;
use App\Models\Scene;
use App\Models\SceneImage;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Audio\RemoteAudio;
use App\Support\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AudioTrackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Không phụ thuộc DNS thật: cdn.test là IP công khai, intranet.test là IP nội bộ.
        RemoteAudio::$resolver = fn (string $host) => match ($host) {
            'intranet.test' => ['10.0.0.5'],
            default => ['93.184.216.34'],
        };
    }

    protected function tearDown(): void
    {
        RemoteAudio::$resolver = null;
        parent::tearDown();
    }

    private function mp3(): string
    {
        return file_get_contents(base_path('tests/Fixtures/tiny.mp3'));
    }

    public function test_upload_audio_file()
    {
        $user = User::factory()->create();
        $file = new UploadedFile(base_path('tests/Fixtures/tiny.mp3'), 'nhac-vui.mp3', 'audio/mpeg', null, true);

        $this->actingAs($user)->post(route('audio-tracks.store'), ['audio' => $file, 'rights' => true])->assertSessionHasNoErrors();

        $track = $user->audioTracks()->sole();
        $this->assertSame('nhac-vui', $track->name);
        $this->assertTrue($track->isPlayable());
        Storage::disk('public')->assertExists($track->path);
        $this->assertEqualsWithDelta(500, $track->duration_ms ?? 500, 150);
    }

    public function test_link_to_audio_file_is_downloaded()
    {
        Http::fake(['cdn.test/*' => Http::response($this->mp3(), 200, ['Content-Type' => 'application/octet-stream'])]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('audio-tracks.store'), ['source_url' => 'https://cdn.test/songs/beat.mp3', 'rights' => true])
            ->assertSessionHasNoErrors();

        $track = $user->audioTracks()->sole();
        $this->assertSame('beat.mp3', $track->name);
        $this->assertTrue($track->isPlayable());
        $this->assertSame('https://cdn.test/songs/beat.mp3', $track->source_url);
        Storage::disk('public')->assertExists($track->path);
    }

    public function test_link_to_web_page_is_saved_as_link_only()
    {
        Http::fake(['*' => Http::response('<html>TikTok</html>', 200, ['Content-Type' => 'text/html'])]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('audio-tracks.store'), ['source_url' => 'https://cdn.test/music/trend-123', 'name' => 'Trend TikTok', 'rights' => true])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'chỉ để tham khảo'));

        $track = $user->audioTracks()->sole();
        $this->assertFalse($track->isPlayable());
        $this->assertSame('Trend TikTok', $track->name);
    }

    public function test_link_to_private_network_or_huge_file_is_rejected()
    {
        Http::fake(['cdn.test/*' => Http::response(str_repeat('a', 100), 200, ['Content-Length' => (string) (50 * 1024 * 1024)])]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('audio-tracks.store'), ['source_url' => 'http://intranet.test/a.mp3', 'rights' => true])
            ->assertSessionHasErrors('source_url');
        $this->actingAs($user)->post(route('audio-tracks.store'), ['source_url' => 'https://cdn.test/huge.mp3', 'rights' => true])
            ->assertSessionHasErrors('source_url');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'intranet.test'));
        $this->assertSame(0, AudioTrack::count());

        $this->actingAs($user)->post(route('audio-tracks.store'), ['rights' => true])->assertSessionHasErrors('audio');
    }

    public function test_tracks_are_private_and_in_use_tracks_cannot_be_deleted()
    {
        $track = AudioTrack::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->put(route('audio-tracks.update', $track), ['name' => 'x'])->assertForbidden();
        $this->actingAs($other)->delete(route('audio-tracks.destroy', $track))->assertForbidden();

        VideoProject::factory()->for($track->user)->create(['audio_track_id' => $track->id]);
        $this->actingAs($track->user)->delete(route('audio-tracks.destroy', $track))->assertSessionHasErrors('audio');
        $this->assertModelExists($track);

        $this->actingAs($track->user)->get(route('audio-tracks.index'))->assertInertia(fn (Assert $page) => $page
            ->where('audioTracks.0.used_count', 1));
    }

    public function test_choose_music_when_creating_and_rendering()
    {
        Queue::fake();
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $model = FashionModel::factory()->for($user)->create();
        $track = AudioTrack::factory()->for($user)->create();
        $linkOnly = AudioTrack::factory()->for($user)->linkOnly()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id, 'fashion_model_id' => $model->id, 'audio_track_id' => $linkOnly->id,
        ])->assertSessionHasErrors('audio_track_id');
        $this->actingAs($user)->post(route('projects.store'), [
            'product_id' => $product->id, 'fashion_model_id' => $model->id, 'audio_track_id' => $track->id,
        ])->assertSessionHasNoErrors();

        $project = $user->videoProjects()->sole();
        $this->assertTrue($project->audioTrack->is($track));

        // Bước xuất video: đổi sang không nhạc.
        $project->update(['status' => VideoProjectStatus::Reviewing, 'script' => ['hooks' => ['H']]]);
        $scene = Scene::factory()->for($project)->create();
        SceneImage::factory()->for($scene)->create(['status' => SceneImageStatus::Approved]);

        $this->actingAs($user)->post(route('projects.renders.store', $project), ['hook' => 0, 'audio_track_id' => null, 'remove_music' => true])
            ->assertSessionHasNoErrors();
        $this->assertNull($project->fresh()->audio_track_id);
        Queue::assertPushed(RenderVideoJob::class);
    }

    public function test_workflow_setting_is_saved_and_shared()
    {
        $user = User::factory()->admin()->create();
        $this->assertSame('auto', Workflow::mode());

        $this->actingAs($user)->put(route('video-settings.update'), [
            'mode' => 'auto', 'scene_count' => 6, 'target_seconds' => 25, 'workflow' => 'manual',
        ])->assertSessionHasNoErrors();

        $this->assertSame('manual', Workflow::mode());
        $this->actingAs($user)->get(route('video-settings.show'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.workflow', 'manual')
            ->where('workflow', 'manual'));

        $this->actingAs($user)->put(route('video-settings.update'), [
            'mode' => 'auto', 'scene_count' => 6, 'target_seconds' => 25, 'workflow' => 'sometimes',
        ])->assertSessionHasErrors('workflow');
    }
}
