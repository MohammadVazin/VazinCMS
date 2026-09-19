<?php
declare(strict_types=1);

/**
 * Validate a tenant demo manifest without creating a tenant, touching a server,
 * loading credentials, or enabling delivery/sales capability.
 *
 * Usage: php scripts/tenant-demo-preflight.php path/to/tenant-demo.json
 */

const ALLOWED_LOCALES = ['fa', 'ar', 'en', 'ru', 'tr', 'hy', 'kk', 'tg', 'zh'];
const SAFE_MODES = ['lead_only', 'affiliate_redirect', 'manual_fulfilment'];
const GATE_KEYS = [
    'VAZIN_VISA_DIRECT_SALES_ENABLED',
    'EXTERNAL_DELIVERY_ENABLED',
    'TELEGRAM_NETWORK_ENABLED',
    'TELEGRAM_ALERT_DELIVERY_ENABLED',
    'TELEGRAM_BUSINESS_ENABLED',
    'TELEGRAM_ASSISTANT_DELIVERY_ENABLED',
    'TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED',
    'TELEGRAM_ASSISTANT_PREVIEW_ENABLED',
];

function fail(string $message): never
{
    fwrite(STDERR, "tenant-demo-preflight: {$message}\n");
    exit(2);
}

function visibleLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function stringValue(array $input, string $key, int $minimum, int $maximum): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($value === '' || visibleLength($value) < $minimum || visibleLength($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/u', $value) === 1) {
        fail("{$key} must be between {$minimum} and {$maximum} visible characters.");
    }
    return $value;
}

function domainValue(array $input): string
{
    $domain = strtolower(stringValue($input, 'primary_domain', 4, 253));
    $pattern = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D';
    if (preg_match($pattern, $domain) !== 1) {
        fail('primary_domain must be an ASCII/Punycode hostname, not an IP address or URL.');
    }
    return $domain;
}

function noSecrets(mixed $value, string $path = '$'): void
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $child) {
        $name = is_string($key) ? $key : (string) $key;
        if (preg_match('/(?:token|secret|password|credential|api[_-]?key|webhook)/i', $name) === 1) {
            fail("{$path}.{$name} is not allowed; credentials and webhook configuration are outside the preflight manifest.");
        }
        noSecrets($child, $path . '.' . $name);
    }
}

function exactKeys(array $input, array $allowed, string $label): void
{
    $unknown = array_values(array_diff(array_keys($input), $allowed));
    if ($unknown !== []) {
        fail($label . ' contains unsupported field(s): ' . implode(', ', array_map('strval', $unknown)) . '.');
    }
}

function validateConnectors(mixed $value): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || count($value) > 10) {
        fail('connector_metadata must be an array with at most 10 planned connector records.');
    }
    $connectors = [];
    foreach ($value as $index => $connector) {
        if (!is_array($connector)) {
            fail("connector_metadata.{$index} must be an object.");
        }
        exactKeys($connector, ['provider_key', 'label', 'mode', 'capabilities'], "connector_metadata.{$index}");
        $providerKey = stringValue($connector, 'provider_key', 2, 80);
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $providerKey) !== 1) {
            fail("connector_metadata.{$index}.provider_key must use lowercase letters, digits, _ or -.");
        }
        $label = stringValue($connector, 'label', 2, 160);
        $mode = (string) ($connector['mode'] ?? '');
        if (!in_array($mode, SAFE_MODES, true)) {
            fail("connector_metadata.{$index}.mode must be a non-live mode.");
        }
        $capabilities = $connector['capabilities'] ?? [];
        if (!is_array($capabilities) || count($capabilities) > 8) {
            fail("connector_metadata.{$index}.capabilities must contain at most 8 declared labels.");
        }
        $normalisedCapabilities = [];
        foreach ($capabilities as $capability) {
            $capability = trim((string) $capability);
            if ($capability === '' || preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $capability) !== 1) {
                fail("connector_metadata.{$index}.capabilities contains an invalid label.");
            }
            $normalisedCapabilities[] = $capability;
        }
        $connectors[] = [
            'provider_key' => $providerKey,
            'label' => $label,
            'mode' => $mode,
            'capabilities' => array_values(array_unique($normalisedCapabilities)),
        ];
    }
    return $connectors;
}

if ($argc !== 2) {
    fail('usage: php scripts/tenant-demo-preflight.php path/to/tenant-demo.json');
}

