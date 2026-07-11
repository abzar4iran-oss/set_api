<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\SupportService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class SupportController
{
    public function __construct(
        private AuthService $auth,
        private SupportService $support,
        private array $appConfig
    ) {
    }

    public function contact(Request $request): void
    {
        $this->handle(function () {
            Response::success($this->support->contactInfo(), 'راه‌های ارتباط با پشتیبانی');
        });
    }

    public function list(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $limit = (int) ($_GET['limit'] ?? 50);
            Response::success($this->support->list((int) $user['id'], $limit), 'تیکیت‌ها');
        });
    }

    public function unreadCount(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success($this->support->unreadCount((int) $user['id']), 'تیکیت‌های خوانده‌نشده');
        });
    }

    public function get(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $id = (string) ($request->json('id') ?? $_GET['id'] ?? '');
            Response::success($this->support->get((int) $user['id'], $id), 'جزئیات تیکیت');
        });
    }

    public function create(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->support->create((int) $user['id'], $request->allJson()),
                'تیکیت ثبت شد.',
                201
            );
        });
    }

    public function addMessage(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $id = (string) (
                $request->json('id')
                ?? $request->json('ticket_id')
                ?? $request->json('ticketId')
                ?? ''
            );
            Response::success(
                $this->support->addUserMessage((int) $user['id'], $id, $request->allJson()),
                'پیام ثبت شد.'
            );
        });
    }

    public function close(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $id = (string) (
                $request->json('id')
                ?? $request->json('ticket_id')
                ?? $request->json('ticketId')
                ?? ''
            );
            Response::success(
                $this->support->closeByUser((int) $user['id'], $id),
                'تیکیت بسته شد.'
            );
        });
    }

    public function markSeen(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $id = (string) (
                $request->json('id')
                ?? $request->json('ticket_id')
                ?? $request->json('ticketId')
                ?? ''
            );
            Response::success(
                $this->support->markSeen((int) $user['id'], $id),
                'تیکیت خوانده شد.'
            );
        });
    }

    public function adminList(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->assertAdmin($request);
            $limit = (int) ($_GET['limit'] ?? 100);
            $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
            Response::success($this->support->adminList($limit, $status), 'تیکیت‌های ادمین');
        });
    }

    public function adminGet(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->assertAdmin($request);
            $id = (string) ($request->json('id') ?? $_GET['id'] ?? '');
            Response::success($this->support->adminGet($id), 'جزئیات تیکیت ادمین');
        });
    }

    public function adminReply(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->assertAdmin($request);
            $id = (string) (
                $request->json('id')
                ?? $request->json('ticket_id')
                ?? $request->json('ticketId')
                ?? ''
            );
            Response::success(
                $this->support->adminReply($id, $request->allJson()),
                'پاسخ ثبت شد.'
            );
        });
    }

    public function adminSetStatus(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->assertAdmin($request);
            $id = (string) (
                $request->json('id')
                ?? $request->json('ticket_id')
                ?? $request->json('ticketId')
                ?? ''
            );
            $status = (string) ($request->json('status') ?? '');
            Response::success(
                $this->support->adminSetStatus($id, $status),
                'وضعیت به‌روزرسانی شد.'
            );
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
