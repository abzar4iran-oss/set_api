<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProgressRepository;
use RuntimeException;

final class ProgressService
{
    private ?array $lessonPathCache = null;

    public function __construct(
        private ProgressRepository $progress,
        private RemoteConfigService $remoteConfig,
        private array $appConfig = []
    ) {
    }

    public function get(int $userId): array
    {
        $row = $this->ensureRow($userId);
        $row = $this->applyHeartRegenAndPersist($userId, $row);
        return [
            'progress' => $this->mapPublic($row),
            'is_new' => (int) $row['revision'] === 0,
            'server_time_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    /**
     * فقط وقتی revision === 0 (هنوز هیچ اکشنی روی سرور نشده) مجاز است.
     * برای انتقال پیشرفت محلی/مهمان به حساب واقعی.
     */
    public function import(int $userId, array $incoming): array
    {
        $row = $this->ensureRow($userId);
        if ((int) $row['revision'] !== 0) {
            throw new RuntimeException('پیشرفت سرور از قبل وجود دارد؛ import مجاز نیست. از GET /progress استفاده کنید.');
        }

        $state = $this->normalizeIncomingSnapshot($incoming);
        $state['revision'] = 1;
        $saved = $this->progress->save($userId, $state);
        $this->progress->addEvent($userId, 'import', ['source' => 'client'], 0, 0, 0, 1);

        return [
            'progress' => $this->mapPublic($saved),
            'is_new' => false,
            'server_time_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    public function action(int $userId, string $action, array $payload = []): array
    {
        $action = strtolower(trim($action));
        $row = $this->ensureRow($userId);
        $row = $this->applyHeartRegenAndPersist($userId, $row);

        $before = $row;
        $meta = [];

        switch ($action) {
            case 'complete_lesson':
                [$row, $meta] = $this->actCompleteLesson($row, $payload);
                break;
            case 'complete_takeoff':
                $row['takeoff_platform_completed'] = 1;
                break;
            case 'complete_surah_listen':
                $after = (int) ($payload['after_lesson_id'] ?? 0);
                if ((int) ($row['pending_surah_after_lesson'] ?? 0) !== $after) {
                    throw new RuntimeException('تمرین شنیدن در انتظار با این درس مطابقت ندارد.');
                }
                $row['pending_surah_after_lesson'] = null;
                break;
            case 'wrong_answer':
                [$row, $meta] = $this->actWrongAnswer($row);
                break;
            case 'correct_answer':
                [$row, $meta] = $this->actCorrectAnswer($row);
                break;
            case 'refill_hearts':
                [$row, $meta] = $this->actRefillHearts($row);
                break;
            case 'add_coins':
                $amount = (int) ($payload['amount'] ?? 0);
                if ($amount <= 0 || $amount > 50000) {
                    throw new RuntimeException('مقدار سکه نامعتبر است.');
                }
                $row['coins'] = (int) $row['coins'] + $amount;
                $meta = ['coins_added' => $amount, 'reason' => (string) ($payload['reason'] ?? 'manual')];
                break;
            case 'spend_coins':
                $amount = (int) ($payload['amount'] ?? 0);
                if ($amount <= 0) {
                    throw new RuntimeException('مقدار کسر سکه نامعتبر است.');
                }
                if ((int) $row['coins'] < $amount) {
                    throw new RuntimeException('سکه کافی نیست.');
                }
                $row['coins'] = (int) $row['coins'] - $amount;
                $meta = ['coins_spent' => $amount, 'reason' => (string) ($payload['reason'] ?? 'spend')];
                break;
            case 'add_compete_points':
                $amount = (int) ($payload['amount'] ?? 0);
                if ($amount <= 0 || $amount > 10000) {
                    throw new RuntimeException('مقدار امتیاز نامعتبر است.');
                }
                $row['compete_points'] = (int) $row['compete_points'] + $amount;
                $meta = ['points_added' => $amount];
                break;
            case 'convert_points':
                [$row, $meta] = $this->actConvertPoints($row, $payload);
                break;
            case 'daily_challenge_win':
                [$row, $meta] = $this->actDailyWin($row, $payload);
                break;
            case 'daily_challenge_fail':
                [$row, $meta] = $this->actDailyFail($row, $payload);
                break;
            case 'daily_challenge_played':
                $row = $this->actDailyPlayed($row, $payload);
                break;
            case 'daily_challenge_finish':
                [$row, $meta] = $this->actDailyFinish($row, $payload);
                break;
            case 'purchase_coin_pack':
                $this->assertDirectGrantAllowed();
                [$row, $meta] = $this->actPurchaseCoinPack($row, $payload);
                break;
            case 'activate_premium':
                $this->assertDirectGrantAllowed();
                [$row, $meta] = $this->actActivatePremium($row, $payload);
                break;
            case 'shop_fulfill':
                [$row, $meta] = $this->actShopFulfill($row, $payload);
                break;
            case 'record_match_played':
                $row['matches_played'] = (int) $row['matches_played'] + 1;
                break;
            case 'compete_entry_charge':
                [$row, $meta] = $this->actCompeteEntryCharge($row, $payload);
                break;
            case 'compete_entry_refund':
                [$row, $meta] = $this->actCompeteEntryRefund($row, $payload);
                break;
            case 'compete_match_settle':
                [$row, $meta] = $this->actCompeteMatchSettle($row, $payload);
                break;
            case 'reset':
                $row = array_merge($row, $this->defaultStateFields());
                $row['revision'] = (int) $before['revision'];
                break;
            default:
                throw new RuntimeException("اکشن «{$action}» پشتیبانی نمی‌شود.");
        }

        $row['revision'] = (int) $before['revision'] + 1;
        $saved = $this->progress->save($userId, $this->rowToSaveArray($row));

        $coinsDelta = (int) $saved['coins'] - (int) $before['coins'];
        $heartsDelta = (int) $saved['hearts'] - (int) $before['hearts'];
        $pointsDelta = (int) $saved['compete_points'] - (int) $before['compete_points'];
        $this->progress->addEvent(
            $userId,
            $action,
            array_merge($payload, $meta),
            $coinsDelta,
            $heartsDelta,
            $pointsDelta,
            (int) $saved['revision']
        );

        return [
            'progress' => $this->mapPublic($saved),
            'action' => $action,
            'meta' => $meta,
            'server_time_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    private function ensureRow(int $userId): array
    {
        $row = $this->progress->findByUserId($userId);
        if ($row) {
            return $row;
        }

        return $this->progress->create($userId, $this->defaultStateFields());
    }

    /** @return array<string, mixed> */
    private function defaultStateFields(): array
    {
        $economy = $this->economy();
        $features = $this->remoteConfig->getPublicConfig()['features'] ?? [];
        $unlockAll = !empty($features['unlock_all_stages']);

        return [
            'revision' => 0,
            'coins' => (int) ($economy['starting_coins'] ?? 110),
            'hearts' => (int) ($economy['max_hearts'] ?? 5),
            'compete_points' => 0,
            'is_premium' => 0,
            'premium_expires_at' => null,
            'last_heart_regen_at' => null,
            'current_lesson' => $unlockAll ? 0 : 1,
            'pending_surah_after_lesson' => null,
            'takeoff_platform_completed' => $unlockAll ? 1 : 0,
            'matches_played' => 0,
            'daily_challenge_locked_until' => null,
            'daily_challenge_won_day_key' => null,
            'daily_challenge_played_day_key' => null,
            'daily_challenge_played_question_ids' => '[]',
            'word_play_level_index' => 0,
            'word_play_content_version' => 9,
        ];
    }

    /** به‌روزرسانی پیشرفت بازی با کلمات بدون تغییر سکه/امتیاز */
    public function setWordPlayProgress(int $userId, int $levelIndex, int $contentVersion): array
    {
        $row = $this->ensureRow($userId);
        $row['word_play_level_index'] = max(0, $levelIndex);
        $row['word_play_content_version'] = max(1, $contentVersion);
        $row['revision'] = (int) $row['revision'] + 1;
        $saved = $this->progress->save($userId, $this->rowToSaveArray($row));
        $this->progress->addEvent(
            $userId,
            'word_play_progress',
            ['level_index' => $levelIndex, 'content_version' => $contentVersion],
            0,
            0,
            0,
            (int) $saved['revision']
        );
        return $this->mapPublic($saved);
    }

    private function applyHeartRegenAndPersist(int $userId, array $row): array
    {
        $economy = $this->economy();
        $maxHearts = (int) ($economy['max_hearts'] ?? 5);
        $regenMinutes = (int) ($economy['heart_regen_minutes'] ?? 240);
        $hearts = (int) $row['hearts'];
        $last = $row['last_heart_regen_at'] !== null ? (int) $row['last_heart_regen_at'] : null;
        $now = (int) round(microtime(true) * 1000);

        if ($regenMinutes <= 0 || $hearts >= $maxHearts) {
            $nextLast = $hearts >= $maxHearts ? null : $last;
            if ($nextLast !== $last) {
                $row['last_heart_regen_at'] = $nextLast;
                return $this->progress->save($userId, $this->rowToSaveArray($row));
            }
            return $row;
        }

        $anchor = $last ?? $now;
        $regenMs = $regenMinutes * 60 * 1000;
        $gained = (int) floor(($now - $anchor) / $regenMs);
        if ($gained <= 0) {
            if ($last === null) {
                $row['last_heart_regen_at'] = $now;
                return $this->progress->save($userId, $this->rowToSaveArray($row));
            }
            return $row;
        }

        $nextHearts = min($maxHearts, $hearts + $gained);
        $row['hearts'] = $nextHearts;
        $row['last_heart_regen_at'] = $nextHearts >= $maxHearts ? null : $anchor + ($gained * $regenMs);
        return $this->progress->save($userId, $this->rowToSaveArray($row));
    }

    /** @return array{0: array, 1: array} */
    private function actCompleteLesson(array $row, array $payload): array
    {
        $lessonId = (int) ($payload['lesson_id'] ?? 0);
        if ($lessonId <= 0) {
            throw new RuntimeException('شناسه درس نامعتبر است.');
        }

        $features = $this->remoteConfig->getPublicConfig()['features'] ?? [];
        $unlockAll = !empty($features['unlock_all_stages']);
        $current = (int) $row['current_lesson'];

        if (!$unlockAll && $current !== 0 && $lessonId !== $current) {
            throw new RuntimeException('فقط درس فعلی مسیر قابل تکمیل است.');
        }

        if (!$unlockAll && $current === 0) {
            throw new RuntimeException('همهٔ دروس قبلاً تکمیل شده‌اند.');
        }

        $path = $this->lessonPath();
        $pathIds = $path['path_lesson_ids'] ?? [];
        if (!in_array($lessonId, $pathIds, true)) {
            throw new RuntimeException('این درس در مسیر یادگیری نیست.');
        }

        $next = $this->nextPathLessonId($lessonId);
        $row['current_lesson'] = $next ?? 0;

        $pending = $this->pendingSurahAfterLesson($lessonId);
        if ($pending !== null) {
            $row['pending_surah_after_lesson'] = $pending;
        }

        $meta = [
            'lesson_id' => $lessonId,
            'next_current_lesson' => (int) $row['current_lesson'],
            'pending_surah_after_lesson' => $row['pending_surah_after_lesson'],
            'rewarded' => false,
        ];

        if ($this->isLearnLesson($lessonId)) {
            $economy = $this->economy();
            $coinReward = (int) ($economy['lesson_complete_coin_reward'] ?? 5);
            $pointsReward = (int) ($economy['lesson_complete_points_reward'] ?? 10);
            if ($this->isPremiumActive($row)) {
                $mult = (int) ($economy['premium_points_multiplier'] ?? 2);
                $pointsReward *= max(1, $mult);
            }
            $row['coins'] = (int) $row['coins'] + $coinReward;
            $row['compete_points'] = (int) $row['compete_points'] + $pointsReward;
            $meta['rewarded'] = true;
            $meta['coins_added'] = $coinReward;
            $meta['points_added'] = $pointsReward;
        }

        return [$row, $meta];
    }

    /** @return array{0: array, 1: array} */
    private function actWrongAnswer(array $row): array
    {
        $economy = $this->economy();
        $maxHearts = (int) ($economy['max_hearts'] ?? 5);
        $isPremium = $this->isPremiumActive($row);
        $unlimited = $isPremium && !empty($economy['premium_unlimited_hearts']);
        $noPenalty = $isPremium && !empty($economy['premium_no_coin_penalty']);

        if ($unlimited && $noPenalty) {
            return [$row, [
                'coins_deducted' => 0,
                'hearts_left' => $maxHearts,
                'hearts_depleted' => false,
            ]];
        }

        $coinsDeducted = 0;
        if (!$noPenalty) {
            $coinsDeducted = (int) ($economy['wrong_answer_coin_penalty'] ?? 0);
            $row['coins'] = max(0, (int) $row['coins'] - $coinsDeducted);
        }

        if ($unlimited) {
            return [$row, [
                'coins_deducted' => $coinsDeducted,
                'hearts_left' => $maxHearts,
                'hearts_depleted' => false,
            ]];
        }

        $nextHearts = max(0, (int) $row['hearts'] - 1);
        $row['hearts'] = $nextHearts;
        if ($row['last_heart_regen_at'] === null) {
            $row['last_heart_regen_at'] = (int) round(microtime(true) * 1000);
        }

        return [$row, [
            'coins_deducted' => $coinsDeducted,
            'hearts_left' => $nextHearts,
            'hearts_depleted' => $nextHearts <= 0,
        ]];
    }

    /** @return array{0: array, 1: array} */
    private function actCorrectAnswer(array $row): array
    {
        $economy = $this->economy();
        $points = (int) ($economy['correct_answer_points_reward'] ?? 2);
        if ($this->isPremiumActive($row)) {
            $points *= max(1, (int) ($economy['premium_points_multiplier'] ?? 2));
        }
        $row['compete_points'] = (int) $row['compete_points'] + $points;

        $coins = (int) ($economy['correct_answer_coin_reward'] ?? 0);
        if ($coins > 0) {
            $row['coins'] = (int) $row['coins'] + $coins;
        }

        return [$row, [
            'points_added' => $points,
            'coins_added' => $coins > 0 ? $coins : 0,
        ]];
    }

    /** @return array{0: array, 1: array} */
    private function actRefillHearts(array $row): array
    {
        $economy = $this->economy();
        $maxHearts = (int) ($economy['max_hearts'] ?? 5);
        if ((int) $row['hearts'] >= $maxHearts) {
            return [$row, ['refill' => false, 'already_full' => true]];
        }

        $cost = (int) ($economy['heart_refill_coin_cost'] ?? 12);
        if ((int) $row['coins'] < $cost) {
            throw new RuntimeException('سکه کافی برای پر کردن قلب نیست.');
        }

        $row['coins'] = (int) $row['coins'] - $cost;
        $row['hearts'] = $maxHearts;
        $row['last_heart_regen_at'] = null;
        return [$row, ['refill' => true, 'coins_spent' => $cost]];
    }

    /** @return array{0: array, 1: array} */
    private function actConvertPoints(array $row, array $payload): array
    {
        $economy = $this->economy();
        $unit = (int) ($economy['points_conversion_unit'] ?? 100);
        $coinsPer = (int) ($economy['coins_per_conversion_unit'] ?? 10);
        $points = (int) ($payload['points'] ?? 0);

        if ($points < $unit || $points % $unit !== 0) {
            throw new RuntimeException('مقدار امتیاز برای تبدیل معتبر نیست.');
        }
        if ((int) $row['compete_points'] < $points) {
            throw new RuntimeException('امتیاز کافی نیست.');
        }

        $coins = (int) (($points / $unit) * $coinsPer);
        $row['compete_points'] = (int) $row['compete_points'] - $points;
        $row['coins'] = (int) $row['coins'] + $coins;
        return [$row, ['points_spent' => $points, 'coins_added' => $coins]];
    }

    /** @return array{0: array, 1: array} */
    private function actDailyWin(array $row, array $payload): array
    {
        $dayKey = $this->requireValidDayKey($payload['day_key'] ?? null);
        $this->assertDayKeyIsToday($dayKey);

        if ((string) ($row['daily_challenge_won_day_key'] ?? '') === $dayKey) {
            return [$row, ['already_won' => true, 'coins_added' => 0, 'points_added' => 0]];
        }

        $dc = $this->dailyChallengeConfig();
        $reward = (int) ($dc['reward_coins'] ?? 15);
        $points = (int) ($dc['win_points'] ?? 1);
        $row['coins'] = (int) $row['coins'] + $reward;
        $row['compete_points'] = (int) $row['compete_points'] + $points;
        $row['daily_challenge_won_day_key'] = $dayKey;
        $row['daily_challenge_locked_until'] = $this->startOfTomorrowMs();

        $questionId = trim((string) ($payload['question_id'] ?? ''));
        if ($questionId !== '') {
            $row = $this->actDailyPlayed($row, [
                'day_key' => $dayKey,
                'question_id' => $questionId,
            ]);
        }

        return [$row, ['coins_added' => $reward, 'points_added' => $points]];
    }

    /** @return array{0: array, 1: array} */
    private function actDailyFail(array $row, array $payload): array
    {
        $dayKey = trim((string) ($payload['day_key'] ?? ''));
        if ($dayKey !== '') {
            $this->assertDayKeyIsToday($dayKey);
            $questionId = trim((string) ($payload['question_id'] ?? ''));
            if ($questionId !== '') {
                $row = $this->actDailyPlayed($row, [
                    'day_key' => $dayKey,
                    'question_id' => $questionId,
                ]);
            }
        }

        $row['daily_challenge_locked_until'] = $this->startOfTomorrowMs();
        return [$row, ['locked' => true]];
    }

    /** @return array{0: array, 1: array} */
    private function actDailyFinish(array $row, array $payload): array
    {
        $dayKey = $this->requireValidDayKey($payload['day_key'] ?? null);
        $this->assertDayKeyIsToday($dayKey);
        $questionId = trim((string) ($payload['question_id'] ?? ''));
        if ($questionId === '') {
            throw new RuntimeException('question_id الزامی است.');
        }

        $outcome = strtolower(trim((string) ($payload['outcome'] ?? '')));
        if (!in_array($outcome, ['won', 'lost'], true)) {
            throw new RuntimeException('outcome باید won یا lost باشد.');
        }

        $row = $this->actDailyPlayed($row, [
            'day_key' => $dayKey,
            'question_id' => $questionId,
        ]);

        if ($outcome === 'won') {
            return $this->actDailyWin($row, [
                'day_key' => $dayKey,
                'question_id' => $questionId,
            ]);
        }

        $row['daily_challenge_locked_until'] = $this->startOfTomorrowMs();
        return [$row, ['locked' => true, 'outcome' => 'lost']];
    }

    /**
     * فراخوانی مستقیم از DailyChallengeService — یک revision برای پایان چالش.
     *
     * @return array{progress: array, meta: array}
     */
    public function finishDailyChallenge(
        int $userId,
        string $dayKey,
        string $questionId,
        bool $won
    ): array {
        $result = $this->action($userId, 'daily_challenge_finish', [
            'day_key' => $dayKey,
            'question_id' => $questionId,
            'outcome' => $won ? 'won' : 'lost',
        ]);

        return [
            'progress' => $result['progress'],
            'meta' => $result['meta'] ?? [],
        ];
    }

    /**
     * اعطای خرید تأییدشده فروشگاه.
     *
     * @return array{progress: array, meta: array}
     */
    public function fulfillShopOrder(
        int $userId,
        int $coinsGrant,
        int $vipDays,
        string $orderToken
    ): array {
        $result = $this->action($userId, 'shop_fulfill', [
            'coins' => $coinsGrant,
            'vip_days' => $vipDays,
            'order_token' => $orderToken,
        ]);
        return ['progress' => $result['progress'], 'meta' => $result['meta'] ?? []];
    }

    private function assertDirectGrantAllowed(): void
    {
        $allowed = !empty($this->appConfig['payments']['allow_direct_grant']);
        if (!$allowed) {
            throw new RuntimeException(
                'اعطای مستقیم غیرفعال است. از مسیر پرداخت فروشگاه (/shop/orders) استفاده کنید.'
            );
        }
    }

    /** @return array{0: array, 1: array} */
    private function actActivatePremium(array $row, array $payload): array
    {
        $days = (int) ($payload['vip_days'] ?? $payload['days'] ?? 30);
        if ($days <= 0) {
            $days = 30;
        }
        $row = $this->applyPremiumDays($row, $days);
        return [$row, ['premium' => true, 'vip_days' => $days, 'payment' => 'direct_grant']];
    }

    /** @return array{0: array, 1: array} */
    private function actShopFulfill(array $row, array $payload): array
    {
        $coins = max(0, (int) ($payload['coins'] ?? 0));
        $vipDays = max(0, (int) ($payload['vip_days'] ?? 0));
        $orderToken = trim((string) ($payload['order_token'] ?? ''));

        if ($coins <= 0 && $vipDays <= 0) {
            throw new RuntimeException('محتوای سفارش برای اعطا خالی است.');
        }

        if ($coins > 0) {
            $row['coins'] = (int) $row['coins'] + $coins;
        }
        if ($vipDays > 0) {
            $row = $this->applyPremiumDays($row, $vipDays);
        }

        return [$row, [
            'coins_added' => $coins,
            'vip_days' => $vipDays,
            'order_token' => $orderToken,
            'payment' => 'verified',
        ]];
    }

    private function applyPremiumDays(array $row, int $days): array
    {
        $now = time();
        $currentExpiry = null;
        if (!empty($row['premium_expires_at'])) {
            $ts = strtotime((string) $row['premium_expires_at']);
            if ($ts !== false && $ts > $now) {
                $currentExpiry = $ts;
            }
        }

        $base = $currentExpiry ?? $now;
        $row['is_premium'] = 1;
        $row['premium_expires_at'] = date('Y-m-d H:i:s', $base + ($days * 86400));
        return $row;
    }

    private function isPremiumActive(array $row): bool
    {
        if (empty($row['is_premium'])) {
            return false;
        }
        if (empty($row['premium_expires_at'])) {
            return true; // legacy بدون انقضا
        }
        $ts = strtotime((string) $row['premium_expires_at']);
        return $ts !== false && $ts > time();
    }

    public function getMappedProgress(int $userId): array
    {
        return $this->get($userId)['progress'];
    }

    private function dailyChallengeConfig(): array
    {
        $config = $this->remoteConfig->getPublicConfig();
        return is_array($config['daily_challenge'] ?? null) ? $config['daily_challenge'] : [];
    }

    private function requireValidDayKey(mixed $raw): string
    {
        $dayKey = trim((string) $raw);
        if ($dayKey === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayKey)) {
            throw new RuntimeException('day_key نامعتبر است (YYYY-MM-DD).');
        }
        return $dayKey;
    }

    private function assertDayKeyIsToday(string $dayKey): void
    {
        $today = $this->todayDayKey();
        if ($dayKey !== $today) {
            throw new RuntimeException("day_key باید امروز سرور باشد ({$today}).");
        }
    }

    public function todayDayKey(): string
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    public function startOfTomorrowMsPublic(): int
    {
        return $this->startOfTomorrowMs();
    }

    /** @return array{progress: array, meta: array} */
    public function chargeCompeteEntry(int $userId, int $fee): array
    {
        $result = $this->action($userId, 'compete_entry_charge', ['amount' => $fee]);
        return ['progress' => $result['progress'], 'meta' => $result['meta'] ?? []];
    }

    /** @return array{progress: array, meta: array} */
    public function refundCompeteEntry(int $userId, int $fee): array
    {
        $result = $this->action($userId, 'compete_entry_refund', ['amount' => $fee]);
        return ['progress' => $result['progress'], 'meta' => $result['meta'] ?? []];
    }

    /**
     * @param 'win'|'draw'|'loss' $outcome
     * @return array{progress: array, meta: array}
     */
    public function settleCompeteMatch(
        int $userId,
        string $outcome,
        int $rewardCoins,
        int $pointsAwarded
    ): array {
        $result = $this->action($userId, 'compete_match_settle', [
            'outcome' => $outcome,
            'reward_coins' => $rewardCoins,
            'points' => $pointsAwarded,
        ]);
        return ['progress' => $result['progress'], 'meta' => $result['meta'] ?? []];
    }

    /** @return array{0: array, 1: array} */
    private function actCompeteEntryCharge(array $row, array $payload): array
    {
        $amount = (int) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('هزینه ورود نامعتبر است.');
        }
        if ((int) $row['coins'] < $amount) {
            throw new RuntimeException('سکه کافی برای ورود به مسابقه نیست.');
        }
        $row['coins'] = (int) $row['coins'] - $amount;
        return [$row, ['coins_spent' => $amount]];
    }

    /** @return array{0: array, 1: array} */
    private function actCompeteEntryRefund(array $row, array $payload): array
    {
        $amount = (int) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ بازگشت نامعتبر است.');
        }
        $row['coins'] = (int) $row['coins'] + $amount;
        return [$row, ['coins_refunded' => $amount]];
    }

    /** @return array{0: array, 1: array} */
    private function actCompeteMatchSettle(array $row, array $payload): array
    {
        $outcome = strtolower(trim((string) ($payload['outcome'] ?? '')));
        if (!in_array($outcome, ['win', 'draw', 'loss'], true)) {
            throw new RuntimeException('outcome باید win یا draw یا loss باشد.');
        }

        $reward = max(0, (int) ($payload['reward_coins'] ?? 0));
        $points = max(0, (int) ($payload['points'] ?? 0));

        if ($outcome === 'win' && $points > 0 && $this->isPremiumActive($row)) {
            $economy = $this->economy();
            $points *= max(1, (int) ($economy['premium_points_multiplier'] ?? 2));
        }

        $row['coins'] = (int) $row['coins'] + $reward;
        $row['compete_points'] = (int) $row['compete_points'] + $points;
        $row['matches_played'] = (int) $row['matches_played'] + 1;

        return [$row, [
            'outcome' => $outcome,
            'coins_added' => $reward,
            'points_added' => $points,
            'matches_played' => (int) $row['matches_played'],
        ]];
    }

    private function actDailyPlayed(array $row, array $payload): array
    {
        $dayKey = trim((string) ($payload['day_key'] ?? ''));
        $questionId = trim((string) ($payload['question_id'] ?? ''));
        if ($dayKey === '' || $questionId === '') {
            throw new RuntimeException('day_key و question_id الزامی هستند.');
        }

        $ids = $this->decodeQuestionIds($row['daily_challenge_played_question_ids'] ?? '[]');
        if ((string) ($row['daily_challenge_played_day_key'] ?? '') !== $dayKey) {
            $ids = [$questionId];
        } elseif (!in_array($questionId, $ids, true)) {
            $ids[] = $questionId;
        }

        $row['daily_challenge_played_day_key'] = $dayKey;
        $row['daily_challenge_played_question_ids'] = json_encode(array_values($ids), JSON_UNESCAPED_UNICODE) ?: '[]';
        return $row;
    }

    /** @return array{0: array, 1: array} */
    private function actPurchaseCoinPack(array $row, array $payload): array
    {
        $packId = trim((string) ($payload['pack_id'] ?? ''));
        $shop = $this->remoteConfig->getPublicConfig()['shop'] ?? [];
        $packs = $shop['coin_packs'] ?? [];
        if (!is_array($packs)) {
            throw new RuntimeException('بستهٔ سکه در Remote Config تعریف نشده.');
        }

        $coins = null;
        foreach ($packs as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            if ($packId !== '' && (string) ($pack['id'] ?? '') === $packId) {
                $coins = (int) ($pack['coins'] ?? 0);
                break;
            }
        }

        // سازگاری با کلاینت قدیمی که فقط تعداد سکه می‌فرستد
        if ($coins === null && isset($payload['coins'])) {
            $requested = (int) $payload['coins'];
            foreach ($packs as $pack) {
                if (is_array($pack) && (int) ($pack['coins'] ?? 0) === $requested) {
                    $coins = $requested;
                    $packId = (string) ($pack['id'] ?? $packId);
                    break;
                }
            }
        }

        if ($coins === null || $coins <= 0) {
            throw new RuntimeException('بستهٔ سکه یافت نشد.');
        }

        // فعلاً بدون درگاه پرداخت — اعتباردهی مستقیم (مرحلهٔ فروشگاه جداگانه پرداخت را اضافه می‌کند)
        $row['coins'] = (int) $row['coins'] + $coins;
        return [$row, [
            'pack_id' => $packId,
            'coins_added' => $coins,
            'payment' => 'granted_without_gateway',
        ]];
    }

    private function normalizeIncomingSnapshot(array $incoming): array
    {
        $defaults = $this->defaultStateFields();
        $ids = $incoming['daily_challenge_played_question_ids']
            ?? $incoming['dailyChallengePlayedQuestionIds']
            ?? [];
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('strval', $ids)));

        $pending = $incoming['pending_surah_after_lesson']
            ?? $incoming['pendingSurahAfterLesson']
            ?? null;

        return [
            'revision' => 0,
            'coins' => max(0, (int) ($incoming['coins'] ?? $defaults['coins'])),
            'hearts' => max(0, (int) ($incoming['hearts'] ?? $defaults['hearts'])),
            'compete_points' => max(0, (int) (
                $incoming['compete_points']
                ?? $incoming['competePoints']
                ?? $defaults['compete_points']
            )),
            'is_premium' => !empty($incoming['is_premium'] ?? $incoming['isPremium'] ?? false) ? 1 : 0,
            'last_heart_regen_at' => $this->nullableInt(
                $incoming['last_heart_regen_at'] ?? $incoming['lastHeartRegenAt'] ?? null
            ),
            'current_lesson' => (int) (
                $incoming['current_lesson']
                ?? $incoming['currentLesson']
                ?? $defaults['current_lesson']
            ),
            'pending_surah_after_lesson' => $pending === null || $pending === ''
                ? null
                : (int) $pending,
            'takeoff_platform_completed' => !empty(
                $incoming['takeoff_platform_completed']
                ?? $incoming['takeoffPlatformCompleted']
                ?? false
            ) ? 1 : 0,
            'matches_played' => max(0, (int) (
                $incoming['matches_played']
                ?? $incoming['matchesPlayed']
                ?? 0
            )),
            'daily_challenge_locked_until' => $this->nullableInt(
                $incoming['daily_challenge_locked_until']
                ?? $incoming['dailyChallengeLockedUntil']
                ?? null
            ),
            'daily_challenge_won_day_key' => $this->nullableString(
                $incoming['daily_challenge_won_day_key']
                ?? $incoming['dailyChallengeWonDayKey']
                ?? null
            ),
            'daily_challenge_played_day_key' => $this->nullableString(
                $incoming['daily_challenge_played_day_key']
                ?? $incoming['dailyChallengePlayedDayKey']
                ?? null
            ),
            'daily_challenge_played_question_ids' => json_encode($ids, JSON_UNESCAPED_UNICODE) ?: '[]',
            'word_play_level_index' => max(0, (int) (
                $incoming['word_play_level_index']
                ?? $incoming['wordPlayLevelIndex']
                ?? $defaults['word_play_level_index']
            )),
            'word_play_content_version' => max(1, (int) (
                $incoming['word_play_content_version']
                ?? $incoming['wordPlayContentVersion']
                ?? $defaults['word_play_content_version']
            )),
        ];
    }

    private function rowToSaveArray(array $row): array
    {
        $ids = $row['daily_challenge_played_question_ids'] ?? '[]';
        if (is_array($ids)) {
            $ids = json_encode(array_values($ids), JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        return [
            'revision' => (int) $row['revision'],
            'coins' => (int) $row['coins'],
            'hearts' => (int) $row['hearts'],
            'compete_points' => (int) $row['compete_points'],
            'is_premium' => !empty($row['is_premium']) ? 1 : 0,
            'premium_expires_at' => $row['premium_expires_at'] ?? null,
            'last_heart_regen_at' => $row['last_heart_regen_at'] !== null && $row['last_heart_regen_at'] !== ''
                ? (int) $row['last_heart_regen_at']
                : null,
            'current_lesson' => (int) $row['current_lesson'],
            'pending_surah_after_lesson' => $row['pending_surah_after_lesson'] !== null
                && $row['pending_surah_after_lesson'] !== ''
                ? (int) $row['pending_surah_after_lesson']
                : null,
            'takeoff_platform_completed' => !empty($row['takeoff_platform_completed']) ? 1 : 0,
            'matches_played' => (int) $row['matches_played'],
            'daily_challenge_locked_until' => $row['daily_challenge_locked_until'] !== null
                && $row['daily_challenge_locked_until'] !== ''
                ? (int) $row['daily_challenge_locked_until']
                : null,
            'daily_challenge_won_day_key' => $this->nullableString($row['daily_challenge_won_day_key'] ?? null),
            'daily_challenge_played_day_key' => $this->nullableString($row['daily_challenge_played_day_key'] ?? null),
            'daily_challenge_played_question_ids' => (string) $ids,
            'word_play_level_index' => (int) ($row['word_play_level_index'] ?? 0),
            'word_play_content_version' => (int) ($row['word_play_content_version'] ?? 9),
        ];
    }

    private function mapPublic(array $row): array
    {
        $ids = $this->decodeQuestionIds($row['daily_challenge_played_question_ids'] ?? '[]');

        return [
            'revision' => (int) $row['revision'],
            'coins' => (int) $row['coins'],
            'hearts' => (int) $row['hearts'],
            'competePoints' => (int) $row['compete_points'],
            'isPremium' => $this->isPremiumActive($row),
            'premiumExpiresAt' => !empty($row['premium_expires_at'])
                ? date('c', strtotime((string) $row['premium_expires_at']) ?: time())
                : null,
            'lastHeartRegenAt' => $row['last_heart_regen_at'] !== null && $row['last_heart_regen_at'] !== ''
                ? (int) $row['last_heart_regen_at']
                : null,
            'currentLesson' => (int) $row['current_lesson'],
            'pendingSurahAfterLesson' => $row['pending_surah_after_lesson'] !== null
                && $row['pending_surah_after_lesson'] !== ''
                ? (int) $row['pending_surah_after_lesson']
                : null,
            'takeoffPlatformCompleted' => !empty($row['takeoff_platform_completed']),
            'matchesPlayed' => (int) $row['matches_played'],
            'dailyChallengeLockedUntil' => $row['daily_challenge_locked_until'] !== null
                && $row['daily_challenge_locked_until'] !== ''
                ? (int) $row['daily_challenge_locked_until']
                : null,
            'dailyChallengeWonDayKey' => $this->nullableString($row['daily_challenge_won_day_key'] ?? null),
            'dailyChallengePlayedDayKey' => $this->nullableString($row['daily_challenge_played_day_key'] ?? null),
            'dailyChallengePlayedQuestionIds' => $ids,
            'wordPlayLevelIndex' => (int) ($row['word_play_level_index'] ?? 0),
            'wordPlayContentVersion' => (int) ($row['word_play_content_version'] ?? 9),
            'updatedAt' => isset($row['updated_at'])
                ? date('c', strtotime((string) $row['updated_at']) ?: time())
                : date('c'),
        ];
    }

    private function economy(): array
    {
        $config = $this->remoteConfig->getPublicConfig();
        return is_array($config['economy'] ?? null) ? $config['economy'] : [];
    }

    private function lessonPath(): array
    {
        if ($this->lessonPathCache !== null) {
            return $this->lessonPathCache;
        }

        $path = dirname(__DIR__, 2) . '/storage/lesson_path.json';
        if (!is_file($path)) {
            throw new RuntimeException('فایل lesson_path.json یافت نشد.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException('lesson_path.json نامعتبر است.');
        }
        $this->lessonPathCache = $data;
        return $data;
    }

    private function nextPathLessonId(int $completedLessonId): ?int
    {
        $path = $this->lessonPath()['path_lesson_ids'] ?? [];
        $index = array_search($completedLessonId, $path, true);
        if ($index === false) {
            return null;
        }
        return $path[$index + 1] ?? null;
    }

    private function isLearnLesson(int $lessonId): bool
    {
        $learn = $this->lessonPath()['learn_lesson_ids'] ?? [];
        return in_array($lessonId, $learn, true);
    }

    private function pendingSurahAfterLesson(int $lessonId): ?int
    {
        $learn = $this->lessonPath()['learn_lesson_ids'] ?? [];
        $index = array_search($lessonId, $learn, true);
        if ($index === false) {
            return null;
        }
        $ordinal = $index + 1;
        $per = (int) ($this->lessonPath()['lessons_per_surah_listen'] ?? 2);
        if ($per <= 0 || $ordinal % $per !== 0) {
            return null;
        }
        return $lessonId;
    }

    private function startOfTomorrowMs(): int
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $tomorrow = new \DateTimeImmutable('tomorrow', $tz);
        return (int) ($tomorrow->getTimestamp() * 1000);
    }

    /** @return list<string> */
    private function decodeQuestionIds(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('strval', $decoded));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }
}
