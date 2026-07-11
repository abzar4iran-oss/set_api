<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DailyChallengeService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class DailyChallengeController
{
    public function __construct(
        private AuthService $auth,
        private DailyChallengeService $daily
    ) {
    }

    public function status(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success($this->daily->status((int) $user['id']), 'وضعیت چالش روزانه');
        });
    }

    public function start(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $maxLessonId = (int) $request->json('max_lesson_id', $request->json('maxLessonId', 1));
            Response::success(
                $this->daily->start((int) $user['id'], $maxLessonId),
                'چالش روزانه شروع شد.'
            );
        });
    }

    public function commit(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $data = $this->daily->commit((int) $user['id'], $request->allJson());
            Response::success($data, 'سوال چالش قفل شد.');
        });
    }

    public function submit(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $data = $this->daily->submit((int) $user['id'], $request->allJson());
            Response::success($data, 'نتیجه چالش ثبت شد.');
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
