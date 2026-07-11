<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ProfileService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class ProfileController
{
    public function __construct(
        private AuthService $auth,
        private ProfileService $profile
    ) {
    }

    public function get(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success($this->profile->get((int) $user['id']), 'پروفایل');
        });
    }

    public function update(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->profile->update((int) $user['id'], $request->allJson()),
                'پروفایل به‌روزرسانی شد.'
            );
        });
    }

    public function updateSettings(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->profile->updateSettings((int) $user['id'], $request->allJson()),
                'تنظیمات ذخیره شد.'
            );
        });
    }

    public function uploadAvatar(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->profile->uploadAvatar((int) $user['id'], $request->allJson()),
                'آواتار به‌روزرسانی شد.'
            );
        });
    }

    public function removeAvatar(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->profile->removeAvatar((int) $user['id']),
                'آواتار حذف شد.'
            );
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
