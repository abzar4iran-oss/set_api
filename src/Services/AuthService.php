<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\OtpRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Support\Phone;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private array $config,
        private UserRepository $users,
        private OtpRepository $otps,
        private TokenRepository $tokens,
        private SmsService $sms
    ) {
    }

    public function sendOtp(string $rawPhone): array
    {
        $phone = Phone::normalize($rawPhone);
        if (!Phone::isValidIranMobile($phone)) {
            throw new RuntimeException('شماره موبایل معتبر نیست. مثال: ۰۹۱۲۳۴۵۶۷۸۹');
        }

        $cooldown = (int) ($this->config['otp']['resend_cooldown_seconds'] ?? 60);
        $last = $this->otps->lastCreatedAt($phone);
        if ($last) {
            $elapsed = time() - strtotime($last);
            if ($elapsed < $cooldown) {
                $wait = $cooldown - $elapsed;
                throw new RuntimeException("لطفاً {$wait} ثانیه صبر کنید و دوباره تلاش کنید.");
            }
        }

        $length = (int) ($this->config['otp']['length'] ?? 6);
        $demoMode = (bool) ($this->config['otp']['demo_mode'] ?? false);
        $fixed = $this->config['otp']['demo_code'] ?? null;

        if ($demoMode && is_string($fixed) && $fixed !== '') {
            $code = preg_replace('/\D+/', '', $fixed) ?: '123456';
        } else {
            $max = (10 ** $length) - 1;
            $code = str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
        }

        $ttl = (int) ($this->config['otp']['ttl_seconds'] ?? 300);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $this->otps->create($phone, password_hash($code, PASSWORD_DEFAULT), $expiresAt);

        $smsFailed = false;
        $provider = (string) ($this->config['sms']['provider'] ?? 'log');
        $requireRealSms = !$demoMode && $provider === 'kavenegar';

        try {
            $this->sms->sendOtp($phone, $code);
        } catch (\Throwable $e) {
            // وقتی کاوه‌نگار فعال است، خطا را پنهان نکن تا مشکل قالب/کلید مشخص شود.
            if ($requireRealSms) {
                throw $e;
            }

            $isLocal = ($this->config['env'] ?? '') === 'local';
            if ($demoMode || $isLocal) {
                $smsFailed = true;
            } else {
                throw $e;
            }
        }

        $payload = [
            'phone' => $phone,
            'expires_in' => $ttl,
            'resend_after' => $cooldown,
        ];

        if ($demoMode || $smsFailed) {
            $payload['demo_code'] = $code;
            if ($smsFailed) {
                $payload['sms_fallback'] = true;
            }
        }

        return $payload;
    }

    public function verifyOtp(string $rawPhone, string $rawCode): array
    {
        $phone = Phone::normalize($rawPhone);
        $code = preg_replace('/\D+/', '', $rawCode) ?? '';

        if (!Phone::isValidIranMobile($phone)) {
            throw new RuntimeException('شماره موبایل معتبر نیست.');
        }
        if ($code === '') {
            throw new RuntimeException('کد تأیید را وارد کنید.');
        }

        $otp = $this->otps->latestActive($phone);
        if (!$otp) {
            throw new RuntimeException('ابتدا درخواست ارسال کد را بزنید یا کد منقضی شده است.');
        }

        $maxAttempts = (int) ($this->config['otp']['max_attempts'] ?? 5);
        if ((int) $otp['attempts'] >= $maxAttempts) {
            throw new RuntimeException('تعداد تلاش‌ها بیش از حد مجاز است. دوباره کد بخواهید.');
        }

        if (!password_verify($code, $otp['code_hash'])) {
            $this->otps->incrementAttempts((int) $otp['id']);
            throw new RuntimeException('کد واردشده درست نیست.');
        }

        $this->otps->consume((int) $otp['id']);

        $user = $this->users->findByPhone($phone);
        if ($user && $this->isProfileComplete($user)) {
            $token = $this->issueAuthToken((int) $user['id']);
            return [
                'status' => 'authenticated',
                'token' => $token,
                'profile' => $this->mapProfile($user),
            ];
        }

        // کاربر جدید یا پروفایل ناقص/مهمان قبلی → باید فرم اطلاعات را پر کند
        $registrationToken = $this->issueRegistrationToken($phone);
        return [
            'status' => 'needs_register',
            'registration_token' => $registrationToken,
            'phone' => $phone,
        ];
    }

    public function register(string $registrationToken, array $form): array
    {
        $row = $this->requireRegistrationToken($registrationToken);
        $phone = $row['phone'];

        $this->assertRegistrationForm($form);

        $payload = [
            'first_name' => trim((string) $form['first_name']),
            'last_name' => trim((string) $form['last_name']),
            'age' => (int) $form['age'],
            'school_grade' => trim((string) $form['school_grade']),
            'province' => trim((string) $form['province']),
            'city' => trim((string) $form['city']),
            'quran_reading_level' => trim((string) ($form['quran_reading_level'] ?? '')),
            'has_previous_class_experience' => !empty($form['has_previous_class_experience']),
            'previous_class_details' => trim((string) ($form['previous_class_details'] ?? '')),
            'profile_complete' => true,
        ];

        $existing = $this->users->findByPhone($phone);
        if ($existing) {
            $user = $this->users->updateProfile((int) $existing['id'], $payload);
            $this->tokens->consumeRegistrationToken((int) $row['id']);
            $token = $this->issueAuthToken((int) $user['id']);
            return [
                'token' => $token,
                'profile' => $this->mapProfile($user),
            ];
        }

        $user = $this->users->create(array_merge($payload, [
            'phone' => $phone,
        ]));

        $this->tokens->consumeRegistrationToken((int) $row['id']);
        $token = $this->issueAuthToken((int) $user['id']);

        return [
            'token' => $token,
            'profile' => $this->mapProfile($user),
        ];
    }

    public function skipRegistration(string $registrationToken): array
    {
        throw new RuntimeException('ورود بدون تکمیل اطلاعات مجاز نیست. لطفاً فرم ثبت‌نام را پر کنید.');
    }

    public function me(?string $bearerToken): array
    {
        $user = $this->userFromBearer($bearerToken);
        return [
            'token' => $bearerToken,
            'profile' => $this->mapProfile($user),
        ];
    }

    public function logout(?string $bearerToken): void
    {
        if (!$bearerToken) {
            return;
        }
        $this->tokens->revokeAuthToken(hash('sha256', $bearerToken));
    }

    /** کاربر فعلی از Bearer — برای کنترلرهای دیگر */
    public function requireUser(?string $bearerToken): array
    {
        return $this->userFromBearer($bearerToken);
    }

    private function requireRegistrationToken(string $plain): array
    {
        if ($plain === '') {
            throw new RuntimeException('توکن ثبت‌نام نامعتبر است. دوباره شماره را تأیید کنید.');
        }

        $row = $this->tokens->findValidRegistrationToken(hash('sha256', $plain));
        if (!$row) {
            throw new RuntimeException('توکن ثبت‌نام منقضی یا نامعتبر است. دوباره شماره را تأیید کنید.');
        }

        return $row;
    }

    private function userFromBearer(?string $bearerToken): array
    {
        if (!$bearerToken) {
            throw new RuntimeException('احراز هویت لازم است.');
        }

        $row = $this->tokens->findValidAuthToken(hash('sha256', $bearerToken));
        if (!$row) {
            throw new RuntimeException('نشست منقضی شده. دوباره وارد شوید.');
        }

        $this->tokens->touchAuthToken((int) $row['id']);
        $user = $this->users->findById((int) $row['user_id']);
        if (!$user) {
            throw new RuntimeException('کاربر یافت نشد.');
        }

        return $user;
    }

    private function issueAuthToken(int $userId): string
    {
        $plain = bin2hex(random_bytes(32));
        $days = (int) ($this->config['auth']['token_ttl_days'] ?? 60);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));
        $this->tokens->createAuthToken($userId, hash('sha256', $plain), $expiresAt);
        return $plain;
    }

    private function issueRegistrationToken(string $phone): string
    {
        $plain = bin2hex(random_bytes(24));
        $minutes = (int) ($this->config['auth']['registration_token_ttl_minutes'] ?? 30);
        $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));
        $this->tokens->createRegistrationToken($phone, hash('sha256', $plain), $expiresAt);
        return $plain;
    }

    private function assertRegistrationForm(array $form): void
    {
        if (trim((string) ($form['first_name'] ?? '')) === '') {
            throw new RuntimeException('نام را وارد کنید.');
        }
        if (trim((string) ($form['last_name'] ?? '')) === '') {
            throw new RuntimeException('نام خانوادگی را وارد کنید.');
        }

        $age = (int) ($form['age'] ?? 0);
        if ($age < 5 || $age > 100) {
            throw new RuntimeException('سن را بین ۵ تا ۱۰۰ سال وارد کنید.');
        }

        foreach (['school_grade', 'province', 'city', 'quran_reading_level'] as $field) {
            if (trim((string) ($form[$field] ?? '')) === '') {
                throw new RuntimeException('لطفاً همه فیلدهای ضروری را تکمیل کنید.');
            }
        }

        if (!empty($form['has_previous_class_experience'])
            && trim((string) ($form['previous_class_details'] ?? '')) === '') {
            throw new RuntimeException('لطفاً توضیح کوتاهی دربارهٔ شرکت قبلی در کلاس‌ها بنویسید.');
        }
    }

    private function isProfileComplete(array $user): bool
    {
        if (empty($user['profile_complete'])) {
            return false;
        }

        $first = trim((string) ($user['first_name'] ?? ''));
        $last = trim((string) ($user['last_name'] ?? ''));
        $grade = trim((string) ($user['school_grade'] ?? ''));
        $province = trim((string) ($user['province'] ?? ''));
        $city = trim((string) ($user['city'] ?? ''));
        $reading = trim((string) ($user['quran_reading_level'] ?? ''));
        $age = (int) ($user['age'] ?? 0);

        if ($first === '' || $last === '') {
            return false;
        }

        // حساب‌های مهمان قدیمی که با skip ساخته شده بودند
        if ($first === 'کاربر' && $last === 'مهمان') {
            return false;
        }

        if ($grade === '' || $grade === 'ثبت‌نشده') {
            return false;
        }
        if ($province === '' || $province === 'ثبت‌نشده') {
            return false;
        }
        if ($city === '' || $city === 'ثبت‌نشده') {
            return false;
        }
        if ($reading === '' || $reading === 'ثبت‌نشده') {
            return false;
        }
        if ($age < 5 || $age > 100) {
            return false;
        }

        return true;
    }

    private function mapProfile(array $user): array
    {
        $created = $user['created_at'] ?? date('c');
        $registeredAt = date('c', strtotime((string) $created) ?: time());

        return [
            'id' => (int) $user['id'],
            'firstName' => (string) $user['first_name'],
            'lastName' => (string) $user['last_name'],
            'age' => (int) $user['age'],
            'schoolGrade' => (string) $user['school_grade'],
            'province' => (string) $user['province'],
            'city' => (string) $user['city'],
            'quranReadingLevel' => (string) $user['quran_reading_level'],
            'hasPreviousClassExperience' => (bool) $user['has_previous_class_experience'],
            'previousClassDetails' => (string) ($user['previous_class_details'] ?? ''),
            'phone' => (string) $user['phone'],
            'registeredAt' => $registeredAt,
            'profileComplete' => $this->isProfileComplete($user),
            'avatarUrl' => !empty($user['avatar_url']) ? (string) $user['avatar_url'] : null,
        ];
    }
}
