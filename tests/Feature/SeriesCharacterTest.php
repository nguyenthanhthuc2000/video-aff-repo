<?php

namespace Tests\Feature;

use App\Jobs\GenerateCharacterImageJob;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\User;
use App\Services\Film\CharacterPrompt;
use App\Services\Film\FilmMuseImageGenerator;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Nhân vật của phim: thêm / xoá, Muse vẽ ảnh, tải ảnh, ảnh chính, chốt; trang chi tiết tập. */
class SeriesCharacterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Series $series;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user)->post(route('series.store'), [
            'idea' => 'A curious boy and his best friend run a tiny detective club in their town.',
            'genre' => 'mystery', 'age_min' => 6, 'age_max' => 12, 'episode_count' => 3, 'episode_minutes' => 5,
        ]);
        $this->series = $this->user->series()->sole();
    }

    private function character(): SeriesCharacter
    {
        return $this->series->characters()->first();
    }

    private function params(SeriesCharacter $character, array $extra = []): array
    {
        return ['series' => $this->series->id, 'character' => $character->id, ...$extra];
    }

    public function test_add_and_delete_character()
    {
        $this->actingAs($this->user)->post(route('series.characters.store', $this->series), [
            'name' => 'Ruby', 'role' => 'friend', 'age' => 9, 'personality' => 'Cheerful',
            'appearance' => 'A 9-year-old girl, short curly red hair, green raincoat, white boots.', 'voice_type' => 'kid_girl',
        ])->assertRedirect();

        $ruby = $this->series->characters()->where('name', 'Ruby')->sole();
        $this->assertSame(3, $ruby->position);
        // Bé gái thứ hai: giọng bé gái khác, không trùng nhân vật đã có.
        $this->assertSame('kid_girl', $ruby->voice['type']);
        $this->assertNotContains($ruby->voice['voice'], $this->series->characters()->whereKeyNot($ruby->id)->get()->pluck('voice.voice'));

        $this->actingAs($this->user)->delete(route('series.characters.destroy', $this->params($ruby)))->assertRedirect(route('series.show', $this->series));
        $this->assertSame(3, $this->series->characters()->count());
    }

    public function test_muse_draws_main_image_then_other_kinds_use_it_as_reference()
    {
        $character = $this->character();

        // Chưa có ảnh chính: chỉ vẽ được ảnh chính.
        $this->actingAs($this->user)->post(route('series.characters.generate', $this->params($character)), ['kind' => 'turnaround'])
            ->assertSessionHasErrors('kind');

        $this->actingAs($this->user)->post(route('series.characters.generate', $this->params($character)), ['kind' => 'main', 'notes' => 'smiling'])
            ->assertSessionHasNoErrors();
        $image = $character->images()->sole();
        $this->assertSame('pending', $image->status);
        $this->assertStringContainsString('Character design of Milo, 10 years old', $image->prompt);
        $this->assertStringContainsString('Additional direction: smiling.', $image->prompt);
        $this->assertStringContainsString(Series::DEFAULT_STYLE, $image->prompt);

        // Lần chạy đầu gửi yêu cầu (sync queue), lần sau hỏi kết quả.
        (new GenerateCharacterImageJob($image))->handle();

        $image->refresh();
        $character->refresh();
        $this->assertSame('ready', $image->status);
        $this->assertSame($image->path, $character->main_image_path);
        Storage::disk(config('videoaff.disk'))->assertExists($image->path);
        $this->assertSame(-800, (int) $this->user->creditTransactions()->where('reference', "character-image:{$image->id}")->value('credits'));

        $this->actingAs($this->user)->post(route('series.characters.generate', $this->params($character)), ['kind' => 'custom'])
            ->assertSessionHasErrors('notes');
        $this->actingAs($this->user)->post(route('series.characters.generate', $this->params($character)), ['kind' => 'turnaround'])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('exactly the same character as in the attached image', $character->images()->first()->prompt);
    }

    public function test_upload_set_main_lock_and_delete_rules()
    {
        $character = $this->character();
        $this->actingAs($this->user)->post(route('series.characters.upload', $this->params($character)), [
            'photos' => [UploadedFile::fake()->image('a.png', 640, 360), UploadedFile::fake()->image('b.png', 640, 360)],
        ])->assertSessionHasNoErrors();

        [$second, $first] = $character->images()->get()->all();
        $this->assertSame($first->path, $character->fresh()->main_image_path);

        $this->actingAs($this->user)->put(route('series.characters.images.main', $this->params($character, ['image' => $second->id])));
        $this->assertSame($second->path, $character->fresh()->main_image_path);

        $this->actingAs($this->user)->put(route('series.characters.lock', $this->params($character)), ['locked' => true]);
        $this->assertNotNull($character->fresh()->locked_at);

        // Đã chốt: không đổi ảnh chính, không xoá ảnh chính; ảnh khác vẫn xoá được.
        $this->actingAs($this->user)->put(route('series.characters.images.main', $this->params($character, ['image' => $first->id])));
        $this->assertSame($second->path, $character->fresh()->main_image_path);
        $this->actingAs($this->user)->delete(route('series.characters.images.destroy', $this->params($character, ['image' => $second->id])));
        $this->assertNotNull($second->fresh());
        $this->actingAs($this->user)->delete(route('series.characters.images.destroy', $this->params($character, ['image' => $first->id])));
        $this->assertNull($first->fresh());
        Storage::disk(config('videoaff.disk'))->assertMissing($first->path);

        $this->actingAs($this->user)->get(route('series.characters.show', $this->params($character)))
            ->assertInertia(fn (Assert $page) => $page->component('series/character')
                ->where('character.locked', true)
                ->has('character.images', 1)
                ->where('character.images.0.is_main', true)
                ->has('characters', 3));
    }

    public function test_lock_needs_main_image_and_other_users_are_blocked()
    {
        $character = $this->character();
        $this->actingAs($this->user)->put(route('series.characters.lock', $this->params($character)), ['locked' => true]);
        $this->assertNull($character->fresh()->locked_at);

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('series.characters.show', $this->params($character)))->assertForbidden();
        $this->actingAs($other)->post(route('series.characters.generate', $this->params($character)), ['kind' => 'main'])->assertForbidden();

        // Nhân vật của bộ phim khác: không truy cập qua URL bộ phim này.
        $this->actingAs($other)->post(route('series.store'), [
            'idea' => 'Two robot friends explore a city made of candy and learn to share.',
            'genre' => 'adventure', 'age_min' => 3, 'age_max' => 6, 'episode_count' => 1, 'episode_minutes' => 3,
        ]);
        $foreign = $other->series()->sole()->characters()->first();
        $this->actingAs($this->user)->get(route('series.characters.show', $this->params($foreign)))->assertNotFound();
    }

    public function test_episode_page_and_characters_in_episode()
    {
        [$first, $second] = $this->series->episodes()->get()->all();
        $character = $this->character();

        $this->actingAs($this->user)->get(route('series.episodes.show', ['series' => $this->series->id, 'episode' => $second->id]))
            ->assertInertia(fn (Assert $page) => $page->component('series/episode')
                ->where('episode.number', 2)
                ->where('previous.id', $first->id)
                ->where('next.number', 3)
                ->has('characters', 3)
                ->has('episode.shots', 0));

        $this->actingAs($this->user)->put(route('series.episodes.update', ['series' => $this->series->id, 'episode' => $second->id]), [
            'title' => 'The Lost Kite', 'synopsis' => 'A kite goes missing in the park.', 'lesson' => 'Share', 'character_ids' => [$character->id, $character->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame([$character->id], $second->fresh()->character_ids);
        $this->assertSame('The Lost Kite', $second->fresh()->title);

        $this->actingAs($this->user)->put(route('series.episodes.update', ['series' => $this->series->id, 'episode' => $second->id]), [
            'title' => 'x', 'synopsis' => 'y', 'character_ids' => [999999],
        ])->assertSessionHasErrors('character_ids.0');
    }

    public function test_film_prompt_is_2d_wide_and_text_free()
    {
        $message = FilmMuseImageGenerator::message('VA-ABC123', 'draw');

        $this->assertStringContainsString('2D cartoon illustration in a WIDE HORIZONTAL 16:9', $message);
        $this->assertStringContainsString('never write it', $message);
        $this->assertStringNotContainsString('photorealistic image', $message);
        $this->assertStringContainsString('Expression sheet', CharacterPrompt::build($this->character(), 'expressions', null, true));
        $this->assertNotNull(Media::disk());
    }
}