$inputPath = $argv[1];
if (!is_file($inputPath) || is_link($inputPath) || filesize($inputPath) === false || filesize($inputPath) > 262144) {
    fail('manifest must be a regular JSON file no larger than 256 KiB.');
}
$json = file_get_contents($inputPath);
if (!is_string($json)) {
    fail('manifest could not be read.');
}
try {
    $input = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail('manifest is not valid JSON.');
}
if (!is_array($input) || array_is_list($input)) {
    fail('manifest must be a JSON object.');
}

noSecrets($input);
exactKeys($input, [
    'tenant_key', 'primary_domain', 'brand_name', 'site_profile', 'primary_color',
    'default_locale', 'enabled_locales', 'operating_mode', 'connector_metadata',
    'feature_gates', 'telegram',
], 'manifest');

$tenantKey = stringValue($input, 'tenant_key', 3, 64);
if (preg_match('/^[a-z][a-z0-9-]*$/D', $tenantKey) !== 1) {
    fail('tenant_key must use lowercase letters, digits and hyphens, beginning with a letter.');
}
$domain = domainValue($input);
$brandName = stringValue($input, 'brand_name', 2, 100);
$profile = (string) ($input['site_profile'] ?? '');
if (!in_array($profile, ['travel', 'visa'], true)) {
    fail('site_profile must be travel or visa.');
}
$primaryColor = (string) ($input['primary_color'] ?? '');
if (preg_match('/^#[a-fA-F0-9]{6}$/D', $primaryColor) !== 1) {
    fail('primary_color must be a six-digit hexadecimal colour.');
}
$defaultLocale = (string) ($input['default_locale'] ?? '');
if (!in_array($defaultLocale, ALLOWED_LOCALES, true)) {
    fail('default_locale is not supported.');
}
$enabledLocales = $input['enabled_locales'] ?? [];
if (!is_array($enabledLocales) || $enabledLocales === []) {
    fail('enabled_locales must contain at least the default locale.');
}
$enabledLocales = array_values(array_unique(array_map('strval', $enabledLocales)));
if (array_diff($enabledLocales, ALLOWED_LOCALES) !== [] || !in_array($defaultLocale, $enabledLocales, true)) {
    fail('enabled_locales contains an unsupported locale or excludes default_locale.');
}
$mode = (string) ($input['operating_mode'] ?? '');
if (!in_array($mode, SAFE_MODES, true)) {
    fail('operating_mode must be lead_only, affiliate_redirect or manual_fulfilment; live_booking is not allowed in a demo preflight.');
}

$requestedGates = $input['feature_gates'] ?? [];
if (!is_array($requestedGates)) {
    fail('feature_gates must be an object when supplied.');
}
exactKeys($requestedGates, GATE_KEYS, 'feature_gates');
foreach ($requestedGates as $key => $enabled) {
    if ($enabled !== false) {
        fail("feature_gates.{$key} must be false; the preflight cannot enable any feature gate.");
    }
}
$telegram = $input['telegram'] ?? [];
if (!is_array($telegram)) {
    fail('telegram must be an object when supplied.');
}
exactKeys($telegram, ['enabled'], 'telegram');
if (($telegram['enabled'] ?? false) !== false) {
    fail('telegram.enabled must be false; no bot, webhook or message delivery is configured by this tool.');
}

$plan = [
    'schema' => 'vazin-tenant-demo-preflight/v1',
    'outcome' => 'preflight_validated_no_provisioning',
    'tenant' => [
        'key' => $tenantKey,
        'primary_domain' => $domain,
        'brand_name' => $brandName,
        'site_profile' => $profile,
        'primary_color' => strtolower($primaryColor),
        'default_locale' => $defaultLocale,
        'enabled_locales' => $enabledLocales,
        'operating_mode' => $mode,
    ],
    'connector_metadata' => validateConnectors($input['connector_metadata'] ?? null),
    'safety' => [
        'provisioning_performed' => false,
        'network_requests_performed' => false,
        'credentials_accepted' => false,
        'feature_gates' => array_fill_keys(GATE_KEYS, false),
    ],
    'still_required' => [
        'A compatible managed-site bootstrap and profile-aware external-runtime worker.',
        'Explicit owner approval for the exact domain, server target and tenant creation.',
        'Tenant licence, legal seller identity, support contact and policy copy.',
        'Separate database, runtime, administrator bootstrap, vhost/TLS and rollback plan.',
        'Supplier contract, encrypted credential hand-off and sandbox evidence before live_booking.',
        'Separate Telegram consent copy, bot/webhook staging test and opt-in evidence before delivery.',
    ],
];

echo json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
