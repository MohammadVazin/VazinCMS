<?php
declare(strict_types=1);

namespace VazinCMS;

final class ReleaseChannel
{
    private const URL = 'https://cms.vazin.online/releases/stable.json';

    public static function stable(): ?array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'ignore_errors' => false,
                    'user_agent' => 'VazinCMS/' . Version::current(),
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]);

            $raw = @file_get_contents(self::URL, false, $context);

            if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) {
                return null;
            }

            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return null;
            }

            if (($data['product'] ?? '') !== 'VazinCMS') {
                return null;
            }

            if (($data['channel'] ?? '') !== 'stable') {
                return null;
            }

            $version = (string) ($data['version'] ?? '');

            if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
                return null;
            }

            $notes = trim((string) ($data['release_notes'] ?? ''));

            if ($notes !== '' && !str_starts_with($notes, 'https://cms.vazin.online/')) {
                $notes = '';
            }

            return [
                'version' => $version,
                'available' => version_compare($version, Version::current(), '>'),
                'release_notes' => $notes,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
