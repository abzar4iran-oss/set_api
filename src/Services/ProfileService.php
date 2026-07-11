<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use RuntimeException;

final class ProfileService
{
    public function __construct(
        private array $appConfig,
        private UserRepository $users,
        private RemoteConfigService $remoteConfig
    ) {
    }

    public function get(int $userId): array
    {
        $user = $this->requireUser($userId);
        return [
            'profile' => $this->mapProfile($user),
            'settings' => $this->mapSettings($user),
        ];
    }

    public function update(int $userId, array $payload): array
    {
        $user = $this->requireUser($userId);

        $firstName = array_key_exists('first_name', $payload) || array_key_exists('firstName', $payload)
            ? trim((string) ($payload['first_name'] ?? $payload['firstName'] ?? ''))
            : (string) $user['first_name'];
        $lastName = array_key_exists('last_name', $payload) || array_key_exists('lastName', $payload)
            ? trim((string) ($payload['last_name'] ?? $payload['lastName'] ?? ''))
            : (string) $user['last_name'];

        if ($firstName === '') {
            throw new RuntimeException('نام را وارد کنید.');
        }
        if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
            throw new RuntimeException('نام بیش از حد طولانی است.');
        }

        $age = array_key_exists('age', $payload)
            ? (int) $payload['age']
            : (int) $user['age'];
        if ($age < 5 || $age > 100) {
            throw new RuntimeException('سن را بین ۵ تا ۱۰۰ سال وارد کنید.');
        }

        $schoolGrade = $this->pick($payload, 'school_grade', 'schoolGrade', (string) $user['school_grade']);
        $province = $this->pick($payload, 'province', 'province', (string) $user['province']);
        $city = $this->pick($payload, 'city', 'city', (string) $user['city']);
        $quran = $this->pick($payload, 'quran_reading_level', 'quranReadingLevel', (string) $user['quran_reading_level']);

        $hasPrev = array_key_exists('has_previous_class_experience', $payload)
            || array_key_exists('hasPreviousClassExperience', $payload)
            ? !empty($payload['has_previous_class_experience'] ?? $payload['hasPreviousClassExperience'])
            : !empty($user['has_previous_class_experience']);

        $prevDetails = array_key_exists('previous_class_details', $payload)
            || array_key_exists('previousClassDetails', $payload)
            ? trim((string) ($payload['previous_class_details'] ?? $payload['previousClassDetails'] ?? ''))
            : (string) ($user['previous_class_details'] ?? '');

        if ($hasPrev && $prevDetails === '') {
            throw new RuntimeException('لطفاً توضیح کوتاهی دربارهٔ شرکت قبلی در کلاس‌ها بنویسید.');
        }

