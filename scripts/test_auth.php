<?php
/**
 * Smoke test: OTP send (demo) + verify + me
 * php scripts/test_auth.php
 */
$base = rtrim(getenv('API_BASE') ?: 'http://localhost/api/public', '/');
$phone = getenv('TEST_PHONE') ?: '09120000000';

function request(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $opts = [
        'http' => [
            'method' => $method,
            'header' => "Accept: application/json\r\nContent-Type: application/json\r\n" .
                ($token ? "Authorization: Bearer {$token}\r\n" : ''),
            'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ];
    $raw = file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) {
        throw new RuntimeException("Request failed: {$url}");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON from {$url}: {$raw}");
    }
    return $decoded;
}

$send = request('POST', $base . '/auth/otp/send', ['phone' => $phone]);
if (empty($send['ok'])) {
    throw new RuntimeException('otp send failed: ' . ($send['message'] ?? ''));
}
$code = (string) ($send['data']['demo_code'] ?? getenv('TEST_OTP') ?: '123456');
echo "OK otp send phone={$send['data']['phone']} demo_code={$code}" . PHP_EOL;

$verify = request('POST', $base . '/auth/otp/verify', [
    'phone' => $phone,
    'code' => $code,
]);
if (empty($verify['ok'])) {
    throw new RuntimeException('otp verify failed: ' . ($verify['message'] ?? ''));
}

$token = (string) ($verify['data']['token'] ?? $verify['data']['access_token'] ?? '');
$regToken = (string) ($verify['data']['registration_token'] ?? '');
if ($token === '' && $regToken !== '') {
    $skip = request('POST', $base . '/auth/register/skip', [
        'registration_token' => $regToken,
    ]);
    if (empty($skip['ok'])) {
        throw new RuntimeException('register skip failed: ' . ($skip['message'] ?? ''));
    }
    $token = (string) ($skip['data']['token'] ?? $skip['data']['access_token'] ?? '');
}

if ($token === '') {
    throw new RuntimeException('no auth token after verify/register');
}
echo "OK auth token acquired" . PHP_EOL;

$me = request('GET', $base . '/auth/me', null, $token);
if (empty($me['ok'])) {
    throw new RuntimeException('auth/me failed: ' . ($me['message'] ?? ''));
}
echo "OK /auth/me phone=" . ($me['data']['user']['phone'] ?? $me['data']['phone'] ?? '?') . PHP_EOL;
