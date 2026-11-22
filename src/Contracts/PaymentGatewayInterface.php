<?php
declare(strict_types=1);

namespace VazinCMS\Contracts;

interface PaymentGatewayInterface
{
    public function key(): string;

    public function label(): string;

    public function supportsCurrency(string $currency): bool;

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $options
     * @return array{status:string,provider_ref:string,checkout_url:?string,instructions:?string}
     */
    public function createCheckout(array $order, array $options = []): array;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{verified:bool,order_number:?string,provider_ref:?string,status:string}
     */
    public function verifyCallback(array $payload, array $headers = []): array;
}
