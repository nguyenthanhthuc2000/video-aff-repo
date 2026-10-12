<?php

namespace App\Services\Image;

use App\Support\Media;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Tạo ảnh cảnh bằng muse.ai qua trình duyệt ngầm, dùng tài khoản người dùng đã đăng nhập.
 * Mỗi yêu cầu có mã riêng (MuseTask) để nhiều ảnh, nhiều sản phẩm chạy song song.
 */
class MuseBrowserImageGenerator implements AsyncImageGenerator, ImageGenerator
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Đầu thông báo lỗi khi Muse từ chối (nút Tạo lại dựa vào đây để tự viết lại prompt). */
    public const REJECTED = 'AI từ chối tạo yêu cầu này.';

    /** Đầu thông báo cũ (dữ liệu trước khi đổi chữ) vẫn được nhận là bị từ chối. */
    public const REJECTED_LEGACY = 'muse.ai từ chối tạo yêu cầu này.';

    public function __construct(private readonly MuseBrowserClient $client) {}

    public function start(array $referencePaths, string $prompt): string
    {
        $code = MuseTask::code();
        $images = [];
        foreach (array_slice($referencePaths, 0, 4) as $path) {
            $contents = Media::disk()->get($path);
            if ($contents !== null) {
                $images[] = ['name' => basename($path), 'base64' => base64_encode($contents)];
            }
        }

        self::call(fn () => $this->client->startTask(
            $code,
            'image',
            static::message($code, $prompt),
            $images,
            (int) config('videoaff.muse_browser.timeout') * 1000,
        ));

        return $code;
    }

    public function poll(string $code, array $referencePaths): ?GeneratedImage
    {
        $result = self::pollTask($this->client, $code, 'image');
        if ($result === null) {
            return null;
        }

        $contents = base64_decode($result['base64'] ?? '', true);
        $type = $contents ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) : null;
        if (! $contents || ! isset(self::EXTENSIONS[$type])) {
            throw new ImageGeneratorException('muse.ai không trả về ảnh hợp lệ.');
        }

        // Chặn trường hợp lấy nhầm ảnh tham chiếu (người mẫu, sản phẩm) thay cho ảnh muse.ai tạo.
        foreach ($referencePaths as $path) {
            if (Media::disk()->get($path) === $contents) {
                throw ImageGeneratorException::retryable('muse.ai trả về đúng ảnh tham chiếu, chưa phải ảnh đã tạo.');
            }
        }

        Log::info('muse.browser_image', ['code' => $code, 'matched' => $result['matched'] ?? null, 'bytes' => strlen($contents)]);

        return new GeneratedImage($contents, self::EXTENSIONS[$type], 'muse.ai-browser', requestId: $code, costUsd: 0);
    }

    /** Chạy đồng bộ (gửi rồi chờ); job dùng start()/poll() để không giữ hàng đợi. */
    public function generate(array $referencePaths, string $prompt): GeneratedImage
    {
        $code = $this->start($referencePaths, $prompt);
        $deadline = time() + (int) config('videoaff.muse_browser.timeout') + 30;
        while (time() < $deadline) {
            sleep(5);
            if ($image = $this->poll($code, $referencePaths)) {
                return $image;
            }
        }

        throw ImageGeneratorException::retryable('Hết thời gian chờ muse.ai trả ảnh.');
    }

    /**
     * Hỏi kết quả một mã. Dịch vụ khởi động lại (mất mã) thì theo dõi lại mã đó trên trang thay vì gửi yêu cầu mới.
     *
     * @return array{mime: string, base64: string, matched?: string}|null
     *
     * @throws ImageGeneratorException
     */
    public static function pollTask(MuseBrowserClient $client, string $code, string $kind): ?array
    {
        $task = self::call(function () use ($client, $code, $kind) {
            try {
                return $client->task($code);
            } catch (MuseBrowserException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
                $client->startTask($code, $kind, '', [], (int) config('videoaff.muse_browser.timeout') * 1000, adopt: true);

                return ['state' => 'pending'];
            }
        });

        return match ($task['state'] ?? null) {
            'done' => $task['result'] ?? throw new ImageGeneratorException('muse.ai không trả kết quả.'),
            'failed' => throw ImageGeneratorException::retryable('muse.ai: '.($task['error'] ?? 'tạo thất bại.')),
            // Muse từ chối nội dung: gửi lại y như cũ cũng bị từ chối, không thử lại; giữ lời giải thích của Muse.
            'rejected' => throw ImageGeneratorException::rejected(
                self::REJECTED.' Bấm Tạo lại để tự viết lại prompt cho phù hợp, hoặc tải ảnh/clip lên. AI trả lời: '.($task['error'] ?? ''),
                (string) ($task['error'] ?? ''),
            ),
            default => null,
        };
    }

    /**
     * Đổi lỗi kết nối / dịch vụ trình duyệt thành lỗi tạo ảnh.
     *
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    public static function call(callable $request): mixed
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw ImageGeneratorException::retryable('Không kết nối được dịch vụ trình duyệt (docker compose up -d browser).', $e);
        } catch (MuseBrowserException $e) {
            if ($e->busy()) {
                throw ImageGeneratorException::busy('muse.ai: '.$e->getMessage(), $e);
            }
            // Chưa đăng nhập: thử lại cũng không được, cần người dùng xác thực.
            throw $e->needsLogin() ? new ImageGeneratorException($e->getMessage(), 0, $e) : ImageGeneratorException::retryable('muse.ai: '.$e->getMessage(), $e);
        }
    }

    /** Lời nhắn gửi Muse: mã yêu cầu, chỉ cần một ảnh, không hỏi lại. */
    public static function message(string $code, string $prompt): string
    {
        return MuseTask::header($code, 'image')."\n"
            .'Create one photorealistic image (vertical 9:16). The first attached photo is the person; the roles of the other attached photos are described below.'
            ."\n\n{$prompt}";
    }
}
