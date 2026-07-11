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

use App\Repositories\DailyChallengeRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\UserRepository;
use App\Services\DailyChallengeService;
use App\Services\ProgressService;
use App\Services\RemoteConfigService;
use App\Support\Database;

$pdo = Database::connection($config);
$users = new UserRepository($pdo);
$phone = '09991112233';
$user = $users->findByPhone($phone);
if (!$user) {
    fwrite(STDERR, "user missing — run test_progress.php first\n");
    exit(1);
}
$uid = (int) $user['id'];

$pdo->exec('DELETE FROM daily_challenge_attempts WHERE user_id = ' . $uid);
// clear daily fields for today
$progressRepo = new ProgressRepository($pdo);
$remote = new RemoteConfigService($config);
$progress = new ProgressService($progressRepo, $remote);
$daily = new DailyChallengeService(new DailyChallengeRepository($pdo), $progress, $remote);

$day = $progress->todayDayKey();
echo "day={$day}\n";

// reset progress daily fields via action reset then import-ish: use SQL
$row = $progressRepo->findByUserId($uid);
if ($row) {
    $progressRepo->save($uid, [
        'revision' => (int) $row['revision'] + 1,
        'coins' => (int) $row['coins'],
        'hearts' => (int) $row['hearts'],
        'compete_points' => (int) $row['compete_points'],
        'is_premium' => (int) $row['is_premium'],
        'last_heart_regen_at' => $row['last_heart_regen_at'],
        'current_lesson' => (int) $row['current_lesson'],
        'pending_surah_after_lesson' => $row['pending_surah_after_lesson'],
        'takeoff_platform_completed' => (int) $row['takeoff_platform_completed'],
        'matches_played' => (int) $row['matches_played'],
        'daily_challenge_locked_until' => null,
        'daily_challenge_won_day_key' => null,
        'daily_challenge_played_day_key' => null,
        'daily_challenge_played_question_ids' => '[]',
    ]);
}

$status = $daily->status($uid);
echo 'can_play=' . ($status['can_play'] ? '1' : '0') . PHP_EOL;

$start = $daily->start($uid, 10);
$token = $start['attempt']['attemptToken'];
echo 'start token=' . substr($token, 0, 8) . '... seed=' . $start['attempt']['pickSeed'] . PHP_EOL;

$commit = $daily->commit($uid, [
    'attempt_token' => $token,
    'question_id' => 'test-q-1',
    'method_id' => 'fill_blank',
    'lesson_id' => 5,
    'correct_answer' => 'سلام',
    'correct_option_ids' => ['a1'],
    'time_limit_seconds' => 25,
    'is_voice' => false,
]);
echo 'committed status=' . $commit['attempt']['status'] . PHP_EOL;

$submit = $daily->submit($uid, [
    'attempt_token' => $token,
    'client_correct' => true,
    'timed_out' => false,
]);
echo 'submit correct=' . ($submit['is_correct'] ? '1' : '0')
    . ' coins=' . $submit['coins_awarded']
    . ' progressCoins=' . $submit['progress']['coins']
    . PHP_EOL;

$status2 = $daily->status($uid);
echo 'after can_play=' . ($status2['can_play'] ? '1' : '0')
    . ' won=' . ($status2['won_today'] ? '1' : '0')
    . PHP_EOL;

echo "OK\n";
