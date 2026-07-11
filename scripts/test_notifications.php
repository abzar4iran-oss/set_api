<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use App\Repositories\NotificationRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\PushTokenRepository;
use App\Repositories\UserRepository;
use App\Services\ExpoPushService;
use App\Services\NotificationService;
use App\Services\RemoteConfigService;
use App\Support\Database;

$pdo = Database::connection($config);
$users = new UserRepository($pdo);
$phone = '09991112233';
$user = $users->findByPhone($phone);
if (!$user) {
    $user = $users->create([
        'phone' => $phone,
        'first_name' => 'تست',
        'last_name' => 'اعلان',
        'age' => 20,
        'school_grade' => 'دهم',
        'province' => 'تهران',
        'city' => 'تهران',
        'quran_reading_level' => 'مبتدی',
        'has_previous_class_experience' => false,
        'previous_class_details' => '',
        'profile_complete' => true,
    ]);
}

$uid = (int) $user['id'];
$pdo->exec('DELETE FROM user_notifications WHERE user_id = ' . $uid);
$pdo->exec('DELETE FROM user_push_tokens WHERE user_id = ' . $uid);

$pushRepo = new PushTokenRepository($pdo);
$svc = new NotificationService(
    $config,
    new NotificationRepository($pdo),
    $pushRepo,
    new ProgressRepository($pdo),
    new RemoteConfigService($config),
    new ExpoPushService($config, $pushRepo)
);

$list = $svc->list($uid);
$count = count($list['notifications'] ?? []);
$unread = (int) ($list['unread_count'] ?? 0);
echo "seeded_count={$count} unread={$unread}\n";
if ($count < 7) {
    fwrite(STDERR, "FAIL expected catalog seed >= 7\n");
    exit(1);
}

$read = $svc->markRead($uid, 'welcome');
echo 'read_welcome=' . (!empty($read['notification']['read']) ? '1' : '0')
    . ' unread=' . $read['unread_count'] . PHP_EOL;

$svc->notifyShopFulfilled($uid, 'tok_test_1', 500, 0);
$svc->notifyDailyChallenge($uid, date('Y-m-d'), true);
$svc->notifyMatchFinished($uid, 'match_tok_1', true, 3);

$list2 = $svc->list($uid);
$ids = array_column($list2['notifications'], 'id');
foreach (['shop-coins-tok_test_1', 'daily-' . date('Y-m-d') . '-won', 'match-match_tok_1'] as $need) {
    if (!in_array($need, $ids, true)) {
        fwrite(STDERR, "FAIL missing event notification {$need}\n");
        exit(1);
    }
}
echo "events_ok=1\n";

$all = $svc->markAllRead($uid);
echo 'mark_all updated=' . $all['updated'] . ' unread=' . $all['unread_count'] . PHP_EOL;
if ((int) $all['unread_count'] !== 0) {
    fwrite(STDERR, "FAIL unread after mark_all\n");
    exit(1);
}

$push = $svc->registerPushToken($uid, [
    'token' => 'ExponentPushToken[test-token-local]',
    'platform' => 'android',
]);
echo 'push_registered=' . (!empty($push['registered']) ? '1' : '0') . PHP_EOL;

$removed = $svc->unregisterPushToken($uid, ['token' => 'ExponentPushToken[test-token-local]']);
echo 'push_removed=' . ($removed['removed'] ?? 0) . PHP_EOL;

$suggestions = $svc->suggestions($uid);
echo 'suggestions=' . count($suggestions['suggestions'] ?? []) . PHP_EOL;

$admin = $svc->adminSend([
    'title' => 'اعلان آزمایشی ادمین',
    'body' => 'این پیام از پنل ادمین ارسال شد.',
    'kind' => 'lesson',
    'user_id' => $uid,
    'deep_link' => '/learn',
]);
echo 'admin_created=' . ($admin['created'] ?? 0) . PHP_EOL;

$dismiss = $svc->dismiss($uid, (string) ($admin['notification_ids'][0] ?? ''));
echo 'dismissed=' . (!empty($dismiss['notification']['dismissed']) ? '1' : '0') . PHP_EOL;

echo "OK\n";
