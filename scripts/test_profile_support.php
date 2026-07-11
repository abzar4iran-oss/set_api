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
use App\Repositories\SupportTicketRepository;
use App\Repositories\UserRepository;
use App\Services\ExpoPushService;
use App\Services\NotificationService;
use App\Services\ProfileService;
use App\Services\RemoteConfigService;
use App\Services\SupportService;
use App\Support\Database;

$pdo = Database::connection($config);
$users = new UserRepository($pdo);
$phone = '09991112233';
$user = $users->findByPhone($phone);
if (!$user) {
    $user = $users->create([
        'phone' => $phone,
        'first_name' => 'تست',
        'last_name' => 'پروفایل',
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

$remote = new RemoteConfigService($config);
$profile = new ProfileService($config, $users, $remote);

$updated = $profile->update($uid, [
    'firstName' => 'علی',
    'lastName' => 'تستی',
    'age' => 18,
]);
echo 'name=' . $updated['profile']['firstName'] . ' ' . $updated['profile']['lastName'] . PHP_EOL;

$settings = $profile->updateSettings($uid, [
    'soundEffectsEnabled' => false,
    'surahListenRepeatCount' => 5,
]);
echo 'sound=' . ($settings['settings']['soundEffectsEnabled'] ? '1' : '0')
    . ' repeat=' . $settings['settings']['surahListenRepeatCount'] . PHP_EOL;

// تصویر آزمایشی (باینری ساده — سرور محتوای تصویر را اعتبارسنجی سخت نمی‌کند)
$avatar = $profile->uploadAvatar($uid, [
    'image_base64' => base64_encode('fake-jpeg-bytes-for-test'),
    'mime_type' => 'image/jpeg',
]);
echo 'avatar=' . ($avatar['avatarUrl'] ? '1' : '0') . PHP_EOL;
if (empty($avatar['avatarUrl'])) {
    fwrite(STDERR, "FAIL avatar upload\n");
    exit(1);
}

$pushRepo = new PushTokenRepository($pdo);
$notifications = new NotificationService(
    $config,
    new NotificationRepository($pdo),
    $pushRepo,
    new ProgressRepository($pdo),
    $remote,
    new ExpoPushService($config, $pushRepo)
);
$support = new SupportService($config, new SupportTicketRepository($pdo), $remote, $notifications);

$ticket = $support->create($uid, [
    'subject' => 'سوال تست',
    'message' => 'این یک پیام تست پشتیبانی است.',
    'category' => 'ticket',
]);
$tid = (string) $ticket['ticket']['id'];
echo 'ticket=' . $tid . ' status=' . $ticket['ticket']['status'] . PHP_EOL;

$reply = $support->adminReply($tid, [
    'message' => 'پاسخ پشتیبانی آزمایشی',
    'status' => 'answered',
]);
echo 'reply_status=' . $reply['ticket']['status']
    . ' msgs=' . count($reply['ticket']['messages']) . PHP_EOL;

$userMsg = $support->addUserMessage($uid, $tid, ['message' => 'ممنون از پاسخ']);
echo 'reopened=' . $userMsg['ticket']['status'] . PHP_EOL;

$list = $support->list($uid);
echo 'list_count=' . count($list['tickets']) . PHP_EOL;

echo "OK\n";
