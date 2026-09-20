<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Database,TravelVisaActivationGate,TravelVisaCommerceService,TravelVisaVazinPayAdapter,VazinPayGatewayClient};

/** Public callback endpoint, inactive unless its tenant is explicitly allow-listed. */
final class TravelVisaPaymentWebhookController
{
    public function handle(string $tenant): never
    {
        $gate=TravelVisaActivationGate::status($tenant);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !$this->tenantAllowed($tenant) || !$gate['ready']) $this->respond(404);
        $raw = file_get_contents('php://input', false, null, 0, 65_537);
        if (!is_string($raw) || strlen($raw) > 65_536) $this->respond(413);
        try {
            $adapter = new TravelVisaVazinPayAdapter(new TravelVisaCommerceService(Database::connection()), new VazinPayGatewayClient());
            $order = $adapter->reconcileWebhook($tenant, $this->headers(), $raw);
            header('Cache-Control: no-store'); header('Content-Type: application/json; charset=utf-8'); http_response_code(200);
            echo json_encode(['ok'=>true,'reference'=>$order['public_ref'],'payment_status'=>$order['payment_status']], JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $e) {
            $correlation = bin2hex(random_bytes(6));
            error_log('[VazinCMS travel visa payment webhook correlation='.$correlation.'] '.$e->getMessage());
            header('Cache-Control: no-store'); header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
            echo json_encode(['ok'=>false,'correlation'=>$correlation], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $headers=[]; foreach ($_SERVER as $name=>$value) if (str_starts_with($name,'HTTP_')) $headers[str_replace(' ','-',ucwords(strtolower(str_replace('_',' ',substr($name,5)))))] = (string)$value;
        return $headers;
    }
    private function tenantAllowed(string $tenant): bool
    {
        $raw=(string)getenv('TRAVEL_VISA_WEBHOOK_TENANTS'); $allowed=array_filter(array_map('trim',explode(',',$raw)));
        foreach ($allowed as $candidate) if (hash_equals($candidate,$tenant)) return true;
        return false;
    }
    private function respond(int $status): never { http_response_code($status); header('Cache-Control: no-store'); header('Content-Type: application/json; charset=utf-8'); echo '{"ok":false}'; exit; }
}
