<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;
use Throwable;

final class ExtensionRuntime
{
    private static ?RouteCollection $routes = null;
    private static ?HookCollection $hooks = null;

    public static function dispatch(string $method, string $path): bool
    {
        return self::routes()->dispatch($method, $path);
    }

    public static function validateModule(ExtensionManifest $manifest): int
    {
        $routes = new RouteCollection();
        $hooks = new HookCollection();
        self::register($manifest, $routes, $hooks);
        return $routes->count() + $hooks->count();
    }

    /** @return list<array{module:string,ok:bool,error:?string}> */
    public static function emit(string $event, array $payload = []): array
    {
        self::boot();
        return self::$hooks?->emit($event, $payload) ?? [];
    }

    public static function reset(): void
    {
        self::$routes = null;
        self::$hooks = null;
    }

    private static function routes(): RouteCollection
    {
        self::boot();
        return self::$routes ?? new RouteCollection();
    }

    private static function boot(): void
    {
        if (self::$routes instanceof RouteCollection && self::$hooks instanceof HookCollection) return;
        $routes = new RouteCollection();
        $hooks = new HookCollection();
        foreach (ExtensionManager::activeModules() as $row) {
            try {
                $manifest=ExtensionManager::manifestForRow($row);
                $errors=ExtensionManager::compatibilityErrors($manifest);
                if($errors!==[])throw new RuntimeException(implode('؛ ',$errors));
                self::register($manifest, $routes, $hooks);
            } catch (Throwable $error) {
                ExtensionManager::markBroken((string) $row['extension_key'], $error->getMessage());
                error_log('[VazinCMS extension=' . $row['extension_key'] . '] ' . $error->getMessage());
            }
        }
        self::$routes = $routes;
        self::$hooks = $hooks;
    }

    private static function register(ExtensionManifest $manifest, RouteCollection $routes, HookCollection $hooks): void
    {
        if ($manifest->type() !== 'module') {
            throw new RuntimeException('فقط ماژول در runtime اجرا می‌شود.');
        }
        $relative = (string) ($manifest->data()['bootstrap'] ?? '');
        if ($relative === '') return;
        $file = $manifest->resolve($relative);
        ob_start();
        try {
            $registrar = require $file;
            $unexpected = (string) ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        if (trim($unexpected) !== '') {
            throw new RuntimeException('bootstrap ماژول نباید هنگام ثبت خروجی تولید کند.');
        }
        if (!is_callable($registrar)) {
            throw new RuntimeException('bootstrap ماژول باید callable برگرداند.');
        }
        $registrar(new ModuleContext($routes, $hooks, $manifest));
    }
}
