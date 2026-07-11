<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RemoteConfigService
{
    public function __construct(private array $appConfig)
    {
    }

    public function getPublicConfig(): array
    {
        return $this->read();
    }

    public function replace(array $incoming): array
    {
        $current = $this->read();
        $merged = $this->deepMerge($current, $incoming);

        $merged['version'] = (int) ($current['version'] ?? 1) + 1;
        $merged['updated_at'] = date('c');

        $this->assertShape($merged);
        $this->write($merged);

        return $merged;
    }

    public function resetToDefaults(): array
    {
        $defaultsPath = $this->defaultsPath();
        if (!is_file($defaultsPath)) {
            throw new RuntimeException('فایل پیش‌فرض Remote Config یافت نشد.');
        }

        $raw = file_get_contents($defaultsPath);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('پیش‌فرض Remote Config نامعتبر است.');
        }

        $data['version'] = 1;
        $data['updated_at'] = date('c');
        $this->write($data);
        return $data;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->activePath();
        if (!is_file($path)) {
            $defaults = $this->defaultsPath();
            if (!is_file($defaults)) {
                throw new RuntimeException('Remote Config موجود نیست.');
            }
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            copy($defaults, $path);
        }

        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Remote Config خراب است.');
        }

        return $data;
    }

    private function write(array $data): void
    {
        $path = $this->activePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('ذخیره Remote Config ناموفق بود.');
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false) {
            throw new RuntimeException('نوشتن Remote Config ناموفق بود.');
        }
        rename($tmp, $path);
    }

    private function activePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/remote_config.json';
    }

    private function defaultsPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/remote_config.defaults.json';
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $over */
    private function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && $this->isAssoc($value)) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function assertShape(array $data): void
    {
        foreach (['features', 'app', 'economy', 'daily_challenge', 'compete', 'surah_listen', 'shop'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                throw new RuntimeException("بخش «{$key}» در Remote Config الزامی است.");
            }
        }
    }
}
