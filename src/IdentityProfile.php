<?php
declare(strict_types=1);
namespace VazinCMS;

use RuntimeException;

/**
 * Produces the only Vazin ID UserInfo fields that may cross into CMS storage.
 * Core authentication claims fail closed. Optional presentation claims are
 * ignored when absent or malformed and can never affect authorization.
 */
final class IdentityProfile
{
    public const THEME_CLAIM = 'https://vazin.online/claims/theme';
    public const SHARED_CLAIM = 'https://vazin.online/claims/preferences_shared';
    public const REVISION_CLAIM = 'https://vazin.online/claims/profile_revision';
    public const MAX_PROFILE_REVISION = 9007199254740991;

    public static function normalize(array $payload): array
    {
        $sub = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;
        $verified = $payload['email_verified'] ?? null;
        $name = $payload['name'] ?? null;

        if (!is_string($sub) || $sub === '' || $sub !== trim($sub) || strlen($sub) > 128 || preg_match('/[\x00-\x20\x7f]/', $sub) === 1) {
            throw new RuntimeException('شناسه Vazin ID معتبر نیست.');
        }
        if (!is_string($email) || $email === '' || $email !== trim($email) || strlen($email) > 254 || preg_match('/\p{C}/u', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('پروفایل Vazin ID معتبر نیست.');
        }
        if ($verified !== true) {
            throw new RuntimeException('برای ورود، Vazin ID باید ایمیل تأییدشده ارائه کند.');
        }
        if (!is_string($name)) {
            throw new RuntimeException('پروفایل Vazin ID معتبر نیست.');
        }
        if ($name !== trim($name) || mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 160 || preg_match('/\p{C}/u', $name) !== 0) {
            throw new RuntimeException('پروفایل Vazin ID معتبر نیست.');
        }

        $clean = [
            'sub' => $sub,
            'email' => strtolower($email),
            'email_verified' => true,
            'name' => $name,
        ];

        if (array_key_exists(self::SHARED_CLAIM, $payload)) {
            if (!is_bool($payload[self::SHARED_CLAIM])) {
                throw new RuntimeException('تنظیم اشتراک‌گذاری پروفایل Vazin ID معتبر نیست.');
            }
            $clean['preferences_shared'] = $payload[self::SHARED_CLAIM];
            if (array_key_exists(self::REVISION_CLAIM, $payload)) {
                $revision=$payload[self::REVISION_CLAIM];
                if (!is_int($revision) || $revision < 1 || $revision > self::MAX_PROFILE_REVISION) {
                    throw new RuntimeException('نسخه پروفایل Vazin ID معتبر نیست.');
                }
                $clean['profile_revision']=$revision;
            }
        }

        // Missing means a legacy identity server: preserve the existing
        // projection. False clears only Vazin-ID-owned fields. Legacy true
        // without a revision authenticates but must not even interpret its
        // optional claims. Only revisioned true is strict and mutable.
        if (($clean['preferences_shared'] ?? null) !== true || !array_key_exists('profile_revision', $clean)) return $clean;

        if (array_key_exists('preferred_username', $payload)) {
            $username=$payload['preferred_username'];
            if (!is_string($username) || preg_match('/\A[a-z][a-z0-9._]{2,31}\z/D', $username) !== 1) throw new RuntimeException('نام کاربری Vazin ID معتبر نیست.');
            $clean['preferred_username']=$username;
        }
        if (array_key_exists('picture', $payload)) {
            if (!is_string($payload['picture']) || !self::validPicture($payload['picture'])) throw new RuntimeException('تصویر پروفایل Vazin ID معتبر نیست.');
            $clean['picture']=$payload['picture'];
        }
        if (array_key_exists('locale', $payload)) {
            if (!is_string($payload['locale']) || !in_array($payload['locale'], ['fa','en','ru','ar'], true)) throw new RuntimeException('زبان پروفایل Vazin ID معتبر نیست.');
            $clean['locale']=$payload['locale'];
        }
        if (array_key_exists(self::THEME_CLAIM, $payload)) {
            if (!is_string($payload[self::THEME_CLAIM]) || !in_array($payload[self::THEME_CLAIM], ['system','light','dark'], true)) throw new RuntimeException('قالب پروفایل Vazin ID معتبر نیست.');
            $clean['theme']=$payload[self::THEME_CLAIM];
        }
        if (array_key_exists('updated_at', $payload)) {
            $updatedAt=$payload['updated_at'];
            if (!is_int($updatedAt) || $updatedAt < 1 || $updatedAt > self::MAX_PROFILE_REVISION) throw new RuntimeException('زمان پروفایل Vazin ID معتبر نیست.');
            $clean['updated_at']=$updatedAt;
        }

        return $clean;
    }

    private static function validPicture(string $value): bool
    {
        if ($value === '' || $value !== trim($value) || strlen($value) > 2048 || preg_match('/\p{C}/u', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
            return false;
        }
        $host=(string)$parts['host'];
        if(str_starts_with($host,'[')&&str_ends_with($host,']'))$host=substr($host,1,-1);
        $validationHost=rtrim($host,'.');
        if($validationHost===''||str_contains($host,'%')||strlen($validationHost)>253||preg_match('/[^\x20-\x7e]/',$validationHost)===1)return false;
        if(filter_var($validationHost,FILTER_VALIDATE_IP)!==false)return true;
        if(str_contains($validationHost,':'))return false;
        foreach(explode('.',$validationHost)as$label){
            if(preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/D',$label)!==1)return false;
        }
        return true;
    }
}
