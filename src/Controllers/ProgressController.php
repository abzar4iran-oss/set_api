<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ProgressService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class ProgressController
{
    public function __construct(
        private AuthService $auth,
        private ProgressService $progress
    ) {
    }

    public function get(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $data = $this->progress->get((int) $user['id']);
            Response::success($data, 'پیشرفت کاربر');
        });
    }

    public function import(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $body = $request->allJson();
            $snapshot = $body['progress'] ?? $body;
            if (!is_array($snapshot)) {
                throw new RuntimeException('بدنهٔ import نامعتبر است.');
            }
            $data = $this->progress->import((int) $user['id'], $snapshot);
            Response::success($data, 'پیشرفت محلی روی سرور ذخیره شد.');
        });
    }

    public function action(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $action = (string) (
                $request->json('action')
                ?? $request->json('type')
                ?? ''
            );
            $payload = $request->json('payload', []);
            if (!is_array($payload)) {
                $payload = [];
            }

            // اجازهٔ ارسال فیلدها در ریشهٔ JSON
            foreach ($request->allJson() as $key => $value) {
                if (in_array($key, ['action', 'type', 'payload', 'token'], true)) {
                    continue;
                }
                if (!array_key_exists($key, $payload)) {
                    $payload[$key] = $value;
                }
            }

            if ($action === '') {
                throw new RuntimeException('فیلد action الزامی است.');
            }

            $data = $this->progress->action((int) $user['id'], $action, $payload);
            Response::success($data, 'اکشن پیشرفت اعمال شد.');
        });
    }

    private function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = 400;
            if (str_contains($message, 'احراز هویت') || str_contains($message, 'نشست منقضی')) {
                $status = 401;
            }
            Response::error($message, $status);
        } catch (Throwable $e) {
            Response::error('خطای داخلی سرور.', 500, [
                'debug' => $e->getMessage(),
            ]);
        }
    }
}
