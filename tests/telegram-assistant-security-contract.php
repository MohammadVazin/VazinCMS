<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir() . '/vazincms-telegram-assistant-security-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('VAZINCMS_STORAGE_PATH=' . $runtime);
putenv('SESSION_SECURE=false');
putenv('APP_KEY=telegram-assistant-security-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');
require $root . '/src/bootstrap.php';

use VazinCMS\{Database, TelegramAssistantPrivacy, TelegramAssistantRateLimiter};

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expectFailure = static function (callable $action, string $message) use ($check): void {
    $failed = false;
    try { $action(); } catch (Throwable) { $failed = true; }
    $check($failed, $message);
};
$cleanup = static function (string $path) use (&$cleanup): void {
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) $cleanup($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($path);
};

try {
    $pdo = Database::connection();
    $pdo->exec(
        'CREATE TABLE telegram_assistant_rate_limits ('
        . 'rate_key TEXT PRIMARY KEY,hit_count INTEGER NOT NULL,'
        . 'window_started_at TEXT NOT NULL,expires_at TEXT NOT NULL,updated_at TEXT NOT NULL)'
    );
    $pdo->exec(
        'CREATE TABLE telegram_assistant_messages ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,connection_id INTEGER NOT NULL,'
        . 'content_encrypted TEXT NOT NULL,content_key TEXT NOT NULL,expires_at TEXT NOT NULL)'
    );

    $first = TelegramAssistantRateLimiter::consume('customer-message', 'connection:11', 'ip:192.0.2.10', 2, 60);
    $second = TelegramAssistantRateLimiter::consume('customer-message', 'connection:11', 'ip:192.0.2.10', 2, 60);
    $check($first['allowed'] && $first['remaining'] === 1 && $first['retry_after'] === 0, 'first rate-limit request was rejected');
    $check($second['allowed'] && $second['remaining'] === 0, 'second rate-limit request was rejected');
    $beforeReject = $pdo->query('SELECT * FROM telegram_assistant_rate_limits')->fetch();
    $changesBeforeReject = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $rejected = TelegramAssistantRateLimiter::consume('customer-message', 'connection:11', 'ip:192.0.2.10', 2, 60);
    $changesAfterReject = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $afterReject = $pdo->query('SELECT * FROM telegram_assistant_rate_limits')->fetch();
    $check(!$rejected['allowed'] && $rejected['remaining'] === 0 && $rejected['retry_after'] >= 1 && $rejected['retry_after'] <= 60, 'rate-limit rejection result is invalid');
    $check($beforeReject === $afterReject && $changesBeforeReject === $changesAfterReject, 'rejected rate-limit request mutated persisted state');
    $check(!str_contains((string)$beforeReject['rate_key'], '192.0.2.10'), 'raw rate-limit subject was stored');

    $otherTenant = TelegramAssistantRateLimiter::consume('customer-message', 'connection:22', 'ip:192.0.2.10', 2, 60);
    $check($otherTenant['allowed'], 'one tenant exhausted another tenant rate bucket');
    $pdo->exec("UPDATE telegram_assistant_rate_limits SET expires_at='2000-01-01 00:00:00',hit_count=2 WHERE rate_key=(SELECT rate_key FROM telegram_assistant_rate_limits ORDER BY rowid LIMIT 1)");
    $reset = TelegramAssistantRateLimiter::consume('customer-message', 'connection:11', 'ip:192.0.2.10', 2, 60);
    $check($reset['allowed'] && $reset['remaining'] === 1, 'expired rate-limit window was not reset');
    $expectFailure(
        static fn(): array => TelegramAssistantRateLimiter::consume('x', 'tenant', 'subject', 1, 60),
        'invalid rate-limit namespace was accepted'
    );

    $plaintext = "Fake customer asks for a booking: test.user@example.invalid +1 202 555 0100";
    $protected = TelegramAssistantPrivacy::protectContent(11, $plaintext);
    $check(!str_contains($protected['content_encrypted'], $plaintext), 'assistant content was stored in plaintext');
    $check(preg_match('/^[a-f0-9]{32}$/', $protected['content_key']) === 1, 'assistant content key format is invalid');
    $check(TelegramAssistantPrivacy::revealContent(11, $protected['content_encrypted'], $protected['content_key']) === $plaintext, 'assistant content round trip failed');
    $expectFailure(
        static fn(): string => TelegramAssistantPrivacy::revealContent(22, $protected['content_encrypted'], $protected['content_key']),
        'assistant ciphertext opened in another tenant context'
    );
    $expectFailure(
        static fn(): string => TelegramAssistantPrivacy::revealContent(11, $protected['content_encrypted'], str_repeat('0', 32)),
        'assistant ciphertext opened under another content key'
    );
    $check(TelegramAssistantPrivacy::clampRetentionDays(null) === 30, 'default retention is invalid');
    $check(TelegramAssistantPrivacy::clampRetentionDays(-100) === 1, 'minimum retention clamp failed');
    $check(TelegramAssistantPrivacy::clampRetentionDays(999) === 90, 'maximum retention clamp failed');
    $fixedExpiry = TelegramAssistantPrivacy::expiresAt(999, 1_700_000_000);
    $check($fixedExpiry === gmdate('Y-m-d H:i:s', 1_700_000_000 + 90 * 86_400), 'retention expiry calculation failed');

    $logInput = "token=123456789:" . str_repeat('A', 35) . "\nemail=test.user@example.invalid id=9988776655";
    $redacted = TelegramAssistantPrivacy::redactForLog($logInput, 'assistant-message');
    $check(!str_contains($redacted, '123456789') && !str_contains($redacted, 'test.user') && !str_contains($redacted, '9988776655'), 'log redaction exposed sensitive input');
    $check(str_contains($redacted, 'redacted hmac=') && !str_contains($redacted, "\n"), 'log redaction is not correlation-safe');

    $insert = $pdo->prepare('INSERT INTO telegram_assistant_messages(connection_id,content_encrypted,content_key,expires_at) VALUES(:connection,:encrypted,:key,:expires)');
    $expiredOne = TelegramAssistantPrivacy::protectContent(11, 'expired-one');
    $futureOne = TelegramAssistantPrivacy::protectContent(11, 'future-one');
    $otherExpired = TelegramAssistantPrivacy::protectContent(22, 'other-expired');
    $insert->execute(['connection'=>11,'encrypted'=>$expiredOne['content_encrypted'],'key'=>$expiredOne['content_key'],'expires'=>'2000-01-01 00:00:00']);
    $insert->execute(['connection'=>11,'encrypted'=>$futureOne['content_encrypted'],'key'=>$futureOne['content_key'],'expires'=>'2999-01-01 00:00:00']);
    $insert->execute(['connection'=>22,'encrypted'=>$otherExpired['content_encrypted'],'key'=>$otherExpired['content_key'],'expires'=>'2000-01-01 00:00:00']);
    $check(TelegramAssistantPrivacy::purgeExpired(11, 1) === 1, 'tenant-scoped retention purge removed the wrong number of rows');
    $check((int)$pdo->query('SELECT COUNT(*) FROM telegram_assistant_messages WHERE connection_id=11')->fetchColumn() === 1, 'retention purge removed unexpired tenant content');
    $check((int)$pdo->query('SELECT COUNT(*) FROM telegram_assistant_messages WHERE connection_id=22')->fetchColumn() === 1, 'retention purge crossed the tenant boundary');
    $check(TelegramAssistantPrivacy::purgeExpired(11) === 0, 'retention purge removed an unexpired row');

    $fixtureDirectory = __DIR__ . '/fixtures/telegram';
    $fixtureExpectations = [
        'business-connection.json'=>'business_connection',
        'business-message.json'=>'business_message',
        'edited-business-message.json'=>'edited_business_message',
        'deleted-business-messages.json'=>'deleted_business_messages',
        'business-message-loop.json'=>'business_message',
    ];
    foreach ($fixtureExpectations as $file=>$field) {
        $decoded = json_decode((string)file_get_contents($fixtureDirectory . '/' . $file), true, 32, JSON_THROW_ON_ERROR);
        $check(isset($decoded['update_id'], $decoded[$field]) && is_array($decoded[$field]), 'invalid Telegram fixture: ' . $file);
    }
    $loop = json_decode((string)file_get_contents($fixtureDirectory . '/business-message-loop.json'), true, 32, JSON_THROW_ON_ERROR);
    $check(isset($loop['business_message']['sender_business_bot']['id']), 'loop fixture does not identify the sending business bot');

    echo "Telegram assistant security contract: OK\n";
} finally {
    $cleanup($runtime);
}
