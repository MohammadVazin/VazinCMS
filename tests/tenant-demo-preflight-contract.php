<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$script = $root . '/scripts/tenant-demo-preflight.php';
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$run = static function (array $manifest) use ($script): array {
    $path = tempnam(sys_get_temp_dir(), 'vazincms-tenant-demo-');
    if ($path === false) {
        throw new RuntimeException('Could not create temporary manifest.');
    }
    try {
        file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($path);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start tenant preflight process.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    } finally {
        @unlink($path);
    }
};

$valid = [
    'tenant_key' => 'agency-demo',
    'primary_domain' => 'demo.example.test',
    'brand_name' => 'نمونه آژانس سفر',
    'site_profile' => 'travel',
    'primary_color' => '#3156D3',
    'default_locale' => 'fa',
    'enabled_locales' => ['fa', 'ru', 'en'],
    'operating_mode' => 'lead_only',
    'connector_metadata' => [[
        'provider_key' => 'future-b2b-provider',
        'label' => 'Future contracted provider',
        'mode' => 'manual_fulfilment',
        'capabilities' => ['quote', 'status'],
    ]],
    'feature_gates' => ['VAZIN_VISA_DIRECT_SALES_ENABLED' => false],
    'telegram' => ['enabled' => false],
];

[$code, $stdout, $stderr] = $run($valid);
$check($code === 0 && $stderr === '', 'Valid demo manifest did not pass: ' . $stderr);
$plan = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
$check(($plan['outcome'] ?? '') === 'preflight_validated_no_provisioning', 'Preflight outcome incorrectly implies a provisioned tenant.');
$check(($plan['safety']['provisioning_performed'] ?? true) === false, 'Preflight claimed to provision a tenant.');
$check(($plan['safety']['network_requests_performed'] ?? true) === false, 'Preflight claimed to make a network request.');
foreach (($plan['safety']['feature_gates'] ?? []) as $key => $enabled) {
    $check($enabled === false, "Preflight enabled {$key}.");
}

$liveBooking = $valid;
$liveBooking['operating_mode'] = 'live_booking';
[$code, , $stderr] = $run($liveBooking);
$check($code !== 0 && str_contains($stderr, 'live_booking is not allowed'), 'Preflight accepted live booking.');

$gateEnabled = $valid;
$gateEnabled['feature_gates']['EXTERNAL_DELIVERY_ENABLED'] = true;
[$code, , $stderr] = $run($gateEnabled);
$check($code !== 0 && str_contains($stderr, 'must be false'), 'Preflight accepted a feature gate activation.');

$secret = $valid;
$secret['connector_metadata'][0]['credential'] = 'not-allowed';
[$code, , $stderr] = $run($secret);
$check($code !== 0 && str_contains($stderr, 'not allowed'), 'Preflight accepted a credential.');

echo "Tenant demo preflight contract: OK\n";
