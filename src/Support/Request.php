<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    private array $json;
    private array $query;
    private array $server;

    public function __construct()
    {
        $this->server = $_SERVER;
        $this->query = $_GET;

        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $this->json = is_array($decoded) ? $decoded : [];

        if (!$this->json && !empty($_POST)) {
            $this->json = $_POST;
        }
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Strip common base folders when not using a virtual host
        $path = preg_replace('#^/(api/)?public#', '', $path) ?? $path;
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function json(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function allJson(): array
    {
        return $this->json;
    }

    public function bearerToken(): ?string
    {
        $header = $this->server['HTTP_AUTHORIZATION']
            ?? $this->server['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }

        $alt = $this->json('token') ?? ($this->query['token'] ?? null);
        return is_string($alt) && $alt !== '' ? $alt : null;
    }
}
