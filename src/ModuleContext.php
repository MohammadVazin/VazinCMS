<?php
declare(strict_types=1);

namespace VazinCMS;

final class ModuleContext
{
    public function __construct(
        private RouteCollection $routes,
        private HookCollection $hooks,
        private ExtensionManifest $manifest
    )
    {
    }

    public function key(): string { return $this->manifest->key(); }
    public function version(): string { return $this->manifest->version(); }
    public function directory(): string { return $this->manifest->directory(); }
    public function assetUrl(string $relative): string { return ExtensionAsset::url('module',$this->key(),$relative); }
    public function get(string $path, callable $handler): void { $this->routes->get($path, $handler); }
    public function post(string $path, callable $handler): void { $this->routes->post($path, $handler); }
    public function any(string $path, callable $handler): void { $this->routes->any($path, $handler); }
    public function on(string $event, callable $handler): void { $this->hooks->on($event, $this->key(), $handler); }
    public function regex(array $methods, string $pattern, callable $handler): void
    {
        $this->routes->regex($methods, $pattern, $handler);
    }
}
