<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use ZipArchive;

/** Imports only inspected local media from an archive; it never fetches remote URLs. */
final class MediaArchiveImporter
{
    private const MAX_ARCHIVE_BYTES = 52_428_800;
    private const MAX_FILE_BYTES = 10_485_760;
    private const MAX_EXPANDED_BYTES = 209_715_200;
    private const MAX_FILES = 500;
    private const TYPES = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','application/pdf'=>'pdf'];

    /** @return array{imported:int,skipped:int,duplicates:int} */
    public static function import(string $archive, PDO $pdo, int $actorId): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('پشتیبانی ZIP روی سرور فعال نیست.');
        $archive = realpath($archive) ?: '';
        $size = $archive !== '' && is_file($archive) && !is_link($archive) ? filesize($archive) : false;
        if ($size === false || $size < 1 || $size > self::MAX_ARCHIVE_BYTES) throw new InvalidArgumentException('حجم آرشیو رسانه باید بین ۱ بایت تا ۵۰ مگابایت باشد.');
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) throw new InvalidArgumentException('آرشیو ZIP قابل خواندن نیست.');
        if ($zip->numFiles > self::MAX_FILES) throw new InvalidArgumentException('آرشیو بیش از ۵۰۰ ورودی دارد.');
        $stage = RuntimePaths::storage() . '/migration-media';
        if (!is_dir($stage) && !mkdir($stage, 0700, true) && !is_dir($stage)) throw new RuntimeException('فضای موقت رسانه در دسترس نیست.');
        $stats = ['imported'=>0,'skipped'=>0,'duplicates'=>0]; $expanded = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) throw new InvalidArgumentException('یک ورودی ZIP معتبر نیست.');
                $name = (string)($stat['name'] ?? '');
                if (str_ends_with($name, '/')) continue;
                self::validatePath($name); self::rejectLink($zip, $i);
                $entrySize = (int)($stat['size'] ?? -1); $compressed = (int)($stat['comp_size'] ?? -1);
                $expanded += $entrySize;
                if ($entrySize < 0 || $entrySize > self::MAX_FILE_BYTES || $expanded > self::MAX_EXPANDED_BYTES || ($entrySize > 1_000_000 && $compressed > 0 && $entrySize / $compressed > 200)) throw new InvalidArgumentException('آرشیو از محدودیت ایمنی استخراج عبور کرده است.');
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) throw new InvalidArgumentException('خواندن یکی از فایل‌های آرشیو ممکن نیست.');
                $temporary = tempnam($stage, 'media-');
                if ($temporary === false) { fclose($stream); throw new RuntimeException('ساخت فایل موقت ممکن نیست.'); }
                // tempnam already created this private file; reopening it for writing is safe.
                $out = fopen($temporary, 'wb');
                if (!is_resource($out)) { fclose($stream); @unlink($temporary); throw new RuntimeException('نوشتن فایل موقت ممکن نیست.'); }
                $copied = stream_copy_to_stream($stream, $out, self::MAX_FILE_BYTES + 1); fclose($stream); fclose($out);
                if ($copied !== $entrySize) { @unlink($temporary); throw new InvalidArgumentException('اندازهٔ فایل استخراج‌شده معتبر نیست.'); }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
                if (!isset(self::TYPES[$mime])) { @unlink($temporary); $stats['skipped']++; continue; }
                $inspect = MediaPipeline::inspect($temporary, $mime); $duplicate = MediaPipeline::duplicate($pdo, (string)$inspect['sha256']);
                if ($duplicate !== null) { @unlink($temporary); $stats['duplicates']++; continue; }
                $stored = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime]; $target = RuntimePaths::uploads() . '/' . $stored;
                if (!rename($temporary, $target)) { @unlink($temporary); throw new RuntimeException('ذخیرهٔ رسانه ممکن نیست.'); }
                try {
                    $metadata = json_encode(['imported_from'=>'archive','original_path'=>$name], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $pdo->prepare('INSERT INTO cms_media(file_name,stored_name,mime_type,file_size,alt_text,uploaded_by,width,height,sha256,storage_driver,storage_key,metadata_json) VALUES(:name,:stored,:mime,:size,\'\',:actor,:width,:height,:sha,\'local\',:stored_key,:metadata)')->execute(['name'=>basename($name),'stored'=>$stored,'mime'=>$mime,'size'=>$entrySize,'actor'=>$actorId,'width'=>$inspect['width'],'height'=>$inspect['height'],'sha'=>$inspect['sha256'],'stored_key'=>$stored,'metadata'=>$metadata]);
                    $mediaId = (int)$pdo->lastInsertId();
                    foreach (MediaPipeline::derivatives($target, $mime, $stored) as $derivative) $pdo->prepare('INSERT INTO cms_media_derivatives(media_id,variant,stored_name,mime_type,width,height,file_size,sha256) VALUES(:media,:variant,:stored,:mime,:width,:height,:size,:sha)')->execute(['media'=>$mediaId,'variant'=>$derivative['variant'],'stored'=>$derivative['stored_name'],'mime'=>$derivative['mime_type'],'width'=>$derivative['width'],'height'=>$derivative['height'],'size'=>$derivative['file_size'],'sha'=>$derivative['sha256']]);
                } catch (\Throwable $error) { @unlink($target); throw $error; }
                $stats['imported']++;
            }
        } finally { $zip->close(); }
        return $stats;
    }

    private static function validatePath(string $path): void
    {
        if ($path === '' || strlen($path) > 500 || str_contains($path, '\\') || preg_match('#(^/|(^|/)\.\.?(?:/|$)|[\x00-\x1f\x7f:]|//)#', $path)) throw new InvalidArgumentException('مسیر ناامن در ZIP شناسایی شد.');
    }
    private static function rejectLink(ZipArchive $zip, int $index): void
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) return;
        $ops = 0; $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $ops, $attributes) && (($attributes >> 16) & 0170000) === 0120000) throw new InvalidArgumentException('پیوند نمادین در ZIP مجاز نیست.');
    }
}
