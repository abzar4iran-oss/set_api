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

use App\Repositories\CompeteMatchRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\UserRepository;
use App\Services\CompeteMatchService;
use App\Services\ProgressService;
use App\Services\RemoteConfigService;
use App\Support\Database;

$pdo = Database::connection($config);
$users = new UserRepository($pdo);
$phone = '09991112233';
$user = $users->findByPhone($phone);
if (!$user) {
    fwrite(STDERR, "user missing\n");
    exit(1);
}
$uid = (int) $user['id'];

$pdo->exec('DELETE FROM compete_matches WHERE user_id = ' . $uid);

$remote = new RemoteConfigService($config);
$progress = new ProgressService(new ProgressRepository($pdo), $remote);
$svc = new CompeteMatchService(new CompeteMatchRepository($pdo), $progress, $remote, null, $users);

// ensure coins
$snap = $progress->getMappedProgress($uid);
if (($snap['coins'] ?? 0) < 50) {
    $progress->action($uid, 'add_coins', ['amount' => 100, 'reason' => 'test']);
}

$start = $svc->start($uid, 10);
$token = $start['match']['matchToken'];
echo 'start fee=' . $start['match']['entryFee'] . ' seed=' . $start['match']['seed'] . ' coins=' . $start['progress']['coins'] . PHP_EOL;

$rounds = [];
for ($i = 0; $i < 5; $i++) {
    $rounds[] = [
        'round_index' => $i,
        'question_id' => 'q-' . $i,
        'method_id' => 'fill_blank',
        'lesson_id' => 3,
        'correct_answer' => 'ans' . $i,
        'correct_option_ids' => ['o' . $i],
    ];
}
$svc->commit($uid, ['match_token' => $token, 'rounds' => $rounds]);
echo "committed\n";

$results = [];
for ($i = 0; $i < 5; $i++) {
    $results[] = [
        'round_index' => $i,
        'player_correct' => true,
        'player_time_ms' => 3000 + $i * 100,
        'opponent_correct' => $i % 2 === 0,
        'opponent_time_ms' => 5000,
    ];
}

$finish = $svc->finish($uid, ['match_token' => $token, 'rounds' => $results]);
echo 'finish outcome=' . $finish['outcome']
    . ' player=' . $finish['player_score']
    . ' opp=' . $finish['opponent_score']
    . ' coinsAwarded=' . $finish['coins_awarded']
    . ' points=' . $finish['points_awarded']
    . PHP_EOL;

$board = $svc->leaderboard($uid, 5);
echo 'leaderboard entries=' . count($board['entries']) . PHP_EOL;

echo "OK\n";