        $updated = $this->users->updateProfile($userId, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'age' => $age,
            'school_grade' => $schoolGrade,
            'province' => $province,
            'city' => $city,
            'quran_reading_level' => $quran,
            'has_previous_class_experience' => $hasPrev,
            'previous_class_details' => $prevDetails,
            'profile_complete' => true,
        ]);

        return [
            'profile' => $this->mapProfile($updated),
            'settings' => $this->mapSettings($updated),
        ];
    }

    public function updateSettings(int $userId, array $payload): array
    {
        $user = $this->requireUser($userId);
        $current = $this->mapSettings($user);

        $sound = array_key_exists('sound_effects_enabled', $payload)
            || array_key_exists('soundEffectsEnabled', $payload)
            ? !empty($payload['sound_effects_enabled'] ?? $payload['soundEffectsEnabled'])
            : $current['soundEffectsEnabled'];

        $repeat = array_key_exists('surah_listen_repeat_count', $payload)
            || array_key_exists('surahListenRepeatCount', $payload)
            ? (int) ($payload['surah_listen_repeat_count'] ?? $payload['surahListenRepeatCount'])
            : $current['surahListenRepeatCount'];

        $limits = $this->surahRepeatLimits();
        $repeat = max($limits['min'], min($limits['max'], $repeat));

        $settings = [
            'sound_effects_enabled' => $sound,
            'surah_listen_repeat_count' => $repeat,
        ];

        $updated = $this->users->updateSettingsJson(
            $userId,
            json_encode($settings, JSON_UNESCAPED_UNICODE) ?: '{}'
        );

        return [
            'profile' => $this->mapProfile($updated),
            'settings' => $this->mapSettings($updated),
        ];
    }

    public function uploadAvatar(int $userId, array $payload): array
    {
        $user = $this->requireUser($userId);
        $binary = null;
        $mime = 'image/jpeg';

        if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
            $binary = file_get_contents($_FILES['avatar']['tmp_name']);
            $mime = (string) ($_FILES['avatar']['type'] ?? 'image/jpeg');
        } else {
            $b64 = (string) ($payload['image_base64'] ?? $payload['imageBase64'] ?? '');
            $mime = (string) ($payload['mime_type'] ?? $payload['mimeType'] ?? 'image/jpeg');
            if ($b64 === '') {
                throw new RuntimeException('تصویر آواتار ارسال نشده است.');
            }
            if (str_contains($b64, ',')) {
                $parts = explode(',', $b64, 2);
                if (preg_match('#data:(image/[^;]+)#', $parts[0], $m)) {
                    $mime = $m[1];
                }
                $b64 = $parts[1];
            }
            $binary = base64_decode($b64, true);
        }

        if ($binary === false || $binary === null || $binary === '') {
            throw new RuntimeException('تصویر آواتار نامعتبر است.');
        }

        $maxBytes = (int) ($this->appConfig['profile']['avatar_max_bytes'] ?? (2 * 1024 * 1024));
        if (strlen($binary) > $maxBytes) {
            throw new RuntimeException('حجم تصویر بیش از حد مجاز است.');
        }

        $ext = match (strtolower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $dir = dirname(__DIR__, 2) . '/public/uploads/avatars';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('امکان ذخیره آواتار وجود ندارد.');
        }

        $filename = 'u' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = $dir . '/' . $filename;
        if (file_put_contents($path, $binary) === false) {
            throw new RuntimeException('ذخیره آواتار ناموفق بود.');
        }

        // حذف آواتار قبلی اگر در uploads باشد
        $old = (string) ($user['avatar_url'] ?? '');
        if ($old !== '' && str_contains($old, '/uploads/avatars/')) {
            $oldName = basename(parse_url($old, PHP_URL_PATH) ?: '');
            if ($oldName !== '' && is_file($dir . '/' . $oldName)) {
                @unlink($dir . '/' . $oldName);
            }
        }

        $publicUrl = rtrim((string) ($this->appConfig['base_url'] ?? ''), '/') . '/uploads/avatars/' . $filename;
        $updated = $this->users->updateAvatarUrl($userId, $publicUrl);

        return [
            'profile' => $this->mapProfile($updated),
            'settings' => $this->mapSettings($updated),
            'avatarUrl' => $publicUrl,
        ];
    }

    public function removeAvatar(int $userId): array
    {
        $user = $this->requireUser($userId);
        $old = (string) ($user['avatar_url'] ?? '');
        if ($old !== '' && str_contains($old, '/uploads/avatars/')) {
            $dir = dirname(__DIR__, 2) . '/public/uploads/avatars';
            $oldName = basename(parse_url($old, PHP_URL_PATH) ?: '');
            if ($oldName !== '' && is_file($dir . '/' . $oldName)) {
                @unlink($dir . '/' . $oldName);
            }
        }
        $updated = $this->users->updateAvatarUrl($userId, null);
        return [
            'profile' => $this->mapProfile($updated),
            'settings' => $this->mapSettings($updated),
            'avatarUrl' => null,
        ];
    }

    public function mapProfile(array $user): array
    {
        $created = $user['created_at'] ?? date('c');
        $registeredAt = date('c', strtotime((string) $created) ?: time());
        $avatar = $user['avatar_url'] ?? null;

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
            'profileComplete' => (bool) $user['profile_complete'],
            'avatarUrl' => is_string($avatar) && $avatar !== '' ? $avatar : null,
        ];
    }

    public function mapSettings(array $user): array
    {
        $defaults = [
            'soundEffectsEnabled' => true,
            'surahListenRepeatCount' => 3,
        ];
        $raw = $user['settings_json'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $limits = $this->surahRepeatLimits();
        $repeat = (int) ($decoded['surah_listen_repeat_count']
            ?? $decoded['surahListenRepeatCount']
            ?? $defaults['surahListenRepeatCount']);
        $repeat = max($limits['min'], min($limits['max'], $repeat));

        return [
            'soundEffectsEnabled' => array_key_exists('sound_effects_enabled', $decoded)
                ? !empty($decoded['sound_effects_enabled'])
                : (array_key_exists('soundEffectsEnabled', $decoded)
                    ? !empty($decoded['soundEffectsEnabled'])
                    : true),
            'surahListenRepeatCount' => $repeat,
        ];
    }

    private function surahRepeatLimits(): array
    {
        $cfg = $this->remoteConfig->getPublicConfig()['surah_listen'] ?? [];
        return [
            'min' => max(1, (int) ($cfg['min_repeat_count'] ?? 3)),
            'max' => max(1, (int) ($cfg['max_repeat_count'] ?? 10)),
        ];
    }

    private function requireUser(int $userId): array
    {
        $user = $this->users->findById($userId);
        if (!$user) {
            throw new RuntimeException('کاربر یافت نشد.');
        }
        return $user;
    }

    private function pick(array $payload, string $snake, string $camel, string $fallback): string
    {
        if (array_key_exists($snake, $payload) || array_key_exists($camel, $payload)) {
            return trim((string) ($payload[$snake] ?? $payload[$camel] ?? ''));
        }
        return $fallback;
    }
}
