<?php
declare(strict_types=1);
namespace VazinCMS;
use InvalidArgumentException;

/** Shared, tenant-neutral lifecycle. Money remains authoritative in VazinPay. */
final class TravelVisaCommerceContract
{
    public static function invoice(string $orderRef, string $amount, string $currency, string $returnUrl): array
    {
        if (preg_match('/^travelvisa:[A-Z0-9_-]{10,100}$/', $orderRef) !== 1) throw new InvalidArgumentException('Invalid travel order reference.');
        if (preg_match('/^(0|[1-9]\d*)\.\d{2}$/', $amount) !== 1 || (float)$amount <= 0) throw new InvalidArgumentException('Invalid amount.');
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || !str_starts_with($returnUrl, 'https://')) throw new InvalidArgumentException('Invalid invoice details.');
        return ['external_order_id'=>$orderRef,'amount'=>$amount,'currency'=>$currency,'return_url'=>$returnUrl];
    }
    public static function canStartProcessing(string $payment, ?string $processingAt, ?string $refundLock): bool { return $payment==='paid' && $processingAt===null && $refundLock===null; }
    public static function canRefund(string $payment, ?string $processingAt, ?string $processingLock): bool { return $payment==='paid' && $processingAt===null && $processingLock===null; }
}
