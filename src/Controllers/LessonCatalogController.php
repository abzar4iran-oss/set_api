<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\LessonCatalogService;
use App\Support\Request;
use App\Support\Response;
use Throwable;

final class LessonCatalogController
{
    public function __construct(
        private LessonCatalogService $catalog
    ) {
    }

    public function list(Request $request): void
    {
        $this->handle(function () {
            Response::success($this->catalog->getCatalog(), 'کاتالوگ دروس');
        });
    }

    public function get(Request $request): void
    {
        $this->handle(function () use ($request) {
            $id = (int) ($request->query('id') ?? $request->json('id') ?? 0);
            if ($id <= 0) {
                Response::error('شناسه درس نامعتبر است.', 422);
            }

            $lesson = $this->catalog->getLesson($id);
            if ($lesson === null) {
                Response::error('درس پیدا نشد.', 404);
            }

            Response::success(['lesson' => $lesson], 'جزئیات درس');
        });
    }

    private function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
