<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use RuntimeException;

/** Thin, fail-closed adapter for the public VazinPay invoice contract. */
final class VazinPayGatewayClient
{
    /** @var null|callable(string,string,?array,array<string,string>):array{status:int,body:array<string,mixed>} */
    private $transport;

    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $apiKey = '',
        private readonly string $webhookSecret = '',
        ?callable $transport = null,
    ) { $this->transport = $transport; }

    /** @return array<string,mixed> */
    public function createInvoice(string $externalOrderId, string $amount, string $currency, string $description = '', string $returnUrl = ''): array
    {
        if (preg_match('/^[A-Za-z0-9:_-]{8,190}$/', $externalOrderId) !== 1) throw new InvalidArgumentException('شناسهٔ سفارش خارجی معتبر نیست.');
        $body = ['external_order_id'=>$externalOrderId, 'amount'=>self::money($amount), 'currency'=>self::currency($currency)];
        if ($description !== '') $body['description'] = self::text($description, 500, 'شرح');
        if ($returnUrl !== '') $body['return_url'] = self::returnUrl($returnUrl);
        return $this->request('POST', '/v1/invoices', $body, [200,201]);
    }

    /** @return array<string,mixed> */
    public function invoice(string $invoiceId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{6,190}$/', $invoiceId) !== 1) throw new InvalidArgumentException('شناسهٔ فاکتور معتبر نیست.');
        return $this->request('GET', '/v1/invoices/' . rawurlencode($invoiceId), null, [200]);
    }

    /** @return array<string,mixed> Does not treat 202/pending_provider as a completed refund. */
    public function refund(string $invoiceId, string $reason, string $idempotencyKey, string $amount = ''): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{6,190}$/', $invoiceId) !== 1) throw new InvalidArgumentException('شناسهٔ فاکتور معتبر نیست.');
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey) !== 1) throw new InvalidArgumentException('کلید idempotency معتبر نیست.');
        $body = ['reason'=>self::text($reason, 500, 'دلیل بازپرداخت')];
        if ($amount !== '') $body['amount'] = self::money($amount);
        return $this->request('POST', '/v1/invoices/' . rawurlencode($invoiceId) . '/refund', $body, [200,202], ['Idempotency-Key'=>$idempotencyKey]);
    }

    /** @return array{event:string,delivery:string,payload:array<string,mixed>} */
    public function verifyWebhook(array $headers, string $rawBody, int $now = 0): array
    {
        $event = trim((string)($headers['X-VazinPay-Event'] ?? $headers['x-vazinpay-event'] ?? ''));
        $delivery = trim((string)($headers['X-VazinPay-Delivery'] ?? $headers['x-vazinpay-delivery'] ?? ''));
        $timestamp = trim((string)($headers['X-VazinPay-Timestamp'] ?? $headers['x-vazinpay-timestamp'] ?? ''));
        $signature = trim((string)($headers['X-VazinPay-Signature'] ?? $headers['x-vazinpay-signature'] ?? ''));
        $secret = $this->webhookSecret !== '' ? $this->webhookSecret : (string)getenv('VAZINPAY_WEBHOOK_SECRET');
        if (!in_array($event, ['invoice.paid','refund'], true) || $delivery === '' || preg_match('/^\d{10}$/', $timestamp) !== 1 || $secret === '') throw new RuntimeException('وب‌هوک VazinPay معتبر نیست.');
        $now = $now ?: time(); if (abs($now - (int)$timestamp) > 300) throw new RuntimeException('زمان وب‌هوک VazinPay معتبر نیست.');
        $expected = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) throw new RuntimeException('امضای وب‌هوک VazinPay معتبر نیست.');
        try { $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new RuntimeException('بدنهٔ وب‌هوک JSON معتبر نیست.'); }
        if (!is_array($payload)) throw new RuntimeException('بدنهٔ وب‌هوک معتبر نیست.');
        return ['event'=>$event,'delivery'=>$delivery,'payload'=>$payload];
    }

    /** @param list<int> $accepted @param array<string,string> $extraHeaders @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body, array $accepted, array $extraHeaders = []): array
    {
        $base = $this->baseUrl !== '' ? $this->baseUrl : (string)getenv('VAZINPAY_GATEWAY_URL');
        $key = $this->apiKey !== '' ? $this->apiKey : (string)getenv('VAZINPAY_API_KEY');
        $parts = parse_url($base);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'pay.vazin.online' || $key === '') throw new RuntimeException('اتصال امن VazinPay تنظیم نشده است.');
        $headers = ['Authorization'=>'Bearer '.$key, 'Accept'=>'application/json'] + $extraHeaders;
        $result = $this->transport ? ($this->transport)($method, $path, $body, $headers) : $this->curl(rtrim($base,'/').$path, $method, $body, $headers);
        if (!in_array($result['status'], $accepted, true)) throw new RuntimeException('VazinPay پاسخ موفق نداد: HTTP '.$result['status']);
        return $result['body'];
    }

    /** @param array<string,string> $headers @return array{status:int,body:array<string,mixed>} */
    private function curl(string $url, string $method, ?array $body, array $headers): array
    {
        $lines = []; foreach ($headers as $name=>$value) $lines[] = $name . ': ' . $value;
        if ($body !== null) $lines[] = 'Content-Type: application/json';
        $ch = curl_init($url); if ($ch === false) throw new RuntimeException('اتصال VazinPay ایجاد نشد.');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$lines,CURLOPT_PROXY=>'']);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
        if (!is_string($raw)) throw new RuntimeException('VazinPay در دسترس نیست: '.$error);
        $decoded = json_decode($raw, true); return ['status'=>$status,'body'=>is_array($decoded)?$decoded:[]];
    }

    private static function money(string $value): string { if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', trim($value), $m) !== 1) throw new InvalidArgumentException('مبلغ معتبر نیست.'); return $m[1].'.'.str_pad($m[2] ?? '', 2, '0'); }
    private static function currency(string $value): string { $value = strtoupper(trim($value)); if (preg_match('/^[A-Z]{3}$/', $value) !== 1) throw new InvalidArgumentException('ارز معتبر نیست.'); return $value; }
    private static function text(string $value, int $max, string $label): string { $value = trim($value); $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); if ($value === '' || $length > $max) throw new InvalidArgumentException($label.' معتبر نیست.'); return $value; }
    private static function returnUrl(string $value): string { $p = parse_url($value); if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') === '' || isset($p['user'],$p['pass'],$p['fragment'])) throw new InvalidArgumentException('نشانی بازگشت باید HTTPS معتبر باشد.'); return $value; }
}
