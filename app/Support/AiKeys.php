<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\MiniMax\MiniMaxClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Khoá AI nhập ở trang "Kết nối AI" (lưu mã hoá trong app_settings), .env là dự phòng. Khoá không bao giờ ghi log / trả về giao diện
 * (chỉ 4 ký tự cuối). Kiểm tra kết nối chỉ gọi endpoint miễn phí liệt kê model.
 */
final class AiKeys
{
    public const KEYS = [
        'minimax' => ['setting' => 'minimax_api_key', 'config' => 'videoaff.minimax.key', 'label' => 'MiniMax (Hailuo video, image-01, Music)'],
        'fal' => ['setting' => 'fal_api_key', 'config' => 'videoaff.fal.key', 'label' => 'fal (MiniMax Music 3, ElevenLabs, ACE-Step)'],
        'anthropic' => ['setting' => 'anthropic_api_key', 'config' => 'videoaff.claude.api_key', 'label' => 'Claude API (Anthropic)'],
    ];

    /** Kết quả kiểm tra kết nối gần nhất: {provider: {ok, message, checked_at}}. */
    public const STATUS = 'ai_key_status';

    public const MINIMAX_REGION = 'minimax_region';

    /** Ghi đè config bằng khoá / vùng nhập trên giao diện (gọi lúc khởi động app; chưa có bảng thì bỏ qua). */
    public static function apply(): void
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return;
            }
            foreach (self::KEYS as $key) {
                if ($value = AppSetting::get($key['setting'])) {
                    config([$key['config'] => $value]);
                }
            }
            if ($region = AppSetting::get(self::MINIMAX_REGION)) {
                config(['videoaff.minimax.region' => $region]);
            }
        } catch (Throwable) {
            // Chưa có database (cài đặt lần đầu): dùng .env.
        }
    }

    public static function get(string $provider): ?string
    {
        return filled($value = config(self::KEYS[$provider]['config'])) ? (string) $value : null;
    }

    /** Khoá đến từ giao diện, .env hay chưa có. */
    public static function source(string $provider): ?string
    {
        if (AppSetting::get(self::KEYS[$provider]['setting'])) {
            return 'ui';
        }

        return filled(env(match ($provider) {
            'minimax' => 'MINIMAX_API_KEY', 'fal' => 'FAL_KEY', default => 'ANTHROPIC_API_KEY'
        })) ? 'env' : null;
    }

    public static function masked(?string $key): ?string
    {
        return $key ? '••••'.substr($key, -4) : null;
    }

    public static function put(string $provider, ?string $value): void
    {
        AppSetting::put(self::KEYS[$provider]['setting'], filled($value) ? trim($value) : null);
        $status = self::statuses();
        unset($status[$provider]);
        AppSetting::put(self::STATUS, json_encode($status));
    }

    /** @return array<string, array{ok: bool, message: string, checked_at: string}> */
    public static function statuses(): array
    {
        return json_decode((string) AppSetting::get(self::STATUS), true) ?: [];
    }

    /** Khoá đã kiểm tra thành công (dùng để chọn MiniMax làm mặc định). */
    public static function valid(string $provider): bool
    {
        return self::get($provider) !== null && (self::statuses()[$provider]['ok'] ?? false);
    }

    /**
     * Kiểm tra khoá bằng lệnh miễn phí (liệt kê model), không tạo gì, không tốn tiền.
     *
     * @return array{ok: bool, message: string, checked_at: string}
     */
    public static function check(string $provider): array
    {
        $key = self::get($provider);
        $result = fn (bool $ok, string $message) => ['ok' => $ok, 'message' => $message, 'checked_at' => now()->toIso8601String()];
        if ($key === null) {
            $status = $result(false, 'Chưa có khoá.');
        } else {
            try {
                $response = match ($provider) {
                    'minimax' => Http::withToken($key)->acceptJson()->timeout(20)->get(MiniMaxClient::baseUrl().'/v1/models'),
                    'fal' => Http::withHeaders(['Authorization' => "Key {$key}"])->acceptJson()->timeout(20)->get('https://api.fal.ai/v1/models', ['limit' => 1]),
                    default => Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(20)->get('https://api.anthropic.com/v1/models', ['limit' => 1]),
                };
                $status = match (true) {
                    $response->successful() && (int) ($response->json('base_resp.status_code') ?? 0) === 0 => $result(true, 'Kết nối được.'),
                    in_array($response->status(), [401, 403], true) || in_array((int) $response->json('base_resp.status_code'), [1004, 2049], true) => $result(false, 'Khoá không hợp lệ.'),
                    default => $result(false, 'Lỗi HTTP '.$response->status().'.'),
                };
            } catch (Throwable) {
                $status = $result(false, 'Không kết nối được (mạng / vùng máy chủ).');
            }
        }
        AppSetting::put(self::STATUS, json_encode([...self::statuses(), $provider => $status]));

        return $status;
    }
}
