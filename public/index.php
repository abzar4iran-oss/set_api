<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CompeteMatchController;
use App\Controllers\DailyChallengeController;
use App\Controllers\NotificationController;
use App\Controllers\ProfileController;
use App\Controllers\ProgressController;
use App\Controllers\RemoteConfigController;
use App\Controllers\ShopController;
use App\Controllers\SupportController;
use App\Repositories\CompeteMatchRepository;
use App\Repositories\DailyChallengeRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OtpRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\PushTokenRepository;
use App\Repositories\ShopOrderRepository;
use App\Repositories\SupportTicketRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CompeteMatchService;
use App\Services\DailyChallengeService;
use App\Services\ExpoPushService;
use App\Services\NotificationService;
use App\Services\ProfileService;
use App\Services\ProgressService;
use App\Services\RemoteConfigService;
use App\Services\ShopService;
use App\Services\SmsService;
use App\Services\SupportService;
use App\Support\Cors;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'فایل config.php یافت نشد. از config.example.php کپی بگیرید.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @var array $config */
$config = require $configFile;

Cors::apply($config);

try {
    $pdo = Database::connection($config);
} catch (Throwable $e) {
    Response::error(
        $config['debug'] ? $e->getMessage() : 'اتصال پایگاه‌داده برقرار نشد.',
        500
    );
}

$authService = new AuthService(
    $config,
    new UserRepository($pdo),
    new OtpRepository($pdo),
    new TokenRepository($pdo),
    new SmsService($config)
);

$controller = new AuthController($authService);
$remoteConfigService = new RemoteConfigService($config);
$remoteConfigController = new RemoteConfigController(
    $remoteConfigService,
    $config
);
$progressService = new ProgressService(
    new ProgressRepository($pdo),
    $remoteConfigService,
    $config
);
$progressController = new ProgressController(
    $authService,
    $progressService
);
$pushTokenRepository = new PushTokenRepository($pdo);
$notificationService = new NotificationService(
    $config,
    new NotificationRepository($pdo),
    $pushTokenRepository,
    new ProgressRepository($pdo),
    $remoteConfigService,
    new ExpoPushService($config, $pushTokenRepository)
);
$notificationController = new NotificationController(
    $authService,
    $notificationService,
    $config
);
$dailyChallengeController = new DailyChallengeController(
    $authService,
    new DailyChallengeService(
        new DailyChallengeRepository($pdo),
        $progressService,
        $remoteConfigService,
        $notificationService
    )
);
$competeMatchController = new CompeteMatchController(
    $authService,
    new CompeteMatchService(
        new CompeteMatchRepository($pdo),
        $progressService,
        $remoteConfigService,
        $notificationService
    )
);
$shopController = new ShopController(
    $authService,
    new ShopService(
        $config,
        new ShopOrderRepository($pdo),
        $progressService,
        $remoteConfigService,
        $notificationService
    )
);
$userRepository = new UserRepository($pdo);
$profileService = new ProfileService($config, $userRepository, $remoteConfigService);
$profileController = new ProfileController($authService, $profileService);
$supportController = new SupportController(
    $authService,
    new SupportService(
        $config,
        new SupportTicketRepository($pdo),
        $remoteConfigService,
        $notificationService
    ),
    $config
);
$request = new Request();
$router = new Router();

$router->add('GET', '/', [$controller, 'health']);
$router->add('GET', '/health', [$controller, 'health']);
$router->add('POST', '/auth/otp/send', [$controller, 'sendOtp']);
$router->add('POST', '/auth/otp/verify', [$controller, 'verifyOtp']);
$router->add('POST', '/auth/register', [$controller, 'register']);
$router->add('POST', '/auth/register/skip', [$controller, 'skipRegister']);
$router->add('GET', '/auth/me', [$controller, 'me']);
$router->add('POST', '/auth/logout', [$controller, 'logout']);

$router->add('GET', '/config', [$remoteConfigController, 'get']);
$router->add('PUT', '/config', [$remoteConfigController, 'put']);
$router->add('POST', '/config', [$remoteConfigController, 'put']);
$router->add('POST', '/config/reset', [$remoteConfigController, 'reset']);

