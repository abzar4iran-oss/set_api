<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompeteMatchRepository;
use App\Repositories\UserRepository;
use RuntimeException;

final class CompeteMatchService
{
    private const ROUND_COUNT = 5;
    private const ROUND_SECONDS = 15;
    private const MATCH_TTL_MS = 45 * 60 * 1000;

    private const PLAYER_NAMES = [
        'علی', 'فاطمه', 'محمد', 'زهرا', 'حسین', 'مریم', 'امیر', 'سارا', 'رضا', 'نرگس',
    ];

    private const PLAYER_COLORS = [
        '#2563EB', '#DB2777', '#059669', '#D97706', '#7C3AED', '#0891B2', '#DC2626', '#4F46E5',
    ];

    public function __construct(
        private CompeteMatchRepository $matches,
        private ProgressService $progress,
        private RemoteConfigService $remoteConfig,
        private ?NotificationService $notifications = null,
        private ?UserRepository $users = null
    ) {
    }

    public function get(int $userId, string $token): array
    {
        $row = $this->requireUserMatch($userId, $token);
        return [
            'match' => $this->mapMatch($row),
            'progress' => $this->progress->getMappedProgress($userId),
        ];
    }

    public function active(int $userId): array
    {
        $row = $this->matches->findActiveForUser($userId);
        if ($row && $this->isStale($row)) {
            $this->expireStale($row);
            $row = null;
        }

        return [
            'match' => $row ? $this->mapMatch($row) : null,
            'config' => $this->publicConfig(),
            'progress' => $this->progress->getMappedProgress($userId),
        ];
    }

    public function history(int $userId, int $limit = 20): array
    {
        $rows = $this->matches->listForUser($userId, $limit);
        return [
            'matches' => array_map(fn (array $row) => $this->mapMatch($row), $rows),
            'config' => $this->publicConfig(),
        ];
    }

    public function leaderboard(int $userId, int $limit = 10): array
    {
        $rows = $this->matches->leaderboard($limit);
        $progress = $this->progress->getMappedProgress($userId);
        $entries = [];
        $rank = 0;
        $currentRank = null;

        foreach ($rows as $row) {
            $rank++;
            $uid = (int) $row['user_id'];
            $name = trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']));
            if ($name === '') {
                $name = 'بازیکن';
            }
            $entry = [
                'rank' => $rank,
                'userId' => $uid,
                'name' => $name,
                'city' => trim((string) ($row['city'] ?? '')),
                'points' => (int) $row['compete_points'],
                'matchesPlayed' => (int) $row['matches_played'],
                'isCurrentUser' => $uid === $userId,
                'avatarColor' => self::PLAYER_COLORS[($uid - 1) % count(self::PLAYER_COLORS)],
                'avatarUrl' => !empty($row['avatar_url']) ? (string) $row['avatar_url'] : null,
            ];
            if ($uid === $userId) {
                $currentRank = $rank;
            }
            $entries[] = $entry;
        }

        return [
            'entries' => $entries,
            'current_user' => [
                'userId' => $userId,
                'rank' => $currentRank,
                'points' => (int) ($progress['competePoints'] ?? 0),
                'matchesPlayed' => (int) ($progress['matchesPlayed'] ?? 0),
            ],
            'server_time_ms' => $this->nowMs(),
        ];
    }

    public function start(int $userId, int $maxLessonId): array
    {
        $this->assertEnabled();
        if ($maxLessonId < 1) {
            throw new RuntimeException('max_lesson_id نامعتبر است.');
        }

        $active = $this->matches->findActiveForUser($userId);
        if ($active) {
            if ($this->isStale($active)) {
                $this->expireStale($active);
            } else {
                return [
                    'match' => $this->mapMatch($active),
                    'config' => $this->publicConfig(),
                    'progress' => $this->progress->getMappedProgress($userId),
                    'resumed' => true,
                ];
            }
        }

        $compete = $this->competeConfig();
        $fee = (int) ($compete['entry_fee_coins'] ?? 5);
        $charge = $this->progress->chargeCompeteEntry($userId, $fee);

        $seed = random_int(1, 0x7FFFFFFF);
        $opponent = $this->buildOpponent($userId, $seed);
        $token = bin2hex(random_bytes(24));

        $row = $this->matches->create([
            'user_id' => $userId,
            'match_token' => $token,
            'status' => 'open',
            'seed' => $seed,
            'max_lesson_id' => $maxLessonId,
            'round_count' => self::ROUND_COUNT,
            'round_seconds' => self::ROUND_SECONDS,
            'entry_fee' => $fee,
            'opponent_json' => json_encode($opponent, JSON_UNESCAPED_UNICODE) ?: '{}',
            'coins_spent' => $fee,
            'started_at_ms' => $this->nowMs(),
        ]);

        return [
            'match' => $this->mapMatch($row),
            'config' => $this->publicConfig(),
            'progress' => $charge['progress'],
            'resumed' => false,
            'search_ms' => 3000,
        ];
    }

