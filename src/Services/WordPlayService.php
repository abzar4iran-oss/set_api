<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\WordPlayRepository;
use RuntimeException;

/**
 * سرور «بازی با کلمات» — پازل روی کلاینت؛ پیشرفت/پاداش روی سرور.
 */
final class WordPlayService
{
    private const STAGE_COUNT = 1500;
    private const HINT_COIN_COST = 18;
    private const BONUS_COIN_REWARD = 3;
    private const STAGE_COIN_REWARD = 5;
    private const STAGE_POINTS = 120;
    private const BONUS_POINTS = 30;
    private const CHAPTER_GIFT = 10;
    private const CHAPTER_MIN = 7;
    private const CHAPTER_MAX = 10;
    /** باید با WORD_PLAY_CONTENT_VERSION کلاینت هم‌خوان باشد */
    private const CONTENT_VERSION = 9;

    public function __construct(
        private WordPlayRepository $attempts,
        private ProgressService $progress
    ) {
    }

    public function status(int $userId): array
    {
        $mapped = $this->progress->getMappedProgress($userId);
        $levelIndex = (int) ($mapped['wordPlayLevelIndex'] ?? 0);
        $contentVersion = (int) ($mapped['wordPlayContentVersion'] ?? self::CONTENT_VERSION);

        if ($contentVersion < self::CONTENT_VERSION) {
            $this->progress->setWordPlayProgress($userId, 0, self::CONTENT_VERSION);
            $mapped = $this->progress->getMappedProgress($userId);
            $levelIndex = 0;
            $contentVersion = self::CONTENT_VERSION;
        }

        return [
            'enabled' => true,
            'level_index' => $levelIndex,
            'content_version' => $contentVersion,
            'stage_count' => self::STAGE_COUNT,
            'config' => $this->publicConfig(),
            'progress' => $mapped,
        ];
    }

    public function start(int $userId, array $payload): array
    {
        $status = $this->status($userId);
        $levelIndex = (int) $status['level_index'];
        $stageIndex = (int) ($payload['stage_index'] ?? $payload['stageIndex'] ?? -1);

        if ($stageIndex < 0 || $stageIndex >= self::STAGE_COUNT) {
            throw new RuntimeException('شماره مرحله نامعتبر است.');
        }
        if ($stageIndex > $levelIndex) {
            throw new RuntimeException('این مرحله هنوز قفل است.');
        }

        $existing = $this->attempts->findOpenForUserStage($userId, $stageIndex);
        if ($existing) {
            return [
                'attempt' => $this->mapAttempt($existing),
                'progress' => $status['progress'],
                'config' => $this->publicConfig(),
                'resumed' => true,
            ];
        }

        $token = bin2hex(random_bytes(24));
        $row = $this->attempts->create([
            'user_id' => $userId,
            'status' => 'open',
            'attempt_token' => $token,
            'stage_index' => $stageIndex,
            'content_version' => self::CONTENT_VERSION,
            'started_at_ms' => $this->nowMs(),
            'hints_used' => 0,
        ]);

        return [
            'attempt' => $this->mapAttempt($row),
            'progress' => $this->progress->getMappedProgress($userId),
            'config' => $this->publicConfig(),
            'resumed' => false,
        ];
    }

    public function hint(int $userId, array $payload): array
    {
        $token = trim((string) ($payload['attempt_token'] ?? $payload['attemptToken'] ?? ''));
        $row = $this->requireOpenAttempt($userId, $token);

        $result = $this->progress->action($userId, 'spend_coins', [
            'amount' => self::HINT_COIN_COST,
            'reason' => 'word_play_hint',
        ]);

        $updated = $this->attempts->update((int) $row['id'], [
            'hints_used' => (int) $row['hints_used'] + 1,
        ]);

        return [
            'attempt' => $this->mapAttempt($updated ?? $row),
            'progress' => $result['progress'],
            'hint_coin_cost' => self::HINT_COIN_COST,
        ];
    }

