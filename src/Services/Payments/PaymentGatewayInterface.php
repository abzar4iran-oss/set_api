<?php
declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

interface PaymentGatewayInterface
{
    public function name(): string;

    /**
     * @return array{authority: string, payment_url: string, raw?: array}
     */
    public function requestPayment(array $order, string $callbackUrl): array;

    /**
     * @return array{ok: bool, ref_id?: string, message?: string, raw?: array}
     */
    public function verifyPayment(array $order, string $authority): array;
}
