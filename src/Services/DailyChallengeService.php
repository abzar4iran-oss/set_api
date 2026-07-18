<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\DailyChallengeRepository;
use RuntimeException;

final class DailyChallengeService
{
    public function __construct(
        private DailyChallengeRepository $attempts,
        private ProgressService $progress,
        private RemoteConfigService $remoteConfig,
        private ?NotificationService $notifications = null
    ) {
    }

    public function status(int $userId): array
    {
        $this->assertFeatureEnabled();
        $dayKey = $this->progress->todayDayKey();
        $dc = $this->dailyConfig();
        $progress = $this->progress->getMappedProgress($userId);
        $latest = $this->attempts->findLatestForUserDay($userId, $dayKey);
        $oneAttempt = !empty($dc['one_attempt_per_day']);

        $wonToday = ($progress['dailyChallengeWonDayKey'] ?? null) === $dayKey;
        $playedToday = ($progress['dailyChallengePlayedDayKey'] ?? null) === $dayKey;
        $lockedUntil = $progress['dailyChallengeLockedUntil'] ?? null;
        $now = $this->nowMs();
        $locked = is_int($lockedUntil) && $lockedUntil > $now;

        $canPlay = true;
        $blockReason = null;
        if ($oneAttempt && ($playedToday || $wonToday || $locked)) {
            $canPlay = false;
            $blockReason = $wonToday ? 'already_won' : 'already_played';
        } elseif (!$oneAttempt && $wonToday) {
            // برد روز یک‌بار؛ تلاش‌های بعدی بدون پاداش اضافه — هنوز can_play برای تمرین
            $canPlay = true;
        }

        // اگر attempt باز/committed مانده
        $active = null;
        if ($latest && in_array((string) $latest['status'], ['open', 'committed'], true)) {
            $active = $this->mapAttempt($latest);
            if ($this->isAttemptExpired($latest)) {
                $this->expireAttempt($latest);
                $active = null;
                if ($oneAttempt) {
                    $canPlay = false;
                    $blockReason = 'expired';
                }
            }
        }

        return [
            'enabled' => true,
            'day_key' => $dayKey,
            'timezone' => 'Asia/Tehran',
            'server_time_ms' => $now,
            'locked_until' => $lockedUntil,
            'can_play' => ($active !== null && in_array((string) $active['status'], ['open', 'committed'], true))
                || ($canPlay && $active === null),
            'block_reason' => $blockReason,
            'won_today' => $wonToday,
            'played_today' => $playedToday,
            'one_attempt_per_day' => $oneAttempt,
            'reward_coins' => (int) ($dc['reward_coins'] ?? 15),
            'win_points' => (int) ($dc['win_points'] ?? 1),
            'standard_seconds' => (int) ($dc['standard_seconds'] ?? 45),
            'easy_question_seconds' => (int) ($dc['easy_question_seconds'] ?? 25),
            'played_question_ids' => $progress['dailyChallengePlayedQuestionIds'] ?? [],
            'active_attempt' => $active,
            'latest_attempt' => $latest ? $this->mapAttempt($latest) : null,
            'progress' => $progress,
        ];
    }

    public function start(int $userId, int $maxLessonId): array
    {
        $this->assertFeatureEnabled();
        $dayKey = $this->progress->todayDayKey();
        $dc = $this->dailyConfig();
        $oneAttempt = !empty($dc['one_attempt_per_day']);
        $status = $this->status($userId);

        if ($oneAttempt && !$status['can_play'] && empty($status['active_attempt'])) {
            throw new RuntimeException('امروز دیگر نمی‌توانید چالش روزانه را بازی کنید.');
        }

        if (!empty($status['active_attempt'])) {
            return [
                'day_key' => $dayKey,
                'attempt' => $status['active_attempt'],
                'config' => $this->publicConfig($dc),
                'progress' => $status['progress'],
                'resumed' => true,
            ];
        }

        if ($maxLessonId < 1) {
            throw new RuntimeException('max_lesson_id نامعتبر است.');
        }

        $seed = $this->pickSeed($userId, $dayKey, $maxLessonId);
        $token = bin2hex(random_bytes(24));
        $row = $this->attempts->create([
            'user_id' => $userId,
            'day_key' => $dayKey,
            'status' => 'open',
            'attempt_token' => $token,
            'max_lesson_id' => $maxLessonId,
            'pick_seed' => $seed,
            'started_at_ms' => $this->nowMs(),
        ]);

        return [
            'day_key' => $dayKey,
            'attempt' => $this->mapAttempt($row),
            'config' => $this->publicConfig($dc),
            'progress' => $this->progress->getMappedProgress($userId),
            'resumed' => false,
            'pick' => [
                'seed' => $seed,
                'day_key' => $dayKey,
                'max_lesson_id' => $maxLessonId,
                'exclude_question_ids' => $status['played_question_ids'] ?? [],
            ],
        ];
    }