    public function commit(int $userId, array $payload): array
    {
        $this->assertEnabled();
        $token = trim((string) ($payload['match_token'] ?? $payload['matchToken'] ?? ''));
        $row = $this->requireUserMatch($userId, $token);

        if ((string) $row['status'] === 'committed' && !empty($row['rounds_json'])) {
            return [
                'match' => $this->mapMatch($row),
                'already_committed' => true,
            ];
        }

        if ((string) $row['status'] !== 'open') {
            throw new RuntimeException('این مسابقه قابل قفل کردن راند نیست.');
        }

        $rounds = $payload['rounds'] ?? [];
        if (!is_array($rounds) || count($rounds) === 0) {
            throw new RuntimeException('لیست rounds الزامی است.');
        }

        $expected = (int) $row['round_count'];
        if (count($rounds) !== $expected) {
            throw new RuntimeException("تعداد راندها باید {$expected} باشد.");
        }

        $normalized = [];
        $seenIds = [];
        foreach ($rounds as $index => $round) {
            if (!is_array($round)) {
                throw new RuntimeException('راند نامعتبر است.');
            }
            $roundIndex = (int) ($round['round_index'] ?? $round['roundIndex'] ?? $index);
            $questionId = trim((string) ($round['question_id'] ?? $round['questionId'] ?? ''));
            $methodId = trim((string) ($round['method_id'] ?? $round['methodId'] ?? ''));
            $lessonId = (int) ($round['lesson_id'] ?? $round['lessonId'] ?? 0);
            $correctAnswer = trim((string) ($round['correct_answer'] ?? $round['correctAnswer'] ?? ''));
            $optionIds = $round['correct_option_ids'] ?? $round['correctOptionIds'] ?? [];
            if (!is_array($optionIds)) {
                $optionIds = [];
            }
            $optionIds = array_values(array_map('strval', $optionIds));

            if ($questionId === '' || $methodId === '' || $lessonId < 1) {
                throw new RuntimeException('فیلدهای راند ناقص است.');
            }
            if ($lessonId > (int) $row['max_lesson_id']) {
                throw new RuntimeException('درس راند از سقف پیشرفت بالاتر است.');
            }
            if (isset($seenIds[$questionId])) {
                throw new RuntimeException('شناسه سوال تکراری در راندها مجاز نیست.');
            }
            $seenIds[$questionId] = true;

            $normalized[] = [
                'roundIndex' => $roundIndex,
                'questionId' => $questionId,
                'methodId' => $methodId,
                'lessonId' => $lessonId,
                'correctAnswer' => $correctAnswer,
                'correctOptionIds' => $optionIds,
            ];
        }

        usort($normalized, static fn ($a, $b) => $a['roundIndex'] <=> $b['roundIndex']);

        $updated = $this->matches->update((int) $row['id'], [
            'status' => 'committed',
            'rounds_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE) ?: '[]',
            'committed_at_ms' => $this->nowMs(),
        ]);

        return [
            'match' => $this->mapMatch($updated),
            'already_committed' => false,
        ];
    }

