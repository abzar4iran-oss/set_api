<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\RemoteConfigService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class RemoteConfigController
{
    public function __construct(
        private RemoteConfigService $remoteConfig,
        private array $appConfig
    ) {
    }

    public function get(Request $request): void
    {
        $this->handle(function () {
            $data = $this->remoteConfig->getPublicConfig();
            Response::success([
                'config' => $data,
                'etag' => (string) ($data['version'] ?? 0),
            ], 'Remote Config');
        });
    }

    public function put(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->assertAdmin($request);
            $payload = $request->allJson();
            // Allow either full body or { "config": {...} }
            if (isset($payload['config']) && is_array($payload['config'])) {
                $payload = $payload['config'];
            }
            unset($payload['version'], $payload['updated_at']);
            $data = $this->remoteConfig->replace($payload);
            Response::success([
                'config' => $data,
                'etag' => (string) ($data['version'] ?? 0),
            ], 'Remote Config به‌روزرسانی شد.');
        });
    }

    public function reset(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->assertAdmin($request);
            $data = $this->remoteConfig->resetToDefaults();
            Response::success([
                'config' => $data,
                'etag' => (string) ($data['version'] ?? 0),
            ], 'Remote Config به پیش‌فرض برگشت.');
        });
    }

    private function assertAdmin(Request $request): void
    {
        $expected = (string) ($this->appConfig['admin']['api_key'] ?? '');
        if ($expected === '') {
            throw new RuntimeException('کلید ادمین در سرور تنظیم نشده است.');
        }

        $provided = $request->json('admin_key');
        if (!is_string($provided) || $provided === '') {
            $header = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
            $provided = is_string($header) ? $header : '';
        }

        if (!hash_equals($expected, (string) $provided)) {
            Response::error('دسترسی ادمین مجاز نیست.', 401);
        }
    }

    private function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (Throwable $e) {
            Response::error('خطای داخلی سرور.', 500, [
                'debug' => $e->getMessage(),
            ]);
        }
    }
}
