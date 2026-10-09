<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use App\Models\VideoProject;
use App\Services\ProductImport\ClaudeCliProductExtractor;
use App\Services\ProductImport\PageSnapshot;
use App\Services\ProductImport\ProductExtractor;
use App\Services\ProductImport\ProductImportException;
use App\Services\Script\ClaudeCliScriptWriter;
use App\Services\Script\ScriptWriter;
use App\Services\Script\ScriptWriterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClaudeCliTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->dir = sys_get_temp_dir().'/claude-cli-test-'.uniqid();
        mkdir($this->dir);
        config([
            'videoaff.script_driver' => 'claude_cli',
            'videoaff.claude_cli.binary' => $this->dir.'/claude',
            'videoaff.claude_cli.oauth_token' => 'tok-123',
            'videoaff.claude_cli.model' => 'sonnet',
        ]);
    }

    /** CLI giả: lưu stdin, tham số và biến môi trường rồi in event "result". */
    private function fakeCli(array $result): void
    {
        $line = json_encode(['type' => 'result', 'subtype' => 'success', 'total_cost_usd' => 0.01, ...$result], JSON_UNESCAPED_UNICODE);
        file_put_contents($this->dir.'/claude', "#!/bin/sh\ncat > {$this->dir}/stdin.json\necho \"\$@\" > {$this->dir}/args.txt\necho \"\$CLAUDE_CODE_OAUTH_TOKEN\" > {$this->dir}/token.txt\n"
            ."echo '{\"type\":\"system\",\"subtype\":\"init\"}'\ncat <<'JSON'\n{$line}\nJSON\n");
        chmod($this->dir.'/claude', 0755);
    }

    private function script(): array
    {
        return [
            'hooks' => ['H1', 'H2', 'H3'],
            'scenes' => [['visual' => 'V', 'on_screen_text' => 'T', 'voiceover' => 'L', 'image_prompt' => 'P', 'duration_ms' => 3000]],
            'caption' => 'C',
            'hashtags' => ['#a'],
        ];
    }

    public function test_drivers_are_bound()
    {
        $this->assertInstanceOf(ClaudeCliScriptWriter::class, app(ScriptWriter::class));
        $this->assertInstanceOf(ClaudeCliProductExtractor::class, app(ProductExtractor::class));
    }

    public function test_script_writer_sends_images_and_reads_structured_output()
    {
        $this->fakeCli(['is_error' => false, 'result' => '', 'structured_output' => $this->script()]);
        $project = VideoProject::factory()->create();
        $image = ProductImage::factory()->for($project->product)->create(['path' => 'products/a.jpg', 'is_primary' => true]);
        Storage::disk('public')->put($image->path, UploadedFile::fake()->image('a.jpg')->getContent());

        $script = app(ScriptWriter::class)->write($project);

        $this->assertSame(['H1', 'H2', 'H3'], $script['hooks']);
        $input = json_decode(file_get_contents($this->dir.'/stdin.json'), true);
        $this->assertSame('image', $input['message']['content'][0]['type']);
        $this->assertSame('image/jpeg', $input['message']['content'][0]['source']['media_type']);
        $this->assertStringContainsString($project->product->name, $input['message']['content'][1]['text']);
        $args = file_get_contents($this->dir.'/args.txt');
        $this->assertStringContainsString('--tools  --setting-sources', $args);
        $this->assertStringContainsString('--model sonnet', $args);
        $this->assertSame('tok-123', trim(file_get_contents($this->dir.'/token.txt')));
    }

    public function test_extractor_returns_fields()
    {
        $this->fakeCli(['is_error' => false, 'structured_output' => ['name' => 'Váy', 'price' => 199000, 'sizes' => 'S', 'material' => '', 'colors' => '', 'notes' => '', 'reviews' => '']]);

        $fields = app(ProductExtractor::class)->extract(new PageSnapshot('https://shopee.vn/x', 'Váy', 'nội dung'));

        $this->assertSame(199000, $fields['price']);
    }

    public function test_vietnamese_text_with_byte_0x85_is_not_split()
    {
        // "ễ" là E1 BB 85: không được coi byte 0x85 là xuống dòng.
        $this->fakeCli(['is_error' => false, 'structured_output' => ['name' => 'Váy dễ thương', 'price' => null, 'sizes' => '', 'material' => '', 'colors' => '', 'notes' => '', 'reviews' => 'Giao hàng nhanh, dễ mặc']]);

        $fields = app(ProductExtractor::class)->extract(new PageSnapshot('https://shopee.vn/x', 'Váy', 'nội dung'));

        $this->assertSame('Váy dễ thương', $fields['name']);
        $this->assertSame('Giao hàng nhanh, dễ mặc', $fields['reviews']);
    }

    public function test_not_logged_in_is_not_retryable()
    {
        $this->fakeCli(['is_error' => true, 'result' => 'Not logged in · Please run /login']);

        try {
            app(ScriptWriter::class)->write(VideoProject::factory()->create());
            $this->fail('Phải ném lỗi.');
        } catch (ScriptWriterException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('claude setup-token', $e->getMessage());
        }
    }

    public function test_usage_limit_is_retryable()
    {
        $this->fakeCli(['is_error' => true, 'result' => 'Claude usage limit reached. Your limit will reset at 5pm']);

        try {
            app(ScriptWriter::class)->write(VideoProject::factory()->create());
            $this->fail('Phải ném lỗi.');
        } catch (ScriptWriterException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_missing_binary_is_reported()
    {
        $this->expectException(ProductImportException::class);
        $this->expectExceptionMessage('Không tìm thấy lệnh `claude`');

        app(ProductExtractor::class)->extract(new PageSnapshot('https://shopee.vn/x', 'Váy', 'nội dung'));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }
}
