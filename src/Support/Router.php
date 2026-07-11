<?php
declare(strict_types=1);

namespace App\Support;

final class Router
{
    /** @var array<int, array{method:string,path:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => rtrim($path, '/') ?: '/',
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                ($route['handler'])($request);
                return;
            }
        }

        Response::error('مسیر یافت نشد.', 404);
    }
}
