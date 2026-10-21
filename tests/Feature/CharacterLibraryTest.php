<?php

namespace Tests\Feature;

use App\Jobs\FindTopicSceneImageJob;
use App\Jobs\GenerateCharacterPortraitJob;
use App\Jobs\GenerateTopicSceneImageJob;
use App\Models\CharacterPortrait;
use App\Models\TopicShort;
use App\Models\TopicShortScene;
use App\Models\User;
use App\Services\Film\FakeFilmImageGenerator;
use App\Services\Image\GeneratedImage;
use App\Services\Image\ImageGeneratorException;
use App\Services\TopicShort\WikiImageSearch;
use App\Support\DragonBall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Thư viện nhân vật Ngọc Rồng: vẽ chân dung theo biểu cảm, dùng làm ảnh cảnh / ảnh mẫu cho Shorts chủ đề. */
class CharacterLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('videoaff.disk'));
        $this->user = User::factory()->create();
    }

    public function test_library_lists_every_character_with_expressions()
    {
        $this->assertCount(30, DragonBall::CHARACTERS);
        foreach (DragonBall::CHARACTERS as [, , $suggested]) {
            $this->assertContains('E1', $suggested);
        }
        $this->assertSame('goku', DragonBall::keyFor('Goku'));
        $this->assertSame('goku-ssj', DragonBall::keyFor('goku super saiyan'));
        $this->assertNull(DragonBall::keyFor('Naruto'));

        $this->actingAs($this->user)->get(route('character-library.index'))->assertInertia(fn (Assert $page) => $page
            ->component('character-library/index')->has('characters', 30)->where('characters.0.name', 'Son Goku')->has('expressions', 9));
    }

    public function test_draw_character_neutral_first_then_expressions_follow_it()
    {
        Queue::fake();
        $this->actingAs($this->user)->post(route('character-library.draw'), ['character' => 'krillin'])->assertSessionHas('status', 'Muse đang vẽ 5 ảnh...');
        $this->assertSame(5, $this->user->characterPortraits()->where('status', 'pending')->count());
        $this->assertSame(['E1', 'E2', 'E4', 'E6', 'E9'], Queue::pushed(GenerateCharacterPortraitJob::class)->map(fn ($job) => $job->portrait->expression)->values()->all());

        // Biểu cảm khác chờ mặt thường xong.
        $shocked = $this->user->characterPortraits()->where('expression', 'E4')->first();
        (new GenerateCharacterPortraitJob($shocked))->handle();
        $this->assertNull($shocked->fresh()->request_id);

        $neutral = $this->user->characterPortraits()->where('expression', 'E1')->first();
        (new GenerateCharacterPortraitJob($neutral))->handle();
        (new GenerateCharacterPortraitJob($neutral))->handle();
        $neutral->refresh();
        $this->assertSame('ready', $neutral->status, (string) $neutral->error);
        Storage::disk(config('videoaff.disk'))->assertExists($neutral->image_path);

        $this->assertSame([$neutral->image_path], GenerateCharacterPortraitJob::references($shocked));
        $this->assertStringContainsString('keep the face, hair and outfit identical', DragonBall::prompt('krillin', 'E4', true));
        $this->assertStringContainsString('Black and white manga panel', DragonBall::prompt('krillin', 'E4'));
        (new GenerateCharacterPortraitJob($shocked))->handle();
        (new GenerateCharacterPortraitJob($shocked))->handle();
        $this->assertSame('ready', $shocked->fresh()->status);
        $this->assertSame(2, $this->user->creditTransactions()->where('reference', 'like', 'character-portrait:%')->count());

        // Vẽ cả thư viện: chỉ ảnh gợi ý còn thiếu, bỏ qua ảnh đang vẽ / đã có.
        $this->actingAs($this->user)->post(route('character-library.draw'))->assertSessionHas('status');
        $total = collect(DragonBall::CHARACTERS)->sum(fn ($c) => count($c[2]));
        $this->assertSame($total, $this->user->characterPortraits()->count());
    }

    public function test_upload_portrait_and_use_it_in_topic_short_scenes()
    {
        Queue::fake();
        $this->actingAs($this->user)->post(route('character-library.upload', ['goku', 'E3']), ['image' => UploadedFile::fake()->image('goku.png', 600, 1000)])
            ->assertSessionHasNoErrors();
        $angry = $this->user->characterPortraits()->sole();
        $this->assertSame('upload', $angry->source);
        $this->actingAs($this->user)->post(route('character-library.upload', ['nobody', 'E3']), ['image' => UploadedFile::fake()->image('x.png')])->assertNotFound();

        $short = TopicShort::factory()->for($this->user)->create([
            'status' => 'ready',
            'characters' => [['name' => 'Goku', 'look' => 'spiky hair', 'image_path' => null], ['name' => 'Frieza', 'look' => 'tail', 'image_path' => null]],
        ]);
        $scene = TopicShortScene::factory()->for($short)->create(['characters' => ['Goku', 'Frieza'], 'expression' => 'E3', 'image_status' => 'searching', 'image_query' => 'nothing']);

        // Ảnh thư viện làm ảnh mẫu khi Muse vẽ cảnh, kèm biểu cảm.
        $this->assertSame([$angry->image_path], GenerateTopicSceneImageJob::references($scene));
        $prompt = GenerateTopicSceneImageJob::prompt($scene);
        $this->assertStringContainsString('Goku — looks exactly like attached reference image 1', $prompt);
        $this->assertStringContainsString('MAIN EXPRESSION: furious', $prompt);
        $this->assertSame(CharacterPortrait::best($this->user->id, 'goku', 'E5')?->id, null);

        // Không có ảnh wiki: dùng ảnh thư viện trước khi để Muse vẽ.
        Http::fake(['*' => Http::response(['batchcomplete' => ''])]);
        (new FindTopicSceneImageJob($scene))->handle(app(WikiImageSearch::class));
        $scene->refresh();
        $this->assertSame('library', $scene->image_source);
        $this->assertSame('ready', $scene->image_status);
        Queue::assertNotPushed(GenerateTopicSceneImageJob::class);

        $this->actingAs($this->user)->get(route('topic-shorts.show', $short))->assertInertia(fn (Assert $page) => $page
            ->where('short.scenes.0.library.0.id', $angry->id)->where('short.scenes.0.expression', 'E3'));
        $scene->update(['image_source' => 'muse']);
        $this->actingAs($this->user)->post(route('topic-short-scenes.library', $scene), ['portrait_id' => $angry->id])->assertSessionHas('status');
        $this->assertSame('library', $scene->fresh()->image_source);

        $other = User::factory()->create();
        $theirs = $other->characterPortraits()->create(['character' => 'goku', 'expression' => 'E1', 'status' => 'ready', 'image_path' => 'x.png']);
        $this->actingAs($this->user)->post(route('topic-short-scenes.library', $scene), ['portrait_id' => $theirs->id])->assertNotFound();
    }

    public function test_refused_drawing_retries_once_without_names()
    {
        $prompts = new \ArrayObject;
        $this->app->bind(FakeFilmImageGenerator::class, fn () => new class($prompts) extends FakeFilmImageGenerator
        {
            public function __construct(private \ArrayObject $prompts) {}

            public function start(array $referencePaths, string $prompt): string
            {
                $this->prompts[] = $prompt;

                return parent::start($referencePaths, $prompt);
            }

            public function poll(string $code, array $referencePaths): ?GeneratedImage
            {
                if (count($this->prompts) === 1) {
                    throw ImageGeneratorException::rejected('AI từ chối tạo yêu cầu này.', 'copyrighted character');
                }

                return parent::poll($code, $referencePaths);
            }
        });
        $portrait = $this->user->characterPortraits()->create(['character' => 'vegeta', 'expression' => 'E1', 'status' => 'pending']);
        foreach (range(1, 4) as $_) {
            (new GenerateCharacterPortraitJob($portrait))->handle();
        }
        $this->assertSame('ready', $portrait->fresh()->status, (string) $portrait->fresh()->error);
        $this->assertCount(2, $prompts);
        $this->assertStringContainsString('Vegeta', $prompts[0]);
        $this->assertStringNotContainsString('Vegeta', $prompts[1]);
        $this->assertStringNotContainsString('Dragon Ball', $prompts[1]);
        $this->assertStringContainsString("widow's peak", $prompts[1]);

        $short = TopicShort::factory()->for($this->user)->create(['art_style' => 'Dragon Ball, Akira Toriyama character design', 'characters' => [['name' => 'Goku', 'look' => 'Son Goku, spiky black hair, gi with Kanji', 'image_path' => null]]]);
        $scene = TopicShortScene::factory()->for($short)->create(['characters' => ['Goku'], 'image_prompt' => 'Goku turns Super Saiyan on Namek']);
        $safe = GenerateTopicSceneImageJob::prompt($scene, true);
        $this->assertStringNotContainsString('Goku', $safe);
        $this->assertStringNotContainsString('Toriyama', $safe);
        $this->assertStringContainsString('Character 1 turns golden-haired powered-up on green alien planet', $safe);
    }
}