    public function finish(int $userId, array $payload): array
    {
        $this->assertEnabled();
        $token = trim((string) ($payload['match_token'] ?? $payload['matchToken'] ?? ''));
        $row = $this->requireUserMatch($userId, $token);

        if (in_array((string) $row['status'], ['finished', 'cancelled', 'expired'], true)) {
            return [
                'match' => $this->mapMatch($row),
                'already_finished' => true,
                'progress' => $this->progress->getMappedProgress($userId),
            ];
        }

        if (!in_array((string) $row['status'], ['open', 'committed'], true)) {
            throw new RuntimeException('وضعیت مسابقه برای پایان نامعتبر است.');
        }

        $forfeited = !empty($payload['forfeited']);
        $resultsIn = $payload['rounds'] ?? $payload['results'] ?? [];
        if (!$forfeited && (!is_array($resultsIn) || count($resultsIn) === 0)) {
            throw new RuntimeException('نتایج راندها الزامی است.');
        }

        $playerScore = 0;
        $opponentScore = 0;
        $normalizedResults = [];

        if ($forfeited) {
            $playerScore = 0;
            $opponentScore = (int) $row['round_count'];
            $outcome = 'loss';
        } else {
            foreach ($resultsIn as $index => $result) {
                if (!is_array($result)) {
                    continue;
                }
                $roundIndex = (int) ($result['round_index'] ?? $result['roundIndex'] ?? $index);
                $playerCorrect = (bool) ($result['player_correct'] ?? $result['playerCorrect'] ?? false);
                $opponentCorrect = (bool) ($result['opponent_correct'] ?? $result['opponentCorrect'] ?? false);
                $playerTime = $this->nullableInt($result['player_time_ms'] ?? $result['playerTimeMs'] ?? null);
                $opponentTime = $this->nullableInt($result['opponent_time_ms'] ?? $result['opponentTimeMs'] ?? null);

                // محدودیت منطقی زمان راند
                $maxMs = ((int) $row['round_seconds'] + 2) * 1000;
                if ($playerTime !== null && ($playerTime < 0 || $playerTime > $maxMs)) {
                    $playerTime = $maxMs;
                }
                if ($opponentTime !== null && ($opponentTime < 0 || $opponentTime > $maxMs)) {
                    $opponentTime = $maxMs;
                }

                $winner = $this->resolveRoundWinner(
                    $playerCorrect,
                    $playerTime,
                    $opponentCorrect,
                    $opponentTime
                );
                if ($winner === 'player') {
                    $playerScore++;
                } elseif ($winner === 'opponent') {
                    $opponentScore++;
                }

                $normalizedResults[] = [
                    'roundIndex' => $roundIndex,
                    'playerCorrect' => $playerCorrect,
                    'playerTimeMs' => $playerTime,
                    'opponentCorrect' => $opponentCorrect,
                    'opponentTimeMs' => $opponentTime,
                    'winner' => $winner,
                ];
            }

            if ($playerScore > $opponentScore) {
                $outcome = 'win';
            } elseif ($playerScore < $opponentScore) {
                $outcome = 'loss';
            } else {
                $outcome = 'draw';
            }
        }

        // جلوگیری از امتیاز بالاتر از تعداد راند
        $maxRounds = (int) $row['round_count'];
        $playerScore = min($playerScore, $maxRounds);
        $opponentScore = min($opponentScore, $maxRounds);

        $compete = $this->competeConfig();
        $reward = 0;
        $basePoints = 0;
        if ($outcome === 'win') {
            $reward = (int) ($compete['win_reward_coins'] ?? 10);
            $basePoints = (int) ($compete['win_reward_points'] ?? 20);
        } elseif ($outcome === 'draw') {
            $reward = (int) ($compete['draw_reward_coins'] ?? 0);
        }

        $settle = $this->progress->settleCompeteMatch($userId, $outcome, $reward, $basePoints);
        $pointsAwarded = (int) ($settle['meta']['points_added'] ?? $basePoints);

        $updated = $this->matches->update((int) $row['id'], [
            'status' => 'finished',
            'results_json' => json_encode($normalizedResults, JSON_UNESCAPED_UNICODE) ?: '[]',
            'player_score' => $playerScore,
            'opponent_score' => $opponentScore,
            'outcome' => $outcome,
            'coins_awarded' => $reward,
            'points_awarded' => $pointsAwarded,
            'finished_at_ms' => $this->nowMs(),
        ]);

        $this->notifications?->notifyMatchFinished(
            $userId,
            (string) $updated['match_token'],
            $outcome === 'win',
            $pointsAwarded
        );

        return [
            'match' => $this->mapMatch($updated),
            'outcome' => $outcome,
            'player_score' => $playerScore,
            'opponent_score' => $opponentScore,
            'coins_awarded' => $reward,
            'points_awarded' => $pointsAwarded,
            'already_finished' => false,
            'progress' => $settle['progress'],
            'meta' => $settle['meta'],
        ];
    }

