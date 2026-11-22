<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * File lifecycle for the proposed site-scoped CMS storage.
 *
 * A tenant's uploaded files live under a distinct directory inside the
 * isolated RuntimePaths::uploads() root. Never delete a legacy/shared-root
 * file or a sibling tenant file. No migration of existing legacy uploads is
 * performed by this class.
 */
final class SiteScopedMediaService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId,
        private readonly SiteScopedContentWriter $writer
    ) {
        if ($siteId<1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_schema_unavailable');
        }
        if (MediaStorage::driver()!=='local') {
            throw new RuntimeException('tenant_media_driver_unsupported');
        }
    }

    public static function siteDir(int $siteId,bool $create=false): string
    {
        if($siteId<1)throw new RuntimeException('tenant_site_required');
        $base=RuntimePaths::uploads();
        $path=$base.'/site-'.(string)$siteId;
        if($create && !is_dir($path) && !mkdir($path,0750,false) && !is_dir($path)) {
            throw new RuntimeException('tenant_media_directory_unavailable');
        }
        if(is_link($path) || !is_dir($path)) {
            throw new RuntimeException('tenant_media_directory_unavailable');
        }
        $real=realpath($path);
        if($real===false || $real!==$path || !str_starts_with($real,$base.'/')) {
            throw new RuntimeException('tenant_media_directory_unsafe');
        }
        return $real;
    }

    public static function filePath(int $siteId,string $storedName): string
    {
        if(preg_match('/^[a-f0-9]{32}(?:-(?:320|768|1280))?\.(?:jpg|jpeg|png|webp|gif|pdf)$/D',$storedName)!==1) {
            throw new RuntimeException('tenant_media_name_invalid');
        }
        $path=self::siteDir($siteId).'/'.$storedName;
        if(is_link($path))throw new RuntimeException('tenant_media_symlink_denied');
        return $path;
    }

    public function upload(int $actorId,array $file,string $alt=''): int
    {
        $this->writer->assertMediaWrite($actorId);
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
           !is_string($file['tmp_name']??null) ||
           !is_uploaded_file((string)$file['tmp_name']) ||
           (int)($file['size']??0)<1 || (int)$file['size']>10*1024*1024) {
            throw new InvalidArgumentException('tenant_media_upload_invalid');
        }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed=[
            'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
            'image/gif'=>'gif','application/pdf'=>'pdf'
        ];
        if(!is_string($mime)||!isset($allowed[$mime])) {
            throw new InvalidArgumentException('tenant_media_type_invalid');
        }
        $inspection=MediaPipeline::inspect((string)$file['tmp_name'],$mime);
        if(($inspection['sha256']??'')==='') throw new RuntimeException('tenant_media_hash_missing');
        if(str_starts_with($mime,'image/') && (!is_int($inspection['width']) || !is_int($inspection['height']))) {
            throw new InvalidArgumentException('tenant_media_image_invalid');
        }
        // Local duplicate warning only. The old global unique SHA index remains
        // an explicit multi-site migration blocker and is NOT bypassed.
        $dup=$this->pdo->prepare('SELECT id FROM cms_media WHERE site_id=:site AND sha256=:hash LIMIT 1');
        $dup->execute(['site'=>$this->siteId,'hash'=>$inspection['sha256']]);
        if($dup->fetchColumn())throw new InvalidArgumentException('tenant_media_duplicate');

        $dir=self::siteDir($this->siteId,true);
        $stored=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
        $target=$dir.'/'.$stored;
        if(file_exists($target) || is_link($target)) {
            throw new RuntimeException('tenant_media_filename_collision');
        }
        if(!move_uploaded_file((string)$file['tmp_name'],$target)) {
            throw new RuntimeException('tenant_media_move_failed');
        }
        chmod($target,0640);
        $created=[$target];
        $began=false;
        try {
            $derivatives=MediaPipeline::derivatives($target,$mime,$stored);
            foreach($derivatives as $d){
                $derivative=self::filePath($this->siteId,(string)$d['stored_name']);
                $created[]=$derivative;
                chmod($derivative,0640);
            }
            $began=$this->pdo->beginTransaction();
            if(!$began)throw new RuntimeException('tenant_media_tx_failed');
            $q=$this->pdo->prepare(
                'INSERT INTO cms_media(site_id,file_name,stored_name,mime_type,file_size,alt_text,'.
                'uploaded_by,width,height,sha256,storage_driver,storage_key,metadata_json) '.
                'VALUES(:site,:file,:stored,:mime,:size,:alt,:actor,:width,:height,:sha,:driver,:key,:meta)'
            );
            $q->execute([
                'site'=>$this->siteId,'file'=>basename((string)($file['name']??'upload')),
                'stored'=>$stored,'mime'=>$mime,'size'=>(int)$file['size'],
                'alt'=>mb_substr(trim($alt),0,255),'actor'=>$actorId,
                'width'=>$inspection['width'],'height'=>$inspection['height'],
                'sha'=>$inspection['sha256'],'driver'=>'local',
                'key'=>$stored,'meta'=>'{}'
            ]);
            $mediaId=(int)$this->pdo->lastInsertId();
            $insert=$this->pdo->prepare(
                'INSERT INTO cms_media_derivatives(site_id,media_id,variant,stored_name,mime_type,'.
                'width,height,file_size,sha256) '.
                'VALUES(:site,:media,:variant,:name,:mime,:width,:height,:size,:sha)'
            );
            foreach($derivatives as $d) {
                $insert->execute([
                    'site'=>$this->siteId,'media'=>$mediaId,'variant'=>$d['variant'],
                    'name'=>$d['stored_name'],'mime'=>$d['mime_type'],
                    'width'=>$d['width'],'height'=>$d['height'],
                    'size'=>$d['file_size'],'sha'=>$d['sha256']
                ]);
            }
            $this->pdo->commit();
            return $mediaId;
        } catch(Throwable $e) {
            if($began && $this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach($created as $path) {
                if (is_file($path) && !is_link($path)) @unlink($path);
            }
            throw $e;
        }
    }

    public function delete(int $actorId,int $mediaId): void
    {
        $row=$this->writer->mediaRow($actorId,$mediaId);
        $q=$this->pdo->prepare(
            'SELECT stored_name FROM cms_media_derivatives WHERE site_id=:site AND media_id=:media'
        );
        $q->execute(['site'=>$this->siteId,'media'=>$mediaId]);
        $names=array_merge([(string)$row['stored_name']],$q->fetchAll(PDO::FETCH_COLUMN));
        // Validate BEFORE mutating any DB record; do not trust a stale path.
        $paths=[];
        foreach($names as $name)$paths[]=self::filePath($this->siteId,(string)$name);
        $q=$this->pdo->prepare('DELETE FROM cms_media WHERE site_id=:site AND id=:media');
        $q->execute(['site'=>$this->siteId,'media'=>$mediaId]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_content_unavailable');

        // The rows are gone (including ON DELETE CASCADE variants). An unlink
        // failure leaves an orphan in THIS tenant's directory, not cross-site
        // deletion. Report it for cleanup rather than claiming full success.
        foreach($paths as $path) {
            if(is_file($path) && !is_link($path) && !unlink($path)) {
                throw new RuntimeException('tenant_media_orphan_cleanup_required');
            }
        }
    }
}
