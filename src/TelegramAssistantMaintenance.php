<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;

final class TelegramAssistantMaintenance
{
    /** @return array<string,int> */
    public static function run(int $limit=1000): array
    {
        $pdo=Database::connection();$limit=max(1,min(5000,$limit));$result=['messages'=>0];
        $connections=$pdo->query('SELECT connection_id FROM telegram_assistant_profiles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach($connections as$connectionId)$result['messages']+=TelegramAssistantPrivacy::purgeExpired((int)$connectionId,$limit);
        $result['sessions']=self::deleteById($pdo,'DELETE FROM telegram_miniapp_sessions WHERE id IN (SELECT id FROM telegram_miniapp_sessions WHERE expires_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT :limit)',$limit);
        $result['flows']=self::deleteById($pdo,'DELETE FROM telegram_assistant_flow_sessions WHERE id IN (SELECT id FROM telegram_assistant_flow_sessions WHERE expires_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT :limit)',$limit);
        $statement=$pdo->prepare('DELETE FROM telegram_assistant_replays WHERE replay_key IN (SELECT replay_key FROM telegram_assistant_replays WHERE expires_at<=CURRENT_TIMESTAMP ORDER BY replay_key LIMIT :limit)');$statement->bindValue(':limit',$limit,PDO::PARAM_INT);$statement->execute();$result['replays']=$statement->rowCount();
        $statement=$pdo->prepare('DELETE FROM telegram_assistant_rate_limits WHERE rate_key IN (SELECT rate_key FROM telegram_assistant_rate_limits WHERE expires_at<=CURRENT_TIMESTAMP ORDER BY rate_key LIMIT :limit)');$statement->bindValue(':limit',$limit,PDO::PARAM_INT);$statement->execute();$result['rate_limits']=$statement->rowCount();
        $statement=$pdo->prepare("UPDATE telegram_assistant_outbox SET status='cancelled',last_error='expired_before_delivery',updated_at=CURRENT_TIMESTAMP WHERE id IN (SELECT id FROM telegram_assistant_outbox WHERE status='pending' AND expires_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT :limit)");$statement->bindValue(':limit',$limit,PDO::PARAM_INT);$statement->execute();$result['outbox_cancelled']=$statement->rowCount();
        return $result;
    }

    private static function deleteById(PDO $pdo,string $sql,int $limit): int
    {
        $statement=$pdo->prepare($sql);$statement->bindValue(':limit',$limit,PDO::PARAM_INT);$statement->execute();return $statement->rowCount();
    }
}
