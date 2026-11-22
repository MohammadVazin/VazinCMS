<?php
declare(strict_types=1);

namespace VazinCMS\Modules\Analytics;

use VazinCMS\ModuleDatabase;

final class AnalyticsService
{
    private bool $ready = false;

    public function __construct(private ModuleDatabase $db)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) return;
        $this->db->ensureTable('hits', [
            'day_bucket'    => 'string:12',
            'path'          => 'string:255',
            'referrer_host' => 'string:180',
            'device_type'   => 'string:20:default=desktop',
            'visitor_hash'  => 'string:64',
        ]);
        $this->ready = true;
    }

    public function recordHit(string $path, string $referrer = '', string $userAgent = '', string $ip = ''): int
    {
        $this->ensureSchema();
        $cleanPath = '/' . ltrim(mb_substr(trim((string) (parse_url($path, PHP_URL_PATH) ?: '/')), 0, 240), '/');
        $refHost = '';
        if ($referrer !== '') {
            $parsedRef = parse_url($referrer, PHP_URL_HOST);
            if (is_string($parsedRef)) {
                $refHost = strtolower(mb_substr(trim($parsedRef), 0, 175));
            }
        }
        $uaLower = strtolower($userAgent);
        $device = str_contains($uaLower, 'bot') || str_contains($uaLower, 'spider')
            ? 'bot'
            : ((str_contains($uaLower, 'mobile') || str_contains($uaLower, 'android') || str_contains($uaLower, 'iphone')) ? 'mobile' : 'desktop');

        $day = gmdate('Y-m-d');
        // Privacy-preserving daily rotating hash (never stores raw IP or persistent cross-day tracking ID)
        $visitorHash = hash('sha256', $day . '|' . $ip . '|' . substr($userAgent, 0, 120));

        return $this->db->insert('hits', [
            'day_bucket' => $day,
            'path' => $cleanPath,
            'referrer_host' => $refHost,
            'device_type' => $device,
            'visitor_hash' => $visitorHash,
        ]);
    }

    /**
     * @return array{total_views:int,unique_visitors:int,top_pages:array<string,int>,devices:array<string,int>}
     */
    public function summary(int $limitRows = 500): array
    {
        $this->ensureSchema();
        $rows = $this->db->where('hits', [], 'id DESC', max(10, min(1000, $limitRows)));
        $visitors = [];
        $pages = [];
        $devices = ['desktop' => 0, 'mobile' => 0, 'bot' => 0];

        foreach ($rows as $r) {
            if (($r['device_type'] ?? '') === 'bot') {
                $devices['bot']++;
                continue;
            }
            $vh = (string) ($r['visitor_hash'] ?? '');
            if ($vh !== '') $visitors[$vh] = true;
            $p = (string) ($r['path'] ?? '/');
            $pages[$p] = ($pages[$p] ?? 0) + 1;
            $dev = (string) ($r['device_type'] ?? 'desktop');
            $devices[$dev] = ($devices[$dev] ?? 0) + 1;
        }
        arsort($pages);
        return [
            'total_views' => array_sum($pages),
            'unique_visitors' => count($visitors),
            'top_pages' => array_slice($pages, 0, 10, true),
            'devices' => $devices,
        ];
    }
}
