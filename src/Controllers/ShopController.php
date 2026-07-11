<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ShopService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class ShopController
{
    public function __construct(
        private AuthService $auth,
        private ShopService $shop
    ) {
    }

    public function catalog(Request $request): void
    {
        $this->handle(function () {
            Response::success($this->shop->catalog(), 'کاتالوگ فروشگاه');
        });
    }

    public function createOrder(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $data = $this->shop->createOrder((int) $user['id'], $request->allJson());
            Response::success($data, 'سفارش ایجاد شد.', 201);
        });
    }

    public function getOrder(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $token = (string) (
                $request->json('order_token')
                ?? $request->json('orderToken')
                ?? ($_GET['token'] ?? '')
            );
            Response::success($this->shop->getOrder((int) $user['id'], $token), 'جزئیات سفارش');
        });
    }

    public function history(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $limit = (int) ($_GET['limit'] ?? 30);
            Response::success($this->shop->history((int) $user['id'], $limit), 'تاریخچه خریدها');
        });
    }

    public function verify(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $data = $this->shop->verify((int) $user['id'], $request->allJson());
            Response::success($data, $data['message'] ?? 'نتیجه پرداخت');
        });
    }

    public function mockComplete(Request $request): void
    {
        $this->handle(function () use ($request) {
            $user = $this->auth->requireUser($request->bearerToken());
            $token = (string) (
                $request->json('order_token')
                ?? $request->json('orderToken')
                ?? ''
            );
            $data = $this->shop->mockComplete((int) $user['id'], $token);
            Response::success($data, $data['message'] ?? 'پرداخت آزمایشی انجام شد.');
        });
    }

    public function callback(Request $request): void
    {
        try {
            $authority = (string) ($_GET['Authority'] ?? $_GET['authority'] ?? '');
            $status = (string) ($_GET['Status'] ?? $_GET['status'] ?? '');
            $result = $this->shop->handleCallback($authority, $status);
            $order = $result['order'] ?? [];
            $ok = !empty($result['ok']);
            $returnUrl = $this->shop->appReturnUrl($order, $ok);

            header('Location: ' . $returnUrl);
            exit;
        } catch (Throwable $e) {
            $returnUrl = $this->shop->appReturnUrl([
                'orderToken' => '',
                'status' => 'failed',
            ], false);
            header('Location: ' . $returnUrl . '&error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    public function mockPayPage(Request $request): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $authority = (string) ($_GET['authority'] ?? '');
        header('Content-Type: text/html; charset=utf-8');

        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $safeAuth = htmlspecialchars($authority, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>پرداخت آزمایشی</title>
  <style>
    body { font-family: Tahoma, sans-serif; background:#F8FAFC; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
    .card { background:#fff; border:1px solid #E2E8F0; border-radius:16px; padding:24px; max-width:420px; width:90%; text-align:center; }
    button { background:#7C3AED; color:#fff; border:0; border-radius:999px; padding:12px 20px; font-size:16px; font-weight:700; cursor:pointer; width:100%; }
    .hint { color:#64748B; font-size:14px; line-height:1.8; margin:12px 0 20px; }
  </style>
</head>
<body>
  <div class="card">
    <h2>درگاه آزمایشی آل‌نجم</h2>
    <p class="hint">این صفحه فقط برای محیط توسعه است. با تأیید، پرداخت موفق شبیه‌سازی می‌شود.</p>
    <form method="get" action="callback">
      <input type="hidden" name="Authority" value="{$safeAuth}" />
      <input type="hidden" name="Status" value="OK" />
      <input type="hidden" name="token" value="{$safeToken}" />
      <button type="submit">پرداخت موفق</button>
    </form>
    <form method="get" action="callback" style="margin-top:10px">
      <input type="hidden" name="Authority" value="{$safeAuth}" />
      <input type="hidden" name="Status" value="NOK" />
      <button type="submit" style="background:#94A3B8">انصراف</button>
    </form>
  </div>
</body>
</html>
HTML;
        exit;
    }

    private function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = 400;
            if (str_contains($message, 'احراز هویت') || str_contains($message, 'نشست منقضی')) {
                $status = 401;
            }
            Response::error($message, $status);
        } catch (Throwable $e) {
            Response::error('خطای داخلی سرور.', 500, [
                'debug' => $e->getMessage(),
            ]);
        }
    }
}