    public function complete(int $userId, array $payload): array
    {
        $token = trim((string) ($payload['attempt_token'] ?? $payload['attemptToken'] ?? ''));
        $row = $this->requireOpenAttempt($userId, $token);

        $stageIndex = (int) $row['stage_index'];
        $targetCount = (int) ($payload['target_count'] ?? $payload['targetCount'] ?? 0);
        $bonusCount = max(0, (int) ($payload['bonus_count'] ?? $payload['bonusCount'] ?? 0));
        $foundTargets = (int) ($payload['found_targets_count'] ?? $payload['foundTargetsCount'] ?? 0);

        if ($targetCount < 2 || $targetCount > 12) {
            throw new RuntimeException('تعداد کلمات هدف نامعتبر است.');
        }
        if ($bonusCount > 24) {
            throw new RuntimeException('تعداد کلمات جایزه نامعتبر است.');
        }
        if ($foundTargets < $targetCount) {
            throw new RuntimeException('هنوز همهٔ کلمات هدف پیدا نشده‌اند.');
        }

        $status = $this->status($userId);
        $levelIndex = (int) $status['level_index'];
        if ($stageIndex > $levelIndex) {
            throw new RuntimeException('این مرحله برای شما قفل است.');
        }

        $stageCoins = self::STAGE_COIN_REWARD;
        $bonusCoins = $bonusCount * self::BONUS_COIN_REWARD;
        $stageNumber = $stageIndex + 1;
        $chapterGift = $this->isChapterEnd($stageNumber) ? self::CHAPTER_GIFT : 0;
        $coinsAwarded = $stageCoins + $bonusCoins + $chapterGift;
        $pointsAwarded = self::STAGE_POINTS + ($bonusCount * self::BONUS_POINTS);

        $advanced = false;
        $nextLevel = $levelIndex;
        if ($stageIndex === $levelIndex && $levelIndex < self::STAGE_COUNT - 1) {
            $nextLevel = $levelIndex + 1;
            $advanced = true;
            $this->progress->setWordPlayProgress($userId, $nextLevel, self::CONTENT_VERSION);
        } elseif ($stageIndex === $levelIndex) {
            // آخرین مرحله — فقط نسخهٔ محتوا را هم‌تراز کن
            $this->progress->setWordPlayProgress($userId, $levelIndex, self::CONTENT_VERSION);
            $advanced = true;
        }

        if ($coinsAwarded > 0) {
            $this->progress->action($userId, 'add_coins', [
                'amount' => $coinsAwarded,
                'reason' => 'word_play_complete',
            ]);
        }
        if ($pointsAwarded > 0) {
            $this->progress->action($userId, 'add_compete_points', [
                'amount' => $pointsAwarded,
                'reason' => 'word_play_complete',
            ]);
        }

        $updated = $this->attempts->update((int) $row['id'], [
            'status' => 'completed',
            'completed_at_ms' => $this->nowMs(),
            'target_count' => $targetCount,
            'bonus_count' => $bonusCount,
            'coins_awarded' => $coinsAwarded,
            'points_awarded' => $pointsAwarded,
            'chapter_gift' => $chapterGift,
        ]);

        $progress = $this->progress->getMappedProgress($userId);

        return [
            'attempt' => $this->mapAttempt($updated ?? $row),
            'advanced' => $advanced,
            'level_index' => (int) ($progress['wordPlayLevelIndex'] ?? $nextLevel),
            'coins_awarded' => $coinsAwarded,
            'points_awarded' => $pointsAwarded,
            'stage_coins' => $stageCoins,
            'bonus_coins' => $bonusCoins,
            'chapter_gift' => $chapterGift,
            'progress' => $progress,
            'config' => $this->publicConfig(),
        ];
    }

    /** @return array<string, int> */
    private function publicConfig(): array
    {
        return [
            'hint_coin_cost' => self::HINT_COIN_COST,
            'bonus_coin_reward' => self::BONUS_COIN_REWARD,
            'stage_coin_reward' => self::STAGE_COIN_REWARD,
            'stage_points' => self::STAGE_POINTS,
            'bonus_points' => self::BONUS_POINTS,
            'chapter_complete_coin_gift' => self::CHAPTER_GIFT,
            'stage_count' => self::STAGE_COUNT,
            'content_version' => self::CONTENT_VERSION,
        ];
    }

    /** @param array<string, mixed> $row */
    private function mapAttempt(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'attempt_token' => (string) ($row['attempt_token'] ?? ''),
            'stage_index' => (int) ($row['stage_index'] ?? 0),
            'content_version' => (int) ($row['content_version'] ?? self::CONTENT_VERSION),
            'started_at_ms' => (int) ($row['started_at_ms'] ?? 0),
            'completed_at_ms' => isset($row['completed_at_ms']) && $row['completed_at_ms'] !== null
                ? (int) $row['completed_at_ms']
                : null,
            'hints_used' => (int) ($row['hints_used'] ?? 0),
            'coins_awarded' => (int) ($row['coins_awarded'] ?? 0),
            'points_awarded' => (int) ($row['points_awarded'] ?? 0),
            'chapter_gift' => (int) ($row['chapter_gift'] ?? 0),
        ];
    }

    private function requireOpenAttempt(int $userId, string $token): array
    {
        if ($token === '') {
            throw new RuntimeException('توکن تلاش نامعتبر است.');
        }
        $row = $this->attempts->findByToken($token);
        if (!$row || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('تلاش یافت نشد.');
        }
        if ((string) $row['status'] !== 'open') {
            throw new RuntimeException('این تلاش دیگر باز نیست.');
        }
        return $row;
    }

    private function isChapterEnd(int $stageNumber1Based): bool
    {
        $chapters = $this->buildChapters();
        foreach ($chapters as $ch) {
            if ($ch['end'] === $stageNumber1Based) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{start:int,end:int}> */
    private function buildChapters(): array
    {
        $chapters = [];
        $cursor = 1;
        $index = 0;
        while ($cursor <= self::STAGE_COUNT) {
            $size = $this->chapterSizeFor($index);
            $start = $cursor;
            $end = min($start + $size - 1, self::STAGE_COUNT);
            $chapters[] = ['start' => $start, 'end' => $end];
            $cursor = $end + 1;
            $index += 1;
        }
        if (
            count($chapters) >= 2
            && ($chapters[count($chapters) - 1]['end'] - $chapters[count($chapters) - 1]['start'] + 1) < self::CHAPTER_MIN
        ) {
            $tail = array_pop($chapters);
            $chapters[count($chapters) - 1]['end'] = $tail['end'];
        }
        return $chapters;
    }

    private function chapterSizeFor(int $chapterIndex): int
    {
        $state = (($chapterIndex * 1013904223 + 17) & 0xFFFFFFFF) ^ 0x9e3779b9;
        $state = (($state ^ ($state >> 15)) * (($state | 1) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        // ساده‌سازی قطعی هم‌تراز کلاینت
        $span = self::CHAPTER_MAX - self::CHAPTER_MIN + 1;
        return self::CHAPTER_MIN + (int) ($state % $span);
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
