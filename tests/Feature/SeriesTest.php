<?php

namespace Tests\Feature;

use App\Enums\SeriesStatus;
use App\Jobs\PlanSeriesJob;
use App\Models\Series;
use App\Models\User;
use App\Services\Film\AgeRules;
use App\Services\Film\SeriesPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phim hoạt hình: tạo từ ý tưởng (độ tuổi từ … đến …), Claude lên kế hoạch, sửa kế hoạch, quyền theo chủ phim. */
class SeriesTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return [
            'idea' => 'A curious boy and his best friend run a tiny detective club and solve small mysteries in their town.',
            'genre' => 'mystery',
            'age_min' => 6,
            'age_max' => 12,
            'episode_count' => 4,
            'episode_minutes' => 5,
            'subtitle_languages' => ['es', 'vi'],
            ...$override,
        ];
    }

    public function test_creating_series_plans_it_in_background()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('series.store'), $this->payload())->assertRedirect();

        $series = $user->series()->sole();
        $this->assertSame(SeriesStatus::BibleReady, $series->status);
        $this->assertTrue($series->made_for_kids);
        $this->assertSame(300, $series->episode_seconds);
        $this->assertSame(Series::DEFAULT_STYLE, $series->style);
        $this->assertSame(['es', 'vi'], $series->subtitle_languages);
        $this->assertSame('Milo and the Mystery Club', $series->title);
        $this->assertSame(4, $series->episodes()->count());
        $this->assertSame([1, 2, 3, 4], $series->episodes->pluck('number')->all());
        $this->assertSame(3, $series->characters()->count());
        $this->assertSame('am_puck', $series->characters()->first()->voice['voice']);
        $this->assertSame(3, $series->locations()->count());
    }

    public function test_creator_uploads_character_images_and_picks_30_second_episodes()
    {
        Storage::fake(config('videoaff.disk'));
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('series.store'), $this->payload([
            'episode_minutes' => null,
            'episode_seconds' => 30,
            'characters' => [
                ['name' => 'Milo', 'note' => 'nhân vật chính', 'image' => UploadedFile::fake()->image('milo.png', 400, 400)],
                ['name' => 'Bông', 'note' => 'cô mèo trắng', 'image' => UploadedFile::fake()->image('bong.jpg', 400, 400)],
            ],
        ]))->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'xem ảnh 2 nhân vật'));

        $series = $user->series()->sole();
        $this->assertSame(30, $series->episode_seconds);
        $this->assertSame(SeriesStatus::BibleReady, $series->status, (string) $series->error);
        $this->assertStringContainsString('30 seconds', SeriesPlanner::userText($series));
        $this->assertStringContainsString('Image 1: Milo (nhân vật chính)', SeriesPlanner::userText($series));
        // Milo có trong kế hoạch của Claude: gắn ảnh; Bông Claude bỏ sót: vẫn được thêm, có ảnh.
        $milo = $series->characters()->where('name', 'Milo')->sole();
        $bong = $series->characters()->where('name', 'Bông')->sole();
        foreach ([$milo, $bong] as $character) {
            $this->assertNotNull($character->main_image_path);
            Storage::disk(config('videoaff.disk'))->assertExists($character->main_image_path);
            $this->assertSame(1, $character->images()->where('source', 'upload')->count());
        }
        $this->assertSame('cô mèo trắng', $bong->appearance);
        $this->assertNull($series->characters()->where('name', 'Lily')->value('main_image_path'));

        // Ảnh bắt buộc, tên không trùng, độ dài phải trong danh sách.
        $this->actingAs($user)->post(route('series.store'), $this->payload(['episode_seconds' => 45, 'characters' => [['name' => 'A'], ['name' => 'a', 'image' => UploadedFile::fake()->image('a.png')]]]))
            ->assertSessionHasErrors(['episode_seconds', 'characters.0.image', 'characters.0.name']);
    }

    public function test_teen_series_is_not_made_for_kids()
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('series.store'), $this->payload(['age_min' => 13, 'age_max' => 16]))->assertRedirect();

        $this->assertFalse($user->series()->sole()->made_for_kids);
        Queue::assertPushed(PlanSeriesJob::class);
    }

    public function test_age_range_and_copyrighted_ideas_are_validated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('series.store'), $this->payload(['age_min' => 10, 'age_max' => 8]))->assertSessionHasErrors('age_max');
        $this->actingAs($user)->post(route('series.store'), $this->payload(['age_min' => 2]))->assertSessionHasErrors('age_min');
        $this->actingAs($user)->post(route('series.store'), $this->payload(['idea' => 'Doraemon and Nobita go on a new adventure in space together']))
            ->assertSessionHasErrors('idea');
        $this->assertSame(0, Series::count());
    }

    public function test_age_rules_use_youngest_limits_and_middle_story()
    {
        $prompt = AgeRules::prompt(6, 12);

        $this->assertStringContainsString('aged 6–12', $prompt);
        $this->assertStringContainsString('always a happy ending', $prompt);
        $this->assertStringContainsString('layered plots', $prompt);
        $this->assertNull(SeriesPlanner::blockedName('A conference of animals'));
        $this->assertSame('pikachu', SeriesPlanner::blockedName('a yellow mouse like Pikachu'));
    }

    public function test_owner_edits_plan_and_locked_appearance_is_kept()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('series.store'), $this->payload());
        $series = $user->series()->sole();
        [$milo, $lily] = $series->characters()->get()->all();
        $milo->update(['locked_at' => now()]);
        $episode = $series->episodes()->first();
        $location = $series->locations()->first();

        $this->actingAs($user)->put(route('series.update', $series), [
            'title' => 'Mystery Club',
            'bible' => ['logline' => 'New logline', 'premise' => 'P', 'world' => 'W', 'tone' => 'T'],
            'characters' => [
                ['id' => $milo->id, 'name' => 'Milo', 'role' => 'main', 'age' => 11, 'personality' => 'Brave', 'appearance' => 'changed', 'voice_type' => 'kid_boy'],
                ['id' => $lily->id, 'name' => 'Lily', 'role' => 'friend', 'age' => 10, 'personality' => 'Smart', 'appearance' => 'A girl in a red dress', 'voice_type' => 'adult_female'],
            ],
            'locations' => [['id' => $location->id, 'name' => 'Treehouse', 'description' => 'A big treehouse']],
            'episodes' => [['id' => $episode->id, 'title' => 'The Lost Kite', 'synopsis' => 'A kite goes missing.', 'lesson' => 'Share']],
        ])->assertSessionHasNoErrors();

        $series->refresh();
        $this->assertSame('Mystery Club', $series->title);
        $this->assertSame('New logline', $series->bible['logline']);
        $this->assertSame(['friendship', 'curiosity', 'honesty'], $series->bible['themes']);
        $this->assertStringContainsString('yellow hoodie', $milo->fresh()->appearance);
        $this->assertSame(11, $milo->fresh()->age);
        $this->assertSame('A girl in a red dress', $lily->fresh()->appearance);
        $this->assertSame('af_sarah', $lily->fresh()->voice['voice']);
        $this->assertSame('The Lost Kite', $episode->fresh()->title);
        $this->assertSame('Treehouse', $location->fresh()->name);

        // Đã chốt nhân vật: không cho lên kế hoạch lại (sẽ thay hết).
        Queue::fake();
        $this->actingAs($user)->post(route('series.plan', $series))->assertSessionHas('status');
        Queue::assertNothingPushed();
    }

    public function test_other_users_cannot_see_or_edit_series()
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post(route('series.store'), $this->payload());
        $series = $owner->series()->sole();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('series.show', $series))->assertForbidden();
        $this->actingAs($other)->put(route('series.update', $series), ['title' => 'x'])->assertForbidden();
        $this->actingAs($other)->delete(route('series.destroy', $series))->assertForbidden();
        $this->actingAs($other)->get(route('series.index'))->assertInertia(fn (Assert $page) => $page->component('series/index')->has('series', 0));

        $this->actingAs($owner)->get(route('series.show', $series))
            ->assertInertia(fn (Assert $page) => $page->component('series/show')->has('series.episodes', 4)->where('series.can_replan', true));
        $this->actingAs($owner)->delete(route('series.destroy', $series))->assertRedirect(route('series.index'));
        $this->assertSame(0, Series::count());
    }

    public function test_failed_planning_is_shown_on_series()
    {
        $user = User::factory()->create();
        config(['videoaff.script_driver' => 'web']);

        $this->actingAs($user)->post(route('series.store'), $this->payload());

        $series = $user->series()->sole();
        $this->assertSame(SeriesStatus::Failed, $series->status);
        $this->assertStringContainsString('cần kết nối Claude', $series->error);
    }
}
