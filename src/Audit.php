<?php
declare(strict_types=1);
namespace VazinCMS;

final class Audit
{
    public static function log(string $action, string $description, ?int $userId = null, array $metadata = []): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO audit_logs(user_id,action,description,ip_address,metadata) VALUES(:user_id,:action,:description,:ip,:metadata)');
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'ip' => Security::clientIp(),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
