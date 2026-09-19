<?php
declare(strict_types=1);

namespace VazinCMS;

use Closure;
use RuntimeException;

final class TelegramBotApi
{
    private const METHODS = [
        'getMe','getWebhookInfo','setWebhook','deleteWebhook','sendMessage','editMessageText','setChatMenuButton',
        'getBusinessConnection','sendChatAction','answerCallbackQuery','readBusinessMessage',
    ];
    private ?Closure $transport;

    public function __construct(private string $token, ?callable $transport = null)
    {
        if (!self::validToken($token)) throw new RuntimeException('قالب Bot Token تلگرام معتبر نیست.');
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function validToken(string $token): bool
    {
        return preg_match('/^[1-9][0-9]{4,19}:[A-Za-z0-9_-]{30,100}$/', trim($token)) === 1;
    }

    public function call(string $method, array $parameters = []): array
    {
        if (!in_array($method, self::METHODS, true)) throw new RuntimeException('متد Bot API مجاز نیست.');
        DeliveryPolicy::assertTelegramEnabled();
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $parameters);
            if (!is_array($response)) throw new RuntimeException('پاسخ آزمایشی Telegram معتبر نیست.');
            return $this->result($response);
        }

        $endpoint = 'https://api.telegram.org/bot' . $this->token . '/' . $method;
        $payload = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = curl_init($endpoint);
        if ($handle === false) throw new RuntimeException('راه‌اندازی اتصال Telegram ناموفق بود.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw) || $networkError !== '') {
            throw new RuntimeException('ارتباط با Telegram برقرار نشد.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new RuntimeException('پاسخ Telegram قابل پردازش نیست.');
        if ($status < 200 || $status >= 300) {
            $this->fail($decoded, $status);
        }
        return $this->result($decoded);
    }

    private function result(array $response): array
    {
        if (($response['ok'] ?? false) !== true) {
            $this->fail($response);
        }
        $result = $response['result'] ?? [];
        return is_array($result) ? $result : ['value' => $result];
    }

    private function fail(array $response, int $httpStatus = 0): never
    {
        $code = (int)($response['error_code'] ?? $httpStatus);
        $retryAfter = max(0, min(86400, (int)($response['parameters']['retry_after'] ?? 0)));
        $description = mb_substr((string)($response['description'] ?? ($httpStatus > 0 ? 'HTTP ' . $httpStatus : 'عملیات ناموفق بود.')), 0, 300);
        throw new TelegramBotApiException('Telegram: ' . $description, $code, $retryAfter);
    }
}