    public function commit(int $userId, array $payload): array
    {
        $this->assertFeatureEnabled();
        $token = trim((string) ($payload['attempt_token'] ?? ''));
        $row = $this->requireUserAttempt($userId, $token);

        if ((string) $row['status'] === 'committed' && !empty($row['question_id'])) {
            return [
                'attempt' => $this->mapAttempt($row),
                'already_committed' => true,
            ];
        }

        if ((string) $row['status'] !== 'open') {
            throw new RuntimeException('این تلاش قابل قفل کردن سوال نیست.');
        }

        if ($this->isAttemptExpired($row)) {
            $this->expireAttempt($row);
            throw new RuntimeException('زمان شروع تلاش منقضی شده. دوباره start بزنید.');
        }

        $questionId = trim((string) ($payload['question_id'] ?? ''));
        $methodId = trim((string) ($payload['method_id'] ?? ''));
        $correctAnswer = trim((string) ($payload['correct_answer'] ?? ''));
        $lessonId = (int) ($payload['lesson_id'] ?? 0);
        $timeLimit = (int) ($payload['time_limit_seconds'] ?? 0);
        $isVoice = !empty($payload['is_voice']) || $methodId === 'voice_teaching';

        if ($questionId === '' || $methodId === '') {
            throw new RuntimeException('question_id و method_id الزامی هستند.');
        }
        if ($lessonId < 1) {
            throw new RuntimeException('lesson_id نامعتبر است.');
        }
        if ($lessonId > (int) $row['max_lesson_id']) {
            throw new RuntimeException('درس سوال از سقف پیشرفت کاربر بالاتر است.');
        }
        if ($timeLimit < 5 || $timeLimit > 120) {
            throw new RuntimeException('time_limit_seconds نامعتبر است.');
        }
        if (!$isVoice && $correctAnswer === '') {
            throw new RuntimeException('correct_answer برای سوال غیرصوتی الزامی است.');
        }

        $optionIds = $payload['correct_option_ids'] ?? [];
        if (!is_array($optionIds)) {
            $optionIds = [];
        }
        $optionIds = array_values(array_filter(array_map('strval', $optionIds)));

        $updated = $this->attempts->update((int) $row['id'], [
            'status' => 'committed',
            'question_id' => $questionId,
            'method_id' => $methodId,
            'lesson_id' => $lessonId,
            'correct_answer' => $correctAnswer !== '' ? $correctAnswer : null,
            'correct_option_ids' => json_encode($optionIds, JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_voice' => $isVoice ? 1 : 0,
            'time_limit_seconds' => $timeLimit,
            'committed_at_ms' => $this->nowMs(),
        ]);

        return [
            'attempt' => $this->mapAttempt($updated),
            'already_committed' => false,
        ];
    }

    public function submit(int $userId, array $payload): array
    {
        $this->assertFeatureEnabled();
        $token = trim((string) ($payload['attempt_token'] ?? ''));
        $row = $this->requireUserAttempt($userId, $token);

        if (in_array((string) $row['status'], ['won', 'lost', 'expired'], true)) {
            return [
                'attempt' => $this->mapAttempt($row),
                'already_finished' => true,
                'progress' => $this->progress->getMappedProgress($userId),
            ];
        }

        if ((string) $row['status'] !== 'committed') {
            throw new RuntimeException('ابتدا سوال را با commit قفل کنید.');
        }

        $now = $this->nowMs();
        $committedAt = (int) ($row['committed_at_ms'] ?? $row['started_at_ms']);
        $limitSec = (int) ($row['time_limit_seconds'] ?? 25);
        // بافر شبکه/رندر
        $graceMs = 5000;
        $timedOut = ($now - $committedAt) > (($limitSec * 1000) + $graceMs);

        $answer = trim((string) ($payload['answer'] ?? $payload['answer_raw'] ?? ''));
        $optionId = trim((string) ($payload['option_id'] ?? ''));
        $clientCorrect = array_key_exists('client_correct', $payload)
            ? (bool) $payload['client_correct']
            : null;
        $timedOutFlag = !empty($payload['timed_out']) || $timedOut;

        $isVoice = !empty($row['is_voice']);
        $isCorrect = false;
        $failReason = null;

        if ($timedOutFlag) {
            $isCorrect = false;
            $failReason = 'timeout';
        } elseif ($optionId !== '' || $answer !== '') {
            $isCorrect = $this->gradeAnswer($row, $answer, $optionId);
            if (!$isCorrect) {
                $failReason = 'wrong_answer';
            }
        } elseif ($clientCorrect !== null) {
            // UI فعلی فقط صحت را برمی‌گرداند؛ محدودیت زمان + یک‌بار تلاش روی سرور است
            $isCorrect = $clientCorrect;
            if (!$isCorrect) {
                $failReason = $isVoice ? 'voice_failed' : 'wrong_answer';
            }
        } else {
            throw new RuntimeException('پاسخ یا client_correct الزامی است.');
        }

        $dayKey = (string) $row['day_key'];
        if ($dayKey !== $this->progress->todayDayKey()) {
            throw new RuntimeException('روز تلاش با امروز سرور مطابقت ندارد.');
        }

        $questionId = (string) $row['question_id'];
        $coinsAwarded = 0;
        $pointsAwarded = 0;
        $progressMeta = [];

        $oneAttempt = !empty($this->dailyConfig()['one_attempt_per_day']);
        $progressSnap = $this->progress->getMappedProgress($userId);
        $alreadyWon = ($progressSnap['dailyChallengeWonDayKey'] ?? null) === $dayKey;

        if ($isCorrect) {
            if ($alreadyWon && !$oneAttempt) {
                // برد تکراری در حالت چندتلاشی — بدون پاداش دوباره
                $finish = [
                    'progress' => $progressSnap,
                    'meta' => ['already_won' => true, 'coins_added' => 0, 'points_added' => 0],
                ];
            } else {
                $finish = $this->progress->finishDailyChallenge($userId, $dayKey, $questionId, true);
            }
            $coinsAwarded = (int) ($finish['meta']['coins_added'] ?? 0);
            $pointsAwarded = (int) ($finish['meta']['points_added'] ?? 0);
            $progressMeta = $finish['meta'];
            $status = 'won';
        } else {
            if ($oneAttempt) {
                $finish = $this->progress->finishDailyChallenge($userId, $dayKey, $questionId, false);
                $progressMeta = $finish['meta'];
            } else {
                // چندتلاشی: شکست قفل ایجاد نمی‌کند؛ فقط لاگ تلاش
                $finish = ['progress' => $progressSnap];
            }
            $status = 'lost';
        }

        $updated = $this->attempts->update((int) $row['id'], [
            'status' => $status,
            'submitted_at_ms' => $now,
            'answer_raw' => $answer !== '' ? $answer : ($optionId !== '' ? $optionId : null),
            'is_correct' => $isCorrect ? 1 : 0,
            'coins_awarded' => $coinsAwarded,
            'points_awarded' => $pointsAwarded,
            'fail_reason' => $failReason,
        ]);

        $this->notifications?->notifyDailyChallenge($userId, $dayKey, $isCorrect);

        return [
            'attempt' => $this->mapAttempt($updated),
            'is_correct' => $isCorrect,
            'timed_out' => $timedOutFlag,
            'fail_reason' => $failReason,
            'coins_awarded' => $coinsAwarded,
            'points_awarded' => $pointsAwarded,
            'meta' => $progressMeta,
            'progress' => $finish['progress'] ?? $this->progress->getMappedProgress($userId),
            'already_finished' => false,
        ];
    }

    private function gradeAnswer(array $row, string $answer, string $optionId): bool
    {
        $correctOptionIds = json_decode((string) ($row['correct_option_ids'] ?? '[]'), true);
        if (!is_array($correctOptionIds)) {
            $correctOptionIds = [];
        }
        $correctOptionIds = array_map('strval', $correctOptionIds);

        if ($optionId !== '' && $correctOptionIds !== []) {
            return in_array($optionId, $correctOptionIds, true);
        }

        $expected = $this->normalizeAnswer((string) ($row['correct_answer'] ?? ''));
        if ($expected === '') {
            return false;
        }

        $got = $this->normalizeAnswer($answer);
        if ($got === $expected) {
            return true;
        }

        // سازگاری عربی/فارسی ی و ک
        $got = str_replace(['ي', 'ك'], ['ی', 'ک'], $got);
        $expected = str_replace(['ي', 'ك'], ['ی', 'ک'], $expected);
        return $got === $expected;
    }

    private function normalizeAnswer(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return $value;
    }

    private function requireUserAttempt(int $userId, string $token): array
    {
        if ($token === '') {
            throw new RuntimeException('attempt_token الزامی است.');
        }
        $row = $this->attempts->findByToken($token);
        if (!$row || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('تلاش چالش یافت نشد.');
        }
        return $row;
    }

    private function isAttemptExpired(array $row): bool
    {
        $status = (string) $row['status'];
        if ($status === 'open') {
            // ۱۰ دقیقه برای انتخاب سوال
            return ($this->nowMs() - (int) $row['started_at_ms']) > 10 * 60 * 1000;
        }
        if ($status === 'committed') {
            $limitSec = (int) ($row['time_limit_seconds'] ?? 25);
            $committedAt = (int) ($row['committed_at_ms'] ?? $row['started_at_ms']);
            return ($this->nowMs() - $committedAt) > (($limitSec + 30) * 1000);
        }
        return false;
    }

    private function expireAttempt(array $row): void
    {
        if (in_array((string) $row['status'], ['won', 'lost', 'expired'], true)) {
            return;
        }
        $this->attempts->update((int) $row['id'], [
            'status' => 'expired',
            'fail_reason' => 'expired',
            'submitted_at_ms' => $this->nowMs(),
            'is_correct' => 0,
        ]);

        $dc = $this->dailyConfig();
        if (!empty($dc['one_attempt_per_day']) && !empty($row['question_id'])) {
            try {
                $this->progress->finishDailyChallenge(
                    (int) $row['user_id'],
                    (string) $row['day_key'],
                    (string) $row['question_id'],
                    false
                );
            } catch (RuntimeException) {
                // اگر day عوض شده نادیده
            }
        }
    }

    private function mapAttempt(array $row): array
    {
        $optionIds = json_decode((string) ($row['correct_option_ids'] ?? '[]'), true);
        if (!is_array($optionIds)) {
            $optionIds = [];
        }

        return [
            'id' => (int) $row['id'],
            'dayKey' => (string) $row['day_key'],
            'status' => (string) $row['status'],
            'attemptToken' => (string) $row['attempt_token'],
            'questionId' => $row['question_id'] !== null ? (string) $row['question_id'] : null,
            'methodId' => $row['method_id'] !== null ? (string) $row['method_id'] : null,
            'lessonId' => $row['lesson_id'] !== null ? (int) $row['lesson_id'] : null,
            'isVoice' => !empty($row['is_voice']),
            'timeLimitSeconds' => $row['time_limit_seconds'] !== null ? (int) $row['time_limit_seconds'] : null,
            'maxLessonId' => (int) $row['max_lesson_id'],
            'pickSeed' => (int) $row['pick_seed'],
            'startedAtMs' => (int) $row['started_at_ms'],
            'committedAtMs' => $row['committed_at_ms'] !== null ? (int) $row['committed_at_ms'] : null,
            'submittedAtMs' => $row['submitted_at_ms'] !== null ? (int) $row['submitted_at_ms'] : null,
            'isCorrect' => $row['is_correct'] !== null ? (bool) $row['is_correct'] : null,
            'coinsAwarded' => (int) $row['coins_awarded'],
            'pointsAwarded' => (int) $row['points_awarded'],
            'failReason' => $row['fail_reason'] !== null ? (string) $row['fail_reason'] : null,
            // correct_* فقط برای دیباگ ادمین نیست — عمداً در API عمومی برنمی‌گردانیم بعد از commit
            // اما قبل از submit کلاینت خودش می‌داند؛ اینجا حذف می‌کنیم
        ];
    }

    private function pickSeed(int $userId, string $dayKey, int $maxLessonId): int
    {
        $raw = $dayKey . '|' . $userId . '|' . $maxLessonId;
        $hash = 2166136261;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $hash ^= ord($raw[$i]);
            $hash = ($hash * 16777619) & 0xFFFFFFFF;
        }
        return $hash;
    }

    private function assertFeatureEnabled(): void
    {
        $features = $this->remoteConfig->getPublicConfig()['features'] ?? [];
        if (isset($features['daily_challenge_enabled']) && empty($features['daily_challenge_enabled'])) {
            throw new RuntimeException('چالش روزانه فعلاً غیرفعال است.');
        }
    }

    private function dailyConfig(): array
    {
        $config = $this->remoteConfig->getPublicConfig();
        return is_array($config['daily_challenge'] ?? null) ? $config['daily_challenge'] : [];
    }

    private function publicConfig(array $dc): array
    {
        return [
            'rewardCoins' => (int) ($dc['reward_coins'] ?? 15),
            'winPoints' => (int) ($dc['win_points'] ?? 1),
            'oneAttemptPerDay' => !empty($dc['one_attempt_per_day']),
            'standardSeconds' => (int) ($dc['standard_seconds'] ?? 45),
            'easyQuestionSeconds' => (int) ($dc['easy_question_seconds'] ?? 25),
        ];
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
