<?php
declare(strict_types=1);

namespace App\Support;

final class Phone
{
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0' . $digits;
        }

        return $digits;
    }

    public static function isValidIranMobile(string $raw): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::normalize($raw));
    }
}
