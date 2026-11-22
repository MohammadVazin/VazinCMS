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
        if (SiteScopedRuntimePolicy::scopedMode(Database::connection())) {
            throw new RuntimeException('tenant_plugin_validation_requires_isolated_control_plane');
        }
        $routes = new RouteCollection();
        $hooks = new HookCollection();
        self::register($manifest, $routes, $hooks);
        return $routes->count() + $hooks->count();
    }

    /** @return list<array{module:string,ok:bool,error:?string,duration_us?:int}> */
    public static function emit(string $event, array $payload = []): array
    {
        self::boot();
        return self::$hooks?->emit($event, $payload) ?? [];
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function filter(string $hook, mixed $value, array $context = []): mixed
    {
        if (self::$hooks === null) {
            try {
                self::boot();
            } catch (Throwable) {
                return $value;
            }
        }
        return self::$hooks?->applyFilters($hook, $value, $context) ?? $value;
    }

    public static function ensureBooted(): void
    {
        self::boot();
    }

    public static function reset(): void
    {
        self::$routes = null;
        self::$hooks = null;
        BlockRegistry::reset();
        PaymentGatewayRegistry::reset();
        DynamicContentService::resetRuntimeTypes();
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
        try {
            $pdo = Database::connection();
            $pdo->query('SELECT 1 FROM cms_extensions LIMIT 1');
        } catch (Throwable) {
            self::$routes = $routes;
            self::$hooks = $hooks;
            return;
        }
        $scoped = SiteScopedRuntimePolicy::scopedMode($pdo);
        foreach (ExtensionManager::activeModules() as $row) {
            if (($row['source'] ?? '') !== 'bundled' ||
               ($scoped && !SiteScopedRuntimePolicy::moduleAllowed($row))) continue;
            try {
                $manifest = ExtensionManager::manifestForRow($row);
                $errors = ExtensionManager::compatibilityErrors($manifest);
                if ($errors !== []) throw new RuntimeException(implode('؛ ', $errors));
                self::register($manifest, $routes, $hooks);
            } catch (Throwable $error) {
                if (!$scoped) {
                    ExtensionManager::markBroken(
                        (string) $row['extension_key'],
                        $error->getMessage()
                    );
                }
                error_log('[VazinCMS extension=' . $row['extension_key'] . '] ' . $error->getMessage());
            }
        }
        self::$routes = $routes;
        self::$hooks = $hooks;
    }

    private static function assertBundledModule(ExtensionManifest $manifest): void
    {
        $root = realpath(dirname(__DIR__) . '/extensions/modules');
        $path = realpath($manifest->directory());
        if (!is_string($root) || !is_dir($root) || is_link($root) ||
           !is_string($path) || !is_dir($path) ||
           is_link($manifest->directory()) ||
           dirname($path) !== $root || basename($path) !== $manifest->key()) {
            throw new RuntimeException('plugin_inprocess_untrusted_package_denied');
        }
    }

    private static function register(ExtensionManifest $manifest, RouteCollection $routes, HookCollection $hooks): void
    {
        self::assertBundledModule($manifest);
        if ($manifest->type() !== 'module') {
            throw new RuntimeException('فقط ماژول در runtime اجرا می‌شود.');
        }
        $data = $manifest->data();
        if (($data['schema'] ?? 1) === 2 && !empty($data['content_types']) && is_array($data['content_types'])) {
            foreach ($data['content_types'] as $typeKey => $typeDef) {
                if (is_string($typeKey) && is_array($typeDef)) {
                    DynamicContentService::registerRuntimeType($manifest->key(), $typeKey, $typeDef);
                }
            }
        }
        $relative = (string) ($data['bootstrap'] ?? '');
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
