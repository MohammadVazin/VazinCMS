<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

final class VazinIdSettings
{
    private const PROVIDER = 'vazin_id';
    private const DEFAULT_SCOPES = 'openid profile email';

    public static function current(): array
    {
        $fallback = [
            'provider' => self::PROVIDER,
            'issuer_url' => self::validIssuer((string) getenv('VAZIN_ID_URL')) ? rtrim((string) getenv('VAZIN_ID_URL'), '/') : '',
            'client_id' => trim((string) getenv('VAZIN_ID_CLIENT_ID')),
            'scopes' => self::DEFAULT_SCOPES,
            'is_enabled' => 1,
            'auto_provision' => 1,
            'source' => 'environment',
        ];
        try {
            $statement = Database::connection()->prepare('SELECT * FROM identity_providers WHERE provider=:provider');
            $statement->execute(['provider' => self::PROVIDER]);
            $row = $statement->fetch();
            if (is_array($row) && (trim((string)$row['issuer_url']) !== '' || trim((string)$row['client_id']) !== '')) {
                return [
                    'provider' => self::PROVIDER,
                    'issuer_url' => rtrim((string)$row['issuer_url'], '/'),
                    'client_id' => trim((string)$row['client_id']),
                    'scopes' => self::normalizeScopes((string)$row['scopes']),
                    'is_enabled' => (int)$row['is_enabled'],
                    'auto_provision' => (int)$row['auto_provision'],
                    'source' => 'database',
                ];
            }
        } catch (Throwable) {
        }
        return $fallback;
    }

    public static function configured(): bool
    {
        $settings = self::current();
        return (int)$settings['is_enabled'] === 1
            && self::validIssuer((string)$settings['issuer_url'])
            && trim((string)$settings['client_id']) !== '';
    }

    public static function required(): bool
    {
        return filter_var((string) getenv('VAZIN_ID_REQUIRED'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function save(array $input, int $userId): void
    {
        $issuer = rtrim(trim((string)($input['issuer_url'] ?? '')), '/');
        $clientId = trim((string)($input['client_id'] ?? ''));
        $enabled = !empty($input['is_enabled']) ? 1 : 0;
        $autoProvision = !empty($input['auto_provision']) ? 1 : 0;
        $scopes = self::normalizeScopes((string)($input['scopes'] ?? self::DEFAULT_SCOPES));
        if ($issuer !== '' && !self::validIssuer($issuer)) {
            throw new RuntimeException('نشانی Vazin ID باید HTTPS و متعلق به دامنهٔ مجاز باشد.');
        }
        if ($enabled === 1 && ($issuer === '' || $clientId === '')) {
            throw new RuntimeException('برای فعال‌سازی، نشانی صادرکننده و Client ID لازم است.');
        }
        if ($clientId !== '' && preg_match('/^[A-Za-z0-9._:-]{3,255}$/', $clientId) !== 1) {
            throw new RuntimeException('Client ID معتبر نیست.');
        }
        $statement = Database::connection()->prepare(
            'INSERT INTO identity_providers(provider,issuer_url,client_id,scopes,is_enabled,auto_provision,updated_by) '
            . 'VALUES(:provider,:issuer,:client,:scopes,:enabled,:auto,:user) '
            . 'ON CONFLICT(provider) DO UPDATE SET issuer_url=:issuer2,client_id=:client2,scopes=:scopes2,is_enabled=:enabled2,auto_provision=:auto2,updated_by=:user2,updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'provider'=>self::PROVIDER,'issuer'=>$issuer,'client'=>$clientId,'scopes'=>$scopes,
            'enabled'=>$enabled,'auto'=>$autoProvision,'user'=>$userId,
            'issuer2'=>$issuer,'client2'=>$clientId,'scopes2'=>$scopes,
            'enabled2'=>$enabled,'auto2'=>$autoProvision,'user2'=>$userId,
        ]);
    }

    public static function validIssuer(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower((string)$parts['host']);
        $allowed = array_filter(array_map('trim', explode(',', (string)(getenv('VAZIN_ID_ALLOWED_HOSTS') ?: 'id.vazin.online'))));
        return in_array($host, array_map('strtolower', $allowed), true);
    }

    private static function normalizeScopes(string $scopes): string
    {
        $requested = preg_split('/\s+/', trim($scopes)) ?: [];
        $allowed = ['openid','profile','email'];
        $normalized = array_values(array_unique(array_intersect($allowed, $requested)));
        if (!in_array('openid', $normalized, true)) array_unshift($normalized, 'openid');
        return implode(' ', $normalized ?: $allowed);
    }
}