    public function cancel(int $userId, array $payload): array
    {
        $this->assertEnabled();
        $token = trim((string) ($payload['match_token'] ?? $payload['matchToken'] ?? ''));
        $row = $this->requireUserMatch($userId, $token);

        if (in_array((string) $row['status'], ['finished', 'cancelled', 'expired'], true)) {
            return [
                'match' => $this->mapMatch($row),
                'refunded' => false,
                'progress' => $this->progress->getMappedProgress($userId),
            ];
        }

        // فقط قبل از شروع واقعی بازی (open) هزینه برمی‌گردد
        $refund = 0;
        $progress = $this->progress->getMappedProgress($userId);
        if ((string) $row['status'] === 'open') {
            $refund = (int) $row['entry_fee'];
            if ($refund > 0) {
                $refunded = $this->progress->refundCompeteEntry($userId, $refund);
                $progress = $refunded['progress'];
            }
        }

        $updated = $this->matches->update((int) $row['id'], [
            'status' => 'cancelled',
            'outcome' => 'cancelled',
            'finished_at_ms' => $this->nowMs(),
            'coins_awarded' => $refund > 0 ? $refund : 0,
        ]);

        return [
            'match' => $this->mapMatch($updated),
            'refunded' => $refund > 0,
            'refund_coins' => $refund,
            'progress' => $progress,
        ];
    }

    private function resolveRoundWinner(
        bool $playerCorrect,
        ?int $playerTimeMs,
        bool $opponentCorrect,
        ?int $opponentTimeMs
    ): string {
        if ($playerCorrect && $opponentCorrect) {
            $playerTime = $playerTimeMs ?? PHP_INT_MAX;
            $opponentTime = $opponentTimeMs ?? PHP_INT_MAX;
            if ($playerTime < $opponentTime) {
                return 'player';
            }
            if ($opponentTime < $playerTime) {
                return 'opponent';
            }
            return 'none';
        }
        if ($playerCorrect) {
            return 'player';
        }
        if ($opponentCorrect) {
            return 'opponent';
        }
        return 'none';
    }

    private function buildOpponent(int $userId, int $seed): array
    {
        mt_srand($seed);
        $preferHuman = (mt_rand() / mt_getrandmax()) < 0.72;
        $color = self::PLAYER_COLORS[mt_rand(0, count(self::PLAYER_COLORS) - 1)];

        if ($preferHuman && $this->users !== null) {
            $real = $this->users->findRandomOpponent($userId);
            if ($real) {
                $uid = (int) $real['id'];
                $displayName = trim(
                    ((string) ($real['first_name'] ?? '')) . ' ' . ((string) ($real['last_name'] ?? ''))
                );
                if ($displayName === '') {
                    $displayName = 'بازیکن';
                }
                $avatarUrl = !empty($real['avatar_url']) ? (string) $real['avatar_url'] : null;

                return [
                    'id' => 'user_' . $uid,
                    'userId' => $uid,
                    'displayName' => $displayName,
                    'isBot' => false,
                    'avatarColor' => self::PLAYER_COLORS[($uid - 1) % count(self::PLAYER_COLORS)],
                    'avatarUrl' => $avatarUrl,
                    'skill' => round(0.55 + (mt_rand() / mt_getrandmax()) * 0.28, 4),
                ];
            }
        }

        return [
            'id' => 'sim_' . $seed,
            'displayName' => self::PLAYER_NAMES[mt_rand(0, count(self::PLAYER_NAMES) - 1)],
            'isBot' => true,
            'avatarColor' => $color,
            'avatarUrl' => null,
            'skill' => round(0.34 + (mt_rand() / mt_getrandmax()) * 0.2, 4),
        ];
    }

    private function requireUserMatch(int $userId, string $token): array
    {
        if ($token === '') {
            throw new RuntimeException('match_token الزامی است.');
        }
        $row = $this->matches->findByToken($token);
        if (!$row || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('مسابقه یافت نشد.');
        }
        return $row;
    }

    private function isStale(array $row): bool
    {
        return ($this->nowMs() - (int) $row['started_at_ms']) > self::MATCH_TTL_MS
            && in_array((string) $row['status'], ['open', 'committed'], true);
    }

