<?php
declare(strict_types=1);
namespace VazinCMS;

/**
 * Fail-closed Travel/Visa connectivity and commercial-release gate.
 *
 * webhook_ready may be true in an explicitly configured sandbox while
 * commercial sales stay disabled. ready is reserved for commercial release.
 */
final class TravelVisaActivationGate
{
    /** @return array{ready:bool,webhook_ready:bool,missing:list<string>} */
    public static function status(string $tenant): array
    {
        $required = [
            'TRAVEL_VISA_COMMERCIAL_ENABLED',
            'TRAVEL_VISA_SELLER_ID',
            'VAZINPAY_GATEWAY_URL',
            'VAZINPAY_API_KEY',
            'VAZINPAY_WEBHOOK_SECRET',
            'TRAVEL_VISA_WEBHOOK_TENANTS',
        ];
        $missing = [];
        foreach ($required as $key) {
            if (trim((string)getenv($key)) === '') $missing[] = $key;
        }

        $commercialRaw = strtolower(trim((string)getenv('TRAVEL_VISA_COMMERCIAL_ENABLED')));
        if ($commercialRaw !== '' && !in_array($commercialRaw, ['1','0','true','false','yes','no','on','off'], true)) {
            $missing[] = 'TRAVEL_VISA_COMMERCIAL_ENABLED';
        }
        $commercialEnabled = filter_var($commercialRaw, FILTER_VALIDATE_BOOLEAN);

        $tenants = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)getenv('TRAVEL_VISA_WEBHOOK_TENANTS'))
        )));
        if (!in_array($tenant, $tenants, true)) $missing[] = 'tenant_webhook_allowlist';

        $missing = array_values(array_unique($missing));
        $webhookReady = $missing === [];
        return [
            'ready' => $commercialEnabled && $webhookReady,
            'webhook_ready' => $webhookReady,
            'missing' => $missing,
        ];
    }
}
