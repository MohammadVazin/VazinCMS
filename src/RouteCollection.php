<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;

final class RouteCollection
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->exact(['GET', 'HEAD'], $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->exact(['POST'], $path, $handler);
    }

    public function any(string $path, callable $handler): void
    {
        $this->exact(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
    }

    public function exact(array $methods, string $path, callable $handler): void
    {
        if (preg_match('#^/[A-Za-z0-9_./-]*$#', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('مسیر دقیق ماژول معتبر نیست.');
        }
        $this->routes[] = [$this->methods($methods), null, $path, $handler];
    }

    public function regex(array $methods, string $pattern, callable $handler): void
    {
        if (strlen($pattern) > 500 || !str_starts_with($pattern, '#^/') || !str_ends_with($pattern, '$#')) {
            throw new InvalidArgumentException('الگوی مسیر ماژول باید محدود و کامل باشد.');
        }
        set_error_handler(static fn(): bool => true);
        try { $valid = preg_match($pattern, '/'); } finally { restore_error_handler(); }
        if ($valid === false) {
            throw new InvalidArgumentException('الگوی مسیر ماژول معتبر نیست.');
        }
        $this->routes[] = [$this->methods($methods), $pattern, null, $handler];
    }

    public function dispatch(string $method, string $path): bool
    {
        $method = strtoupper($method === 'HEAD' ? 'GET' : $method);
        foreach ($this->routes as [$methods, $pattern, $exact, $handler]) {
            if (!in_array($method, $methods, true)) continue;
            $matches = [];
            if ($exact !== null) {
                if ($path !== $exact) continue;
            } elseif (preg_match($pattern, $path, $matches) !== 1) {
                continue;
            }
            $handler($matches);
            return true;
        }
        return false;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    private function methods(array $methods): array
    {
        $allowed = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $normalized = array_values(array_unique(array_map('strtoupper', $methods)));
        if ($normalized === [] || array_diff($normalized, $allowed) !== []) {
            throw new InvalidArgumentException('روش HTTP ماژول معتبر نیست.');
        }
        return $normalized;
    }
}