    private function expireStale(array $row): void
    {
        // انقضای open → refund؛ committed → forfeit بدون پاداش ولی بدون matches_played اضافه؟
        // سیاست: open refund؛ committed = باخت بدون settle دوباره اگر قبلاً settled نشده
        if ((string) $row['status'] === 'open') {
            try {
                $this->progress->refundCompeteEntry((int) $row['user_id'], (int) $row['entry_fee']);
            } catch (RuntimeException) {
                // نادیده
            }
            $this->matches->update((int) $row['id'], [
                'status' => 'expired',
                'outcome' => 'expired',
                'finished_at_ms' => $this->nowMs(),
            ]);
            return;
        }

        try {
            $this->progress->settleCompeteMatch((int) $row['user_id'], 'loss', 0, 0);
        } catch (RuntimeException) {
            // نادیده
        }
        $this->matches->update((int) $row['id'], [
            'status' => 'expired',
            'outcome' => 'loss',
            'finished_at_ms' => $this->nowMs(),
        ]);
    }

    private function mapMatch(array $row): array
    {
        $opponent = json_decode((string) ($row['opponent_json'] ?? '{}'), true);
        if (!is_array($opponent)) {
            $opponent = [];
        }
        $rounds = json_decode((string) ($row['rounds_json'] ?? 'null'), true);
        $results = json_decode((string) ($row['results_json'] ?? 'null'), true);

        return [
            'id' => (int) $row['id'],
            'matchToken' => (string) $row['match_token'],
            'status' => (string) $row['status'],
            'seed' => (int) $row['seed'],
            'maxLessonId' => (int) $row['max_lesson_id'],
            'roundCount' => (int) $row['round_count'],
            'roundSeconds' => (int) $row['round_seconds'],
            'entryFee' => (int) $row['entry_fee'],
            'opponent' => [
                'id' => (string) ($opponent['id'] ?? ''),
                'userId' => isset($opponent['userId']) ? (int) $opponent['userId'] : null,
                'displayName' => (string) ($opponent['displayName'] ?? 'حریف'),
                'isBot' => !empty($opponent['isBot']),
                'avatarColor' => (string) ($opponent['avatarColor'] ?? '#7C3AED'),
                'avatarUrl' => !empty($opponent['avatarUrl'])
                    ? (string) $opponent['avatarUrl']
                    : (!empty($opponent['avatar_url']) ? (string) $opponent['avatar_url'] : null),
                'skill' => (float) ($opponent['skill'] ?? 0.5),
            ],
            'rounds' => is_array($rounds) ? $rounds : null,
            'results' => is_array($results) ? $results : null,
            'playerScore' => (int) $row['player_score'],
            'opponentScore' => (int) $row['opponent_score'],
            'outcome' => $row['outcome'] !== null ? (string) $row['outcome'] : null,
            'coinsSpent' => (int) $row['coins_spent'],
            'coinsAwarded' => (int) $row['coins_awarded'],
            'pointsAwarded' => (int) $row['points_awarded'],
            'startedAtMs' => (int) $row['started_at_ms'],
            'committedAtMs' => $row['committed_at_ms'] !== null ? (int) $row['committed_at_ms'] : null,
            'finishedAtMs' => $row['finished_at_ms'] !== null ? (int) $row['finished_at_ms'] : null,
        ];
    }

    private function assertEnabled(): void
    {
        $features = $this->remoteConfig->getPublicConfig()['features'] ?? [];
        if (isset($features['compete_enabled']) && empty($features['compete_enabled'])) {
            throw new RuntimeException('مسابقه زنده فعلاً غیرفعال است.');
        }
    }

    private function competeConfig(): array
    {
        $config = $this->remoteConfig->getPublicConfig();
        return is_array($config['compete'] ?? null) ? $config['compete'] : [];
    }

    private function publicConfig(): array
    {
        $c = $this->competeConfig();
        return [
            'entryFeeCoins' => (int) ($c['entry_fee_coins'] ?? 5),
            'winRewardCoins' => (int) ($c['win_reward_coins'] ?? 10),
            'winRewardPoints' => (int) ($c['win_reward_points'] ?? 20),
            'drawRewardCoins' => (int) ($c['draw_reward_coins'] ?? 0),
            'roundCount' => self::ROUND_COUNT,
            'roundSeconds' => self::ROUND_SECONDS,
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
