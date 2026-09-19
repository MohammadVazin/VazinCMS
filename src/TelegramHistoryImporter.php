<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;
use Throwable;

final class TelegramHistoryImporter
{
    private const MAX_BYTES = 200_000_000;

    public static function importFile(int $connectionId, string $path, string $sourceType = 'telegram_export'): array
    {
        if (!in_array($sourceType, ['telegram_export','mtproto'], true)) throw new RuntimeException('نوع منبع تاریخچه پشتیبانی نمی‌شود.');
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || is_link($resolved)) throw new RuntimeException('فایل تاریخچه پیدا نشد.');
        $size = filesize($resolved);
        if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) throw new RuntimeException('حجم فایل تاریخچه باید کمتر از ۲۰۰ مگابایت باشد.');
        $raw = file_get_contents($resolved);
        if (!is_string($raw)) throw new RuntimeException('خواندن فایل تاریخچه ناموفق بود.');
        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($decoded)) throw new RuntimeException('فایل JSON تاریخچه معتبر نیست.');
        $fingerprint = hash('sha256', $raw);
        unset($raw);
        $messages = $sourceType === 'telegram_export' ? ($decoded['messages'] ?? null) : ($decoded['messages'] ?? $decoded);
        if (!is_array($messages)) throw new RuntimeException('فهرست messages در فایل تاریخچه پیدا نشد.');
        return self::importMessages($connectionId, $messages, $sourceType, $fingerprint);
    }

    public static function importMessages(int $connectionId, iterable $messages, string $sourceType, string $fingerprint): array
    {
        if (!in_array($sourceType, ['telegram_export','mtproto'], true) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('مشخصات منبع تاریخچه معتبر نیست.');
        }
        $connection = TelegramSyncService::connectionById($connectionId);
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO telegram_history_imports(connection_id,source_type,source_fingerprint,status) VALUES(:connection,:type,:fingerprint,'processing') "
            . "ON CONFLICT(connection_id,source_type,source_fingerprint) DO UPDATE SET status='processing',scanned_count=0,created_count=0,updated_count=0,skipped_count=0,error_count=0,last_error=NULL,started_at=CURRENT_TIMESTAMP,finished_at=NULL"
        )->execute(['connection'=>$connectionId,'type'=>$sourceType,'fingerprint'=>$fingerprint]);
        $find = $pdo->prepare('SELECT id FROM telegram_history_imports WHERE connection_id=:connection AND source_type=:type AND source_fingerprint=:fingerprint');
        $find->execute(['connection'=>$connectionId,'type'=>$sourceType,'fingerprint'=>$fingerprint]);
        $importId = (int)$find->fetchColumn();
        $stats = ['import_id'=>$importId,'scanned'=>0,'created'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'last_error'=>null];
        $cursor = null;
        foreach ($messages as $message) {
            $stats['scanned']++;
            if (!is_array($message) || (isset($message['type']) && $message['type'] !== 'message')) {
                $stats['skipped']++;
                continue;
            }
            try {
                $normalized = $sourceType === 'telegram_export' ? self::normalizeExportMessage($message) : self::normalizeMtprotoMessage($message);
                if ($normalized === null) { $stats['skipped']++; continue; }
                $cursor = (string)$normalized['message_id'];
                $result = TelegramSyncService::syncChannelMessage(
                    $connection,
                    $normalized,
                    null,
                    json_encode($message, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)
                );
                !empty($result['created']) ? $stats['created']++ : $stats['updated']++;
            } catch (Throwable $error) {
                $stats['errors']++;
                $stats['last_error'] = mb_substr($error->getMessage(), 0, 1000);
            }
            if ($stats['scanned'] % 100 === 0) self::checkpoint($importId, $stats, $cursor, false);
        }
        self::checkpoint($importId, $stats, $cursor, true);
        Database::connection()->prepare('UPDATE telegram_connections SET last_sync_at=CURRENT_TIMESTAMP,last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute(['error'=>$stats['last_error'],'id'=>$connectionId]);
        return $stats;
    }

    private static function normalizeExportMessage(array $message): ?array
    {
        $id = filter_var($message['id'] ?? null, FILTER_VALIDATE_INT);
        $timestamp = filter_var($message['date_unixtime'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($timestamp)) {
            $parsed = strtotime((string)($message['date'] ?? ''));
            $timestamp = $parsed === false ? null : $parsed;
        }
        if (!is_int($id) || $id < 1 || !is_int($timestamp) || $timestamp < 1) return null;
        $text = self::flattenText($message['text'] ?? '');
        $normalized = ['message_id'=>$id,'date'=>$timestamp,'text'=>$text];
        $edit = filter_var($message['edited_unixtime'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($edit) && !empty($message['edited'])) {
            $parsed = strtotime((string)$message['edited']);
            $edit = $parsed === false ? null : $parsed;
        }
        if (is_int($edit) && $edit > 0) $normalized['edit_date'] = $edit;
        foreach (['photo','media_type','mime_type','poll'] as $key) {
            if (isset($message[$key])) $normalized[$key] = $message[$key];
        }
        $mediaType = mb_strtolower(trim((string)($message['media_type'] ?? '')));
        if (isset($message['file'])) {
            $target = str_contains($mediaType, 'voice') ? 'voice'
                : (str_contains($mediaType, 'video') ? 'video'
                : (str_contains($mediaType, 'audio') ? 'audio'
                : (str_contains($mediaType, 'animation') ? 'animation' : 'document')));
            $normalized[$target] = $message['file'];
        } elseif ($mediaType !== '') {
            if (str_contains($mediaType, 'voice')) $normalized['voice'] = true;
            elseif (str_contains($mediaType, 'video')) $normalized['video'] = true;
            elseif (str_contains($mediaType, 'audio')) $normalized['audio'] = true;
            elseif (str_contains($mediaType, 'animation')) $normalized['animation'] = true;
            elseif (str_contains($mediaType, 'sticker')) $normalized['sticker'] = true;
        }
        $preview = $message['vazin_public_preview'] ?? null;
        if (is_array($preview) && is_array($preview['kinds'] ?? null)) {
            foreach (['photo','video','voice','document','poll'] as $kind) {
                if (in_array($kind, $preview['kinds'], true) && !array_key_exists($kind, $normalized)) {
                    $normalized[$kind] = true;
                }
            }
        }
        return $normalized;
    }

    private static function normalizeMtprotoMessage(array $message): ?array
    {
        $id = filter_var($message['message_id'] ?? $message['id'] ?? null, FILTER_VALIDATE_INT);
        $timestamp = filter_var($message['date'] ?? $message['date_unixtime'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($timestamp) && isset($message['date'])) {
            $parsed = strtotime((string)$message['date']);
            $timestamp = $parsed === false ? null : $parsed;
        }
        if (!is_int($id) || $id < 1 || !is_int($timestamp) || $timestamp < 1) return null;
        $normalized = ['message_id'=>$id,'date'=>$timestamp,'text'=>self::flattenText($message['text']??$message['message']??'')];
        $edit = filter_var($message['edit_date'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($edit) && $edit > 0) $normalized['edit_date'] = $edit;
        return $normalized;
    }

    private static function flattenText(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (!is_array($value)) return '';
        $parts = [];
        foreach ($value as $part) {
            if (is_string($part)) $parts[] = $part;
            elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) $parts[] = $part['text'];
        }
        return implode('', $parts);
    }

    private static function checkpoint(int $importId, array $stats, ?string $cursor, bool $finished): void
    {
        $status = $finished ? ($stats['errors'] > 0 ? 'partial' : 'completed') : 'processing';
        Database::connection()->prepare(
            'UPDATE telegram_history_imports SET status=:status,scanned_count=:scanned,created_count=:created,updated_count=:updated,skipped_count=:skipped,error_count=:errors,cursor_value=:cursor,last_error=:error,finished_at=' . ($finished?'CURRENT_TIMESTAMP':'NULL') . ' WHERE id=:id'
        )->execute([
            'status'=>$status,'scanned'=>$stats['scanned'],'created'=>$stats['created'],'updated'=>$stats['updated'],
            'skipped'=>$stats['skipped'],'errors'=>$stats['errors'],'cursor'=>$cursor,'error'=>$stats['last_error'],'id'=>$importId,
        ]);
    }
}
