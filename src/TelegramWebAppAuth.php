<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class TelegramWebAppAuth
{
    public static function validate(string $initData, string $botToken, int $maximumAge = 600): array
    {
        if (strlen($initData) < 20 || strlen($initData) > 16_384 || str_contains($initData, "\0")
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $initData) === 1 || !TelegramBotApi::validToken($botToken)) {
            throw new RuntimeException('دادهٔ آغازین Telegram Web App معتبر نیست.');
        }
        $values = [];
        foreach (explode('&', $initData) as $pair) {
            if (!str_contains($pair, '=')) throw new RuntimeException('ساختار دادهٔ Telegram ناقص است.');
            [$rawKey,$rawValue] = explode('=', $pair, 2);
            $key = rawurldecode($rawKey);
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || isset($values[$key])) {
                throw new RuntimeException('کلید تکراری یا نامعتبر در دادهٔ Telegram وجود دارد.');
            }
            $values[$key] = rawurldecode($rawValue);
        }
        $providedHash = strtolower((string)($values['hash'] ?? ''));
        unset($values['hash'], $values['signature']);
        if (preg_match('/^[a-f0-9]{64}$/', $providedHash) !== 1) throw new RuntimeException('امضای Telegram وجود ندارد.');
        ksort($values, SORT_STRING);
        $check = implode("\n", array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($values), array_values($values)));
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', $check, $secret);
        if (!hash_equals($expected, $providedHash)) throw new RuntimeException('امضای Telegram Web App معتبر نیست.');

        $authDate = filter_var($values['auth_date'] ?? null, FILTER_VALIDATE_INT);
        $now = time();
        $maximumAge = max(30, min(600, $maximumAge));
        if (!is_int($authDate) || $authDate < 1 || $authDate > $now + 30 || $now - $authDate > $maximumAge) {
            throw new RuntimeException('نشست Telegram Web App منقضی شده است.');
        }
        $user = json_decode((string)($values['user'] ?? ''), true);
        $userId = is_array($user) ? filter_var($user['id'] ?? null, FILTER_VALIDATE_INT) : false;
        if (!is_array($user) || !is_int($userId) || $userId < 1 || $userId > 4_503_599_627_370_495) {
            throw new RuntimeException('شناسهٔ کاربر Telegram دریافت نشد.');
        }
        $user['id'] = $userId;
        return [
            'user'=>$user,
            'auth_date'=>$authDate,
            'query_id'=>mb_substr((string)($values['query_id']??''), 0, 255),
            'init_hash'=>hash('sha256', $initData),
            'raw'=>$values,
        ];
    }
}
