<?php

namespace Tests\Feature;

use App\Models\TopicShort;
use App\Models\User;
use App\Services\TopicShort\ShortVideo;
use App\Support\Pronounce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Giọng Việt đọc tên tiếng Anh theo bảng cách đọc; phụ đề giữ tên gốc. */
class PronunciationTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_replaces_whole_words_longest_first_case_insensitive()
    {
        $this->assertSame('Xôn Gô cu gặp Vê ghê ta ở Na mếch.', Pronounce::apply('Son Goku gặp VEGETA ở Namek.', null));
        $this->assertSame('Xu pơ Xai dan Bờ lu', Pronounce::apply('Super Saiyan Blue', null));
        // Không đụng chữ nằm trong từ khác.
        $this->assertSame('Gokuu', Pronounce::apply('Gokuu', null));
    }

    public function test_user_words_override_defaults_and_are_used_by_the_voice_line()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('pronunciations.store'), ['word' => ' Goku ', 'say' => 'Gồ cu'])->assertRedirect();
        $this->actingAs($user)->post(route('pronunciations.store'), ['word' => 'Hit', 'say' => 'Hít'])->assertRedirect();
        $this->assertDatabaseHas('pronunciations', ['user_id' => $user->id, 'word' => 'goku', 'say' => 'Gồ cu']);

        $short = TopicShort::factory()->for($user)->create(['language' => 'vi']);
        $this->assertSame('Gồ cu đấu Hít', ShortVideo::line($short, 'Goku đấu Hit')['text']);
        // Người khác vẫn dùng cách đọc mặc định.
        $this->assertSame('Gô cu', Pronounce::apply('Goku', User::factory()->create()->id));
        // Short tiếng Anh giữ nguyên chữ.
        $this->assertSame('Goku', ShortVideo::line(TopicShort::factory()->for($user)->create(['language' => 'en']), 'Goku')['text']);

        $found = collect(Pronounce::found('Goku và Vegeta', $user->id))->keyBy('word');
        $this->assertTrue($found['goku']['custom']);
        $this->assertSame('Vê ghê ta', $found['vegeta']['say']);
        $this->assertTrue($found->has('hit'));

        $this->actingAs($user)->delete(route('pronunciations.destroy'), ['word' => 'GOKU'])->assertRedirect();
        $this->assertSame('Gô cu', Pronounce::apply('Goku', $user->id));
    }

    public function test_show_lists_names_in_narration()
    {
        $user = User::factory()->create();
        $short = TopicShort::factory()->for($user)->create(['language' => 'vi', 'status' => 'ready']);
        $short->scenes()->create(['position' => 1, 'narration' => 'Frieza phá huỷ hành tinh của Vegeta.', 'image_status' => 'none']);

        $this->actingAs($user)->get(route('topic-shorts.show', $short))->assertInertia(fn (Assert $page) => $page
            ->where('pronunciations.0.word', 'vegeta')
            ->where('pronunciations.1.word', 'frieza')
            ->has('pronunciations', 2));
    }

    public function test_preview_returns_wav()
    {
        Http::fake(['*/tts' => Http::response(['base64' => base64_encode('RIFFwav'), 'duration' => 1, 'lines' => [['start' => 0, 'end' => 1]]])]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('pronunciations.preview', ['say' => 'Gô cu']))
            ->assertOk()->assertHeader('Content-Type', 'audio/wav');
        Http::assertSent(fn ($request) => $request['lines'][0]['text'] === 'Gô cu' && $request['lines'][0]['lang'] === 'vi');
        $this->actingAs($user)->get(route('pronunciations.preview'))->assertSessionHasErrors('say');
    }
}
