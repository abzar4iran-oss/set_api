<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\CompeteMatchService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class CompeteMatchController
{
    public function __construct(
        private AuthService $auth,
        private CompeteMatchService $matches
    ) {
    }

    public function active(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success($this->matches->active((int) $user['id']), 'مسابقه فعال');
        });
    }

    public function get(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $token = (string) ($request->json('match_token')
                ?? $request->json('matchToken')
                ?? ($_GET['token'] ?? ''));
            Response::success($this->matches->get((int) $user['id'], $token), 'جزئیات مسابقه');
        });
    }

    public function history(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $limit = (int) ($_GET['limit'] ?? $request->json('limit', 20));
            Response::success($this->matches->history((int) $user['id'], $limit), 'تاریخچه مسابقات');
        });
    }

    public function leaderboard(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $limit = (int) ($_GET['limit'] ?? $request->json('limit', 10));
            Response::success($this->matches->leaderboard((int) $user['id'], $limit), 'لیدربورد');
        });
    }

    public function start(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $maxLessonId = (int) $request->json('max_lesson_id', $request->json('maxLessonId', 1));
            Response::success(
                $this->matches->start((int) $user['id'], $maxLessonId),
                'مسابقه شروع شد.'
            );
        });
    }

    public function commit(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->matches->commit((int) $user['id'], $request->allJson()),
                'راندهای مسابقه قفل شد.'
            );
        });
    }

    public function finish(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->matches->finish((int) $user['id'], $request->allJson()),
                'نتیجه مسابقه ثبت شد.'
            );
        });
    }

    public function cancel(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            Response::success(
                $this->matches->cancel((int) $user['id'], $request->allJson()),
                'مسابقه لغو شد.'
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
