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
use App\Repositories\ShopOrderRepository;
use App\Repositories\UserRepository;
use App\Services\ProgressService;
use App\Services\RemoteConfigService;
use App\Services\ShopService;
use App\Support\Database;

$pdo = Database::connection($config);
$users = new UserRepository($pdo);
$phone = '09991112233';
$user = $users->findByPhone($phone);
if (!$user) {
    $user = $users->create([
        'phone' => $phone,
        'first_name' => 'تست',
        'last_name' => 'فروشگاه',
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
$pdo->exec('DELETE FROM shop_orders WHERE user_id = ' . $uid);
$pdo->exec('DELETE FROM progress_events WHERE user_id = ' . $uid);
$pdo->exec('DELETE FROM user_progress WHERE user_id = ' . $uid);

$remote = new RemoteConfigService($config);
$progress = new ProgressService(new ProgressRepository($pdo), $remote, $config);
$shop = new ShopService($config, new ShopOrderRepository($pdo), $progress, $remote);

$catalog = $shop->catalog();
echo 'provider=' . $catalog['provider']
    . ' packs=' . count($catalog['coin_packs'] ?? [])
    . ' vip=' . (($catalog['vip_plan']['id'] ?? '') ?: 'none')
    . ' allow_direct=' . (!empty($catalog['allow_direct_grant']) ? '1' : '0')
    . PHP_EOL;

$packId = (string) ($catalog['coin_packs'][0]['id'] ?? 'c1');
$before = $progress->get($uid);
$coinsBefore = (int) $before['progress']['coins'];
echo "coins_before={$coinsBefore}\n";

$created = $shop->createOrder($uid, [
    'product_type' => 'coin_pack',
    'product_id' => $packId,
    'client_request_id' => 'test_pack_' . time(),
]);
$token = (string) $created['order']['orderToken'];
echo 'order_token=' . $token
    . ' status=' . $created['order']['status']
    . ' amount=' . $created['order']['amountIrr']
    . PHP_EOL;

$done = $shop->mockComplete($uid, $token);
$coinsAfter = (int) ($done['progress']['coins'] ?? 0);
$grant = (int) ($created['order']['coinsGrant'] ?? 0);
echo 'fulfilled status=' . $done['order']['status']
    . " coins_after={$coinsAfter} grant={$grant}\n";

if ($coinsAfter !== $coinsBefore + $grant) {
    fwrite(STDERR, "FAIL coin grant mismatch\n");
    exit(1);
}

$vipId = (string) ($catalog['vip_plan']['id'] ?? 'vip_monthly');
$vipOrder = $shop->createOrder($uid, [
    'product_type' => 'vip',
    'product_id' => $vipId,
    'client_request_id' => 'test_vip_' . time(),
]);
$vipDone = $shop->mockComplete($uid, (string) $vipOrder['order']['orderToken']);
$premium = !empty($vipDone['progress']['isPremium']);
echo 'vip premium=' . ($premium ? '1' : '0')
    . ' expires=' . ($vipDone['progress']['premiumExpiresAt'] ?? 'null')
    . PHP_EOL;

if (!$premium) {
    fwrite(STDERR, "FAIL vip not activated\n");
    exit(1);
}

$history = $shop->history($uid, 10);
echo 'history_count=' . count($history['orders'] ?? []) . PHP_EOL;

try {
    $progress->action($uid, 'purchase_coin_pack', ['coins' => 10]);
    fwrite(STDERR, "FAIL direct grant should be blocked\n");
    exit(1);
} catch (Throwable $e) {
    echo 'direct_grant_blocked=1 msg=' . $e->getMessage() . PHP_EOL;
}

echo "OK\n";
