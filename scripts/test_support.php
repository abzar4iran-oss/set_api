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
use App\Services\RemoteConfigService;
use App\Services\SupportService;
use App\Support\Database;

$pdo = Database::connection($config);
$users = new UserRepository($pdo);
$phone = '09991112244';
$user = $users->findByPhone($phone);
if (!$user) {
    $user = $users->create([
        'phone' => $phone,
        'first_name' => 'تست',
        'last_name' => 'پشتیبانی',
        'age' => 22,
        'school_grade' => 'یازدهم',
        'province' => 'تهران',
        'city' => 'تهران',
        'quran_reading_level' => 'متوسط',
        'has_previous_class_experience' => false,
        'previous_class_details' => '',
        'profile_complete' => true,
    ]);
}
$uid = (int) $user['id'];
$pdo->exec('DELETE FROM support_ticket_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE user_id = ' . $uid . ')');
$pdo->exec('DELETE FROM support_tickets WHERE user_id = ' . $uid);

$remote = new RemoteConfigService($config);
$push = new PushTokenRepository($pdo);
$notifications = new NotificationService(
    $config,
    new NotificationRepository($pdo),
    $push,
    new ProgressRepository($pdo),
    $remote,
    new ExpoPushService($config, $push)
);
$support = new SupportService($config, new SupportTicketRepository($pdo), $remote, $notifications);

$contact = $support->contactInfo();
echo 'contact_email=' . $contact['email'] . ' channels=' . count($contact['channels']) . PHP_EOL;

$created = $support->create($uid, [
    'subject' => 'مشکل تست',
    'message' => 'این یک تیکیت آزمایشی کامل است.',
    'category' => 'ticket',
]);
$tid = (string) $created['ticket']['id'];
echo 'created=' . $tid . PHP_EOL;

$callback = $support->create($uid, [
    'subject' => 'درخواست تماس',
    'message' => 'لطفاً تماس بگیرید برای پیگیری.',
    'category' => 'callback_request',
]);
echo 'callback=' . $callback['ticket']['id'] . PHP_EOL;

$reply = $support->adminReply($tid, [
    'message' => 'سلام، مشکل را بررسی می‌کنیم.',
    'status' => 'answered',
]);
echo 'admin_reply_status=' . $reply['ticket']['status'] . PHP_EOL;

$unread = $support->unreadCount($uid);
echo 'unread_after_reply=' . $unread['unread_count'] . PHP_EOL;
if ((int) $unread['unread_count'] < 1) {
    fwrite(STDERR, "FAIL expected unread >= 1\n");
    exit(1);
}

$seen = $support->markSeen($uid, $tid);
echo 'seen_unread_flag=' . (!empty($seen['ticket']['unread']) ? '1' : '0') . PHP_EOL;

$userMsg = $support->addUserMessage($uid, $tid, ['message' => 'ممنون، منتظرم.']);
echo 'user_reopen=' . $userMsg['ticket']['status'] . PHP_EOL;

$closed = $support->closeByUser($uid, $tid);
echo 'closed=' . $closed['ticket']['status'] . PHP_EOL;

$admin = $support->adminGet($tid);
echo 'admin_user_phone=' . ($admin['ticket']['user']['phone'] ?? '') . PHP_EOL;

$list = $support->list($uid);
echo 'list_count=' . count($list['tickets']) . PHP_EOL;

echo "OK\n";
