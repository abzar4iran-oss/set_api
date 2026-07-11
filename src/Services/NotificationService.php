<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\NotificationRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\PushTokenRepository;
use RuntimeException;
use Throwable;

final class NotificationService
{
    private const KINDS = ['welcome', 'challenge', 'compete', 'lesson', 'practice', 'premium'];

    public function __construct(
        private array $appConfig,
        private NotificationRepository $notifications,
        private PushTokenRepository $pushTokens,
        private ProgressRepository $progress,
        private RemoteConfigService $remoteConfig,
        private ExpoPushService $expoPush
    ) {
    }

    public function list(int $userId, int $limit = 100): array
    {
        $this->assertEnabled();
        $this->ensureSeedCatalog($userId);
        $rows = $this->notifications->listForUser($userId, false, $limit);

        return [
            'notifications' => array_map(fn (array $row) => $this->mapPublic($row), $rows),
            'unread_count' => $this->notifications->unreadCount($userId),
            'server_time_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    public function unreadCount(int $userId): array
    {
        $this->assertEnabled();
        $this->ensureSeedCatalog($userId);
        return [
            'unread_count' => $this->notifications->unreadCount($userId),
            'server_time_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    public function markRead(int $userId, string $publicId): array
    {
        $this->assertEnabled();
        $publicId = trim($publicId);
        if ($publicId === '') {
            throw new RuntimeException('id اعلان الزامی است.');
        }
        $row = $this->notifications->markRead($userId, $publicId);
        if (!$row) {
            throw new RuntimeException('اعلان یافت نشد.');
        }
        return [
            'notification' => $this->mapPublic($row),
            'unread_count' => $this->notifications->unreadCount($userId),
        ];
    }

    public function markAllRead(int $userId): array
    {
        $this->assertEnabled();
        $updated = $this->notifications->markAllRead($userId);
        return [
            'updated' => $updated,
            'unread_count' => 0,
        ];
    }

    public function dismiss(int $userId, string $publicId): array
    {
        $this->assertEnabled();
        $publicId = trim($publicId);
        if ($publicId === '') {
            throw new RuntimeException('id اعلان الزامی است.');
        }
        $row = $this->notifications->dismiss($userId, $publicId);
        if (!$row) {
            throw new RuntimeException('اعلان یافت نشد.');
        }
        return [
            'notification' => $this->mapPublic($row),
            'unread_count' => $this->notifications->unreadCount($userId),
        ];
    }

    /**
     * پیشنهادهای زمینه‌ای (مثل contextual کلاینت) — فقط پیشنهاد؛ در اینباکس ذخیره نمی‌شوند.
     */
    public function suggestions(int $userId): array
    {
        $this->assertEnabled();
        $row = $this->progress->findByUserId($userId) ?? [];
        $todayKey = date('Y-m-d');
        $isPremium = !empty($row['is_premium']);
        if ($isPremium && !empty($row['premium_expires_at'])) {
            $ts = strtotime((string) $row['premium_expires_at']);
            if ($ts !== false && $ts <= time()) {
                $isPremium = false;
            }
        }

        $currentLesson = (int) ($row['current_lesson'] ?? 1);
        $completed = max(0, $currentLesson - 1);
        $playedDay = $row['daily_challenge_played_day_key'] ?? null;
        $wonDay = $row['daily_challenge_won_day_key'] ?? null;
        $triedToday = $playedDay === $todayKey || $wonDay === $todayKey;

        $items = [];
        if (!$triedToday) {
            $items[] = $this->suggestion(
                "ctx-challenge-{$todayKey}",
                'challenge',
                'چالش امروز را از دست ندهید!',
                'هنوز چالش روزانه امروز را امتحان نکرده‌اید. با یک پاسخ درست سکه و امتیاز بگیرید.',
                '/daily-challenge'
            );
        }

        $items[] = $this->suggestion(
            "ctx-lesson-new-{$currentLesson}-{$todayKey}",
            'lesson',
            'ادامه درس فراموش نشود',
            'درس فعال شما هنوز کامل نشده. چند دقیقه تمرین امروز می‌تواند پیشرفت شما را حفظ کند.',
            '/learn'
        );

        $items[] = $this->suggestion(
            "ctx-lesson-review-{$todayKey}",
            'lesson',
            'مرور درس‌های گذشته',
            $completed > 0
                ? "{$completed} درس را گذرانده‌اید. چند دقیقه مرور، یادگیری را محکم‌تر می‌کند."
                : 'مرور کوتاه درس‌های قبلی به ماندگاری یادگیری کمک می‌کند.',
            '/learn'
        );

        $items[] = $this->suggestion(
            "ctx-practice-{$todayKey}",
            'practice',
            'وقت تمرین است',
            'چند دقیقه تمرین صوتی و تعاملی می‌تواند مهارت قرائت شما را تقویت کند.',
            '/learn'
        );

        $items[] = $this->suggestion(
            "ctx-compete-{$todayKey}",
            'compete',
            'حریف جدید منتظر شماست!',
            'یک بازیکن آنلاین برای دوئل آماده است. همین حالا به بخش مسابقه بروید.',
            '/compete'
        );

        if (!$isPremium) {
            $items[] = $this->suggestion(
                "ctx-premium-{$todayKey}",
                'premium',
                'امکانات ویژه در دسترس شماست',
                'با اشتراک ویژه به تمام درس‌ها، تمرین‌های پیشرفته و مزایای بیشتر دسترسی داشته باشید.',
                '/subscribe'
            );
        }

        return [
            'suggestions' => $items,
            'stagger_seconds' => (int) ($this->appConfig['notifications']['suggestion_stagger_seconds'] ?? 300),
            'day_key' => $todayKey,
        ];
    }

    public function registerPushToken(int $userId, array $payload): array
    {
        $this->assertEnabled();
        $token = trim((string) ($payload['token'] ?? $payload['push_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('token الزامی است.');
        }
        $platform = isset($payload['platform']) ? trim((string) $payload['platform']) : null;
        $deviceId = isset($payload['device_id'])
            ? trim((string) $payload['device_id'])
            : (isset($payload['deviceId']) ? trim((string) $payload['deviceId']) : null);
        $appVersion = isset($payload['app_version'])
            ? trim((string) $payload['app_version'])
            : (isset($payload['appVersion']) ? trim((string) $payload['appVersion']) : null);

        $row = $this->pushTokens->upsert(
            $userId,
            $token,
            $platform !== '' ? $platform : null,
            $deviceId !== '' ? $deviceId : null,
            $appVersion !== '' ? $appVersion : null
        );

        return [
            'token' => $this->mapPushToken($row),
            'registered' => true,
        ];
    }

    public function unregisterPushToken(int $userId, array $payload): array
    {
        $this->assertEnabled();
        $token = trim((string) ($payload['token'] ?? $payload['push_token'] ?? ''));
        if ($token === '') {
            $removed = $this->pushTokens->deleteAllForUser($userId);
            return ['removed' => $removed, 'all' => true];
        }
        $ok = $this->pushTokens->deleteByToken($userId, $token);
        return ['removed' => $ok ? 1 : 0, 'all' => false];
    }

    /**
     * ارسال ادمین به یک کاربر، لیست کاربران، یا همه.
     *
     * @return array{created:int,pushed:int,notification_ids:list<string>}
     */
    public function adminSend(array $payload): array
    {
        $this->assertEnabled();
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $kind = strtolower(trim((string) ($payload['kind'] ?? 'lesson')));
        if ($title === '' || $body === '') {
            throw new RuntimeException('title و body الزامی هستند.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('kind نامعتبر است.');
        }

        $deepLink = isset($payload['deep_link'])
            ? trim((string) $payload['deep_link'])
            : (isset($payload['deepLink']) ? trim((string) $payload['deepLink']) : null);
        $publicId = trim((string) ($payload['public_id'] ?? $payload['publicId'] ?? ''));
        if ($publicId === '') {
            $publicId = 'admin-' . bin2hex(random_bytes(8));
        }

        $userIds = $this->resolveTargetUserIds($payload);
        $created = 0;
        $pushed = 0;
        $ids = [];

        foreach ($userIds as $uid) {
            $item = $this->createForUser(
                $uid,
                $publicId . '-u' . $uid,
                $title,
                $body,
                $kind,
                'admin',
                $deepLink,
                ['broadcast_id' => $publicId],
                true
            );
            if ($item['created']) {
                $created++;
            }
            $ids[] = $item['notification']['id'];
            $pushed += $item['pushed'] ? 1 : 0;
        }

        return [
            'created' => $created,
            'pushed' => $pushed,
            'targets' => count($userIds),
            'notification_ids' => $ids,
            'broadcast_id' => $publicId,
        ];
    }

    /** رویداد سیستم — idempotent با public_id */
    public function notifyEvent(
        int $userId,
        string $publicId,
        string $kind,
        string $title,
        string $body,
        ?string $deepLink = null,
        array $meta = [],
        bool $sendPush = true
    ): void {
        try {
            if (!$this->isEnabled()) {
                return;
            }
            $this->createForUser($userId, $publicId, $title, $body, $kind, 'event', $deepLink, $meta, $sendPush);
        } catch (Throwable $e) {
            // رویدادها نباید جریان اصلی را بشکنند
        }
    }

    public function notifyWelcome(int $userId): void
    {
        $this->notifyEvent(
            $userId,
            'welcome',
            'welcome',
            'به النجم ثاقب خوش آمدید!',
            'مسیر یادگیری قرآن را از بخش اول شروع کنید و هر روز کمی تمرین کنید.',
            '/learn',
            [],
            false
        );
    }

    public function notifyShopFulfilled(int $userId, string $orderToken, int $coins, int $vipDays): void
    {
        if ($coins > 0) {
            $this->notifyEvent(
                $userId,
                'shop-coins-' . $orderToken,
                'premium',
                'خرید سکه موفق بود',
                number_format($coins) . ' سکه به حساب شما اضافه شد.',
                '/subscribe',
                ['order_token' => $orderToken, 'coins' => $coins]
            );
        }
        if ($vipDays > 0) {
            $this->notifyEvent(
                $userId,
                'shop-vip-' . $orderToken,
                'premium',
                'اشتراک ویژه فعال شد',
                "اشتراک ویژه شما برای {$vipDays} روز فعال شد.",
                '/subscribe',
                ['order_token' => $orderToken, 'vip_days' => $vipDays]
            );
        }
    }

    public function notifyMatchFinished(int $userId, string $matchToken, bool $won, int $pointsDelta): void
    {
        $this->notifyEvent(
            $userId,
            'match-' . $matchToken,
            'compete',
            $won ? 'پیروزی در مسابقه!' : 'نتیجه مسابقه',
            $won
                ? 'آفرین! در دوئل پیروز شدید' . ($pointsDelta > 0 ? " و {$pointsDelta} امتیاز گرفتید." : '.')
                : 'این بار نشد؛ دوباره در بخش مسابقه امتحان کنید.',
            '/compete',
            ['match_token' => $matchToken, 'won' => $won, 'points' => $pointsDelta]
        );
    }

    public function notifyDailyChallenge(int $userId, string $dayKey, bool $won): void
    {
        $this->notifyEvent(
            $userId,
            'daily-' . $dayKey . '-' . ($won ? 'won' : 'lost'),
            'challenge',
            $won ? 'چالش روزانه را بردید!' : 'چالش روزانه تمام شد',
            $won
                ? 'پاسخ درست بود. سکه و امتیاز به حساب شما اضافه شد.'
                : 'امروز نشد؛ فردا دوباره چالش را امتحان کنید.',
            '/daily-challenge',
            ['day_key' => $dayKey, 'won' => $won]
        );
    }

    /**
     * @return array{notification: array, created: bool, pushed: bool}
     */
    private function createForUser(
        int $userId,
        string $publicId,
        string $title,
        string $body,
        string $kind,
        string $source,
        ?string $deepLink,
        array $meta,
        bool $sendPush
    ): array {
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'lesson';
        }

        $existing = $this->notifications->findByPublicId($userId, $publicId);
        if ($existing) {
            return [
                'notification' => $this->mapPublic($existing),
                'created' => false,
                'pushed' => false,
            ];
        }

        $row = $this->notifications->create([
            'user_id' => $userId,
            'public_id' => $publicId,
            'title' => $title,
            'body' => $body,
            'kind' => $kind,
            'source' => $source,
            'deep_link' => $deepLink,
            'meta_json' => $meta !== [] ? (json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}') : null,
        ]);

        $mapped = $this->mapPublic($row);
        $pushed = false;
        if ($sendPush) {
            $pushed = $this->expoPush->sendToUser($userId, $mapped) > 0;
        }

        return [
            'notification' => $mapped,
            'created' => true,
            'pushed' => $pushed,
        ];
    }

    private function ensureSeedCatalog(int $userId): void
    {
        $cfg = $this->appConfig['notifications'] ?? [];
        if (isset($cfg['seed_catalog']) && empty($cfg['seed_catalog'])) {
            return;
        }

        foreach ($this->catalogSeed() as $item) {
            if ($this->notifications->findByPublicId($userId, $item['public_id'])) {
                continue;
            }
            $this->notifications->create([
                'user_id' => $userId,
                'public_id' => $item['public_id'],
                'title' => $item['title'],
                'body' => $item['body'],
                'kind' => $item['kind'],
                'source' => 'catalog',
                'deep_link' => $item['deep_link'] ?? null,
                'created_at' => $item['created_at'],
            ]);
        }
    }

    /** @return list<array{public_id:string,title:string,body:string,kind:string,deep_link?:string,created_at:string}> */
    private function catalogSeed(): array
    {
        return [
            [
                'public_id' => 'welcome',
                'title' => 'به النجم ثاقب خوش آمدید!',
                'body' => 'مسیر یادگیری قرآن را از بخش اول شروع کنید و هر روز کمی تمرین کنید.',
                'kind' => 'welcome',
                'deep_link' => '/learn',
                'created_at' => '2026-06-04 08:00:00',
            ],
            [
                'public_id' => 'daily-challenge',
                'title' => 'چالش روزانه آماده است',
                'body' => 'امروز چالش جدیدی برایتان آماده شده. با پاسخ درست سکه و امتیاز بگیرید.',
                'kind' => 'challenge',
                'deep_link' => '/daily-challenge',
                'created_at' => '2026-06-04 10:30:00',
            ],
            [
                'public_id' => 'compete-invite',
                'title' => 'حریف جدید منتظر شماست',
                'body' => 'به بخش مسابقه بروید و در دوئل آنلاین شرکت کنید تا امتیازتان بالاتر برود.',
                'kind' => 'compete',
                'deep_link' => '/compete',
                'created_at' => '2026-06-03 16:00:00',
            ],
            [
                'public_id' => 'lesson-reminder',
                'title' => 'ادامه درس فراموش نشود',
                'body' => 'درس فعال شما هنوز کامل نشده. چند دقیقه تمرین امروز می‌تواند پیشرفت شما را حفظ کند.',
                'kind' => 'lesson',
                'deep_link' => '/learn',
                'created_at' => '2026-06-02 09:15:00',
            ],
            [
                'public_id' => 'lesson-review',
                'title' => 'مرور درس‌های گذشته',
                'body' => 'چند دقیقه مرور درس‌های قبلی به ماندگاری یادگیری کمک می‌کند.',
                'kind' => 'lesson',
                'deep_link' => '/learn',
                'created_at' => '2026-06-02 14:00:00',
            ],
            [
                'public_id' => 'practice-reminder',
                'title' => 'وقت تمرین است',
                'body' => 'چند دقیقه تمرین صوتی و تعاملی می‌تواند مهارت قرائت شما را تقویت کند.',
                'kind' => 'practice',
                'deep_link' => '/learn',
                'created_at' => '2026-06-01 18:30:00',
            ],
            [
                'public_id' => 'premium-offer',
                'title' => 'اشتراک ویژه با تخفیف',
                'body' => 'با اشتراک ویژه به تمام درس‌ها و امکانات پیشرفته دسترسی داشته باشید.',
                'kind' => 'premium',
                'deep_link' => '/subscribe',
                'created_at' => '2026-06-01 12:00:00',
            ],
        ];
    }

    /** @return list<int> */
    private function resolveTargetUserIds(array $payload): array
    {
        if (isset($payload['user_id']) || isset($payload['userId'])) {
            return [(int) ($payload['user_id'] ?? $payload['userId'])];
        }
        if (isset($payload['user_ids']) && is_array($payload['user_ids'])) {
            return array_values(array_unique(array_map('intval', $payload['user_ids'])));
        }
        if (isset($payload['userIds']) && is_array($payload['userIds'])) {
            return array_values(array_unique(array_map('intval', $payload['userIds'])));
        }
        if (!empty($payload['broadcast']) || !empty($payload['all'])) {
            return $this->notifications->allUserIds();
        }
        throw new RuntimeException('هدف ارسال مشخص نیست (user_id / user_ids / broadcast).');
    }

    private function mapPublic(array $row): array
    {
        $created = $row['created_at'] ?? null;
        $createdIso = is_string($created) && $created !== ''
            ? date('c', strtotime($created) ?: time())
            : date('c');

        return [
            'id' => (string) $row['public_id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'createdAt' => $createdIso,
            'kind' => (string) $row['kind'],
            'read' => !empty($row['read_at']),
            'dismissed' => !empty($row['dismissed_at']),
            'source' => (string) ($row['source'] ?? 'system'),
            'deepLink' => $row['deep_link'] !== null && $row['deep_link'] !== ''
                ? (string) $row['deep_link']
                : null,
        ];
    }

    private function mapPushToken(array $row): array
    {
        return [
            'token' => (string) $row['token'],
            'platform' => $row['platform'] !== null ? (string) $row['platform'] : null,
            'deviceId' => $row['device_id'] !== null ? (string) $row['device_id'] : null,
            'lastSeenAt' => !empty($row['last_seen_at'])
                ? date('c', strtotime((string) $row['last_seen_at']) ?: time())
                : null,
        ];
    }

    private function suggestion(
        string $id,
        string $kind,
        string $title,
        string $body,
        ?string $deepLink
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'createdAt' => date('c'),
            'kind' => $kind,
            'deepLink' => $deepLink,
        ];
    }

    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('اعلان‌ها فعلاً غیرفعال هستند.');
        }
    }

    private function isEnabled(): bool
    {
        $cfg = $this->appConfig['notifications'] ?? [];
        if (isset($cfg['enabled']) && empty($cfg['enabled'])) {
            return false;
        }
        $features = $this->remoteConfig->getPublicConfig()['features'] ?? [];
        if (isset($features['notifications_enabled']) && empty($features['notifications_enabled'])) {
            return false;
        }
        return true;
    }
}
