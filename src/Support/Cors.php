<?php
declare(strict_types=1);

namespace App\Support;

final class Cors
{
    public static function apply(array $config): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $allowed = $config['cors']['allowed_origins'] ?? ['*'];

        if (in_array('*', $allowed, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With, X-Admin-Key');
        header('Access-Control-Max-Age: 86400');

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
