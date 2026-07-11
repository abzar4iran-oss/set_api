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

use App\Repositories\ProgressRepository;
use App\Repositories\UserRepository;
use App\Services\ProgressService;
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
        'last_name' => 'پیشرفت',
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
$pdo->exec('DELETE FROM progress_events WHERE user_id = ' . $uid);
$pdo->exec('DELETE FROM user_progress WHERE user_id = ' . $uid);

$svc = new ProgressService(new ProgressRepository($pdo), new RemoteConfigService($config));

$g = $svc->get($uid);
echo 'fresh is_new=' . ($g['is_new'] ? '1' : '0') . ' coins=' . $g['progress']['coins'] . PHP_EOL;

$imp = $svc->import($uid, [
    'coins' => 250,
    'hearts' => 3,
    'currentLesson' => 1,
    'takeoffPlatformCompleted' => true,
    'competePoints' => 5,
]);
echo 'import coins=' . $imp['progress']['coins'] . ' rev=' . $imp['progress']['revision'] . PHP_EOL;

$a = $svc->action($uid, 'complete_lesson', ['lesson_id' => 1]);
echo 'complete next=' . $a['progress']['currentLesson']
    . ' coins=' . $a['progress']['coins']
    . ' pending=' . json_encode($a['progress']['pendingSurahAfterLesson'])
    . ' meta=' . json_encode($a['meta'], JSON_UNESCAPED_UNICODE)
    . PHP_EOL;

$w = $svc->action($uid, 'wrong_answer');
echo 'wrong hearts=' . $w['progress']['hearts'] . ' coins=' . $w['progress']['coins'] . PHP_EOL;

$c = $svc->action($uid, 'correct_answer');
echo 'correct points=' . $c['progress']['competePoints'] . PHP_EOL;

$r = $svc->action($uid, 'refill_hearts');
echo 'refill hearts=' . $r['progress']['hearts'] . ' coins=' . $r['progress']['coins'] . PHP_EOL;

echo "OK\n";
