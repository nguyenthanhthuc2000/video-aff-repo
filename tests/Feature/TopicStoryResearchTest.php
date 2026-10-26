<?php

namespace Tests\Feature;

use App\Jobs\CheckTopicShortFactsJob;
use App\Jobs\GenerateCharacterPortraitJob;
use App\Jobs\PlanTopicSeriesJob;
use App\Jobs\ResearchTopicJob;
use App\Jobs\WriteTopicShortJob;
use App\Models\LoreFact;
use App\Models\TopicShort;
use App\Models\User;
use App\Services\Script\ScriptWriterException;
use App\Services\TopicShort\MangaPage;
use App\Services\TopicShort\ShortWriter;
use App\Services\TopicShort\TopicResearcher;
use App\Support\DragonBall;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Video truyện có tư liệu thật: research → duyệt → kho tư liệu → kịch bản gắn nguồn, 3 hook, kiểm chứng, series. */
class TopicStoryResearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function create(array $data = []): TopicShort
    {
        $this->actingAs($this->user)->post(route('topic-shorts.store'), [
            'topic' => 'Vì sao Vegeta thay đổi?', 'style' => 'moment', 'image_style' => 'manga', 'language' => 'vi', 'target_seconds' => 60, 'wiki' => 'commons',
            'use_research' => true, ...$data,
        ])->assertRedirect();

        return $this->user->topicShorts()->latest('id')->first();
    }

    public function test_research_then_approve_saves_library_and_writes_script()
    {
        Queue::fake();
        $short = $this->create(['research_notes' => 'Chỉ kể manga']);
        $this->assertSame('researching', $short->status);
        $this->assertTrue($short->use_research);
        Queue::assertPushed(ResearchTopicJob::class);
        Queue::assertNotPushed(WriteTopicShortJob::class);

        (new ResearchTopicJob($short))->handle();
        $short->refresh();
        $this->assertSame('research_ready', $short->status);
        $this->assertCount(4, $short->research['facts']);
        $this->assertSame('F1', $short->research['facts'][0]['id']);
        $this->assertSame('', $short->research['facts'][3]['source_url']);

        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))
            ->assertInertia(fn (Assert $page) => $page->where('short.status', 'research_ready')->has('short.research.facts', 4));

        // Bỏ chi tiết giả thuyết, sửa chi tiết 1; phải giữ ít nhất 3.
        $facts = collect($short->research['facts'])->map(fn ($f) => ['id' => $f['id'], 'enabled' => true, 'text' => $f['text']])->all();
        $facts[1]['enabled'] = $facts[2]['enabled'] = false;
        $this->actingAs($this->user)->post(route('topic-shorts.research.approve', $short), ['facts' => $facts])->assertSessionHasErrors('facts');

        $facts[1]['enabled'] = $facts[2]['enabled'] = true;
        $facts[3]['enabled'] = false;
        $facts[0]['text'] = 'Vegeta đến Trái Đất cùng Nappa.';
        $this->actingAs($this->user)->post(route('topic-shorts.research.approve', $short), ['facts' => $facts, 'research_notes' => 'Nhấn mạnh Namek'])->assertSessionHasNoErrors()->assertRedirect();
        $short->refresh();
        $this->assertSame('writing', $short->status);
        $this->assertSame('Nhấn mạnh Namek', $short->research_notes);
        $this->assertSame(['F1', 'F2', 'F3'], array_column($short->approvedFacts(), 'id'));
        $this->assertSame(3, LoreFact::where('user_id', $this->user->id)->count());
        $this->assertSame(['vegeta', 'goku'], LoreFact::where('text', 'Vegeta đến Trái Đất cùng Nappa.')->sole()->characters);
        Queue::assertPushed(WriteTopicShortJob::class);

        (new WriteTopicShortJob($short))->handle();
        $short->refresh();
        $this->assertSame('ready', $short->status);
        $this->assertCount(3, $short->hooks);
        $this->assertSame(0, $short->hook_index);
        $first = $short->scenes()->where('position', 1)->sole();
        $this->assertSame($short->hooks[0]['text'], $first->narration);
        $this->assertNotNull($first->mood);
        Queue::assertPushed(CheckTopicShortFactsJob::class);

        (new CheckTopicShortFactsJob($short))->handle();
        $this->assertSame(0, $short->scenes()->whereNull('fact_check')->count());
        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))
            ->assertInertia(fn (Assert $page) => $page->has('short.scenes.0.fact_check.status')->has('short.hooks', 3));

        // Đổi hook: lời kể cảnh 1 đổi theo, cần kiểm chứng lại.
        $this->actingAs($this->user)->post(route('topic-shorts.hook', $short), ['index' => 2])->assertRedirect();
        $first->refresh();
        $this->assertSame($short->hooks[2]['text'], $first->narration);
        $this->assertSame('error', $first->fact_check['status']);
        $this->assertSame(2, $short->fresh()->hook_index);

        $this->actingAs($this->user)->post(route('topic-shorts.fact-check', $short))->assertRedirect();
        Queue::assertPushed(CheckTopicShortFactsJob::class, 2);
    }

    public function test_library_facts_are_reused_and_not_duplicated()
    {
        Queue::fake();
        $lore = $this->user->loreFacts()->create(['characters' => ['vegeta'], 'era' => 'Buu Saga', 'kind' => 'fact', 'text' => 'Vegeta tự hy sinh để cứu gia đình.']);
        User::factory()->create()->loreFacts()->create(['characters' => ['vegeta'], 'kind' => 'fact', 'text' => 'Của người khác.']);
        $short = $this->create();

        $this->assertSame([$lore->id], TopicResearcher::library($short)->pluck('id')->all());
        $research = TopicResearcher::normalize([
            'summary' => 'x',
            'library_ids' => ["L{$lore->id}", 'L999'],
            'facts' => [
                ['kind' => 'fact', 'era' => '', 'text' => 'Một', 'source_url' => 'javascript:alert(1)', 'source_title' => '', 'characters' => []],
                ['kind' => 'bogus', 'era' => '', 'text' => 'Hai', 'source_url' => 'https://kanzenshuu.com/a', 'source_title' => 'K', 'characters' => ['Vegeta']],
                ['kind' => 'fact', 'era' => '', 'text' => 'hai', 'source_url' => '', 'source_title' => '', 'characters' => []],
            ],
            'sources' => [['url' => 'ftp://x', 'title' => 'bad']],
        ], TopicResearcher::library($short)->keyBy('id')->all());
        $this->assertSame(['F1', 'F2', 'F3'], array_column($research['facts'], 'id'));
        $this->assertSame($lore->id, $research['facts'][0]['lore_id']);
        $this->assertSame('', $research['facts'][1]['source_url']);
        $this->assertSame('fact', $research['facts'][2]['kind']);
        $this->assertSame(['https://kanzenshuu.com/a'], array_column($research['sources'], 'url'));

        $short->update(['research' => $research]);
        $this->assertSame(2, TopicResearcher::remember($short));
        $this->assertSame(0, TopicResearcher::remember($short->fresh()));
        $this->assertSame(3, $this->user->loreFacts()->count());

        $this->expectException(ScriptWriterException::class);
        TopicResearcher::normalize(['facts' => [['text' => 'Chỉ một']]]);
    }

    public function test_series_splits_into_parts_sharing_research()
    {
        Queue::fake();
        $short = $this->create(['series_parts' => 3]);
        $this->assertSame(3, $short->series_parts);
        (new ResearchTopicJob($short))->handle();
        $facts = collect($short->fresh()->research['facts'])->map(fn ($f) => ['id' => $f['id'], 'enabled' => true, 'text' => $f['text']])->all();
        $this->actingAs($this->user)->post(route('topic-shorts.research.approve', $short), ['facts' => $facts])->assertRedirect();
        Queue::assertPushed(PlanTopicSeriesJob::class);

        (new PlanTopicSeriesJob($short->fresh()))->handle();
        $parts = $this->user->topicShorts()->orderBy('part')->get();
        $this->assertCount(3, $parts);
        $this->assertSame([1, 2, 3], $parts->pluck('part')->all());
        $this->assertCount(1, $parts->pluck('topic_series_id')->unique());
        $this->assertSame($short->fresh()->research, $parts[2]->research);
        $this->assertCount(3, $parts[0]->series->outline);
        Queue::assertPushed(WriteTopicShortJob::class, 3);

        (new WriteTopicShortJob($parts[1]))->handle();
        $this->assertSame('ready', $parts[1]->fresh()->status);
        $this->actingAs($this->user)->get(route('topic-shorts.show', $parts[1]))
            ->assertInertia(fn (Assert $page) => $page->where('short.part', 2)->has('short.series.parts', 3));
    }

    public function test_short_without_research_writes_directly()
    {
        Queue::fake();
        $short = $this->create(['use_research' => false, 'series_parts' => 3]);
        $this->assertSame('writing', $short->status);
        $this->assertSame(1, $short->series_parts);
        Queue::assertPushed(WriteTopicShortJob::class);
        Queue::assertNotPushed(ResearchTopicJob::class);
        (new WriteTopicShortJob($short))->handle();
        Queue::assertNotPushed(CheckTopicShortFactsJob::class);
    }

    public function test_panels_keep_only_library_characters_and_real_poses()
    {
        $panels = ShortWriter::panels([
            ['character' => 'Vegeta', 'pose' => 'a1', 'camera' => 'low angle'],
            ['character' => 'Unknown guy', 'pose' => 'E1', 'camera' => ''],
            ['character' => 'goku', 'pose' => 'S9', 'camera' => 'close-up'],
            ['character' => 'Frieza', 'pose' => 'E4', 'camera' => ''],
            ['character' => 'Cell', 'pose' => 'E1', 'camera' => ''],
        ]);
        $this->assertSame(['vegeta', 'goku', 'frieza'], array_column($panels, 'character'));
        $this->assertSame(['A1', 'E1', 'E4'], array_column($panels, 'pose'));
        $this->assertSame(['goku', 'vegeta'], DragonBall::charactersIn('Goku và Vegeta ai mạnh hơn?'));
    }

    public function test_manga_page_composes_library_panels_and_draws_missing()
    {
        Queue::fake();
        Storage::fake(config('videoaff.disk'));
        $portrait = fn (string $character, string $code) => $this->user->characterPortraits()->create([
            'character' => $character, 'expression' => $code, 'status' => 'ready', 'source' => 'upload',
            'image_path' => tap("character-portraits/{$character}-{$code}.png", fn ($path) => Media::disk()->put($path, self::png())),
        ]);
        $portrait('vegeta', 'E1');
        $portrait('vegeta', 'A1');
        $short = $this->user->topicShorts()->create(['topic' => 'Vegeta', 'language' => 'vi', 'style' => 'moment', 'image_style' => 'manga', 'target_seconds' => 60, 'wiki' => 'commons', 'status' => 'ready']);
        $scene = $short->scenes()->create(['position' => 1, 'narration' => 'x', 'image_status' => 'none', 'panels' => [
            ['character' => 'vegeta', 'pose' => 'A1', 'camera' => ''],
            ['character' => 'vegeta', 'pose' => 'E4', 'camera' => 'close-up'],
            ['character' => 'goku', 'pose' => 'E2', 'camera' => ''],
        ]]);

        $this->assertTrue(MangaPage::compose($scene));
        $scene->refresh();
        $this->assertSame('page', $scene->image_source);
        $this->assertSame('ready', $scene->image_status);
        [$width, $height] = getimagesizefromstring(Media::disk()->get($scene->image_path));
        $this->assertSame([1080, 1920], [$width, $height]);

        $missing = MangaPage::missing([$scene], $this->user->id);
        $this->assertSame(['vegeta:E4', 'goku:E2'], array_map(fn ($p) => "{$p['character']}:{$p['pose']}", $missing));
        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))
            ->assertInertia(fn (Assert $page) => $page->has('short.missing_panels', 2)->where('short.scenes.0.panels.1.exact', false)->where('short.scenes.0.panels.2.url', null));

        $this->actingAs($this->user)->post(route('topic-shorts.draw-missing', $short))->assertRedirect();
        $this->assertSame(['E1', 'E2', 'E4'], $this->user->characterPortraits()->where('status', 'pending')->orderBy('expression')->pluck('expression')->all());
        Queue::assertPushed(GenerateCharacterPortraitJob::class, 3);

        // Ảnh tải lên vào thư viện: trang manga đang chờ được ghép lại.
        $old = $scene->image_path;
        $this->actingAs($this->user)->post(route('character-library.upload', ['vegeta', 'E4']), ['image' => UploadedFile::fake()->image('v.png', 300, 400)])->assertRedirect();
        $this->assertNotSame($old, $scene->fresh()->image_path);
        $this->assertSame(1, count(MangaPage::missing([$scene->fresh()], $this->user->id)));
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(300, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 160, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_lore_facts_page_edit_delete_and_owner_only()
    {
        $fact = $this->user->loreFacts()->create(['characters' => ['vegeta'], 'kind' => 'fact', 'text' => 'Cũ']);
        $this->actingAs($this->user)->get(route('lore-facts.index', ['character' => 'vegeta']))
            ->assertInertia(fn (Assert $page) => $page->component('lore-facts/index')->has('facts', 1)->where('facts.0.characters.0', 'Vegeta'));
        $this->actingAs($this->user)->put(route('lore-facts.update', $fact), ['kind' => 'quote', 'text' => 'Mới', 'source_url' => 'https://dragonball.fandom.com/wiki/Vegeta'])->assertRedirect();
        $this->assertSame('Mới', $fact->fresh()->text);

        $other = User::factory()->create();
        $this->actingAs($other)->delete(route('lore-facts.destroy', $fact))->assertForbidden();
        $this->actingAs($this->user)->delete(route('lore-facts.destroy', $fact))->assertRedirect();
        $this->assertSame(0, LoreFact::count());
    }
}
