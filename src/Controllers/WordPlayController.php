<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\WordPlayService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class WordPlayController
{
    public function __construct(
        private AuthService $auth,
        private WordPlayService $wordPlay
    ) {
    }

    public function status(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success($this->wordPlay->status((int) $user['id']), 'وضعیت بازی با کلمات');
        });
    }

    public function start(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->wordPlay->start((int) $user['id'], $request->allJson()),
                'مرحله شروع شد.'
            );
        });
    }

    public function hint(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->wordPlay->hint((int) $user['id'], $request->allJson()),
                'راهنما ثبت شد.'
            );
        });
    }

    public function complete(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->wordPlay->complete((int) $user['id'], $request->allJson()),
                'مرحله تکمیل شد.'
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
