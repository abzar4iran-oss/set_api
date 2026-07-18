<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class LessonCatalogService
{
    private ?array $cache = null;

    public function __construct(
        private array $appConfig = []
    ) {
    }

    public function getCatalog(): array
    {
        $data = $this->load();
        return [
            'version' => (int) ($data['version'] ?? 1),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
            'media_base_url' => $this->mediaBaseUrl(),
            'path_lesson_ids' => array_values(array_map('intval', $data['path_lesson_ids'] ?? [])),
            'learn_lesson_ids' => array_values(array_map('intval', $data['learn_lesson_ids'] ?? [])),
            'workshop_lesson_ids' => array_values(array_map('intval', $data['workshop_lesson_ids'] ?? [])),
            'exam_lesson_ids' => array_values(array_map('intval', $data['exam_lesson_ids'] ?? [])),
            'lessons_per_surah_listen' => (int) ($data['lessons_per_surah_listen'] ?? 2),
            'lessons' => array_values(array_map(
                fn (array $lesson) => $this->mapLesson($lesson),
                $data['lessons'] ?? []
            )),
        ];
    }

    public function getLesson(int $id): ?array
    {
        foreach ($this->getCatalog()['lessons'] as $lesson) {
            if ((int) $lesson['id'] === $id) {
                return $lesson;
            }
        }
        return null;
    }

    private function mapLesson(array $lesson): array
    {
        $folder = $lesson['media_folder'] ?? null;
        return [
            'id' => (int) ($lesson['id'] ?? 0),
            'term' => (int) ($lesson['term'] ?? 1),
            'type' => (string) ($lesson['type'] ?? 'learn'),
            'title' => (string) ($lesson['title'] ?? ''),
            'focus' => (string) ($lesson['focus'] ?? ''),
            'media_folder' => is_string($folder) && $folder !== '' ? $folder : null,
        ];
    }

    private function mediaBaseUrl(): string
    {
        $base = rtrim((string) ($this->appConfig['base_url'] ?? ''), '/');
        return $base !== '' ? $base . '/media' : '/media';
    }

    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = dirname(__DIR__, 2) . '/storage/lessons_catalog.json';
        if (!is_file($path)) {
            throw new RuntimeException('فایل کاتالوگ دروس روی سرور پیدا نشد.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('خواندن کاتالوگ دروس ناموفق بود.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('کاتالوگ دروس نامعتبر است.');
        }

        $this->cache = $decoded;
        return $this->cache;
    }
}
