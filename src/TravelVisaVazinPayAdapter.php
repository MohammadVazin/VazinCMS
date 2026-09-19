<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

/**
 * Tenant-scoped bridge between the neutral Travel/Visa lifecycle and VazinPay.
 * It never treats a browser return as payment proof: VazinPay readback is required.
 */
final class TravelVisaVazinPayAdapter
{
    public function __construct(
        private readonly TravelVisaCommerceService $orders,
        private readonly VazinPayGatewayClient $gateway,
    ) {}

    /** @return array<string,mixed> */
    public function createInvoice(string $tenant, string $reference, string $returnUrl, string $description, string $idempotencyKey): array
    {
        $order = $this->orders->order($tenant, $reference);
        if ((string)($order['invoice_id'] ?? '') !== '') throw new RuntimeException('invoice_already_bound');
        $contract = TravelVisaCommerceContract::invoice('travelvisa:' . $reference, (string)$order['amount'], (string)$order['currency'], $returnUrl);
        $invoice = $this->gateway->createInvoice($contract['external_order_id'], $contract['amount'], $contract['currency'], $description, $contract['return_url']);
        $invoiceId = trim((string)($invoice['invoice_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{6,190}$/', $invoiceId) !== 1) throw new RuntimeException('pay_invoice_id_missing');
        $this->orders->bindInvoice($reference, $invoiceId, $idempotencyKey);
        return $this->orders->order($tenant, $reference);
    }

    /** @return array<string,mixed> */
    public function reconcileReadback(string $tenant, string $reference, string $deliveryKey): array
    {
        $order = $this->orders->order($tenant, $reference);
        $invoiceId = (string)($order['invoice_id'] ?? '');
        if ($invoiceId === '') throw new RuntimeException('invoice_not_bound');
        return $this->orders->applyReadback($reference, $this->gateway->invoice($invoiceId), $deliveryKey);
    }

    /** @return array<string,mixed> */
    public function reconcileWebhook(string $tenant, array $headers, string $rawBody, int $now = 0): array
    {
        $event = $this->gateway->verifyWebhook($headers, $rawBody, $now);
        $reference = (string)($event['payload']['external_order_id'] ?? '');
        if (!str_starts_with($reference, 'travelvisa:')) throw new RuntimeException('webhook_order_mismatch');
        return $this->reconcileReadback($tenant, substr($reference, strlen('travelvisa:')), (string)$event['delivery']);
    }
}