$router->add('GET', '/progress', [$progressController, 'get']);
$router->add('POST', '/progress/import', [$progressController, 'import']);
$router->add('POST', '/progress/action', [$progressController, 'action']);

$router->add('GET', '/daily-challenge', [$dailyChallengeController, 'status']);
$router->add('POST', '/daily-challenge/start', [$dailyChallengeController, 'start']);
$router->add('POST', '/daily-challenge/commit', [$dailyChallengeController, 'commit']);
$router->add('POST', '/daily-challenge/submit', [$dailyChallengeController, 'submit']);

$router->add('GET', '/matches/active', [$competeMatchController, 'active']);
$router->add('GET', '/matches/get', [$competeMatchController, 'get']);
$router->add('GET', '/matches/history', [$competeMatchController, 'history']);
$router->add('POST', '/matches/start', [$competeMatchController, 'start']);
$router->add('POST', '/matches/commit', [$competeMatchController, 'commit']);
$router->add('POST', '/matches/finish', [$competeMatchController, 'finish']);
$router->add('POST', '/matches/cancel', [$competeMatchController, 'cancel']);
$router->add('GET', '/compete/leaderboard', [$competeMatchController, 'leaderboard']);

$router->add('GET', '/shop/catalog', [$shopController, 'catalog']);
$router->add('POST', '/shop/orders', [$shopController, 'createOrder']);
$router->add('GET', '/shop/orders/get', [$shopController, 'getOrder']);
$router->add('GET', '/shop/orders/history', [$shopController, 'history']);
$router->add('POST', '/shop/orders/verify', [$shopController, 'verify']);
$router->add('POST', '/shop/payments/mock-complete', [$shopController, 'mockComplete']);
$router->add('GET', '/shop/payments/callback', [$shopController, 'callback']);
$router->add('GET', '/shop/payments/mock-pay', [$shopController, 'mockPayPage']);

$router->add('GET', '/notifications', [$notificationController, 'list']);
$router->add('GET', '/notifications/unread-count', [$notificationController, 'unreadCount']);
$router->add('GET', '/notifications/suggestions', [$notificationController, 'suggestions']);
$router->add('POST', '/notifications/read', [$notificationController, 'markRead']);
$router->add('POST', '/notifications/read-all', [$notificationController, 'markAllRead']);
$router->add('POST', '/notifications/dismiss', [$notificationController, 'dismiss']);
$router->add('POST', '/devices/push-token', [$notificationController, 'registerPushToken']);
$router->add('POST', '/devices/push-token/remove', [$notificationController, 'unregisterPushToken']);
$router->add('POST', '/admin/notifications/send', [$notificationController, 'adminSend']);

$router->add('GET', '/profile', [$profileController, 'get']);
$router->add('PUT', '/profile', [$profileController, 'update']);
$router->add('POST', '/profile', [$profileController, 'update']);
$router->add('PUT', '/profile/settings', [$profileController, 'updateSettings']);
$router->add('POST', '/profile/settings', [$profileController, 'updateSettings']);
$router->add('POST', '/profile/avatar', [$profileController, 'uploadAvatar']);
$router->add('POST', '/profile/avatar/remove', [$profileController, 'removeAvatar']);

$router->add('GET', '/support/contact', [$supportController, 'contact']);
$router->add('GET', '/support/tickets', [$supportController, 'list']);
$router->add('GET', '/support/tickets/unread-count', [$supportController, 'unreadCount']);
$router->add('GET', '/support/tickets/get', [$supportController, 'get']);
$router->add('POST', '/support/tickets', [$supportController, 'create']);
$router->add('POST', '/support/tickets/message', [$supportController, 'addMessage']);
$router->add('POST', '/support/tickets/close', [$supportController, 'close']);
$router->add('POST', '/support/tickets/seen', [$supportController, 'markSeen']);
$router->add('GET', '/admin/support/tickets', [$supportController, 'adminList']);
$router->add('GET', '/admin/support/tickets/get', [$supportController, 'adminGet']);
$router->add('POST', '/admin/support/reply', [$supportController, 'adminReply']);
$router->add('POST', '/admin/support/status', [$supportController, 'adminSetStatus']);

$router->dispatch($request);
