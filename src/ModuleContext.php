<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use VazinCMS\Contracts\PaymentGatewayInterface;

final class ModuleContext
{
    private ?ModuleDatabase $moduleDb = null;

    public function __construct(
        private RouteCollection $routes,
        private HookCollection $hooks,
        private ExtensionManifest $manifest
    ) {
    }

    public function key(): string { return $this->manifest->key(); }
    public function version(): string { return $this->manifest->version(); }
    public function schema(): int { return $this->manifest->schema(); }
    public function directory(): string { return $this->manifest->directory(); }
    public function assetUrl(string $relative): string { return ExtensionAsset::url('module', $this->key(), $relative); }
    public function hasPermission(string $capability): bool { return CapabilityGuard::has($this->manifest, $capability); }

    public function get(string $path, callable $handler): void
    {
        $this->assertRouteCapability($path);
        $this->routes->get($path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->assertRouteCapability($path);
        $this->routes->post($path, $handler);
    }

    public function any(string $path, callable $handler): void
    {
        $this->assertRouteCapability($path);
        $this->routes->any($path, $handler);
    }

    public function regex(array $methods, string $pattern, callable $handler): void
    {
        if (str_contains($pattern, '/admin')) {
            CapabilityGuard::assert($this->manifest, 'routes:admin');
        } else {
            CapabilityGuard::assert($this->manifest, 'routes:public');
        }
        $this->routes->regex($methods, $pattern, $handler);
    }

    /**
     * Registers a JSON Headless API endpoint under `/api/v1/modules/<module-key>/<subPath>`.
     *
     * @param string|list<string> $methods
     */
    public function api(string|array $methods, string $subPath, callable $handler, bool $requireAuth = false): void
    {
        CapabilityGuard::assert($this->manifest, 'routes:api');
        $subPath = trim($subPath, '/');
        if ($subPath !== '' && preg_match('#^[a-z0-9][a-z0-9/_-]{0,120}$#i', $subPath) !== 1) {
            throw new InvalidArgumentException('مسیر API ماژول معتبر نیست: ' . $subPath);
        }
        $fullPath = '/api/v1/modules/' . $this->key() . ($subPath !== '' ? '/' . $subPath : '');
        $methodList = is_array($methods) ? array_map('strtoupper', $methods) : [strtoupper($methods)];
        $moduleKey = $this->key();

        $wrapped = static function (array $matches) use ($handler, $requireAuth, $moduleKey): void {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            try {
                if ($requireAuth) {
                    $user = Auth::user();
                    if (!is_array($user)) {
                        ApiAuth::authorize('modules');
                    }
                }
                $rawBody = file_get_contents('php://input');
                $jsonBody = (is_string($rawBody) && trim($rawBody) !== '')
                    ? (json_decode($rawBody, true) ?: [])
                    : [];
                $input = is_array($jsonBody) && $jsonBody !== [] ? $jsonBody : $_POST;
                $result = $handler($input, $_GET, $matches);
                echo json_encode([
                    'ok' => true,
                    'module' => $moduleKey,
                    'data' => $result,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (InvalidArgumentException $error) {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'module' => $moduleKey,
                    'error' => ['code' => 'validation_error', 'message' => $error->getMessage()],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $error) {
                error_log('[VazinCMS module-api=' . $moduleKey . '] ' . $error->getMessage());
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'module' => $moduleKey,
                    'error' => ['code' => 'module_api_error', 'message' => 'خطای داخلی در پردازش API ماژول.'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        };

        $this->routes->regex($methodList, '#^' . preg_quote($fullPath, '#') . '$#', $wrapped);
    }

    public function on(string $event, callable $handler, int $priority = 10): void
    {
        CapabilityGuard::assert($this->manifest, 'hooks:listen');
        $this->hooks->on($event, $this->key(), $handler, $priority);
    }

    public function filter(string $hook, callable $handler, int $priority = 10): void
    {
        CapabilityGuard::assert($this->manifest, 'hooks:filter');
        $this->hooks->addFilter($hook, $this->key(), $handler, $priority);
    }

    /**
     * @param array{label?:string,category?:string,schema?:array<string,string>} $meta
     */
    public function registerBlock(string $blockName, array $meta, callable $renderer): string
    {
        CapabilityGuard::assert($this->manifest, 'blocks:register');
        return BlockRegistry::register($this->key(), $blockName, $meta, $renderer);
    }

    /**
     * @param array<string,mixed> $definition
     */
    public function registerContentType(string $typeKey, array $definition): void
    {
        CapabilityGuard::assert($this->manifest, 'content:types');
        DynamicContentService::registerRuntimeType($this->key(), $typeKey, $definition);
    }

    public function registerPaymentGateway(PaymentGatewayInterface $gateway): void
    {
        CapabilityGuard::assert($this->manifest, 'payments:gateway');
        PaymentGatewayRegistry::register($this->key(), $gateway);
    }

    public function db(): ModuleDatabase
    {
        CapabilityGuard::assert($this->manifest, 'db:isolated');
        if (!$this->moduleDb instanceof ModuleDatabase) {
            $this->moduleDb = new ModuleDatabase(Database::connection(), $this->key());
        }
        return $this->moduleDb;
    }

    public function getSetting(string $name, mixed $default = null): mixed
    {
        CapabilityGuard::assert($this->manifest, 'settings:manage');
        $fullKey = $this->settingKey($name);
        $pdo = Database::connection();
        if (SiteScopeMode::active($pdo)) {
            $map = (new SiteScopedContentRepository($pdo, SiteContext::id()))->settingsMap();
            if (!array_key_exists($fullKey, $map)) return $default;
            $decoded = json_decode((string) $map[$fullKey], true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $map[$fullKey];
        }
        $stmt = $pdo->prepare('SELECT setting_value FROM cms_settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute(['k' => $fullKey]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) return $default;
        $decoded = json_decode((string) $raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    public function setSetting(string $name, mixed $value): void
    {
        CapabilityGuard::assert($this->manifest, 'settings:manage');
        $fullKey = $this->settingKey($name);
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (SiteScopeMode::active($pdo)) {
            $siteId = SiteContext::id();
            $sql = $driver === 'sqlite'
                ? 'INSERT INTO cms_settings(site_id, setting_key, setting_value) VALUES(:s, :k, :v) ON CONFLICT(site_id, setting_key) DO UPDATE SET setting_value = :v2, updated_at = CURRENT_TIMESTAMP'
                : 'INSERT INTO cms_settings(site_id, setting_key, setting_value) VALUES(:s, :k, :v) ON CONFLICT(site_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP';
            $args = ['s' => $siteId, 'k' => $fullKey, 'v' => $encoded];
            if ($driver === 'sqlite') $args['v2'] = $encoded;
            $pdo->prepare($sql)->execute($args);
            return;
        }
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO cms_settings(setting_key, setting_value) VALUES(:k, :v) ON CONFLICT(setting_key) DO UPDATE SET setting_value = :v2, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO cms_settings(setting_key, setting_value) VALUES(:k, :v) ON CONFLICT(setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP';
        $args = ['k' => $fullKey, 'v' => $encoded];
        if ($driver === 'sqlite') $args['v2'] = $encoded;
        $pdo->prepare($sql)->execute($args);
    }

    /**
     * SSRF-guarded outbound HTTPS JSON client restricted to `outbound_hosts` in manifest.
     *
     * @param ?array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{status:int,body:mixed}
     */
    public function httpJson(string $method, string $url, ?array $payload = null, array $headers = []): array
    {
        $validatedUrl = CapabilityGuard::assertOutboundUrl($this->manifest, $url);
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('متد HTTP مجاز نیست.');
        }
        $ch = curl_init($validatedUrl);
        if ($ch === false) {
            throw new RuntimeException('خطا در آماده‌سازی کلاینت HTTPS.');
        }
        $reqHeaders = ['Accept: application/json', 'User-Agent: VazinCMS-Module/' . Version::current()];
        foreach ($headers as $hk => $hv) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $hk) === 1 && !str_contains($hv, "\r") && !str_contains($hv, "\n")) {
                $reqHeaders[] = $hk . ': ' . $hv;
            }
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $reqHeaders,
        ];
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $reqHeaders[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $reqHeaders;
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return ['status' => $status, 'body' => $decoded ?? $raw];
    }

    private function assertRouteCapability(string $path): void
    {
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            CapabilityGuard::assert($this->manifest, 'routes:admin');
            return;
        }
        CapabilityGuard::assert($this->manifest, 'routes:public');
    }

    private function settingKey(string $name): string
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,60}$/', $name) !== 1) {
            throw new InvalidArgumentException('کلید تنظیمات ماژول معتبر نیست: ' . $name);
        }
        return 'mod.' . $this->key() . '.' . $name;
    }
}
