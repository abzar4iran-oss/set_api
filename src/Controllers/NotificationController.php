<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\NotificationService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class NotificationController
{
    public function __construct(
        private AuthService $auth,
        private NotificationService $notifications,
        private array $appConfig
    ) {
    }

    public function list(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $limit = (int) ($_GET['limit'] ?? 100);
            Response::success(
                $this->notifications->list((int) $user['id'], $limit),
                'لیست اعلان‌ها'
            );
        });
    }

    public function unreadCount(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->notifications->unreadCount((int) $user['id']),
                'تعداد خوانده‌نشده'
            );
        });
    }

    public function markRead(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $id = (string) (
                $request->json('id')
                ?? $request->json('notification_id')
                ?? $request->json('notificationId')
                ?? ''
            );
            Response::success(
                $this->notifications->markRead((int) $user['id'], $id),
                'اعلان خوانده شد'
            );
        });
    }

    public function markAllRead(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->notifications->markAllRead((int) $user['id']),
                'همه اعلان‌ها خوانده شدند'
            );
        });
    }

    public function dismiss(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $id = (string) (
                $request->json('id')
                ?? $request->json('notification_id')
                ?? $request->json('notificationId')
                ?? ''
            );
            Response::success(
                $this->notifications->dismiss((int) $user['id'], $id),
                'اعلان حذف شد'
            );
        });
    }

    public function suggestions(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->notifications->suggestions((int) $user['id']),
                'پیشنهادهای اعلان'
            );
        });
    }

    public function registerPushToken(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->notifications->registerPushToken((int) $user['id'], $request->allJson()),
                'توکن پوش ثبت شد',
                201
            );
        });
    }

    public function unregisterPushToken(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->notifications->unregisterPushToken((int) $user['id'], $request->allJson()),
                'توکن پوش حذف شد'
            );
        });
    }

    public function adminSend(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->assertAdmin($request);
            $data = $this->notifications->adminSend($request->allJson());
            Response::success($data, 'اعلان ارسال شد', 201);
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
